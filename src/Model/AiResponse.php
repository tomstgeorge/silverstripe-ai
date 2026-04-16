<?php

declare(strict_types=1);

namespace DiveShop365\AI\Model;

/**
 * Value object representing a normalised response from any AI provider.
 *
 * Regardless of which provider produced the response, callers always
 * receive the same structure. Structured output is available via $data;
 * raw text is always in $rawContent for debugging.
 */
class AiResponse
{
    /**
     * Whether the request completed without error.
     */
    public bool $success = false;

    /**
     * The raw text content returned by the model.
     */
    public string $rawContent = '';

    /**
     * Parsed structured data when a responseSchema was provided.
     * Null if no schema was requested or parsing failed.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = null;

    /**
     * Human-readable error message on failure.
     */
    public ?string $error = null;

    /**
     * Name of the provider that produced this response (for logging/debugging).
     */
    public string $provider = '';

    public static function success(string $rawContent, ?array $data = null, string $provider = ''): self
    {
        $instance = new self();
        $instance->success = true;
        $instance->rawContent = $rawContent;
        $instance->data = $data;
        $instance->provider = $provider;
        return $instance;
    }

    public static function failure(string $error, string $provider = ''): self
    {
        $instance = new self();
        $instance->success = false;
        $instance->error = $error;
        $instance->provider = $provider;
        return $instance;
    }
}
