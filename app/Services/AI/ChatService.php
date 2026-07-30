<?php

namespace App\Services\AI;

use App\Services\AI\Providers\ProviderManager;

/**
 * ChatService — dedicated service for all chat completions.
 *
 * Routes through ProviderManager so the primary provider handles chat.
 * Supports streaming and all standard chat options.
 */
class ChatService
{
    private \App\Services\AI\Providers\ProviderManager $manager;

    public function __construct(?\App\Services\AI\Providers\ProviderManager $manager = null)
    {
        $this->manager = $manager ?? new \App\Services\AI\Providers\ProviderManager();
    }

    public function complete(array $messages, array $opts = []): array
    {
        $result = $this->manager->chat($messages, $opts);
        $result['capability'] = 'chat';
        return $result;
    }

    public function stream(array $messages, array $opts = []): \Generator
    {
        $providerName = $this->manager->primaryName();

        foreach ($this->manager->streamChat($messages, $opts) as $chunk) {
            $chunk['capability'] = 'chat';
            $chunk['provider'] = $chunk['provider'] ?? $providerName;
            yield $chunk;
        }
    }

    public function isAvailable(): bool
    {
        foreach ($this->manager->listProviders() as $info) {
            if ($info['available'] && in_array('chat', $info['capabilities'] ?? [], true)) {
                return true;
            }
        }
        return false;
    }
}
