<?php

declare(strict_types=1);

namespace DiveShop365\AI\Feature\Seo;

use DiveShop365\AI\Model\AiRequest;
use DiveShop365\AI\Service\AiService;
use SilverStripe\Core\Injector\Injectable;

/**
 * Generates SEO meta tag suggestions for a page.
 *
 * Strips raw HTML to meaningful text (headings, paragraphs, lists),
 * excludes chrome (nav, header, footer), then asks the model for
 * MetaTitle, MetaDescription and FocusKeyword.
 *
 * To add a new AI feature, follow this pattern:
 *   1. Create Feature/Whatever/WhateverService.php — builds the AiRequest
 *   2. Create Feature/Whatever/WhateverResult.php  — typed value object
 *   3. Wire into your controller / extension
 */
class SeoAiSuggestService
{
    use Injectable;

    /** HTML tags whose text content is extracted as page content. */
    private array $includedTags = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li'];

    /** HTML tags stripped out entirely before content extraction. */
    private array $excludedTags = ['nav', 'header', 'footer', 'script', 'style', 'noscript'];

    public function __construct(private AiService $ai)
    {
    }

    /**
     * Generate SEO suggestions from raw page HTML.
     *
     * @param string $pageHtml     Raw HTML of the page (from fetch or file_get_contents)
     * @param string $brandContext Brand context prompt from SiteConfig
     * @param string $currentTitle Existing page title (for context)
     */
    public function suggest(string $pageHtml, string $brandContext = '', string $currentTitle = ''): SeoSuggestions
    {
        $content = $this->extractContent($pageHtml);
        $request = $this->buildRequest($content, $brandContext, $currentTitle);
        $response = $this->ai->complete($request);

        if (!$response->success || $response->data === null) {
            return new SeoSuggestions('', '', '');
        }

        return SeoSuggestions::fromArray($response->data);
    }

    /**
     * Strip page HTML down to meaningful text content.
     */
    private function extractContent(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // Suppress HTML parse warnings for malformed markup
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR);
        $xpath = new \DOMXPath($dom);

        // Remove excluded elements entirely
        foreach ($this->excludedTags as $tag) {
            foreach (iterator_to_array($xpath->query("//{$tag}")) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Collect text from included elements
        $parts = [];
        foreach ($this->includedTags as $tag) {
            foreach ($xpath->query("//{$tag}") as $node) {
                $text = trim($node->textContent);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        // Deduplicate and join
        $parts = array_unique($parts);
        return implode(' ', $parts);
    }

    private function buildRequest(string $content, string $brandContext, string $currentTitle): AiRequest
    {
        $system = <<<SYSTEM
        You are an expert SEO specialist. Your task is to generate accurate, compelling meta tags
        for web pages. Always respect the character limits. Write in a natural, human tone that
        reflects the brand voice. Never fabricate features or claims not present in the content.
        SYSTEM;

        $contextBlock = $brandContext
            ? "Brand context:\n{$brandContext}\n\n"
            : '';

        $titleBlock = $currentTitle
            ? "Current page title: {$currentTitle}\n\n"
            : '';

        $user = <<<USER
        {$contextBlock}{$titleBlock}Generate SEO meta tags for the following page content:

        {$content}
        USER;

        return AiRequest::create(trim($system), trim($user))
            ->withSchema($this->responseSchema(), 'seo_tags')
            ->withMaxTokens(512)
            ->withTemperature(0.3);
    }

    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'metaTitle' => [
                    'type' => 'string',
                    'description' => 'Page meta title. Max 60 characters. Include the primary keyword near the start.',
                ],
                'metaDescription' => [
                    'type' => 'string',
                    'description' => 'Page meta description. Max 160 characters. Summarise the page value and include the keyword naturally.',
                ],
                'focusKeyword' => [
                    'type' => 'string',
                    'description' => 'Single primary focus keyword or short phrase (2–4 words) that best represents this page.',
                ],
            ],
            'required' => ['metaTitle', 'metaDescription', 'focusKeyword'],
        ];
    }
}
