<?php

declare(strict_types=1);

namespace DiveShop365\AI\Service;

use DiveShop365\AI\Enum\ChatAudience;

/**
 * Builds Flowise prediction request payloads with a mandatory audience filter.
 *
 * The audience is required at construction time — it is impossible to build a
 * query payload without declaring which channel is being served. This prevents
 * accidentally exposing staff-only or CMS-only content to public visitors.
 *
 * Usage:
 *   $service = new FlowiseQueryService(ChatAudience::Customer);
 *   $payload = $service->buildPayload($question, $sessionId);
 *
 * The qdrantFilter in overrideConfig ensures Flowise only retrieves vectors
 * whose payload.audience matches the declared channel.
 */
class FlowiseQueryService
{
    public function __construct(private readonly ChatAudience $audience) {}

    /**
     * Build a Flowise prediction request payload.
     *
     * The audience filter is always present — there is no code path that
     * produces a payload without it.
     */
    public function buildPayload(string $question, string $sessionId): array
    {
        return [
            'question'       => $question,
            'sessionId'      => $sessionId,
            'overrideConfig' => [
                'qdrantFilter' => [
                    'must' => [[
                        'key'   => 'audience',
                        'match' => ['value' => $this->audience->value],
                    ]],
                ],
            ],
        ];
    }

    public function getAudience(): ChatAudience
    {
        return $this->audience;
    }
}
