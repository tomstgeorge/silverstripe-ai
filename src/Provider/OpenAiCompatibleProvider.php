<?php

declare(strict_types=1);

namespace DiveShop365\AI\Provider;

use DiveShop365\AI\Model\AiRequest;
use DiveShop365\AI\Model\AiResponse;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * OpenAI-compatible provider.
 *
 * Works with any service that implements the OpenAI chat completions API:
 *   - OpenRouter  (https://openrouter.ai/api/v1)         free models available
 *   - Groq        (https://api.groq.com/openai/v1)       fast, free tier
 *   - Mistral     (https://api.mistral.ai/v1)            free experiment tier
 *   - OpenAI      (https://api.openai.com/v1)            paid
 *   - Google      (https://generativelanguage.googleapis.com/v1beta/openai)  Gemini via OAI compat
 *
 * Swap services by changing baseUrl + model + apiKey in YAML — no code changes.
 *
 * Structured output:
 *   Uses response_format json_schema when a responseSchema is provided.
 *   Falls back to json_object mode (with a schema description in the prompt)
 *   for models that don't support full JSON schema enforcement.
 *
 * OpenRouter-specific:
 *   Set $siteUrl and $appName for the HTTP-Referer / X-Title headers
 *   that OpenRouter uses for rate-limit routing.
 */
class OpenAiCompatibleProvider extends AbstractAiProvider
{
    protected string $baseUrl = 'https://openrouter.ai/api/v1';

    protected string $model = 'google/gemini-2.0-flash-exp:free';

    protected int $maxTokens = 1024;

    /**
     * Optional: shown in OpenRouter dashboard. Set via YAML properties.
     */
    protected string $siteUrl = '';

    protected string $appName = 'DiveShop365';

    /**
     * Whether to use full JSON schema enforcement (response_format json_schema).
     * Set to false for models that only support json_object mode.
     */
    protected bool $useJsonSchema = true;

    public function setSiteUrl(string $url): void
    {
        $this->siteUrl = $url;
    }

    public function setAppName(string $name): void
    {
        $this->appName = $name;
    }

    public function setBaseUrl(string $url): void
    {
        $this->baseUrl = rtrim($url, '/');
    }

    public function setUseJsonSchema(bool $use): void
    {
        $this->useJsonSchema = $use;
    }

    public function getProviderName(): string
    {
        $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: $this->baseUrl;
        return "OpenAI-compatible ({$host} / {$this->model})";
    }

    public function complete(AiRequest $request): AiResponse
    {
        if (!$this->isAvailable()) {
            return AiResponse::failure(
                'Provider not configured — set the API key in your .env file.',
                $this->getProviderName()
            );
        }

        try {
            $body = $this->buildRequestBody($request);
            $headers = $this->buildHeaders();

            $response = $this->getHttpClient()->post($this->baseUrl . '/chat/completions', [
                'headers' => $headers,
                'json'    => $body,
            ]);

            $payload = json_decode((string) $response->getBody(), true);
            return $this->parseResponse($payload, $request);

        } catch (GuzzleException $e) {
            $this->log('error', 'API request failed: ' . $e->getMessage());
            return AiResponse::failure('API request failed: ' . $e->getMessage(), $this->getProviderName());
        } catch (\Throwable $e) {
            $this->log('error', 'Provider error: ' . $e->getMessage());
            return AiResponse::failure('Provider error: ' . $e->getMessage(), $this->getProviderName());
        }
    }

    private function buildHeaders(): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ];

        // OpenRouter-specific headers (ignored by other providers)
        if ($this->siteUrl) {
            $headers['HTTP-Referer'] = $this->siteUrl;
        }
        if ($this->appName) {
            $headers['X-Title'] = $this->appName;
        }

        return $headers;
    }

    private function buildRequestBody(AiRequest $request): array
    {
        $messages = [];

        if (!empty($request->systemPrompt)) {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt];
        }

        $userContent = $request->userPrompt;

        $body = [
            'model'       => $this->model,
            'max_tokens'  => $request->maxTokens ?? $this->maxTokens,
            'temperature' => $request->temperature,
            'messages'    => $messages,
        ];

        if ($request->responseSchema !== null) {
            if ($this->useJsonSchema) {
                // Full JSON schema enforcement — supported by most modern models
                $body['response_format'] = [
                    'type'        => 'json_schema',
                    'json_schema' => [
                        'name'   => $request->responseSchemaName,
                        'strict' => true,
                        'schema' => $request->responseSchema,
                    ],
                ];
            } else {
                // Fallback: json_object mode + schema description in prompt
                $body['response_format'] = ['type' => 'json_object'];
                $schemaJson = json_encode($request->responseSchema, JSON_PRETTY_PRINT);
                $userContent .= "\n\nRespond with valid JSON matching this schema exactly:\n{$schemaJson}";
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userContent];
        $body['messages'] = $messages;

        return $body;
    }

    private function parseResponse(array $payload, AiRequest $request): AiResponse
    {
        $rawContent = $payload['choices'][0]['message']['content'] ?? '';

        if ($request->responseSchema !== null) {
            $data = json_decode($rawContent, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                $this->log('warning', 'JSON parse failed. Raw: ' . substr($rawContent, 0, 200));
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
            $logger->$level('[DiveShop365\AI\OpenAiCompatibleProvider] ' . $message);
        } catch (\Throwable) {
        }
    }
}
