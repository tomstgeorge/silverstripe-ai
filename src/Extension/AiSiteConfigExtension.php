<?php

declare(strict_types=1);

namespace DiveShop365\AI\Extension;

use DiveShop365\AI\Service\AiService;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TextareaField;

/**
 * Adds AI configuration fields to SiteConfig.
 *
 * AiContextPrompt — brand context fed into every AI request.
 *   The more specific this is, the better the suggestions.
 *
 * Apply via YAML:
 *   SilverStripe\SiteConfig\SiteConfig:
 *     extensions:
 *       - DiveShop365\AI\Extension\AiSiteConfigExtension
 */
class AiSiteConfigExtension extends Extension
{
    private static array $db = [
        'AiContextPrompt' => 'Text',
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->findOrMakeTab('Root.AI', 'AI Settings');

        $aiService = AiService::create();
        $statusHtml = $aiService->isAvailable()
            ? '<p class="alert alert-success" style="margin:0 0 1rem">Provider active: <strong>' . htmlspecialchars($aiService->getProviderName()) . '</strong></p>'
            : '<p class="alert alert-warning" style="margin:0 0 1rem">No AI provider configured. Set <code>AI_ANTHROPIC_API_KEY</code> in your <code>.env</code> file.</p>';

        $fields->addFieldsToTab('Root.AI', [
            LiteralField::create('AiProviderStatus', $statusHtml),
            HeaderField::create('AiContextHeader', 'Brand Context'),
            TextareaField::create('AiContextPrompt', 'Brand Context Prompt')
                ->setRows(6)
                ->setRightTitle(
                    'Describe your brand, tone of voice, target audience, and any specific SEO goals. '
                    . 'This context is included in every AI request to produce more accurate and on-brand suggestions.'
                ),
        ]);
    }
}
