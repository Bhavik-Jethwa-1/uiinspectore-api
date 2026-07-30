<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiProvider implements AIProvider
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY', '');
        $this->model  = env('GEMINI_MODEL', 'gemini-2.0-flash');
    }

    public function isAvailable(): bool
    {
        return !empty(trim($this->apiKey));
    }

    public function providerName(): string
    {
        return 'gemini';
    }

    public function providerLabel(): string
    {
        return 'Google Gemini';
    }

    public function capabilities(): array
    {
        return ['chat', 'vision', 'code'];
    }

    private function buildUrl(string $model, bool $stream = false): string
    {
        $url = "{$this->baseUrl}/{$model}:generateContent";
        $params = ['key' => $this->apiKey];
        if ($stream) {
            $params['alt'] = 'sse';
        }
        return $url . '?' . http_build_query($params);
    }

    private function buildContents(array $messages): array
    {
        $contents = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] === 'assistant' ? 'model' : 'user';
            $content = $msg['content'] ?? ($msg['text'] ?? '');
            if (is_array($content)) {
                // Vision: mixed text + image parts
                $parts = [];
                foreach ($content as $part) {
                    if (isset($part['type']) && $part['type'] === 'image_url') {
                        $url = $part['image_url']['url'] ?? $part['url'] ?? '';
                        if (str_starts_with($url, 'data:')) {
                            $mime = explode(';', $url)[0];
                            $base64 = explode(',', $url)[1];
                            $parts[] = ['inlineData' => ['mimeType' => substr($mime, 5), 'data' => $base64]];
                        } else {
                            // URL image — use inlineData with public URL
                            $parts[] = ['fileData' => ['mimeType' => 'image/jpeg', 'fileUri' => $url]];
                        }
                    } elseif (isset($part['type']) && $part['type'] === 'text') {
                        $parts[] = ['text' => $part['text']];
                    } elseif (isset($part['text'])) {
                        $parts[] = ['text' => $part['text']];
                    }
                }
                $contents[] = ['role' => $role, 'parts' => $parts ?: [['text' => '']]];
            } else {
                $contents[] = ['role' => $role, 'parts' => [['text' => $content]]];
            }
        }
        return $contents;
    }

    public function chat(array $messages, array $opts = []): array
    {
        if (!$this->isAvailable()) {
            return ['error' => 'Gemini is not configured. Please add an API key in Admin Settings.', 'status' => 503];
        }

        $model = $opts['model'] ?? $this->model;
        $temperature = $opts['temperature'] ?? 0.7;
        $maxTokens   = $opts['max_tokens'] ?? 2000;

        $contents = $this->buildContents($messages);

        $body = [
            'contents'           => $contents,
            'generationConfig'  => [
                'temperature'  => (float) $temperature,
                'maxOutputTokens' => (int) $maxTokens,
                'topP'        => isset($opts['top_p']) ? (float) $opts['top_p'] : 0.95,
            ],
        ];

        try {
            $response = Http::timeout(60)
                ->post($this->buildUrl($model), $body);

            if (!$response->successful()) {
                $err = $response->json();
                $msg = $err['error']['message'] ?? "HTTP {$response->status()}";
                Log::error('Gemini chat error', ['status' => $response->status(), 'error' => $msg]);
                return ['error' => "Gemini error: {$msg}", 'status' => $response->status()];
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            return [
                'reply'        => $text,
                'model'       => $model,
                'finish_reason' => $data['candidates'][0]['finishReason'] ?? null,
                'usage'       => [
                    'prompt_tokens'     => $data['usageMetadata']['promptTokenCount'] ?? 0,
                    'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
                    'total_tokens'     => $data['usageMetadata']['totalTokenCount'] ?? 0,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Gemini chat exception', ['error' => $e->getMessage()]);
            return ['error' => 'Gemini request failed: ' . $e->getMessage(), 'status' => 500];
        }
    }

    public function streamChat(array $messages, array $opts = []): \Generator
    {
        if (!$this->isAvailable()) {
            yield ['delta' => '', 'done' => true, 'error' => 'Gemini is not configured.'];
            return;
        }

        $model = $opts['model'] ?? $this->model;
        $temperature = $opts['temperature'] ?? 0.7;
        $maxTokens   = $opts['max_tokens'] ?? 2000;

        $contents = $this->buildContents($messages);

        $body = [
            'contents'           => $contents,
            'generationConfig'  => [
                'temperature'  => (float) $temperature,
                'maxOutputTokens' => (int) $maxTokens,
                'topP'        => isset($opts['top_p']) ? (float) $opts['top_p'] : 0.95,
            ],
        ];

        try {
            $response = Http::timeout(120)
                ->withOptions(['stream' => true])
                ->post($this->buildUrl($model, true), $body);

            $fullReply = '';
            $reader = $response->toPsrResponse()->getBody();

            while (!$reader->eof()) {
                $line = $reader->read(4096);
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $json = trim(substr($line, 6));
                if ($json === '[DONE]') break;
                $data = json_decode($json, true);
                if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    $delta = $data['candidates'][0]['content']['parts'][0]['text'];
                    $fullReply .= $delta;
                    yield ['delta' => $delta, 'done' => false];
                }
            }

            yield ['delta' => '', 'done' => true, 'reply' => $fullReply];
        } catch (\Throwable $e) {
            Log::error('Gemini stream exception', ['error' => $e->getMessage()]);
            yield ['delta' => '', 'done' => true, 'error' => 'Gemini stream failed: ' . $e->getMessage()];
        }
    }

    public function vision(string $imageUrl, string $prompt, array $opts = []): array
    {
        if (!$this->isAvailable()) {
            return ['error' => 'Gemini is not configured. Please add an API key in Admin Settings.', 'status' => 503];
        }

        $model = $opts['model'] ?? $this->model;
        $maxTokens = $opts['max_tokens'] ?? 2000;

        $parts = [];
        // Image part
        if (str_starts_with($imageUrl, 'data:')) {
            [$meta, $base64] = explode(',', $imageUrl, 2);
            $mime = explode(';', $meta)[0];
            $mime = substr($mime, 5); // remove "data:"
            $parts[] = ['inlineData' => ['mimeType' => $mime, 'data' => $base64]];
        } else {
            $parts[] = ['fileData' => ['mimeType' => 'image/jpeg', 'fileUri' => $imageUrl]];
        }
        // Text part
        $parts[] = ['text' => $prompt];

        $body = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['maxOutputTokens' => (int) $maxTokens],
        ];

        try {
            $response = Http::timeout(60)->post($this->buildUrl($model), $body);

            if (!$response->successful()) {
                $err = $response->json();
                $msg = $err['error']['message'] ?? "HTTP {$response->status()}";
                return ['error' => "Gemini vision error: {$msg}", 'status' => $response->status()];
            }

            $data = $response->json();
            return [
                'reply' => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
                'model' => $model,
            ];
        } catch (\Throwable $e) {
            Log::error('Gemini vision exception', ['error' => $e->getMessage()]);
            return ['error' => 'Gemini vision failed: ' . $e->getMessage(), 'status' => 500];
        }
    }

    public function image(string $prompt, array $opts = []): array
    {
        // Gemini doesn't have an image generation endpoint in the free tier
        // Fall back to text explanation
        return [
            'error' => 'Gemini does not support image generation. Please use OpenAI (DALL-E) or MiniMax for image generation.',
            'status' => 501,
        ];
    }

    public function code(array $messages, array $opts = []): array
    {
        // Code uses the same chat endpoint with a system prompt
        $systemMsg = [
            'role'    => 'system',
            'content' => 'You are an expert code generator. Output ONLY complete, working code — no explanations, no markdown fences unless asked. Focus on: ' . ($opts['language'] ?? 'general') . '.',
        ];
        return $this->chat(array_merge([$systemMsg], $messages), $opts);
    }
}
