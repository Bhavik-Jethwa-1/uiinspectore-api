<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIProvider implements AIProvider
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.openai.com/v1';

    public function __construct()
    {
        $this->apiKey = env('OPENAI_API_KEY', '');
        $this->model  = env('OPENAI_MODEL', 'gpt-4o');
    }

    public function isAvailable(): bool
    {
        return !empty(trim($this->apiKey));
    }

    public function providerName(): string
    {
        return 'openai';
    }

    public function providerLabel(): string
    {
        return 'OpenAI';
    }

    public function capabilities(): array
    {
        return ['chat', 'vision', 'image', 'code'];
    }

    public function chat(array $messages, array $opts = []): array
    {
        if (!$this->isAvailable()) {
            return ['error' => 'OpenAI is not configured. Please add an API key in Admin Settings.', 'status' => 503];
        }

        $model = $opts['model'] ?? $this->model;
        $temperature = $opts['temperature'] ?? 0.7;
        $maxTokens   = $opts['max_tokens'] ?? 2000;
        $topP        = $opts['top_p'] ?? null;
        $stop        = $opts['stop'] ?? null;

        $finalMessages = $messages;
        if (!empty($opts['system'])) {
            array_unshift($finalMessages, ['role' => 'system', 'content' => $opts['system']]);
        }

        $body = [
            'model'       => $model,
            'messages'    => $finalMessages,
            'temperature' => (float) $temperature,
            'max_tokens'  => (int) $maxTokens,
        ];
        if ($topP) $body['top_p'] = (float) $topP;
        if ($stop) $body['stop']  = $stop;

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post("{$this->baseUrl}/chat/completions", $body);

            if (!$response->successful()) {
                $err = $response->json();
                $msg = $err['error']['message'] ?? "HTTP {$response->status()}";
                Log::error('OpenAI chat error', ['status' => $response->status(), 'error' => $msg]);
                return ['error' => "OpenAI error: {$msg}", 'status' => $response->status()];
            }

            $data = $response->json();
            $choice = $data['choices'][0] ?? [];

            return [
                'reply'        => $choice['message']['content'] ?? '',
                'model'       => $data['model'] ?? $model,
                'finish_reason' => $choice['finish_reason'] ?? null,
                'usage'       => $data['usage'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('OpenAI chat exception', ['error' => $e->getMessage()]);
            return ['error' => 'OpenAI request failed: ' . $e->getMessage(), 'status' => 500];
        }
    }

    public function streamChat(array $messages, array $opts = []): \Generator
    {
        if (!$this->isAvailable()) {
            yield ['delta' => '', 'done' => true, 'error' => 'OpenAI is not configured.'];
            return;
        }

        $model = $opts['model'] ?? $this->model;
        $temperature = $opts['temperature'] ?? 0.7;
        $maxTokens   = $opts['max_tokens'] ?? 2000;
        $topP = $opts['top_p'] ?? null;
        $stop = $opts['stop'] ?? null;

        $finalMessages = $messages;
        if (!empty($opts['system'])) {
            array_unshift($finalMessages, ['role' => 'system', 'content' => $opts['system']]);
        }

        $body = [
            'model'       => $model,
            'messages'    => $finalMessages,
            'temperature' => (float) $temperature,
            'max_tokens'  => (int) $maxTokens,
            'stream'      => true,
        ];
        if ($topP) $body['top_p'] = (float) $topP;
        if ($stop) $body['stop']  = $stop;

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(120)
                ->withOptions(['stream' => true])
                ->post("{$this->baseUrl}/chat/completions", $body);

            $fullReply = '';
            $reader = $response->toPsrResponse()->getBody();

            while (!$reader->eof()) {
                $line = $reader->read(4096);
                if (str_starts_with(trim($line), 'data: ')) {
                    $json = trim(substr($line, 6));
                    if ($json === '[DONE]') break;
                    $data = json_decode($json, true);
                    if (isset($data['choices'][0]['delta']['content'])) {
                        $delta = $data['choices'][0]['delta']['content'];
                        $fullReply .= $delta;
                        yield ['delta' => $delta, 'done' => false];
                    }
                    if (isset($data['choices'][0]['finish_reason'])) {
                        yield ['delta' => '', 'done' => true, 'reply' => $fullReply, 'finish_reason' => $data['choices'][0]['finish_reason']];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('OpenAI stream exception', ['error' => $e->getMessage()]);
            yield ['delta' => '', 'done' => true, 'error' => 'OpenAI stream failed: ' . $e->getMessage()];
        }
    }

    public function vision(string $imageUrl, string $prompt, array $opts = []): array
    {
        if (!$this->isAvailable()) {
            return ['error' => 'OpenAI is not configured. Please add an API key in Admin Settings.', 'status' => 503];
        }

        $model = $opts['model'] ?? 'gpt-4o';
        $maxTokens = $opts['max_tokens'] ?? 2000;
        $resolvedUrl = $this->resolveImageUrl($imageUrl);

        $messages = [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $resolvedUrl]],
            ],
        ]];

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $model,
                    'messages'    => $messages,
                    'max_tokens'  => (int) $maxTokens,
                    'temperature' => 0.7,
                ]);

            if (!$response->successful()) {
                $err = $response->json();
                $msg = $err['error']['message'] ?? "HTTP {$response->status()}";
                return ['error' => "OpenAI vision error: {$msg}", 'status' => $response->status()];
            }

            $data = $response->json();
            return [
                'reply' => $data['choices'][0]['message']['content'] ?? '',
                'model' => $data['model'] ?? $model,
            ];
        } catch (\Throwable $e) {
            Log::error('OpenAI vision exception', ['error' => $e->getMessage()]);
            return ['error' => 'OpenAI vision failed: ' . $e->getMessage(), 'status' => 500];
        }
    }

    public function image(string $prompt, array $opts = []): array
    {
        if (!$this->isAvailable()) {
            return ['error' => 'OpenAI is not configured. Please add an API key in Admin Settings.', 'status' => 503];
        }

        $model = $opts['model'] ?? 'dall-e-3';
        $size  = $opts['size'] ?? 'square';
        $n     = min((int) ($opts['n'] ?? 1), 4);

        $sizeMap = [
            '512x512'    => '512x512',
            '1024x1024'  => '1024x1024',
            '1024x1792'  => '1024x1792',
            '1792x1024'  => '1792x1024',
            '1:1'        => '1024x1024',
            '9:16'       => '1024x1792',
            '16:9'       => '1792x1024',
            'square'     => '1024x1024',
            'landscape'  => '1792x1024',
            'portrait'   => '1024x1792',
            'hd'         => '1792x1024',
            '4:3'        => '1024x768',
            '3:4'        => '768x1024',
        ];
        $resolvedSize = $sizeMap[$size] ?? '1024x1024';

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(120)
                ->post("{$this->baseUrl}/images/generations", [
                    'model' => $model,
                    'prompt' => $prompt,
                    'n'      => $n,
                    'size'   => $resolvedSize,
                ]);

            if (!$response->successful()) {
                $err = $response->json();
                $msg = $err['error']['message'] ?? "HTTP {$response->status()}";
                return ['error' => "OpenAI image error: {$msg}", 'status' => $response->status()];
            }

            $data = $response->json();
            $urls = array_column($data['data'] ?? [], 'url');

            if (empty($urls)) {
                return ['error' => 'OpenAI returned no images', 'status' => 500];
            }

            return [
                'images' => $urls,
                'model'  => $model,
                'size'   => $resolvedSize,
            ];
        } catch (\Throwable $e) {
            Log::error('OpenAI image exception', ['error' => $e->getMessage()]);
            return ['error' => 'OpenAI image generation failed: ' . $e->getMessage(), 'status' => 500];
        }
    }

    public function code(array $messages, array $opts = []): array
    {
        if (!$this->isAvailable()) {
            return ['error' => 'OpenAI is not configured. Please add an API key in Admin Settings.', 'status' => 503];
        }

        $model = $opts['model'] ?? 'gpt-4o';
        $temperature = $opts['temperature'] ?? 0.3;
        $maxTokens   = $opts['max_tokens'] ?? 4000;

        $finalMessages = $messages;
        if (!empty($opts['system'])) {
            array_unshift($finalMessages, ['role' => 'system', 'content' => $opts['system']]);
        } else {
            array_unshift($finalMessages, [
                'role'    => 'system',
                'content' => 'You are an expert code generator. Output ONLY complete, working code — no explanations, no markdown fences unless asked. Focus on: ' . ($opts['language'] ?? 'react') . '.',
            ]);
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(90)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $model,
                    'messages'    => $finalMessages,
                    'temperature' => (float) $temperature,
                    'max_tokens'  => (int) $maxTokens,
                ]);

            if (!$response->successful()) {
                $err = $response->json();
                $msg = $err['error']['message'] ?? "HTTP {$response->status()}";
                return ['error' => "OpenAI code error: {$msg}", 'status' => $response->status()];
            }

            $data = $response->json();
            $choice = $data['choices'][0] ?? [];

            return [
                'reply'        => $choice['message']['content'] ?? '',
                'model'       => $data['model'] ?? $model,
                'language'    => $opts['language'] ?? 'react',
                'finish_reason' => $choice['finish_reason'] ?? null,
                'usage'       => $data['usage'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('OpenAI code exception', ['error' => $e->getMessage()]);
            return ['error' => 'OpenAI code generation failed: ' . $e->getMessage(), 'status' => 500];
        }
    }

    private function resolveImageUrl(string $url): string
    {
        if (str_starts_with($url, 'data:')) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return rtrim(config('app.url'), '/') . $url;
        }
        return $url;
    }
}
