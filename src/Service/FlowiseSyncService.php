<?php

declare(strict_types=1);

namespace DiveShop365\AI\Service;

use GuzzleHttp\Client;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Syncs content to a Flowise vector store (Qdrant).
 *
 * Used by KnowledgeArticle directly and by FlowiseSyncExtension on any
 * versioned DataObject (Policy, Waiver, etc).
 *
 * Flowise endpoint:
 *   POST {FLOWISE_URL}/api/v1/vector/upsert/{chatflowId}
 *
 * The chatflow must contain a "Plain Text" document loader node so that
 * overrideConfig.text is picked up.
 *
 * Deletes use Qdrant's filter-delete endpoint directly, keyed on
 * metadata.docId (a stable string set per-record).
 *
 * Audience → chatflow mapping:
 *   customer → FLOWISE_CHATFLOW_CUSTOMER
 *   staff    → FLOWISE_CHATFLOW_DEFAULT
 *   cms      → FLOWISE_CHATFLOW_CMS
 *
 * ENV vars:
 *   FLOWISE_URL                 required
 *   FLOWISE_API_KEY             optional (if Flowise auth enabled)
 *   FLOWISE_CHATFLOW_CUSTOMER
 *   FLOWISE_CHATFLOW_DEFAULT
 *   FLOWISE_CHATFLOW_CMS
 *   QDRANT_URL                  optional — enables hard-delete on unpublish
 *   QDRANT_API_KEY              optional
 *   QDRANT_COLLECTION           defaults to "flowise"
 */
class FlowiseSyncService
{
    use Injectable;

    private const AUDIENCE_CHATFLOW_MAP = [
        'customer' => 'CUSTOMER',
        'staff'    => 'DEFAULT',
        'cms'      => 'CMS',
    ];

    /**
     * Upsert content into the Flowise vector store.
     *
     * @param  string $title      Article/document title (prepended to content)
     * @param  string $htmlContent Raw HTML — tags are stripped before embedding
     * @param  string $audience   'customer' | 'staff' | 'cms'
     * @param  string $docId      Stable unique ID for this record (used for later deletes)
     * @param  array  $extraMeta  Any additional metadata to store in Qdrant payload
     * @return string The docId that was used
     */
    public function upsert(
        string $title,
        string $htmlContent,
        string $audience,
        string $docId,
        array $extraMeta = []
    ): string {
        $flowiseUrl = $this->requireEnv('FLOWISE_URL');
        $chatflowId = $this->resolveChatflowId($audience);

        $plainText = $this->htmlToPlain($htmlContent);
        $fullText  = $title . "\n\n" . $plainText;

        $metadata = array_merge([
            'docId'    => $docId,
            'audience' => $audience,
            'title'    => $title,
        ], $extraMeta);

        $this->buildClient()->post(
            rtrim($flowiseUrl, '/') . '/api/v1/vector/upsert/' . $chatflowId,
            [
                'headers'   => $this->buildHeaders(),
                'multipart' => [
                    [
                        'name'     => 'files',
                        'contents' => $fullText,
                        'filename' => $docId . '.txt',
                        'headers'  => ['Content-Type' => 'text/plain'],
                    ],
                    [
                        'name'     => 'overrideConfig',
                        'contents' => \json_encode(['metadata' => $metadata]),
                    ],
                    [
                        'name'     => 'stopNodeId',
                        'contents' => 'qdrant_0',
                    ],
                ],
            ]
        );

        return $docId;
    }

    /**
     * Delete all Qdrant vectors for a given docId.
     *
     * No-ops silently if QDRANT_URL is not set (vectors are orphaned but
     * never returned because the chatflow filters by audience on query).
     */
    public function delete(string $docId): void
    {
        $qdrantUrl  = Environment::getEnv('QDRANT_URL');
        $collection = Environment::getEnv('QDRANT_COLLECTION') ?: 'flowise';

        if (!$qdrantUrl) {
            return;
        }

        $headers = ['Content-Type' => 'application/json'];
        $apiKey  = Environment::getEnv('QDRANT_API_KEY');
        if ($apiKey) {
            $headers['api-key'] = $apiKey;
        }

        (new Client(['timeout' => 30]))->post(
            rtrim($qdrantUrl, '/') . '/collections/' . $collection . '/points/delete',
            [
                'headers' => $headers,
                'json'    => [
                    'filter' => [
                        'must' => [[
                            'key'   => 'metadata.docId',
                            'match' => ['value' => $docId],
                        ]],
                    ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------

    private function resolveChatflowId(string $audience): string
    {
        $suffix     = self::AUDIENCE_CHATFLOW_MAP[$audience] ?? 'DEFAULT';
        $chatflowId = Environment::getEnv("FLOWISE_CHATFLOW_{$suffix}")
                   ?: Environment::getEnv('FLOWISE_CHATFLOW_DEFAULT');

        if (!$chatflowId) {
            throw new \RuntimeException(
                "No Flowise chatflow configured for audience '{$audience}'. "
                . "Set FLOWISE_CHATFLOW_{$suffix} in .env"
            );
        }

        return $chatflowId;
    }

    private function htmlToPlain(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }

    private function buildClient(): Client
    {
        return new Client(['timeout' => 60]);
    }

    private function buildHeaders(bool $json = false): array
    {
        $headers = $json ? ['Content-Type' => 'application/json'] : [];
        $apiKey  = Environment::getEnv('FLOWISE_API_KEY');
        if ($apiKey) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }
        return $headers;
    }

    private function requireEnv(string $key): string
    {
        $value = Environment::getEnv($key);
        if (!$value) {
            throw new \RuntimeException("Required env var '{$key}' is not set");
        }
        return $value;
    }
}
