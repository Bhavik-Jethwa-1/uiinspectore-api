<?php

namespace App\Services\AI\Inspector;

use Illuminate\Support\Facades\Log;

/**
 * CodeGenerationService — generates React + Tailwind CSS code from UI vision analysis.
 *
 * Wraps all external provider calls in try/catch.
 * availability() checks all three providers (gateway, OpenAI, Groq).
 * generateCode() tries each provider in priority order until one succeeds.
 * Never throws — always returns a structured array.
 */
class CodeGenerationService
{
    private const BASE_URL = 'https://api.openai.com/v1';
    private const MODEL   = 'gpt-4o';

    private string $apiKey;
    private string $groqApiKey;
    private string $gatewayUrl;
    private string $gatewayToken;

    public function __construct()
    {
        $this->apiKey       = env('OPENAI_API_KEY', '');
        $this->groqApiKey   = env('GROQ_API_KEY', '');
        $this->gatewayUrl   = env('OPENCLAW_GATEWAY_URL', 'http://127.0.0.1:18789');
        $this->gatewayToken = env('OPENCLAW_GATEWAY_TOKEN', '');
    }

    // ─── Public API ────────────────────────────────────────────────────────

    /**
     * Generate React + Tailwind code.
     *
     * @param array       $visionAnalysis  Structured analysis from vision service
     * @param string      $designStyle     modern_saas | minimal | glassmorphism | enterprise | dark
     * @param string|null $screenshotPath   Optional screenshot path for context
     * @return array{success: bool, code?: string, supporting_code?: string|null,
     *              summary?: string, provider?: string, model?: string,
     *              error?: string, error_code?: string}
     */
    public function generateCode(array $visionAnalysis, string $designStyle, ?string $screenshotPath = null): array
    {
        $prompt = $this->buildCodePrompt($visionAnalysis, $designStyle);

        // Encode screenshot if available
        $imageData = null;
        $mime = 'image/png';
        if ($screenshotPath) {
            $fullPath = storage_path('app/public/' . ltrim($screenshotPath, '/'));
            if (file_exists($fullPath)) {
                $imageData = base64_encode(file_get_contents($fullPath));
                $mime = mime_content_type($fullPath) ?: 'image/png';
            }
        }

        // ── Priority: Gateway (free) → OpenAI (billing) → Groq (free if valid) ──

        // 1. OpenClaw Gateway
        if (!empty($this->gatewayToken)) {
            $result = $this->callGateway($prompt);
            if ($result['success']) {
                $this->log('info', 'Gateway code generation succeeded', [
                    'provider' => 'gateway',
                    'model' => 'minimax-m2.7',
                    'code_length' => strlen($result['code'] ?? ''),
                ]);
                return $result;
            }
            $this->log('warning', 'Gateway failed, trying next provider', [
                'provider' => 'gateway',
                'error' => $result['error'] ?? 'unknown',
                'error_code' => $result['error_code'] ?? 'UNKNOWN',
            ]);
        }

        // 2. OpenAI (billing required)
        if (!empty($this->apiKey) && str_starts_with($this->apiKey, 'sk-')) {
            $result = $this->callOpenAI($prompt, $imageData, $mime);
            if ($result['success']) {
                $this->log('info', 'OpenAI code generation succeeded', [
                    'provider' => 'openai',
                    'model' => self::MODEL,
                    'code_length' => strlen($result['code'] ?? ''),
                ]);
                return $result;
            }
            $this->log('warning', 'OpenAI failed', [
                'provider' => 'openai',
                'error' => $result['error'] ?? 'unknown',
                'error_code' => $result['error_code'] ?? 'UNKNOWN',
            ]);

            // On quota/billing error → try Groq
            if ($this->isQuotaError($result['error'] ?? '')) {
                $groqResult = $this->callGroq($prompt);
                if ($groqResult['success']) {
                    $this->log('info', 'Groq fallback succeeded', [
                        'provider' => 'groq',
                        'model' => 'llama-3.3-70b-versatile',
                        'code_length' => strlen($groqResult['code'] ?? ''),
                    ]);
                    return $groqResult;
                }
                $this->log('warning', 'Groq fallback also failed', [
                    'provider' => 'groq',
                    'error' => $groqResult['error'] ?? 'unknown',
                    'error_code' => $groqResult['error_code'] ?? 'UNKNOWN',
                ]);
            }
        }

        // 3. Groq directly
        if (!empty($this->groqApiKey) && (str_starts_with($this->groqApiKey, 'sk-') || str_starts_with($this->groqApiKey, 'gsk_'))) {
            $result = $this->callGroq($prompt);
            if ($result['success']) {
                $this->log('info', 'Groq code generation succeeded', [
                    'provider' => 'groq',
                    'model' => 'llama-3.3-70b-versatile',
                    'code_length' => strlen($result['code'] ?? ''),
                ]);
                return $result;
            }
            $this->log('warning', 'Groq failed', [
                'provider' => 'groq',
                'error' => $result['error'] ?? 'unknown',
                'error_code' => $result['error_code'] ?? 'UNKNOWN',
            ]);
        }

        $this->log('error', 'All code generation providers failed', [
            'has_gateway' => !empty($this->gatewayToken),
            'has_openai' => !empty($this->apiKey),
            'has_groq' => !empty($this->groqApiKey),
        ]);

        return [
            'success' => false,
            'error' => 'No code generation provider available.',
            'error_code' => 'NO_PROVIDER',
        ];
    }

