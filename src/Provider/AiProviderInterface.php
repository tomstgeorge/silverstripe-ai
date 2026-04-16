<?php

declare(strict_types=1);

namespace DiveShop365\AI\Provider;

use DiveShop365\AI\Model\AiRequest;
use DiveShop365\AI\Model\AiResponse;

/**
 * Contract every AI provider must fulfil.
 *
 * Swap providers by changing the Injector binding in YAML — no
 * feature code changes required.
 *
 * To add a new provider:
 *   1. Create MyProvider extends AbstractAiProvider implements AiProviderInterface
 *   2. Implement complete() and getProviderName()
 *   3. Override in _config: Injector.AiProviderInterface.class: MyProvider
 */
interface AiProviderInterface
{
    /**
     * Send a completion request and return a normalised response.
     * Implementations must never throw — failures are returned as
     * AiResponse::failure().
     */
    public function complete(AiRequest $request): AiResponse;

    /**
     * Whether the provider is configured and ready to use.
     * Checks for API key presence etc. without making a network call.
     */
    public function isAvailable(): bool;

    /**
     * Human-readable name for logging and admin display.
     */
    public function getProviderName(): string;
}
