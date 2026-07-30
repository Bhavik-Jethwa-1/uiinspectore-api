<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\AIService;
use App\Services\AI\AIServiceLocator;
use App\Models\AIProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AIGatewayController — dedicated endpoints for each AI capability.
 *
 * Endpoints:
 *   POST /api/ai/chat       → ChatService     (streaming supported)
 *   POST /api/ai/analyze    → VisionService   (screenshot, UI, UX, a11y, etc.)
 *   POST /api/ai/image     → ImageGenerationService (HD, variations, sizes)
 *   POST /api/ai/code      → CodeGenerationService (react, nextjs, vue, etc.)
 *   GET  /api/ai/models    → Available models for each provider
 *   GET  /api/admin/ai/diagnostics → full diagnostic report
 */
class AIGatewayController extends Controller
{
    private AIService $ai;

    private const PROVIDER_API_URLS = [
        'openai'     => 'https://api.openai.com/v1/chat/completions',
        'anthropic'  => 'https://api.anthropic.com/v1/messages',
        'gemini'     => 'https://generativelanguage.googleapis.com/v1beta/models',
        'groq'       => 'https://api.groq.com/openai/v1/chat/completions',
        'xai'        => 'https://api.x.ai/v1/chat/completions',
        'deepseek'   => 'https://api.deepseek.com/chat/completions',
        'mistral'    => 'https://api.mistral.ai/v1/chat/completions',
        'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
    ];

