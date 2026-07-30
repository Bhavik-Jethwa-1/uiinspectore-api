<?php

namespace App\DTOs\AI;

class ChatRequest
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly array  $messages,
        public readonly ?int    $maxTokens = null,
        public readonly ?float  $temperature = null,
        public readonly ?float  $topP = null,
        public readonly ?array  $stop = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $messages = $data['messages'] ?? [];
        if (isset($data['message']) && is_string($data['message'])) {
            $messages[] = ['role' => 'user', 'content' => $data['message']];
        }

        return new self(
            provider: $data['provider'] ?? 'openai',
            model: $data['model'] ?? '',
            messages: $messages,
            maxTokens: isset($data['max_tokens']) ? (int) $data['max_tokens'] : null,
            temperature: isset($data['temperature']) ? (float) $data['temperature'] : null,
            topP: isset($data['top_p']) ? (float) $data['top_p'] : null,
            stop: $data['stop'] ?? null,
        );
    }
}
