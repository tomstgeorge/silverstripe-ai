<?php

declare(strict_types=1);

namespace DiveShop365\AI\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Base class for AI providers.
 *
 * Handles Guzzle client creation and shared config. Concrete providers
 * extend this and implement complete() and getProviderName().
 */
abstract class AbstractAiProvider implements AiProviderInterface
{
    protected string $apiKey = '';

    protected string $model = '';

    protected int $maxTokens = 1024;

    protected ?ClientInterface $httpClient = null;

    /**
     * Injected via Injector properties in YAML.
     */
    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    public function setMaxTokens(int $maxTokens): void
    {
        $this->maxTokens = $maxTokens;
    }

    /**
     * Allow injecting a mock client in tests.
     */
    public function setHttpClient(ClientInterface $client): void
    {
        $this->httpClient = $client;
    }

    protected function getHttpClient(): ClientInterface
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client(['timeout' => 30]);
        }
        return $this->httpClient;
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }
}
