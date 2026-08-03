<?php

namespace App\Services\AI;

/**
 * Centralized AI service — MiniMax via OpenClaw gateway.
 *
 * All AI features MUST use this service. No duplicate AI logic.
 * SINGLE PROVIDER ONLY: OpenClaw/MiniMax. No multi-provider abstractions.
 *
 * Models:
 *   - chat:    MiniMax-M3 (default)
 *   - vision:  MiniMax-VL-01 (auto-dispatched when image content is present)
 *   - image:   image-01 (MiniMax native image generation)
 */
class MiniMaxService
{
    public const PROVIDER_SLUG = 'openclaw';
    public const CHAT_MODEL    = 'minimax-m3';
    public const VISION_MODEL  = 'minimax-vl-01';
    public const IMAGE_MODEL   = 'image-01';
    public const GATEWAY_MODEL = 'openclaw';

    private string $gatewayUrl;
    private string $gatewayToken;
    private string $model;

    public function __construct()
    {
        $this->gatewayUrl   = env('OPENCLAW_GATEWAY_URL', 'http://127.0.0.1:18789');
        $this->gatewayToken = env('OPENCLAW_GATEWAY_TOKEN', 'c11301b2d79af120e1a150539bb2ab0b50d999d1a302a810');
        // The OpenClaw gateway only accepts the literal model string 'openclaw'.
        // The MiniMax-M3 / MiniMax-VL-01 user-facing labels are returned in
        // responses for the frontend, but the gateway call must use 'openclaw'.
        $this->model       = self::GATEWAY_MODEL;
    }

    /**
     * Get the configured OpenClaw gateway URL.
     */
    public function getGatewayUrl(): string
    {
        return $this->gatewayUrl;
    }

    /**
     * Get the chat model identifier.
     */
    public function getModel(): string
    {
        return $this->model;
    }


    /**
     * Enhance image prompts with quality/style modifiers for more attractive results.
     */
    private function enhancePrompt(string $prompt): string
    {
        $prompt = trim($prompt);

        // Skip enhancement if prompt is too short
        if (strlen($prompt) < 20) {
            return "$prompt, detailed, high quality, professional, 8k, sharp focus, studio lighting";
        }

        $lowerPrompt = strtolower($prompt);
        $qualityWords = ["high quality", "detailed", "4k", "8k", "photorealistic", "professional", "ultra"];
        $hasQuality = false;
        foreach ($qualityWords as $word) {
            if (strpos($lowerPrompt, $word) !== false) {
                $hasQuality = true;
                break;
            }
        }

        if (!$hasQuality) {
            $prompt .= ", detailed, high quality, professional, sharp focus, 8k resolution, studio lighting";
        }

        return $prompt;
    }