    /**
     * Check which (if any) code generation provider is available.
     */
    public function availability(): array
    {
        // Gateway (OpenClaw/MiniMax) — free, always available if token set
        if (!empty($this->gatewayToken)) {
            return [
                'ok' => true,
                'provider' => 'gateway',
                'model' => 'minimax-m2.7',
                'description' => 'OpenClaw gateway (MiniMax-M2.7) — free, no billing required',
            ];
        }

        // OpenAI (billing required)
        if (!empty($this->apiKey) && str_starts_with($this->apiKey, 'sk-')) {
            return [
                'ok' => true,
                'provider' => 'openai',
                'model' => self::MODEL,
                'description' => 'GPT-4o for code generation (billing required)',
            ];
        }

        // Groq (free tier if valid key)
        if (!empty($this->groqApiKey) && (str_starts_with($this->groqApiKey, 'sk-') || str_starts_with($this->groqApiKey, 'gsk_'))) {
            return [
                'ok' => true,
                'provider' => 'groq',
                'model' => 'llama-3.3-70b-versatile',
                'description' => 'Groq Llama 3.3 70B — free tier',
            ];
        }

        return [
            'ok' => false,
            'provider' => null,
            'error' => 'No code generation provider configured. Set OPENCLAW_GATEWAY_TOKEN, OPENAI_API_KEY, or GROQ_API_KEY in .env.',
        ];
    }

    // ─── Private: Provider Calls ───────────────────────────────────────────

    private function callGateway(string $prompt): array
    {
        $body = [
            'model' => 'openclaw',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 200,
            'temperature' => 0.4,
        ];

        try {
            $ch = curl_init($this->gatewayUrl . '/v1/chat/completions');
            if (!$ch) {
                return ['success' => false, 'error' => 'Gateway: curl_init failed', 'error_code' => 'CURL_INIT_FAILED'];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->gatewayToken,
                ],
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);

            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                $this->log('error', 'Gateway curl error', [
                    'provider' => 'gateway',
                    'endpoint' => $this->gatewayUrl . '/v1/chat/completions',
                    'curl_error' => $curlErr,
                ]);
                return ['success' => false, 'error' => 'Gateway: ' . $curlErr, 'error_code' => 'CURL_ERROR'];
            }

            if ($httpCode !== 200) {
                $data = json_decode($resp, true) ?? [];
                $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
                $this->log('error', 'Gateway HTTP error', [
                    'provider' => 'gateway',
                    'endpoint' => $this->gatewayUrl . '/v1/chat/completions',
                    'http_code' => $httpCode,
                    'response' => substr($resp, 0, 500),
                ]);
                return ['success' => false, 'error' => "Gateway error: $msg", 'error_code' => 'GATEWAY_ERROR'];
            }

            $data = json_decode($resp, true);
            $content = trim($data['choices'][0]['message']['content'] ?? '');

