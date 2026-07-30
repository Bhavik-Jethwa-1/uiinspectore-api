<?php

namespace App\TOs\AI;

class ImageRequest
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $prompt,
        public readonly string $size = '1024x1024',
        public readonly string $quality = 'standard',
        public readonly ?int    $seed = null,
        public readonly ?int    $n = null,
        public readonly ?string $style = null,
        public readonly ?string $negativePrompt = null,
    ) {}

    public static function fromArray(array $data): self
    {
        [$w, $h] = explode('x', $data['size'] ?? '1024x1024');

        return new self(
            provider: $data['provider'] ?? 'openai',
            model: $data['model'] ?? '',
            prompt: $data['prompt'] ?? '',
            size: $data['size'] ?? '1024x1024',
            quality: $data['quality'] ?? 'standard',
            seed: isset($data['seed']) ? (int) $data['seed'] : null,
            n: isset($data['n']) ? (int) $data['n'] : null,
            style: $data['style'] ?? null,
            negativePrompt: $data['negative_prompt'] ?? null,
        );
    }
}
