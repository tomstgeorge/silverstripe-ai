<?php

declare(strict_types=1);

namespace DiveShop365\AI\Service;

use DiveShop365\AI\Model\AiRequest;
use DiveShop365\AI\Model\AiResponse;
use DiveShop365\AI\Provider\AiProviderInterface;
use SilverStripe\Core\Injector\Injectable;

/**
 * Thin orchestrator — the only class feature services depend on.
 *
 * Gets the active provider from SilverStripe's Injector, meaning
 * the provider is swapped purely via YAML with zero code changes.
 *
 * Usage:
 *   $response = $this->aiService->complete($request);
 *   if ($response->success) { ... $response->data ... }
 */
class AiService
{
    use Injectable;

    public function __construct(private AiProviderInterface $provider)
    {
    }

    public function complete(AiRequest $request): AiResponse
    {
        return $this->provider->complete($request);
    }

    public function isAvailable(): bool
    {
        return $this->provider->isAvailable();
    }

    public function getProviderName(): string
    {
        return $this->provider->getProviderName();
    }
}
