<?php

declare(strict_types=1);

namespace DiveShop365\AI\Provider;

use DiveShop365\AI\Model\AiRequest;
use DiveShop365\AI\Model\AiResponse;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * Google Gemini provider (native API).
 *
 * Uses the generateContent endpoint with native JSON schema enforcement
 * via responseMimeType + responseSchema — more reliable than the OpenAI
 * compat layer for structured output.
 *
 * Free tier (AI Studio): generous daily limits, no credit card required.
 * Set AI_GEMINI_API_KEY in .env.
 *
 * Default model: gemini-2.0-flash  (fast, free, strong structured output)
 * Alternative:   gemini-2.5-pro    (more capable, lower free quota)
 */
class GeminiProvider extends AbstractAiProvider
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    protected string $model = 'gemini-2.0-flash';

    protected int $maxTokens = 1024;

    public function getProviderName(): string
    {
        return "Google Gemini ({$this->model})";
    }

    public function complete(AiRequest $request): AiResponse
    {
        if (!$this->isAvailable()) {
            return AiResponse::failure(
                'Gemini provider not configured — set AI_GEMINI_API_KEY in your .env file.',
                $this->getProviderName()
            );
        }

        try {
            $url  = self::API_BASE . '/' . $this->model . ':generateContent?key=' . $this->apiKey;
            $body = $this->buildRequestBody($request);

            $response = $this->getHttpClient()->post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => $body,
            ]);

            $payload = json_decode((string) $response->getBody(), true);
            return $this->parseResponse($payload, $request);

        } catch (GuzzleException $e) {
            $this->log('error', 'Gemini API request failed: ' . $e->getMessage());
            return AiResponse::failure('API request failed: ' . $e->getMessage(), $this->getProviderName());
        } catch (\Throwable $e) {
            $this->log('error', 'Gemini provider error: ' . $e->getMessage());
            return AiResponse::failure('Provider error: ' . $e->getMessage(), $this->getProviderName());
        }
    }

    private function buildRequestBody(AiRequest $request): array
    {
        $body = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $request->userPrompt]]],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $request->maxTokens ?? $this->maxTokens,
                'temperature'     => $request->temperature,
            ],
        ];

        if (!empty($request->systemPrompt)) {
            $body['systemInstruction'] = [
                'parts' => [['text' => $request->systemPrompt]],
            ];
        }

        if ($request->responseSchema !== null) {
            // Native Gemini structured output — most reliable way to enforce JSON shape
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseSchema']   = $this->toGeminiSchema($request->responseSchema);
        }

        return $body;
    }

    /**
     * Gemini's schema format is close to JSON Schema but uses uppercase TYPE values
     * and doesn't support all keywords. This normalises the most common subset.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function toGeminiSchema(array $schema): array
    {
        $out = [];

        if (isset($schema['type'])) {
            $out['type'] = strtoupper($schema['type']);
        }

        if (isset($schema['description'])) {
            $out['description'] = $schema['description'];
        }

        if (isset($schema['properties'])) {
            $out['properties'] = [];
            foreach ($schema['properties'] as $key => $prop) {
                $out['properties'][$key] = $this->toGeminiSchema($prop);
            }
        }

        if (isset($schema['required'])) {
            $out['required'] = $schema['required'];
        }

        if (isset($schema['items'])) {
            $out['items'] = $this->toGeminiSchema($schema['items']);
        }

        if (isset($schema['enum'])) {
            $out['enum'] = $schema['enum'];
        }

        return $out;
    }

    private function parseResponse(array $payload, AiRequest $request): AiResponse
    {
        $rawContent = $payload['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if ($request->responseSchema !== null) {
            $data = json_decode($rawContent, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                $this->log('warning', 'Gemini JSON parse failed. Raw: ' . substr($rawContent, 0, 200));
                return AiResponse::failure('Model returned invalid JSON', $this->getProviderName());
            }
            return AiResponse::success($rawContent, $data, $this->getProviderName());
        }

        return AiResponse::success($rawContent, null, $this->getProviderName());
    }

    private function log(string $level, string $message): void
    {
        try {
            /** @var LoggerInterface $logger */
            $logger = Injector::inst()->get(LoggerInterface::class . '.errorhandler');
            $logger->$level('[DiveShop365\AI\GeminiProvider] ' . $message);
        } catch (\Throwable) {
        }
    }
}