            return $this->parseCodeResponse($content, 'gateway', 'minimax-m2.7');

        } catch (\Throwable $e) {
            $this->logException($e, 'gateway', [
                'endpoint' => $this->gatewayUrl . '/v1/chat/completions',
            ]);
            return ['success' => false, 'error' => 'Gateway exception: ' . $e->getMessage(), 'error_code' => 'EXCEPTION'];
        }
    }

    private function callOpenAI(string $prompt, ?string $imageData, string $mime): array
    {
        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => $prompt]]],
        ];

        if ($imageData) {
            $messages[0]['content'][] = [
                'type' => 'image_url',
                'image_url' => ['url' => "data:{$mime};base64,{$imageData}"],
            ];
        }

        $body = [
            'model' => self::MODEL,
            'messages' => $messages,
            'max_tokens' => 4000,
            'temperature' => 0.4,
        ];

        try {
            $ch = curl_init(self::BASE_URL . '/chat/completions');
            if (!$ch) {
                return ['success' => false, 'error' => 'OpenAI: curl_init failed', 'error_code' => 'CURL_INIT_FAILED'];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);

            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                $this->log('error', 'OpenAI curl error', [
                    'provider' => 'openai',
                    'endpoint' => self::BASE_URL . '/chat/completions',
                    'curl_error' => $curlErr,
                ]);
                return ['success' => false, 'error' => 'OpenAI connection error: ' . $curlErr];
            }

            if ($httpCode !== 200) {
                $data = json_decode($resp, true) ?? [];
                $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
                $this->log('error', 'OpenAI HTTP error', [
                    'provider' => 'openai',
                    'endpoint' => self::BASE_URL . '/chat/completions',
                    'http_code' => $httpCode,
                    'response' => substr($resp, 0, 500),
                ]);
                return [
                    'success' => false,
                    'error' => "OpenAI error: $msg",
                    'error_code' => (string) $httpCode,
                ];
            }

            $data = json_decode($resp, true);
            $content = trim($data['choices'][0]['message']['content'] ?? '');

            return $this->parseCodeResponse($content, 'openai', self::MODEL);

        } catch (\Throwable $e) {
            $this->logException($e, 'openai', [
                'endpoint' => self::BASE_URL . '/chat/completions',
            ]);
            return ['success' => false, 'error' => 'OpenAI exception: ' . $e->getMessage()];
        }
    }

    private function callGroq(string $prompt): array
    {
        $body = [
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 4000,
            'temperature' => 0.4,
        ];

        try {
            $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
            if (!$ch) {
                return ['success' => false, 'error' => 'Groq: curl_init failed', 'error_code' => 'CURL_INIT_FAILED'];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->groqApiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);

            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                $this->log('error', 'Groq curl error', [
                    'provider' => 'groq',
                    'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
                    'curl_error' => $curlErr,
                ]);
                return ['success' => false, 'error' => 'Groq connection error: ' . $curlErr];
            }

            if ($httpCode !== 200) {
                $data = json_decode($resp, true) ?? [];
                $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
                $this->log('error', 'Groq HTTP error', [
                    'provider' => 'groq',
                    'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
                    'http_code' => $httpCode,
                    'response' => substr($resp, 0, 500),
                ]);
                return [
                    'success' => false,
                    'error' => "Groq error: $msg",
                    'error_code' => (string) $httpCode,
                ];
            }

            $data = json_decode($resp, true);
            $content = trim($data['choices'][0]['message']['content'] ?? '');

            return $this->parseCodeResponse($content, 'groq', 'llama-3.3-70b-versatile');

        } catch (\Throwable $e) {
            $this->logException($e, 'groq', [
                'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
            ]);
            return ['success' => false, 'error' => 'Groq exception: ' . $e->getMessage()];
        }
    }

    // ─── Private: Parsing ─────────────────────────────────────────────────

    private function parseCodeResponse(string $content, string $provider, string $model): array
    {
        if (empty($content)) {
            $this->log('error', 'Empty response from provider', [
                'provider' => $provider,
                'model' => $model,
            ]);
            return [
                'success' => false,
                'error' => 'Provider returned an empty response.',
                'error_code' => 'EMPTY_RESPONSE',
            ];
        }

        // Strip markdown code fences
        $jsonStr = preg_replace('/^```json\s*/', '', $content);
        $jsonStr = preg_replace('/^```\s*/', '', $jsonStr);
        $jsonStr = preg_replace('/\s*```$/', '', $jsonStr);
        $jsonStr = trim($jsonStr);

        // Try direct JSON parse
        $parsed = json_decode($jsonStr, true);
        if (is_array($parsed) && !empty($parsed['componentCode'])) {
            return [
                'success' => true,
                'code' => $parsed['componentCode'],
                'supporting_code' => $parsed['supportingCode'] ?? $parsed['supporting_code'] ?? null,
                'summary' => $parsed['summary'] ?? 'React component generated.',
                'provider' => $provider,
                'model' => $model,
            ];
        }

        // Try extracting componentCode with simple regex (handles truncated JSON)
        if (preg_match('/"componentCode"\s*:\s*"([^"]*)"/s', $jsonStr, $codeMatch)) {
            $code = stripslashes($codeMatch[1]);
            $summary = 'React component (partial)';
            if (preg_match('/"summary"\s*:\s*"([^"]*)"/s', $jsonStr, $sumMatch)) {
                $summary = stripslashes($sumMatch[1]);
            }
            $this->log('warning', 'Used partial/truncated JSON parse', [
                'provider' => $provider,
                'model' => $model,
                'extracted_length' => strlen($code),
            ]);
            return [
                'success' => true,
                'code' => $code,
                'supporting_code' => null,
                'summary' => $summary,
                'provider' => $provider,
                'model' => $model,
                'truncated' => true,
            ];
        }

        // Try extracting function from non-JSON text
        if (preg_match('/function\s+\w+.*?export\s+default\s+\w+/s', $content, $fnMatch)) {
            $this->log('warning', 'Extracted function code from non-JSON response', [
                'provider' => $provider,
                'model' => $model,
            ]);
            return [
                'success' => true,
                'code' => $fnMatch[0],
                'supporting_code' => null,
                'summary' => 'React component extracted from text.',
                'provider' => $provider,
                'model' => $model,
            ];
        }

        $this->log('error', 'Could not parse code generation response', [
            'provider' => $provider,
            'model' => $model,
            'raw_content_preview' => substr($content, 0, 300),
        ]);

        return [
            'success' => false,
            'error' => 'Provider returned an unexpected response format.',
            'raw_content' => substr($content, 0, 500),
            'error_code' => 'PARSE_ERROR',
        ];
    }

    // ─── Private: Helpers ─────────────────────────────────────────────────

    private function isQuotaError(string $error): bool
    {
        $lower = strtolower($error);
        return str_contains($lower, 'quota')
            || str_contains($lower, 'hard limit')
            || str_contains($lower, 'billing')
            || str_contains($lower, 'exceeded')
            || str_contains($lower, 'insufficient');
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::$level("CodeGen: {$message}", array_merge([
            'service' => 'code_generation',
        ], $context));
    }

    private function logException(\Throwable $e, string $provider, array $extra = []): void
    {
        $this->log('error', "Exception in {$provider} provider", array_merge($extra, [
            'provider' => $provider,
            'exception_class' => get_class($e),
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
        ]));
    }

    private function buildCodePrompt(array $analysis, string $designStyle): string
    {
        $layout     = is_array($analysis['layout'] ?? null) ? implode("\n", $analysis['layout']) : '';
        $components = is_array($analysis['components'] ?? null) ? implode(', ', $analysis['components']) : '';
        $typography = is_array($analysis['typography'] ?? null) ? implode("\n", $analysis['typography']) : '';
        $colors     = is_array($analysis['colors'] ?? null) ? implode(', ', $analysis['colors']) : '';
        $issues     = is_array($analysis['issues'] ?? null) ? implode('; ', $analysis['issues']) : '';
        $mustKeep   = is_array($analysis['must_preserve'] ?? null)
            ? implode(', ', $analysis['must_preserve'])
            : 'layout, header, sidebar, navigation, all component positions';

        $styleSpec = $this->getStyleSpecification($designStyle);

        return <<<PROMPT
You are an expert React+Tailwind developer. Generate a React component of this UI.

Return STRICTLY this JSON format - no other text:
{"componentCode":"function App(){return <div>Hello</div>}","supportingCode":null,"summary":"A component"}

Keep all positions unchanged. Tailwind only. CDN imports allowed.
Style:
{$styleSpec}
PROMPT;
    }

    private function getStyleSpecification(string $designStyle): string
    {
        return match ($designStyle) {
            'modern_saas' => <<<'SPEC'
- Color palette: bg-white, text-gray-900, accent blue-600, subtle gray-50/100 borders
- Cards: bg-white rounded-2xl shadow-sm border border-gray-100
- Buttons: bg-blue-600 hover:bg-blue-700 text-white rounded-xl px-4 py-2
- Inputs: border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500
- Typography: text-sm/md/lg, font-semibold for headings, text-gray-500 for muted
- Sidebar: w-64 bg-white border-r border-gray-100
- Header: h-16 bg-white border-b border-gray-100
- Tables: thead bg-gray-50 text-gray-600 uppercase text-xs tracking-wide
- Shadows: shadow-sm for cards, shadow-lg for modals
- Radius: rounded-xl for cards, rounded-lg for buttons, rounded-full for avatars
SPEC,
            'minimal' => <<<'SPEC'
- Color palette: bg-white, text-gray-900, borders gray-200
- Cards: bg-white border border-gray-200 rounded-2xl shadow-none
- Buttons: border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50
- Inputs: border border-gray-200 rounded-lg
- Typography: text-xs/md/lg, font-medium, text-gray-700
- Sidebar: w-56 bg-white border-r border-gray-200
- Shadows: none — flat minimal aesthetic
- Radius: rounded-lg for everything
SPEC,
            'glassmorphism' => <<<'SPEC'
- Color palette: bg-white/10 backdrop-blur-xl, white/20 borders, gradient accents purple-500/blue-500
- Cards: bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl shadow-2xl
- Buttons: bg-gradient-to-r from-purple-600 to-blue-600 text-white rounded-xl
- Inputs: bg-white/10 backdrop-blur border border-white/20 rounded-xl
- Typography: text-white/90 for primary, white/60 for muted
- Sidebar: w-64 bg-white/5 backdrop-blur-xl border-r border-white/10
- Shadows: shadow-2xl with color glow effects
SPEC,
            'enterprise' => <<<'SPEC'
- Color palette: bg-slate-50, text-slate-900, blue-700 accent, borders slate-200
- Cards: bg-white border border-slate-200 rounded-lg shadow-sm
- Buttons: bg-blue-700 hover:bg-blue-800 text-white rounded-md px-4 py-2
- Inputs: border border-slate-300 rounded-md
- Typography: text-xs/sm/md, font-semibold for headings, text-slate-600 for body
- Sidebar: w-60 bg-slate-50 border-r border-slate-200
- Tables: thead bg-slate-50 border-b border-slate-200 text-slate-600
- Shadows: shadow-sm for cards
- Radius: rounded-md for everything
SPEC,
            'dark' => <<<'SPEC'
- Color palette: bg-[#0f0f14], surfaces bg-[#1a1a24], text white/90, accent cyan-400 or purple-500
- Cards: bg-[#1a1a24] border border-white/10 rounded-xl shadow-xl
- Buttons: bg-cyan-500 hover:bg-cyan-400 text-[#0f0f14] rounded-lg px-4 py-2
- Inputs: bg-[#252530] border border-white/10 rounded-lg text-white
- Typography: text-white/90 primary, text-white/50 muted
- Sidebar: w-64 bg-[#0f0f14] border-r border-white/10
- Tables: thead bg-[#1a1a24] text-white/60 border-b border-white/10
- Shadows: shadow-2xl with subtle glow
- Radius: rounded-lg for cards, rounded-md for buttons
SPEC,
            default => <<<'SPEC'
- Modern SaaS aesthetic with clean design
- Cards: bg-white rounded-xl shadow-sm
- Buttons: bg-blue-600 text-white rounded-lg
- Clean spacing and typography
SPEC,
        };
    }
}
