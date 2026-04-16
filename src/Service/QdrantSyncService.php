<?php

declare(strict_types=1);

namespace DiveShop365\AI\Service;

use GuzzleHttp\Client;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Syncs content to Qdrant directly — bypasses the Flowise upsert API entirely.
 *
 * Flow per upsert:
 *   1. Strip HTML → plain text → split into overlapping chunks
 *   2. Embed each chunk via Google Gemini (gemini-embedding-001, 3072 dims)
 *   3. Delete all existing Qdrant points where payload.docId == $docId
 *   4. Upsert new points with full payload
 *
 * Flowise retrieval continues to work — it reads from the same `chat-bot`
 * collection using the same embedding model. Points are stored with `pageContent`
 * as the text field, which is what the Flowise Qdrant node expects by default.
 *
 * ENV vars:
 *   GOOGLE_AI_API_KEY   required — Google AI Studio key (same one used by Flowise)
 *   QDRANT_URL          required
 *   QDRANT_API_KEY      required
 *   QDRANT_COLLECTION   required — e.g. "chat-bot"
 *
 * One-time Qdrant setup (run once via BuildTask or curl before first use):
 *   PUT {QDRANT_URL}/collections/{collection}/index
 *   { "field_name": "docId", "field_schema": "keyword" }
 */
class QdrantSyncService
{
    use Injectable;

    private const EMBED_MODEL   = 'gemini-embedding-001';
    private const CHUNK_SIZE    = 1000;
    private const CHUNK_OVERLAP = 200;

    /**
     * Chunk, embed, and upsert content into Qdrant.
     *
     * Deletes all existing vectors for $docId first, then inserts fresh ones.
     * Embedding is performed before deleting so that a failed embed leaves
     * the existing vectors intact.
     *
     * @param  string $title       Article/document title (prepended to content for context)
     * @param  string $htmlContent Raw HTML — tags stripped before chunking
     * @param  string $audience    'customer' | 'staff' | 'cms'
     * @param  string $docId       Stable unique ID for this record (used for deletes)
     * @param  array  $extraMeta   Additional payload fields merged into each point
     * @return string The $docId used
     */
    public function upsert(
        string $title,
        string $htmlContent,
        string $audience,
        string $docId,
        array $extraMeta = []
    ): string {
        $plainText = $this->htmlToPlain($htmlContent);
        $fullText  = $title . "\n\n" . $plainText;
        $chunks    = $this->chunk($fullText);

        // Embed all chunks before touching Qdrant — any embed failure here
        // leaves the existing vectors untouched.
        $vectors = [];
        foreach ($chunks as $chunk) {
            $vectors[] = $this->embed($chunk, $title);
        }

        // Delete existing points for this docId (safe to run even if empty)
        $this->delete($docId);

        // Build and upsert new points
        $points = [];
        foreach ($chunks as $i => $chunk) {
            $points[] = [
                'id'      => $this->generateUuid(),
                'vector'  => $vectors[$i],
                'payload' => array_merge([
                    'content' => $chunk,   // field name Flowise reads from Qdrant payload
                    'docId'   => $docId,
                    'audience'    => $audience,
                    'title'       => $title,
                ], $extraMeta),
            ];
        }

        $qdrantUrl  = $this->requireEnv('QDRANT_URL');
        $collection = $this->requireEnv('QDRANT_COLLECTION');

        $this->qdrantClient()->put(
            rtrim($qdrantUrl, '/') . '/collections/' . $collection . '/points?wait=true',
            [
                'headers' => $this->qdrantHeaders(),
                'json'    => ['points' => $points],
            ]
        );

        return $docId;
    }

    /**
     * Delete all Qdrant points where payload.docId matches $docId.
     *
     * No-ops silently if QDRANT_URL is not configured.
     */
    public function delete(string $docId): void
    {
        $qdrantUrl  = Environment::getEnv('QDRANT_URL');
        $collection = Environment::getEnv('QDRANT_COLLECTION') ?: 'chat-bot';

        if (!$qdrantUrl) {
            return;
        }

        $this->qdrantClient()->post(
            rtrim($qdrantUrl, '/') . '/collections/' . $collection . '/points/delete?wait=true',
            [
                'headers' => $this->qdrantHeaders(),
                'json'    => [
                    'filter' => [
                        'must' => [[
                            'key'   => 'docId',
                            'match' => ['value' => $docId],
                        ]],
                    ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Split text into overlapping character-based chunks.
     *
     * @return string[]
     */
    /** Override in tests to inject a mock client. */
    protected function makeHttpClient(int $timeout = 30): Client
    {
        return new Client(['timeout' => $timeout]);
    }

    private function chunk(string $text): array
    {
        $size    = self::CHUNK_SIZE;
        $overlap = self::CHUNK_OVERLAP;
        $len     = mb_strlen($text);
        $chunks  = [];
        $start   = 0;

        while ($start < $len) {
            $chunk = mb_substr($text, $start, $size);
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
            $start += ($size - $overlap);
        }

        return $chunks ?: [$text];
    }

    /**
     * Call the Gemini embedding API for a single text chunk.
     *
     * @return float[] 3072-dimensional embedding vector
     */
    private function embed(string $text, string $title = ''): array
    {
        $apiKey = $this->requireEnv('GOOGLE_AI_STUDIO_KEY');
        $url    = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent?key=%s',
            self::EMBED_MODEL,
            $apiKey
        );

        $body = [
            'model'    => 'models/' . self::EMBED_MODEL,
            'content'  => ['parts' => [['text' => $text]]],
            'taskType' => 'RETRIEVAL_DOCUMENT',
        ];

        // Gemini allows an optional title hint for RETRIEVAL_DOCUMENT
        if ($title !== '') {
            $body['title'] = $title;
        }

        $response = $this->makeHttpClient()->post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => $body,
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['embedding']['values']) || !is_array($data['embedding']['values'])) {
            throw new \RuntimeException(
                'Gemini embedding API returned unexpected response: ' . (string) $response->getBody()
            );
        }

        return $data['embedding']['values'];
    }

    private function htmlToPlain(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }

    private function qdrantClient(): Client
    {
        return $this->makeHttpClient();
    }

    private function qdrantHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        $apiKey  = Environment::getEnv('QDRANT_API_KEY');
        if ($apiKey) {
            $headers['api-key'] = $apiKey;
        }
        return $headers;
    }

    /** Generate a UUID v4. */
    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant 10xx
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function requireEnv(string $key): string
    {
        $value = Environment::getEnv($key);
        if (!$value) {
            throw new \RuntimeException("Required env var '{$key}' is not set");
        }
        return (string) $value;
    }
}
