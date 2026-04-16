<?php

declare(strict_types=1);

namespace DiveShop365\AI\Admin;

use DiveShop365\AI\Model\KnowledgeArticle;
use DiveShop365\Site\Model\FAQItem;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridFieldConfig;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

/**
 * CMS admin panel for managing knowledge base articles.
 *
 * Articles are versioned — publish/unpublish buttons are available via
 * VersionedGridFieldDetailForm wired up in knowledge-base.yml.
 * Publishing an article automatically syncs it to Qdrant.
 * Unpublishing removes it from the vector store.
 */
class KnowledgeBaseAdmin extends ModelAdmin
{
    private static string $url_segment = 'knowledge-base';
    private static string $menu_title  = 'Knowledge Base';
    private static string $menu_icon_class = 'font-icon-book-open';
    private static int    $menu_priority   = -2;

    private static array $managed_models = [
        KnowledgeArticle::class,
        FAQItem::class,
    ];

    public function getGridFieldConfig(): GridFieldConfig
    {
        $config = parent::getGridFieldConfig();
        $config->addComponent(new GridFieldOrderableRows('SortOrder'));
        return $config;
    }
}
