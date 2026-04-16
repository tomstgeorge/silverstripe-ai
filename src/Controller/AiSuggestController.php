<?php

declare(strict_types=1);

namespace DiveShop365\AI\Controller;

use DiveShop365\AI\Feature\Seo\SeoAiSuggestService;
use DiveShop365\AI\Service\AiService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Permission;
use SilverStripe\Security\SecurityToken;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Secure JSON endpoint consumed by the AI tab React component.
 *
 * Routes (registered in ai.yml):
 *   POST ai-suggest/seo  — generate SEO suggestions for a page
 *
 * All actions require CMS_ACCESS_CMSMain permission and a valid
 * SilverStripe SecurityToken (CSRF protection).
 */
class AiSuggestController extends Controller
{
    private static array $allowed_actions = [
        'seo',
    ];

    private static array $url_handlers = [
        'seo' => 'seo',
    ];

    /**
     * POST ai-suggest/seo
     *
     * Body (JSON):
     *   pageId        int     Page ID to generate suggestions for
     *   pageHtml      string  Raw page HTML (optional — fetched server-side if omitted)
     *   currentTitle  string  Current page title (optional context)
     *   SecurityID    string  CSRF token
     *   SecurityToken string  CSRF token name
     */
    public function seo(HTTPRequest $request): HTTPResponse
    {
        $response = $this->getResponse();
        $response->addHeader('Content-Type', 'application/json');

        if (!$request->isPOST()) {
            return $response->setStatusCode(405)->setBody(json_encode(['error' => 'Method not allowed']));
        }

        if (!Permission::check('CMS_ACCESS_CMSMain')) {
            return $response->setStatusCode(403)->setBody(json_encode(['error' => 'Forbidden']));
        }

        $body = json_decode($request->getBody(), true) ?? [];

        // CSRF check
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $response->setStatusCode(400)->setBody(json_encode(['error' => 'Invalid security token']));
        }

        $pageId = (int) ($body['pageId'] ?? 0);
        $pageHtml = $body['pageHtml'] ?? '';
        $currentTitle = trim($body['currentTitle'] ?? '');

        // Resolve page for title context even if HTML was passed
        $page = $pageId ? SiteTree::get()->byID($pageId) : null;
        if ($currentTitle === '' && $page) {
            $currentTitle = (string) $page->Title;
        }

        // Fetch HTML server-side if the client didn't send it
        if (empty($pageHtml) && $page) {
            $pageHtml = $this->fetchPageHtml($page);
        }

        if (empty($pageHtml)) {
            return $response->setStatusCode(422)->setBody(json_encode(['error' => 'No page content available']));
        }

        $aiService = AiService::create();
        if (!$aiService->isAvailable()) {
            return $response->setStatusCode(503)->setBody(json_encode([
                'error' => 'AI provider is not configured. Set AI_ANTHROPIC_API_KEY in your .env file.',
            ]));
        }

        $brandContext = (string) SiteConfig::current_site_config()->AiContextPrompt;
        $seoService   = SeoAiSuggestService::create();
        $suggestions  = $seoService->suggest($pageHtml, $brandContext, $currentTitle);

        return $response->setStatusCode(200)->setBody(json_encode($suggestions->toArray()));
    }

    /**
     * Fetch page HTML from the draft stage URL.
     * Uses draft so suggestions work before publishing (unlike PlasticStudio's approach).
     */
    private function fetchPageHtml(SiteTree $page): string
    {
        try {
            $url = $page->AbsoluteLink() . '?stage=Stage';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'ignore_errors' => true,
                    'header' => "Cookie: " . ($_SERVER['HTTP_COOKIE'] ?? '') . "\r\n",
                ],
            ]);
            $html = @file_get_contents($url, false, $context);
            return $html ?: '';
        } catch (\Throwable) {
            return '';
        }
    }
}