    public function __construct()
    {
        $this->ai = AIServiceLocator::service();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CHAT  —  POST /api/ai/chat
    // ═══════════════════════════════════════════════════════════════════════

    public function chat(Request $request)
    {
        $messages = $request->input('messages', []);
        $opts     = $request->only(['model', 'temperature', 'max_tokens', 'top_p', 'stop', 'system', 'agent_id', 'provider']);
        $stream   = $request->boolean('stream', false);

        if (empty($messages)) {
            return response()->json(['error' => 'messages is required', 'status' => 400], 400);
        }

        // Inject default system prompt if none provided
        if (!isset($opts['system']) && !collect($messages)->contains('role', 'system')) {
            $opts['system'] = 'You are a helpful AI assistant. Be concise, accurate, and friendly. Use markdown formatting when helpful.';
        }

        // If agent_id is provided, route through the user's custom agent
        if (!empty($opts['agent_id'])) {
            return $this->chatViaAgent($opts['agent_id'], $messages, $opts, $stream);
        }

        if ($stream) {
            return $this->chatStream($messages, $opts);
        }

        $result = $this->ai->chatService()->complete($messages, $opts);

        if (!empty($result['error'])) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json($result);
    }

    /**
     * Route chat through a user's custom AI agent.
     */
    private function chatViaAgent(int $agentId, array $messages, array $opts, bool $stream)
    {
        $userId = $opts['_user_id'] ?? null;
        $agent  = AIProvider::where('id', $agentId)
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->where('is_enabled', true)
            ->first();

        if (!$agent) {
            return response()->json(['error' => 'Agent not found or disabled', 'status' => 404], 404);
        }

        $result = $this->callAgent($agent, $messages, $opts, $stream);

        if ($stream) {
            return $result; // streaming response already returned as SSE
        }

        if (!empty($result['error'])) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json(array_merge($result, [
            'agent_id'   => $agent->id,
            'agent_name' => $agent->name,
            'provider'   => $agent->provider,
            'model'     => $agent->default_model,
        ]));
    }

    private function callAgent(AIProvider $agent, array $messages, array $opts, bool $stream)
    {
        $apiKey  = $agent->api_key;
        $model   = $opts['model'] ?? null ?: $agent->default_model;
        $baseUrl = rtrim($agent->base_url ?: (self::PROVIDER_API_URLS[$agent->provider] ?? ''), '/');

        if (empty($apiKey)) {
            return ['error' => 'API key not configured for this agent', 'status' => 400];
        }

        try {
            return match ($agent->provider) {
                'openai',
                'groq',
                'xai',
                'deepseek',
                'mistral' => $this->chatOpenAICompat($apiKey, $baseUrl, $model, $messages, $opts, $stream),

                'anthropic' => $this->chatAnthropic($apiKey, $baseUrl, $model, $messages, $opts, $stream),

                'gemini' => $this->chatGemini($apiKey, $baseUrl, $model, $messages, $opts, $stream),

                'openrouter' => $this->chatOpenRouter($apiKey, $baseUrl, $model, $messages, $opts, $stream),

                'ollama' => $this->chatOllama($apiKey, $baseUrl, $model, $messages, $opts),

                default => ['error' => "Provider {$agent->provider} not yet supported for chat", 'status' => 501],
            };
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), 'status' => 500];
        }
    }

    // ─── OpenAI-compatible providers (Groq, xAI, DeepSeek, Mistral) ─────

    private function chatOpenAICompat(string $apiKey, string $baseUrl, string $model, array $messages, array $opts, bool $stream): array
    {
        $body = [
            'model'      => $model ?: 'gpt-4o',
            'messages'   => $messages,
            'max_tokens' => $opts['max_tokens'] ?? 2000,
            'stream'     => false,
        ];
        if (isset($opts['temperature'])) $body['temperature'] = (float) $opts['temperature'];
        if (isset($opts['top_p']))      $body['top_p']       = (float) $opts['top_p'];
        if (isset($opts['stop']))       $body['stop']        = $opts['stop'];
        if (isset($opts['system']))     array_unshift($body['messages'], ['role' => 'system', 'content' => $opts['system']]);

        $url = $baseUrl ?: 'https://api.openai.com/v1/chat/completions';

        $response = Http::withToken($apiKey)->timeout(60)->post($url, $body);

        if (!$response->successful()) {
            $err = $response->json();
            return ['error' => $err['error']['message'] ?? 'Request failed', 'status' => 502];
        }

        return $response->json();
    }

    // ─── Anthropic ────────────────────────────────────────────────────────

    private function chatAnthropic(string $apiKey, string $baseUrl, string $model, array $messages, array $opts, bool $stream): array
    {
        $systemMsg = $opts['system'] ?? null;
        $allMsgs   = $messages;
        if ($systemMsg) {
            array_unshift($allMsgs, ['role' => 'system', 'content' => $systemMsg]);
        }

        $url = $baseUrl ?: 'https://api.anthropic.com/v1/messages';

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(60)->post($url, [
            'model'      => $model ?: 'claude-sonnet-4-20250514',
            'messages'   => $allMsgs,
            'max_tokens' => $opts['max_tokens'] ?? 2000,
            'stream'     => false,
        ]);

        if (!$response->successful()) {
            $err = $response->json();
            return ['error' => $err['error']['message'] ?? 'Anthropic request failed', 'status' => 502];
        }

        $data = $response->json();
        // Normalize to OpenAI-style response
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $data['content'][0]['text'] ?? '']]],
        ];
    }

    // ─── Gemini ──────────────────────────────────────────────────────────

    private function chatGemini(string $apiKey, string $baseUrl, string $model, array $messages, array $opts, bool $stream): array
    {
        $contents = array_map(fn($m) => [
            'role'  => ($m['role'] ?? '') === 'assistant' ? 'model' : 'user',
            'parts'  => [['text' => $m['content'] ?? '']],
        ], array_filter($messages, fn($m) => ($m['role'] ?? '') !== 'system'));

        $modelSlug = ($model ?: 'gemini-2.0-flash') . ':generateContent';
        $url = ($baseUrl ?: 'https://generativelanguage.googleapis.com/v1beta/models') . "/{$modelSlug}?key={$apiKey}";

        $body = ['contents' => $contents];
        if ($opts['system'] ?? null) {
            $body['systemInstruction'] = ['parts' => [['text' => $opts['system']]]];
        }
        $body['generationConfig'] = [
            'maxOutputTokens' => $opts['max_tokens'] ?? 2000,
            'temperature'     => $opts['temperature'] ?? 0.7,
        ];

        $response = Http::timeout(60)->post($url, $body);

        if (!$response->successful()) {
            $err = $response->json();
            return ['error' => $err['error']['message'] ?? 'Gemini request failed', 'status' => 502];
        }

        $data = $response->json();
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]];
    }

    // ─── OpenRouter ───────────────────────────────────────────────────────

    private function chatOpenRouter(string $apiKey, string $baseUrl, string $model, array $messages, array $opts, bool $stream): array
    {
        $body = [
            'model'      => $model ?: 'openai/gpt-4o',
            'messages'   => $messages,
            'max_tokens' => $opts['max_tokens'] ?? 2000,
            'stream'     => false,
        ];
        if (isset($opts['temperature'])) $body['temperature'] = (float) $opts['temperature'];
        if (isset($opts['system']))     array_unshift($body['messages'], ['role' => 'system', 'content' => $opts['system']]);

        $url = $baseUrl ?: 'https://openrouter.ai/api/v1/chat/completions';

        $response = Http::withToken($apiKey)
            ->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => config('app.name')])
            ->timeout(60)
            ->post($url, $body);

        if (!$response->successful()) {
            $err = $response->json();
            return ['error' => $err['error']['message'] ?? 'OpenRouter request failed', 'status' => 502];
        }

        return $response->json();
    }

    // ─── Ollama ───────────────────────────────────────────────────────────

    private function chatOllama(string $apiKey, string $baseUrl, string $model, array $messages, array $opts): array
    {
        $url = ($baseUrl ?: 'http://localhost:11434') . '/api/chat';

        $response = Http::timeout(60)->post($url, [
            'model'    => $model ?: 'llama3.2',
            'messages' => $messages,
            'stream'   => false,
        ]);

        if (!$response->successful()) {
            $err = $response->json();
            return ['error' => $err['error'] ?? 'Ollama request failed', 'status' => 502];
        }

        $data = $response->json();
        $text = $data['message']['content'] ?? '';
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]];
    }

    private function chatStream(array $messages, array $opts)
    {
        $providerName = $this->ai->primaryProviderName();

        return response()->stream(function () use ($messages, $opts, $providerName) {
            foreach ($this->ai->chatService()->stream($messages, $opts) as $chunk) {
                $chunk['provider'] = $providerName;
                $chunk['model']    = $chunk['model'] ?? $this->ai->diagnostic()['chat_model'] ?? 'dynamic';
                echo "data: " . json_encode($chunk) . "\n\n";
                flush();
            }
        }, 200, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // VISION / ANALYZE  —  POST /api/ai/analyze
    // ═══════════════════════════════════════════════════════════════════════

    public function analyze(Request $request)
    {
        $imageUrl = $request->input('imageUrl', $request->input('image_url', $request->input('url')));
        $type     = $request->input('type', 'custom');
        $prompt   = $request->input('prompt');
        $opts     = $request->only(['max_tokens', 'model', 'temperature']);

        if (empty($imageUrl)) {
            return response()->json(['error' => 'imageUrl is required', 'status' => 400], 400);
        }

        $vision = $this->ai->visionService();

        $result = match ($type) {
            'screenshot'     => $vision->screenshotReview($imageUrl, array_merge($opts, ['prompt' => $prompt])),
            'ui'             => $vision->uiAnalysis($imageUrl, array_merge($opts, ['prompt' => $prompt])),
            'ux'             => $vision->uxAnalysis($imageUrl, array_merge($opts, ['prompt' => $prompt])),
            'accessibility'  => $vision->accessibilityReview($imageUrl, array_merge($opts, ['prompt' => $prompt])),
            'typography'     => $vision->typographyAnalysis($imageUrl, array_merge($opts, ['prompt' => $prompt])),
            'color'          => $vision->colorAnalysis($imageUrl, array_merge($opts, ['prompt' => $prompt])),
            default          => $vision->analyze($imageUrl, $prompt ?? 'Analyze this image in detail.', $opts),
        };

        if (!empty($result['error'])) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json($result);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // IMAGE GENERATION  —  POST /api/ai/image
    // ═══════════════════════════════════════════════════════════════════════

    public function image(Request $request)
    {
        $prompt = $request->input('prompt');
        $opts   = $request->only(['n', 'size', 'model', 'quality', 'style', 'max_tokens']);

        if (empty($prompt)) {
            return response()->json(['error' => 'prompt is required', 'status' => 400], 400);
        }

        $sizeMap = [
            'square'   => 'square', 'landscape' => 'landscape',
            'portrait' => 'portrait', 'hd'       => 'hd',
            '1:1'      => 'square',  '16:9'     => 'landscape',
            '9:16'     => 'portrait', '1024x1024' => 'square',
            '1792x1024' => 'landscape', '1024x1792' => 'portrait',
        ];
        if (isset($opts['size']) && isset($sizeMap[$opts['size']])) {
            $opts['size'] = $sizeMap[$opts['size']];
        }

        $result = $this->ai->imageService()->generate($prompt, $opts);

        if (!empty($result['error'])) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json($result);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CODE GENERATION  —  POST /api/ai/code
    // ═══════════════════════════════════════════════════════════════════════

    public function code(Request $request)
    {
        $messages = $request->input('messages', []);
        $opts     = $request->only(['language', 'model', 'temperature', 'max_tokens', 'top_p', 'stop', 'system']);

        if (empty($messages)) {
            return response()->json(['error' => 'messages is required', 'status' => 400], 400);
        }

        $result = $this->ai->codeService()->generate($messages, $opts);

        if (!empty($result['error'])) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json($result);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MODELS  —  GET /api/ai/models
    // ═══════════════════════════════════════════════════════════════════════

    public function models(Request $request)
    {
        // Cache OpenAI model list for 5 minutes — avoids a live API call on every request
        $openaiModels = \Illuminate\Support\Facades\Cache::remember('openai_models', 300, function () {
            return $this->fetchOpenAIModels();
        });

        $minimaxModels = [
            [
                'id'          => 'MiniMax-M3',
                'name'        => 'MiniMax-M3',
                'provider'    => 'minimax',
                'capabilities' => ['chat', 'vision', 'image', 'code'],
                'is_default'  => true,
            ],
        ];

        // Gemini models (hardcoded — no live API call needed)
        $geminiModels = [
            ['id' => 'gemini-2.0-flash', 'name' => 'Gemini 2.0 Flash', 'provider' => 'gemini', 'capabilities' => ['chat', 'vision', 'code'], 'is_default' => true],
            ['id' => 'gemini-1.5-flash', 'name' => 'Gemini 1.5 Flash', 'provider' => 'gemini', 'capabilities' => ['chat', 'vision', 'code'], 'is_default' => false],
            ['id' => 'gemini-1.5-pro',   'name' => 'Gemini 1.5 Pro',   'provider' => 'gemini', 'capabilities' => ['chat', 'vision', 'code'], 'is_default' => false],
        ];

        $primary = $this->ai->primaryProviderName();
        $preferred = match ($primary) {
            'openai'  => ['provider' => 'openai',  'model' => env('OPENAI_MODEL', 'gpt-4o')],
            'gemini'  => ['provider' => 'gemini',  'model' => 'gemini-2.0-flash'],
            'minimax' => ['provider' => 'minimax', 'model' => 'MiniMax-M3'],
            default   => ['provider' => 'minimax', 'model' => 'MiniMax-M3'],
        };

        $models = ['minimax' => $minimaxModels, 'gemini' => $geminiModels];
        if (!empty($openaiModels)) {
            $models['openai'] = $openaiModels;
        }

        return response()->json([
            'models'    => $models,
            'preferred' => $preferred,
            'source'   => 'live-api',
            'success'  => true,
        ]);
    }

    private function fetchOpenAIModels(): array
    {
        $apiKey = env('OPENAI_API_KEY', '');
        if (empty(trim($apiKey))) return [];

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($apiKey)->timeout(5)
                ->get('https://api.openai.com/v1/models');

            if (!$response->successful()) return [];

            $data = $response->json();
            $chatModels = array_filter(
                $data['data'] ?? [],
                fn($m) => str_starts_with($m['id'], 'gpt-') || str_starts_with($m['id'], 'o1')
            );

            return array_values(array_map(
                fn($m) => [
                    'id'            => $m['id'],
                    'name'          => $m['id'],
                    'provider'      => 'openai',
                    'capabilities'  => ['chat', 'vision', 'image', 'code'],
                    'is_default'    => $m['id'] === (env('OPENAI_MODEL') ?: 'gpt-4o'),
                ],
                $chatModels
            ));
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DIAGNOSTICS  —  GET /api/admin/ai/diagnostics
    // ═══════════════════════════════════════════════════════════════════════

    public function diagnostics(Request $request)
    {
        $diag = $this->ai->diagnostic();

        $capabilities = [];
        foreach ($diag['providers'] as $name => $info) {
            $capabilities[$name] = $info['capabilities'];
        }

        $report = [
            'primary_provider' => $diag['primary_provider'],
            'providers'       => $diag['providers'],
            'capabilities'    => $capabilities,
            'chat'   => ['endpoint' => $diag['chat_endpoint'] ?? null, 'model' => $diag['chat_model'] ?? null, 'available' => $this->ai->chatService()->isAvailable()],
            'vision' => ['endpoint' => $diag['vision_endpoint'] ?? null, 'model' => $diag['vision_model'] ?? null, 'available' => $this->ai->visionService()->isAvailable()],
            'image'  => ['endpoint' => $diag['image_endpoint'] ?? null, 'model' => $diag['image_model'] ?? null, 'available' => $this->ai->imageService()->isAvailable()],
            'code'   => ['endpoint' => $diag['code_endpoint'] ?? null, 'model' => $diag['code_model'] ?? null, 'available' => $this->ai->codeService()->isAvailable()],
            'health' => $this->ai->health(),
            'timestamp' => now()->toISOString(),
        ];

        return response()->json($report);
    }
}
