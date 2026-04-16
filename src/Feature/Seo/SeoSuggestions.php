<?php

declare(strict_types=1);

namespace DiveShop365\AI\Feature\Seo;

/**
 * Typed result from the SEO suggestion feature.
 *
 * Immutable value object — callers read properties, never write them.
 */
class SeoSuggestions
{
    public function __construct(
        public readonly string $metaTitle,
        public readonly string $metaDescription,
        public readonly string $focusKeyword,
    ) {
    }

    /**
     * Build from the structured data array returned by AiResponse.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            metaTitle:       mb_substr(trim($data['metaTitle'] ?? ''), 0, 60),
            metaDescription: mb_substr(trim($data['metaDescription'] ?? ''), 0, 160),
            focusKeyword:    trim($data['focusKeyword'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'metaTitle'       => $this->metaTitle,
            'metaDescription' => $this->metaDescription,
            'focusKeyword'    => $this->focusKeyword,
        ];
    }
}
