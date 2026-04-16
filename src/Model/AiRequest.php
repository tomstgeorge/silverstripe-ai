<?php

declare(strict_types=1);

namespace DiveShop365\AI\Model;

/**
 * Value object representing a request to an AI provider.
 *
 * Features build this object and hand it to AiService — they never
 * interact with a provider directly.
 */
class AiRequest
{
    /**
     * System-level instructions for the model (role, tone, output rules).
     */
    public string $systemPrompt = '';

    /**
     * The user-facing prompt containing the actual task and content.
     */
    public string $userPrompt = '';

    /**
     * Optional JSON Schema describing the expected response structure.
     * When set, providers use structured output / tool-use to guarantee
     * the response matches the schema.
     *
     * @var array<string, mixed>|null
     */
    public ?array $responseSchema = null;

    /**
     * Name used when registering the response schema as a tool.
     * Providers use this as the tool/function name for structured output.
     */
    public string $responseSchemaName = 'output';

    /**
     * Maximum tokens the provider should generate in its response.
     */
    public int $maxTokens = 1024;

    /**
     * Sampling temperature — lower is more deterministic.
     * 0.0–1.0 for Claude, 0.0–2.0 for OpenAI.
     */
    public float $temperature = 0.3;

    public static function create(string $systemPrompt, string $userPrompt): self
    {
        $instance = new self();
        $instance->systemPrompt = $systemPrompt;
        $instance->userPrompt = $userPrompt;
        return $instance;
    }

    public function withSchema(array $schema, string $name = 'output'): self
    {
        $this->responseSchema = $schema;
        $this->responseSchemaName = $name;
        return $this;
    }

    public function withMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;
        return $this;
    }

    public function withTemperature(float $temperature): self
    {
        $this->temperature = $temperature;
        return $this;
    }
}
