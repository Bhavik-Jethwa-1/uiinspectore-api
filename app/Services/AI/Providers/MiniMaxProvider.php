<?php

namespace App\Services\AI\Providers;

use App\Services\AI\MiniMaxService;

class MiniMaxProvider implements AIProvider
{
    private MiniMaxService $service;

    public function __construct()
    {
        $this->service = new MiniMaxService();
    }

    public function isAvailable(): bool
    {
        // Based on OpenClaw gateway token (chat/vision/image all route through gateway).
        $gatewayToken = env('OPENCLAW_GATEWAY_TOKEN', '');
        return !empty(trim($gatewayToken));
    }

    public function providerName(): string
    {
        return 'minimax';
    }

    public function providerLabel(): string
    {
        return 'MiniMax';
    }

    public function capabilities(): array
    {
        return ['chat', 'vision', 'image', 'code'];
    }

    public function chat(array $messages, array $opts = []): array
    {
        $result = $this->service->chat($messages, $opts);
        if (isset($result['error'])) {
            return ['error' => $result['error'], 'status' => $result['status'] ?? 500];
        }
        return [
            'reply'        => $result['reply'] ?? '',
            'model'       => $result['model'] ?? 'MiniMax-M3',
            'usage'       => $result['usage'] ?? [],
            'finish_reason' => $result['finish_reason'] ?? null,
        ];
    }

    public function streamChat(array $messages, array $opts = []): \Generator
    {
        yield from $this->service->streamChat($messages, $opts);
    }

    public function vision(string $imageUrl, string $prompt, array $opts = []): array
    {
        $result = $this->service->vision($imageUrl, $prompt, $opts);
        if (isset($result['error'])) {
            return ['error' => $result['error'], 'status' => $result['status'] ?? 500];
        }
        return [
            'reply' => $result['reply'] ?? '',
            'model' => $result['model'] ?? 'MiniMax-VL-01',
        ];
    }

    public function image(string $prompt, array $opts = []): array
    {
        $result = $this->service->image($prompt, $opts);
        if (isset($result['error'])) {
            return ['error' => $result['error'], 'status' => $result['status'] ?? 500];
        }
        return [
            'images' => $result['images'] ?? [],
            'model'  => $result['model'] ?? 'image-01',
            'size'   => $opts['size'] ?? '1:1',
        ];
    }

    public function code(array $messages, array $opts = []): array
    {
        // MiniMax routes code generation through the chat endpoint.
        // Use a code-optimized system prompt.
        $systemPrompt = $opts['system'] ?? (
            'You are an expert code generator. Output ONLY complete, working code — '
            . 'no explanations, no markdown fences unless explicitly asked. '
            . 'Generate ' . ($opts['language'] ?? 'react') . ' code.'
        );

        $codeMessages = $messages;
        array_unshift($codeMessages, ['role' => 'system', 'content' => $systemPrompt]);

        $result = $this->service->chat($codeMessages, [
            'model'       => $opts['model'] ?? null,
            'temperature' => 0.3,  // Low temp for deterministic code
            'max_tokens'  => $opts['max_tokens'] ?? 4000,
        ]);

        if (isset($result['error'])) {
            return ['error' => $result['error'], 'status' => $result['status'] ?? 500];
        }

        return [
            'reply'        => $result['reply'] ?? '',
            'model'       => $result['model'] ?? 'MiniMax-M3',
            'language'    => $opts['language'] ?? 'react',
            'finish_reason' => $result['finish_reason'] ?? null,
            'usage'       => $result['usage'] ?? [],
        ];
    }
}
