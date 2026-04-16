<?php

declare(strict_types=1);

namespace DiveShop365\AI\Provider;

use DiveShop365\AI\Model\AiRequest;
use DiveShop365\AI\Model\AiResponse;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * Anthropic Claude provider.
 *
 * Uses tool_use with tool_choice={"type":"tool"} to force structured JSON
 * output when a responseSchema is provided. Falls back to a plain text
 * completion when no schema is set.
 *
 * Default config (override in YAML):
 *   model:     claude-sonnet-4-6
 *   maxTokens: 1024
 *
 * Required env var: AI_ANTHROPIC_API_KEY
 */
class ClaudeProvider extends AbstractAiProvider
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    protected string $model = 'claude-sonnet-4-6';

    protected int $maxTokens = 1024;

    public function getProviderName(): string
    {
        return 'Anthropic Claude';
    }

    public function complete(AiRequest $request): AiResponse
    {
        if (!$this->isAvailable()) {
            return AiResponse::failure('Claude provider is not configured — set AI_ANTHROPIC_API_KEY', $this->getProviderName());
        }

        try {
            $body = $this->buildRequestBody($request);
            $response = $this->getHttpClient()->post(self::API_URL, [
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version'  => self::API_VERSION,
                    'content-type'       => 'application/json',
                ],
                'json' => $body,
            ]);

            $payload = json_decode((string) $response->getBody(), true);
            return $this->parseResponse($payload, $request);

        } catch (GuzzleException $e) {
            $this->log('error', 'Claude API request failed: ' . $e->getMessage());
            return AiResponse::failure('API request failed: ' . $e->getMessage(), $this->getProviderName());
        } catch (\Throwable $e) {
            $this->log('error', 'Claude provider error: ' . $e->getMessage());
            return AiResponse::failure('Provider error: ' . $e->getMessage(), $this->getProviderName());
        }
    }

    /**
     * Build the Messages API request body.
     * Uses tool_use when a responseSchema is provided to force structured output.
     */
    private function buildRequestBody(AiRequest $request): array
    {
        $body = [
            'model'      => $this->model,
            'max_tokens' => $request->maxTokens ?? $this->maxTokens,
            'system'     => $request->systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $request->userPrompt],
            ],
        ];

        if ($request->responseSchema !== null) {
            // Force structured output via tool_use
            $body['tools'] = [
                [
                    'name'        => $request->responseSchemaName,
                    'description' => 'Return the structured response in this exact format.',
                    'input_schema' => $request->responseSchema,
                ],
            ];
            $body['tool_choice'] = [
                'type' => 'tool',
                'name' => $request->responseSchemaName,
            ];
        }

        return $body;
    }

    /**
     * Parse the Anthropic API response into a normalised AiResponse.
     */
    private function parseResponse(array $payload, AiRequest $request): AiResponse
    {
        $content = $payload['content'] ?? [];

        // Structured output: find the tool_use block
        if ($request->responseSchema !== null) {
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === $request->responseSchemaName) {
                    $data = $block['input'] ?? [];
                    return AiResponse::success(json_encode($data) ?: '', $data, $this->getProviderName());
                }
            }
            return AiResponse::failure('No tool_use block in Claude response', $this->getProviderName());
        }

        // Plain text completion
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                return AiResponse::success($block['text'] ?? '', null, $this->getProviderName());
            }
        }

        return AiResponse::failure('No text content in Claude response', $this->getProviderName());
    }

    private function log(string $level, string $message): void
    {
        try {
            /** @var LoggerInterface $logger */
            $logger = Injector::inst()->get(LoggerInterface::class . '.errorhandler');
            $logger->$level('[DiveShop365\AI\ClaudeProvider] ' . $message);
        } catch (\Throwable) {
            // Never let logging break the response path
        }
    }
}
