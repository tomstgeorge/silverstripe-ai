<?php

declare(strict_types=1);

namespace DiveShop365\AI\Model;

use DiveShop365\AI\Admin\KnowledgeBaseAdmin;
use DiveShop365\AI\Service\QdrantSyncService;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

/**
 * A versioned CMS-managed knowledge article that syncs to Flowise/Qdrant on publish.
 *
 * Audience is a comma-separated list — an article can be pushed to multiple chatbots:
 *   customer → FLOWISE_CHATFLOW_CUSTOMER  (public website bot)
 *   staff    → FLOWISE_CHATFLOW_DEFAULT   (Flutter staff app)
 *   cms      → FLOWISE_CHATFLOW_CMS       (CMS admin bot)
 */
class KnowledgeArticle extends DataObject
{
    private static string $table_name = 'KnowledgeArticle';

    private static string $singular_name = 'Knowledge Article';
    private static string $plural_name   = 'Knowledge Articles';

    private static array $db = [
        'Title'             => 'Varchar(255)',
        'Summary'           => 'Text',
        'Content'           => 'HTMLText',
        'Audience'          => 'Varchar(50)',   // comma-separated: customer,staff,cms
        'SortOrder'         => 'Int',
        'FlowiseDocumentID' => 'Varchar(100)',  // base doc ID — per-audience IDs derived from this
        'LastSyncedAt'      => 'Datetime',
        'SyncStatus'        => "Enum('pending,synced,error', 'pending')",
        'SyncError'         => 'Text',
    ];

    private static array $extensions = [
        Versioned::class,
    ];

    private static array $summary_fields = [
        'Title'        => 'Title',
        'Audience'     => 'Audience',
        'SyncStatus'   => 'Sync',
        'LastSyncedAt' => 'Last Synced',
    ];

    private static array $searchable_fields = [
        'Title',
        'SyncStatus',
    ];

    private static string $default_sort = 'SortOrder ASC, Title ASC';

    public function getCMSFields(): FieldList
    {
        $fields = FieldList::create(
            TextField::create('Title'),
            CheckboxSetField::create('Audience', 'Publish to', [
                'customer' => 'Customer (public website chatbot)',
                'staff'    => 'Staff (Flutter app chatbot)',
                'cms'      => 'CMS (admin chatbot)',
            ]),
            TextareaField::create('Summary', 'Summary (optional — shown in admin list)'),
            $content = \SilverStripe\Forms\HTMLEditor\HTMLEditorField::create('Content'),
        );

        $content->setRows(20);

        if ($this->FlowiseDocumentID) {
            $fields->push(ReadonlyField::create('LastSyncedAt', 'Last Synced'));
            $fields->push(ReadonlyField::create('SyncStatus', 'Sync Status'));
        }

        if ($this->SyncError) {
            $fields->push(ReadonlyField::create('SyncError', 'Last Sync Error'));
        }

        return $fields;
    }

    public function onAfterPublish(): void
    {
        $this->syncToFlowise();
    }

    public function onAfterPublishRecursive(): void
    {
        $this->syncToFlowise();
    }

    public function onAfterUnpublish(): void
    {
        $this->removeFromFlowise();
    }

    protected function onBeforeDelete(): void
    {
        parent::onBeforeDelete();
        $this->removeFromFlowise();
    }

    // -------------------------------------------------------------------------

    /** Returns the audiences selected for this article as an array. */
    public function getAudiences(): array
    {
        $raw = trim((string) $this->getField('Audience'));
        if (!$raw) {
            return [];
        }
        // CheckboxSetField in SS6 may store as JSON array or comma-separated
        if ($raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map('trim', $decoded)));
            }
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Stable per-audience document ID — used as Qdrant metadata so vectors
     * can be deleted by audience without affecting other articles.
     */
    public function getStableDocumentId(string $audience = ''): string
    {
        $base = 'knowledge-article-' . $this->ID;
        return $audience ? "{$base}-{$audience}" : $base;
    }

    // -------------------------------------------------------------------------
    // Permissions — anyone with access to KnowledgeBaseAdmin can manage articles

    public function canView($member = null): bool
    {
        return Permission::check('CMS_ACCESS_' . KnowledgeBaseAdmin::class, 'any', $member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('CMS_ACCESS_' . KnowledgeBaseAdmin::class, 'any', $member);
    }

    public function canEdit($member = null): bool
    {
        return Permission::check('CMS_ACCESS_' . KnowledgeBaseAdmin::class, 'any', $member);
    }

    public function canDelete($member = null): bool
    {
        return Permission::check('CMS_ACCESS_' . KnowledgeBaseAdmin::class, 'any', $member);
    }

    // -------------------------------------------------------------------------

    private function syncToFlowise(): void
    {
        $audiences = $this->getAudiences();
        if (empty($audiences)) {
            return;
        }

        $service = QdrantSyncService::create();
        $errors  = [];

        foreach ($audiences as $audience) {
            try {
                $service->upsert(
                    (string) $this->Title,
                    (string) $this->Content,
                    $audience,
                    $this->getStableDocumentId($audience),
                    ['articleId' => $this->ID, 'audience' => $audience]
                );
            } catch (\Throwable $e) {
                $errors[] = "{$audience}: " . $e->getMessage();
            }
        }

        $baseId = $this->getStableDocumentId();
        $status = empty($errors) ? 'synced' : 'error';
        $error  = implode('; ', $errors);

        foreach (['KnowledgeArticle', 'KnowledgeArticle_Live'] as $table) {
            \SilverStripe\ORM\DB::prepared_query(
                "UPDATE \"{$table}\" SET \"FlowiseDocumentID\" = ?, \"LastSyncedAt\" = ?, \"SyncStatus\" = ?, \"SyncError\" = ? WHERE \"ID\" = ?",
                [$baseId, date('Y-m-d H:i:s'), $status, $error, $this->ID]
            );
        }
    }

    private function removeFromFlowise(): void
    {
        $service = QdrantSyncService::create();

        // Delete vectors for every possible audience — safe if they don't exist
        foreach (['customer', 'staff', 'cms'] as $audience) {
            try {
                $service->delete($this->getStableDocumentId($audience));
            } catch (\Throwable) {
                // best-effort
            }
        }

        foreach (['KnowledgeArticle', 'KnowledgeArticle_Live'] as $table) {
            \SilverStripe\ORM\DB::prepared_query(
                "UPDATE \"{$table}\" SET \"FlowiseDocumentID\" = '', \"SyncStatus\" = 'pending', \"LastSyncedAt\" = NULL WHERE \"ID\" = ?",
                [$this->ID]
            );
        }
    }
}
