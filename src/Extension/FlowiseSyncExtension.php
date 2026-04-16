<?php

declare(strict_types=1);

namespace DiveShop365\AI\Extension;

use DiveShop365\AI\Service\QdrantSyncService;
use SilverStripe\Core\Extension;

/**
 * Drop this onto any versioned DataObject to auto-sync it to Flowise/Qdrant
 * whenever it is published or unpublished.
 *
 * Configure per-model in YAML:
 *
 *   DiveShop365\Legal\Model\Policy:
 *     extensions:
 *       - DiveShop365\AI\Extension\FlowiseSyncExtension
 *     flowise_title_field:   Title   # DB field to use as the document title
 *     flowise_content_field: Body    # DB field containing the HTML content
 *     flowise_audience:      staff   # 'customer' | 'staff' | 'cms'
 *
 * The extension adds three DB columns to the model's table:
 *   FlowiseDocumentID  — stable docId stored after successful upsert
 *   FlowiseSyncStatus  — 'pending' | 'synced' | 'error'
 *   FlowiseSyncError   — last error message (if any)
 */
class FlowiseSyncExtension extends Extension
{
    private static array $db = [
        'FlowiseDocumentID' => 'Varchar(100)',
        'FlowiseSyncStatus' => "Enum('pending,synced,error','pending')",
        'FlowiseSyncError'  => 'Text',
    ];

    /** Called by Versioned when the record is published. */
    public function onAfterPublish(): void
    {
        $this->sync();
    }

    /** Called by RecursivePublishable — fired when published via the CMS GridField. */
    public function onAfterPublishRecursive(): void
    {
        $this->sync();
    }

    private function sync(): void
    {
        $owner        = $this->getOwner();
        $titleField   = (string) ($owner->config()->get('flowise_title_field')   ?? 'Title');
        $contentField = (string) ($owner->config()->get('flowise_content_field') ?? 'Content');
        $audience     = (string) ($owner->config()->get('flowise_audience')      ?? 'staff');
        $docId        = 'flowise-' . $owner->sanitiseClassName($owner->ClassName) . '-' . $owner->ID;
        $baseTable    = $owner->baseTable();

        $success = false;
        $error   = null;

        try {
            QdrantSyncService::create()->upsert(
                (string) $owner->$titleField,
                (string) $owner->$contentField,
                $audience,
                $docId,
                ['sourceClass' => $owner->ClassName, 'sourceId' => $owner->ID]
            );
            $success = true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        foreach ([$baseTable, $baseTable . '_Live'] as $table) {
            if ($success) {
                \SilverStripe\ORM\DB::prepared_query(
                    "UPDATE \"{$table}\" SET \"FlowiseDocumentID\" = ?, \"FlowiseSyncStatus\" = ?, \"FlowiseSyncError\" = '' WHERE \"ID\" = ?",
                    [$docId, 'synced', $owner->ID]
                );
            } else {
                \SilverStripe\ORM\DB::prepared_query(
                    "UPDATE \"{$table}\" SET \"FlowiseSyncStatus\" = ?, \"FlowiseSyncError\" = ? WHERE \"ID\" = ?",
                    ['error', (string) $error, $owner->ID]
                );
            }
        }
    }

    /** Called by Versioned when the record is unpublished. */
    public function onAfterUnpublish(): void
    {
        $owner = $this->getOwner();

        if (!$owner->FlowiseDocumentID) {
            return;
        }

        try {
            QdrantSyncService::create()->delete((string) $owner->FlowiseDocumentID);

            $owner->FlowiseDocumentID = '';
            $owner->FlowiseSyncStatus = 'pending';
            $owner->FlowiseSyncError  = '';
        } catch (\Throwable $e) {
            $owner->FlowiseSyncStatus = 'error';
            $owner->FlowiseSyncError  = 'Delete failed: ' . $e->getMessage();
        }

        $owner->writeWithoutVersion();
    }

    public function onBeforeDelete(): void
    {
        $owner = $this->getOwner();

        if ($owner->FlowiseDocumentID) {
            try {
                QdrantSyncService::create()->delete((string) $owner->FlowiseDocumentID);
            } catch (\Throwable) {
                // best-effort — don't block deletion
            }
        }
    }
}