    /**
     * Unified chat — dispatches to OpenClaw gateway.
     * Accepts either string content or vision-style content arrays.
     *
     * @param array $messages  Chat messages [{role, content}]
     * @param array $opts {
     *   max_tokens: int       (default 2000)
     *   temperature: float    (default 0.7)
     *   top_p: float|null
     *   stop: array|null
     *   model: string|null    (override chat model; auto-detects vision)
     *   system: string|null   (prepended as system message)
     *   timeout: int          (HTTP timeout seconds, default 180)
     * }
     *
     * @return array {
     *   reply: string,
     *   model: string,
     *   usage?: array,
     *   finish_reason?: string,
     *   error?: string,
     *   status?: int,
     * }
     */
    public function chat(array $messages, array $opts = []): array
    {
        $maxTokens  = (int)   ($opts['max_tokens']  ?? 2000);
        $temperature        = (float) ($opts['temperature'] ?? 0.7);
        $userModel  = (string)($opts['model']       ?? $this->model);
        // Always send 'openclaw' to the gateway — that's the only model it accepts.
        // The user-facing model label ($userModel) is preserved in the response.
        $model      = self::GATEWAY_MODEL;
        $timeout    = (int)   ($opts['timeout']     ?? 360);

        // If a system prompt was supplied, prepend it
        if (!empty($opts['system']) && (empty($messages) || ($messages[0]['role'] ?? '') !== 'system')) {
            array_unshift($messages, ['role' => 'system', 'content' => (string)$opts['system']]);
        }

        // Auto-detect vision: if any message has array content with image_url,
        // tag the response model as MiniMax-VL-01 for the frontend.
        // The actual gateway call still uses 'openclaw' — the gateway handles
        // vision dispatch automatically.
        $hasVision = false;
        foreach ($messages as $m) {
            $content = $m['content'] ?? null;
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (isset($block['type']) && $block['type'] === 'image_url') {
                        $hasVision = true;
                        break 2;
                    }
                }
            }
        }
        $responseModel = $hasVision ? self::VISION_MODEL : self::CHAT_MODEL;

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ];
        if (!empty($opts['top_p'])) {
            $payload['top_p'] = (float) $opts['top_p'];
        }
        if (!empty($opts['stop'])) {
            $payload['stop'] = $opts['stop'];
        }

        $startTime = microtime(true);

        // ── Retry loop (up to 3 attempts for transient overload/rate-limit errors) ─
        $maxRetries = 3;
        $lastError  = null;
        $lastBody   = null;
        $lastCode   = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {

            // ── DEBUG LOG: every chat attempt ──────────────────────────────────
            \Log::info('CHAT_ATTEMPT', [
                'attempt'   => $attempt,
                'service'    => 'MiniMaxService',
                'model'     => $model,
                'endpoint'  => $this->gatewayUrl . '/v1/chat/completions',
            ]);

            $ch = curl_init($this->gatewayUrl . '/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->gatewayToken,
                ],
                CURLOPT_POSTFIELDS    => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $body  = curl_exec($ch);
            $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            $lastBody   = $body;
            $lastCode   = $code;

            // ── Success ────────────────────────────────────────────────────────
            if (!$error && $code === 200) {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $reply = $decoded['reply']
                        ?? $decoded['choices'][0]['message']['content']
                        ?? '';
                    \Log::info('CHAT_OK', ['attempt' => $attempt, 'duration_ms' => $durationMs]);
                    return [
                        'reply' => is_string($reply) ? $reply : '',
                        'model' => $responseModel,
                        'usage'         => $decoded['usage']         ?? null,
                        'finish_reason' => $decoded['finish_reason'] ?? null,
                    ];
                }
                // Invalid JSON — don't retry, just fail
                \Log::error('CHAT_ERROR', [
                    'provider'   => self::PROVIDER_SLUG,
                    'model'      => $model,
                    'error'      => 'Invalid JSON from gateway',
                    'response_body' => mb_substr((string)$body, 0, 500),
                    'duration_ms'=> $durationMs,
                ]);
                return ['error' => 'Invalid JSON from gateway', 'status' => 502, 'model' => $model];
            }

            // ── Transient error — check if worth retrying ────────────────────────
            $shouldRetry = false;
            $errorMsg    = '';

            if ($error) {
                $shouldRetry = true;
                // Make timeout errors more user-friendly
                if (strpos($error, 'timed out') !== false || strpos($error, 'Timeout') !== false) {
                    $errorMsg = 'The request is taking longer than expected. This can happen with complex tasks. Please try a simpler request or try again.';
                } else {
                    $errorMsg = "Gateway error: $error";
                }
            } elseif ($code === 429 || $code >= 500) {
                $shouldRetry = true;
                $errorMsg    = "Gateway returned HTTP $code: " . mb_substr((string)$body, 0, 300);
            } elseif (is_string($body) && (stripos($body, 'overload') !== false || stripos($body, 'rate limit') !== false || stripos($body, 'temporarily') !== false)) {
                $shouldRetry = true;
                $errorMsg    = mb_substr((string)$body, 0, 300);
            }

            if ($shouldRetry && $attempt < $maxRetries) {
                $wait = (int) pow(2, $attempt - 1); // 1s, 2s
                \Log::warning('CHAT_RETRY', [
                    'attempt'    => $attempt,
                    'wait_secs'  => $wait,
                    'error'      => $errorMsg,
                    'http_code'  => $code,
                ]);
                sleep($wait);
                continue; // next attempt
            }

            // Final failure (no retry or retries exhausted)
            \Log::error('CHAT_ERROR', [
                'provider'   => self::PROVIDER_SLUG,
                'model'      => $model,
                'endpoint'   => $this->gatewayUrl . '/v1/chat/completions',
                'error'      => $errorMsg ?: ($error ? "Gateway error: $error" : "Gateway returned HTTP $code"),
                'response_body' => mb_substr((string)$body, 0, 500),
                'http_code'  => $code,
                'duration_ms'=> $durationMs,
                'attempts'   => $attempt,
            ]);

            // Friendly overload message for the UI
            $userMsg = (stripos($errorMsg, 'overload') !== false)
                ? 'The AI service is temporarily overloaded. Please try again in a moment.'
                : $errorMsg;

            return [
                'error'  => $userMsg,
                'status' => in_array($code, [429, 502, 503, 504]) ? $code : 500,
                'model'  => $model,
            ];
        }

        // Should never reach here, but safety net
        return ['error' => 'AI service unavailable after retries', 'status' => 503, 'model' => $model];

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            \Log::error('CHAT_ERROR', [
                'provider'   => self::PROVIDER_SLUG,
                'model'      => $model,
                'error'      => 'Invalid JSON from gateway',
                'response_body' => mb_substr((string)$body, 0, 500),
                'duration_ms'=> $durationMs,
            ]);
            return ['error' => 'Invalid JSON from gateway', 'status' => 502, 'model' => $model];
        }

        $reply = $decoded['reply']
            ?? $decoded['choices'][0]['message']['content']
            ?? '';

        $result = [
            'reply' => is_string($reply) ? $reply : '',
            'model' => $responseModel,
        ];
        if (isset($decoded['usage']))         $result['usage']         = $decoded['usage'];
        if (isset($decoded['choices'][0]['finish_reason'])) $result['finish_reason'] = $decoded['choices'][0]['finish_reason'];

        \Log::info('CHAT_OK', [
            'provider'   => self::PROVIDER_SLUG,
            'model'      => $result['model'],
            'reply_len'  => strlen($result['reply']),
            'finish_reason' => $result['finish_reason'] ?? null,
            'duration_ms'=> $durationMs,
        ]);

        return $result;
    }

    /**
     * Stream chat via OpenClaw gateway (SSE).
     *
     * Yields: ['delta' => string, 'done' => bool, 'error' => ?string]
     */
    public function streamChat(array $messages, array $opts = []): \Generator
    {
        $maxTokens  = (int)   ($opts['max_tokens'] ?? 2000);
        $temperature        = (float) ($opts['temperature'] ?? 0.7);
        $userModel  = (string)($opts['model']      ?? $this->model);
        // Always send 'openclaw' to the gateway.
        $model      = self::GATEWAY_MODEL;
        $timeout    = (int)   ($opts['timeout']    ?? 360);

        if (!empty($opts['system']) && (empty($messages) || ($messages[0]['role'] ?? '') !== 'system')) {
            array_unshift($messages, ['role' => 'system', 'content' => (string)$opts['system']]);
        }

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'stream'      => true,
        ];

        \Log::info('STREAM_CHAT_REQUEST', [
            'service'  => 'MiniMaxService',
            'provider' => self::PROVIDER_SLUG,
            'user_facing_model' => $userModel,
            'gateway_model' => $model,
            'endpoint' => $this->gatewayUrl . '/v1/chat/completions',
            'msg_count'=> count($messages),
            'max_tokens' => $maxTokens,
        ]);

        $ch = curl_init($this->gatewayUrl . '/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->gatewayToken,
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            \Log::error('STREAM_CHAT_ERROR', [
                'provider' => self::PROVIDER_SLUG,
                'model'    => $model,
                'error'    => $err,
            ]);
            // Make timeout errors more user-friendly
            if (strpos($err, 'timed out') !== false || strpos($err, 'Timeout') !== false) {
                yield ['delta' => '', 'done' => true, 'error' => 'The request is taking longer than expected. This can happen with complex tasks. Please try a simpler request or try again.'];
            } else {
                yield ['delta' => '', 'done' => true, 'error' => "Gateway error: $err"];
            }
            return;
        }
        if ($code !== 200 || !$body) {
            \Log::error('STREAM_CHAT_ERROR', [
                'provider' => self::PROVIDER_SLUG,
                'model'    => $model,
                'http_code'=> $code,
                'response' => mb_substr((string)$body, 0, 500),
            ]);
            yield ['delta' => '', 'done' => true, 'error' => "Gateway HTTP $code"];
            return;
        }

        // Parse SSE chunks
        $lines = preg_split("/\r?\n/", $body);
        $totalReply = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'data: [DONE]' || trim($line) === '[DONE]') {
                continue;
            }
            // SSE data lines are "data: {json}" (with space after colon)
            if (!str_starts_with($line, 'data: ')) {
                continue;
            }
            $payload = trim(substr($line, 6)); // skip "data: " (6 chars)
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                continue;
            }
            $delta = $decoded['choices'][0]['delta']['content'] ?? '';
            $finishReason = $decoded['choices'][0]['finish_reason'] ?? null;
            if ($delta !== '') {
                $totalReply .= $delta;
                yield ['delta' => $delta, 'done' => false];
            }
            if ($finishReason === 'stop') {
                break;
            }
        }

        \Log::info('STREAM_CHAT_OK', [
            'provider'   => self::PROVIDER_SLUG,
            'model'      => $model,
            'reply_len'  => strlen($totalReply),
        ]);

        yield ['delta' => '', 'done' => true];
    }

    /**
     * Vision analysis — sends an image URL to OpenClaw gateway with MiniMax-VL-01.
     *
     * @param string $imageUrl Image URL (http(s) or data: URI)
     * @param string $prompt   Question/instruction for the vision model
     * @param array $opts      Forwarded to chat() (max_tokens, temperature, system)
     *
     * @return array  Same shape as chat()
     */
    public function vision(string $imageUrl, string $prompt, array $opts = []): array
    {
        $opts['model'] = self::VISION_MODEL;

        // Convert URL to base64 data-URI if it's an external HTTP(S) URL
        $imageContent = $this->imageUrlToBase64($imageUrl);

        $messages = [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text',      'text' => $prompt ?: 'Describe this image.'],
                ['type' => 'image_url', 'image_url' => ['url' => $imageContent]],
            ],
        ]];

        \Log::info('VISION_REQUEST', [
            'service'    => 'MiniMaxService',
            'provider'   => self::PROVIDER_SLUG,
            'model'      => self::VISION_MODEL,
            'endpoint'   => $this->gatewayUrl . '/v1/chat/completions',
            'image_url'  => mb_substr($imageUrl, 0, 120),
            'prompt'     => mb_substr($prompt, 0, 120),
        ]);

        return $this->chat($messages, $opts);
    }

    /**
     * Download a remote image and return it as a base64 data-URI.
     * Falls back to the original URL if it's already a data-URI.
     */
    private function imageUrlToBase64(string $url): string
    {
        // Already a data-URI — return as-is
        if (str_starts_with($url, 'data:')) {
            return $url;
        }

        // Handle absolute filesystem paths — read directly
        if (str_starts_with($url, '/') && file_exists($url)) {
            $mime = mime_content_type($url);
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($url));
        }

        // Resolve relative URLs (storage/... paths)
        if (!str_starts_with($url, 'http')) {
            $fullPath = storage_path('app/public/' . $url);
            if (file_exists($fullPath)) {
                $mime = mime_content_type($fullPath);
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
            }
            // Try as absolute path from storage/
            if (file_exists(storage_path($url))) {
                $fullPath = storage_path($url);
                $mime = mime_content_type($fullPath);
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
            }
            return $url;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body   = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body && $code === 200) {
            $mime = $this->detectMimeType($body, $url);
            return 'data:' . $mime . ';base64,' . base64_encode($body);
        }

        // Fallback: return original URL and let the gateway try (will fail with clear error)
        return $url;
    }

    private function detectMimeType(string $body, string $url): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($body);
        if ($mime && $mime !== 'application/octet-stream') {
            return $mime;
        }
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return match ($ext) {
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            default => 'image/png',
        };
    }

    /**
     * Health probe — verify gateway is reachable.
     *
     * @return array {status: 'healthy'|'unhealthy', provider, model, latency_ms, error?}
     */
    public function health(): array
    {
        $start = microtime(true);
        $url = $this->gatewayUrl . '/v1/chat/completions';
        $payload = [
            'model'      => $this->model,
            'messages'   => [['role' => 'user', 'content' => 'ping']],
            'max_tokens' => 5,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->gatewayToken,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $ms = (int)((microtime(true) - $start) * 1000);

        $minimaxKey = env('MINIMAX_API_KEY', '');
        $imageReady = !empty($minimaxKey);

        $gatewayOk = $code === 200 && !$err;

        return [
            'status'      => $gatewayOk ? 'healthy' : 'unhealthy',
            'provider'    => self::PROVIDER_SLUG,
            'model'       => self::CHAT_MODEL,
            'endpoint'    => $url,
            'latency_ms'  => $ms,
            'gateway'     => [
                'status'     => $gatewayOk ? 'healthy' : 'unhealthy',
                'http_code'  => $code,
                'error'      => $err ?: null,
            ],
            'image'        => [
                'status'     => $imageReady ? 'healthy' : 'unhealthy',
                'model'      => self::IMAGE_MODEL,
                'api_key_set'=> $imageReady,
                'error'      => $imageReady ? null : 'MINIMAX_API_KEY not set',
            ],
            'vision'       => [
                'status'     => $gatewayOk ? 'healthy' : 'unhealthy',
                'model'      => self::VISION_MODEL,
            ],
        ];
    }

    /**
     * Compact summary of messages for debug logs.
     */
    private function summarizeMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $i => $m) {
            $content = $m['content'] ?? '';
            if (is_array($content)) {
                $text = '';
                $hasImg = false;
                foreach ($content as $block) {
                    if (isset($block['type']) && $block['type'] === 'text') {
                        $text .= $block['text'] . ' ';
                    } elseif (isset($block['type']) && $block['type'] === 'image_url') {
                        $hasImg = true;
                    }
                }
                $out[] = [
                    'role'      => $m['role'] ?? '?',
                    'text_preview' => mb_substr(trim($text), 0, 80),
                    'has_image' => $hasImg,
                ];
            } else {
                $out[] = [
                    'role'         => $m['role'] ?? '?',
                    'text_preview' => mb_substr((string)$content, 0, 80),
                ];
            }
        }
        return $out;
    }

    public function analyze(string $screenshotUrl = null, string $projectContext = ''): array
    {
        $system = 'You are a UI auditor. Return ONLY valid JSON: {"score":80,"accessibility":75,"performance":80,"consistency":70,"ux":78,"visual":82,"bestPractices":["clear hierarchy"],"priorityImprovements":[{"severity":"high","title":"Improve contrast","description":"Text contrast ratio is low","fix":"Use darker text color"}],"summary":"Good dashboard."}. No markdown.';
        $user = $projectContext
            ? ["Audit this UI for: $projectContext. Return JSON only.", $screenshotUrl ? ['type' => 'image_url', 'image_url' => ['url' => $screenshotUrl]] : null]
            : ["Audit this UI screenshot. Return JSON only.", $screenshotUrl ? ['type' => 'image_url', 'image_url' => ['url' => $screenshotUrl]] : null];
        $user = array_filter($user, fn($v) => $v !== null);

        return $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => count($user) === 1 ? $user[0] : $user],
        ], ["max_tokens" => 2000]);
    }

    public function detect(string $screenshotUrl = null, string $projectContext = ''): array
    {
        $system = 'You are a UI bug detector. Return a JSON array. Each: {"type":"string","severity":"high|medium|low","title":"string","description":"string","fix":"string"}. Max 6 issues. Return JSON array only.';
        $user = array_filter([
            $projectContext ? "Detect issues in: $projectContext" : "Detect all UI/UX issues",
            $screenshotUrl ? ['type' => 'image_url', 'image_url' => ['url' => $screenshotUrl]] : null,
        ]);

        return $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => count($user) === 1 ? $user[array_key_first($user)] : array_values($user)],
        ], ["max_tokens" => 2000]);
    }

    public function suggestions(string $projectContext = '', array $categories = []): array
    {
        $system = 'You are a UX consultant. Return a JSON array of 3 suggestions. Each: {"id":1,"category":"string","title":"string","impact":"high|medium|low"}. Return JSON array only.';

        return $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "3 UX improvement suggestions for: $projectContext. Return JSON array only."],
        ], ["max_tokens" => 2000]);
    }

    public function redesign(string $screenshotUrl = null, string $style = 'modern-saas', string $projectContext = ''): array
    {
        $system = "You are a UI designer. Return JSON: {\"summary\":\"string\",\"suggestions\":[\"string\"],\"colorPalette\":{\"primary\":\"#7c5cff\",\"secondary\":\"#1a1a26\",\"accent\":\"#ff6b9d\",\"background\":\"#0e0e16\"},\"typography\":{\"headingFont\":\"Inter\",\"bodyFont\":\"Inter\"}}. Return JSON only.";
        $user = array_filter([
            "Redesign in $style style".($projectContext ? ". Context: $projectContext" : ''),
            $screenshotUrl ? ['type' => 'image_url', 'image_url' => ['url' => $screenshotUrl]] : null,
        ]);

        return $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => count($user) === 1 ? $user[array_key_first($user)] : array_values($user)],
        ], ["max_tokens" => 2000]);
    }

    public function copywriting(string $type = 'landing-page', string $productContext = '', string $tone = 'modern'): array
    {
        $system = 'You are a copywriter. Return JSON: {"headline":"string","subheadline":"string","cta":"string","secondaryCta":"string"}. Return JSON only.';

        return $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "Write $type copy for: $productContext. Return JSON only."],
        ], ["max_tokens" => 2000]);
    }

    public function research(string $topic = 'UI design trends', string $niche = ''): array
    {
        $system = 'You are a design researcher. Return JSON: {"trends":["string"],"competitors":[{"name":"string","strengths":["string"]}]}. Return JSON only.';

        return $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "Research: $topic".($niche ? ". Niche: $niche" : '') . ". Return JSON only."],
        ], ["max_tokens" => 2000]);
    }

    /** System prompt map per analysis type */
    private function getConsultantSystemPrompt(string $analysisType = ''): string
    {
        $prompts = [
            'ui'             => 'You are a Senior UI Designer and Frontend Developer. Analyze the visual design of the provided screenshot. Be specific, critical, and concise. Rate layout, typography, color, spacing, visual hierarchy, and component consistency. Respond ONLY with the JSON format requested by the user.',
            'ux'             => 'You are a Senior UX Designer and Product Consultant. Analyze the user experience of the provided screenshot. Be specific, critical, and concise. Rate navigation clarity, user flow, cognitive load, and interaction patterns. Respond ONLY with the JSON format requested by the user.',
            'ftue'           => 'You are a UX Researcher specializing in first-time user experience. Be brutally honest about onboarding clarity and first impressions. Respond ONLY with the JSON format requested by the user.',
            'business'       => 'You are a SaaS Business Consultant. Evaluate the UI from a business perspective: trust signals, conversion, branding, CTA effectiveness. Respond ONLY with the JSON format requested by the user.',
            'accessibility'  => 'You are an Accessibility Expert (WCAG 2.1 AA certified). Audit the screenshot for contrast, font sizes, touch targets, keyboard navigation, and ARIA labels. Respond ONLY with the JSON format requested by the user.',
            'mobile'         => 'You are a Mobile UX Expert. Evaluate mobile responsiveness: touch targets, spacing, responsive layout, navigation adaptation. Respond ONLY with the JSON format requested by the user.',
            'performance'   => 'You are a Frontend Performance Architect. Suggest concrete optimizations for the UI shown. Respond ONLY with the JSON format requested by the user.',
            'design_system'  => 'You are a Design Systems Architect. Check consistency of buttons, cards, spacing, colors, typography, and components. Respond ONLY with the JSON format requested by the user.',
            'competitor'     => 'You are a Product Strategy Expert. Research and compare this product against top SaaS competitors. Respond ONLY with the JSON format requested by the user.',
            'ai_suggestions' => 'You are a Product Innovation Consultant. Generate AI-powered, actionable improvement ideas. Respond ONLY with the JSON format requested by the user.',
            'features'       => 'You are a Product Manager. Recommend specific missing features for this type of product. Respond ONLY with the JSON format requested by the user.',
            'redesign'       => 'You are a Senior Product Designer. Generate concrete redesign suggestions with modern alternatives. Respond ONLY with the JSON format requested by the user.',
            'report'         => 'You are a Principal Product Consultant. Generate a comprehensive, structured audit report. Be thorough and actionable. Respond ONLY with the JSON format requested by the user.',
        ];

        $type = strtolower(trim($analysisType));
        if (isset($prompts[$type])) {
            return $prompts[$type];
        }
        return 'You are a senior UI/UX designer and product consultant. Be specific, critical, and concise. Give actionable feedback with clear reasoning.';
    }

    public function consultant(string $question, string $context = '', string $analysisType = ''): array
    {
        $system = $this->getConsultantSystemPrompt($analysisType);

        return $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $context ? "$context\n\n$question" : $question],
        ], ['max_tokens' => 2000]);
    }

    /** Consultant with optional screenshot image */
    public function consultWithImage(string $question, string $screenshotUrl = null, int $maxTokens = 2000, string $analysisType = ''): array
    {
        $system = $this->getConsultantSystemPrompt($analysisType);

        $userContent = $screenshotUrl
            ? [
                ['type' => 'text', 'text' => $question],
                ['type' => 'image_url', 'image_url' => ['url' => $screenshotUrl]],
              ]
            : [['type' => 'text', 'text' => $question]];

        return $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userContent],
        ], ['max_tokens' => $maxTokens]);
    }

    public function autodesign(string $description, string $device = 'web', string $style = 'modern-saas'): array
    {
        $system = "You are an elite UI designer. Return JSON: {\"layout\":\"string\",\"sections\":[{\"name\":\"string\",\"components\":[\"string\"]}],\"colorPalette\":{\"primary\":\"#7c5cff\",\"background\":\"#0e0e16\"},\"implementationTips\":[\"string\"]}. Return JSON only.";

        return $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "Design a UI: $description. Device: $device. Style: $style. Return JSON only."],
        ], ["max_tokens" => 2000]);
    }

    public function annotate(string $screenshotUrl): array
    {
        $system = 'You are a UI component detector. Return JSON: {"components":[{"type":"string","label":"string"}],"summary":"string"}. Return JSON only.';

        return $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => [
                ['type' => 'text', 'text' => 'Detect all UI components. Return JSON only.'],
                ['type' => 'image_url', 'image_url' => ['url' => $screenshotUrl]],
            ]],
        ], ["max_tokens" => 2000]);
    }

    public function parseJson(string $text): array
    {
        $text = trim($text);
        if (!$text) return [];

        $decoded = json_decode($text, true);
        if (is_array($decoded)) return $decoded;

        // Handle double-encoded JSON strings from gateway
        if (is_string($decoded)) {
            $try = json_decode($decoded, true);
            if (is_array($try)) return $try;
        }

        // Strip markdown fences
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) return $decoded;

        if (is_string($decoded)) {
            $try = json_decode($decoded, true);
            if (is_array($try)) return $try;
        }

        return [];
    }

    // ─── Image Generation ─────────────────────────────────────────────────────

    /**
     * Generate images using MiniMax's native image generation model.
     *
     * Uses: POST https://api.minimax.io/v1/image_generation
     * Model: image-01
     *
     * @param string $prompt Text description of the image
     * @param array $opts {
     *   size: string       (1:1 | 16:9 | 9:16 | 3:4 | 4:3) default: 1:1
     *   n: int            (1-4) default: 1
     *   timeout: int      HTTP timeout in seconds, default: 60
     * }
     *
     * @return array {
     *   images: string[],      URLs of generated images
     *   model: string,         Model used: 'image-01'
     *   prompt: string,       Original prompt
     *   error?: string,       Present only on failure
     *   status?: int,         HTTP status code on error
     * }
     */
    public function image(string $prompt, array $opts = []): array
    {
        $apiKey  = env('MINIMAX_API_KEY', '');
        $baseUrl = 'https://api.minimax.io/v1';
        $size    = $opts['size'] ?? '1:1';
        $n       = min(max(1, (int)($opts['n'] ?? 1)), 4);
        $timeout = (int)($opts['timeout'] ?? 120);

        if (empty($apiKey)) {
            return [
                'error' => 'MiniMax API key is not configured. ' .
                           'Set MINIMAX_API_KEY in your environment variables.',
                'status' => 503,
                'model'  => 'image-01',
            ];
        }

        // Map size string to MiniMax format
        $sizeMap = [
            '1:1'   => '1:1',
            '16:9'  => '16:9',
            '9:16'  => '9:16',
            '3:4'   => '3:4',
            '4:3'   => '4:3',
            // Also accept 1024x1024 style
            '1024x1024' => '1:1',
            '1024x1792' => '9:16',
            '1792x1024' => '16:9',
            '512x512'   => '1:1',
            '256x256'   => '1:1',
        ];
        $resolvedSize = $sizeMap[$size] ?? '1:1';

        // Enhance prompt with quality/style modifiers for more attractive results
        $enhancedPrompt = $this->enhancePrompt($prompt);

        $payload = [
            'model'        => 'image-01',
            'prompt'       => $enhancedPrompt,
            'image_size'   => $resolvedSize,
            'num_images'   => $n,
        ];

        $startTime = microtime(true);
        $lastError = null;

        // ── EXPLICIT DEBUG LOG: Every image request ─────────────────────────────
        \Log::info('IMAGE_REQUEST', [
            'service'    => 'MiniMaxService',
            'api_url'    => $baseUrl . '/image_generation',
            'model'      => 'image-01',
            'provider'   => 'minimax',
            'size'       => $resolvedSize,
            'num_images' => $n,
            'prompt'     => substr($prompt, 0, 120),
            'api_key_set' => !empty($apiKey) ? 'YES' : 'NO — WILL FAIL',
        ]);

        // Retry up to 2 times on timeout or 502 (MiniMax can be slow for image gen)
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init($baseUrl . '/image_generation');
            if (!$ch) {
                return ['error' => 'Failed to initialize HTTP client', 'status' => 500, 'model' => 'image-01'];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT         => $timeout,
                CURLOPT_CONNECTTIMEOUT  => 15,
                CURLOPT_SSL_VERIFYPEER  => true,
            ]);

            $raw      = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            $durationMs = (int)((microtime(true) - $startTime) * 1000);

            \Log::info('MiniMax_Image', [
                'attempt'   => $attempt,
                'model'     => 'image-01',
                'prompt'    => substr($prompt, 0, 80),
                'size'      => $resolvedSize,
                'n'         => $n,
                'http_code' => $httpCode,
                'curl_err'  => $curlErr,
                'duration_ms' => $durationMs,
            ]);

            // Success on first try
            if ($httpCode === 200 && $raw && !$curlErr) {
                break;
            }

            $lastError = $curlErr ?: "HTTP {$httpCode}";

            // If timed out or got a server error, retry once
            if ($attempt === 1 && ($curlErr || $httpCode >= 500)) {
                \Log::info('MiniMax_Image', ['retry' => 2, 'reason' => $lastError]);
                continue;
            }

            // Second attempt failed — return error
            break;
        }

        if ($lastError && $httpCode !== 200) {
            $errMsg = $lastError;
            if ($httpCode === 200) {
                $errMsg = 'Invalid response from MiniMax';
            }
            \Log::error('IMAGE_ERROR', [
                'service'  => 'MiniMaxService',
                'provider' => 'minimax',
                'model'    => 'image-01',
                'error'    => $errMsg,
                'http_code' => $httpCode,
                'attempts' => 2,
            ]);
            return [
                'error' => "MiniMax API error: {$errMsg}",
                'status' => $httpCode ?: 502,
                'model'  => 'image-01',
            ];
        }

        $response = json_decode($raw, true);

        if (isset($response['error'])) {
            \Log::error('IMAGE_ERROR', [
                'service'  => 'MiniMaxService',
                'provider' => 'minimax',
                'model'    => 'image-01',
                'error'    => $response['error']['message'] ?? json_encode($response['error']),
                'http_code' => $httpCode,
            ]);
            return [
                'error' => $response['error']['message'] ?? json_encode($response['error']),
                'status' => 400,
                'model'  => 'image-01',
            ];
        }

        $imageUrls = $response['data']['image_urls'] ?? [];

        if (empty($imageUrls)) {
            return [
                'error' => 'MiniMax returned no images',
                'status' => 500,
                'model'  => 'image-01',
            ];
        }

        // ── Download images to local storage so they don't expire ──────────────
        $localUrls = [];
        $storageDir = base_path('storage/app/auto_designer/images');
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }

        foreach ($imageUrls as $externalUrl) {
            $filename = 'img_' . uniqid() . '_' . time() . '.png';
            $localPath = $storageDir . '/' . $filename;
            $ch = curl_init($externalUrl);
            $fh = fopen($localPath, 'wb');
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fh,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $ok = curl_exec($ch);
            $curlErr = curl_error($ch);
            curl_close($ch);
            fclose($fh);

            if ($ok && file_exists($localPath) && filesize($localPath) > 1024) {
                // Accessible at /storage/auto_designer/images/{filename} via nginx alias
                $localUrls[] = '/storage/auto_designer/images/' . $filename;
            } else {
                // Fallback: keep external URL if download failed
                @unlink($localPath);
                $localUrls[] = $externalUrl;
            }
        }

        return [
            'images'  => $localUrls,
            'model'   => 'image-01',
            'prompt'  => $prompt,
            'size'    => $resolvedSize,
            'n'       => count($localUrls),
        ];
    }
}
