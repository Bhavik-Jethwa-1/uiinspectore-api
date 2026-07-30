<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AIProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * AIProvidersController — CRUD for user-owned AI provider agents.
 *
 * Users can add their own API keys for any AI provider (OpenAI, Gemini,
 * Groq, Anthropic, etc.) and switch between them in the chat UI.
 *
 * Routes:
 *   GET    /api/ai/agents         — list user's agents
 *   POST   /api/ai/agents         — create agent
 *   GET    /api/ai/agents/{id}    — get agent
 *   PUT    /api/ai/agents/{id}    — update agent
 *   DELETE /api/ai/agents/{id}    — delete agent
 *   POST   /api/ai/agents/{id}/default — set as default
 */
class AIProvidersController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════════
    // PROVIDER REGISTRY — known providers with defaults
    // ═══════════════════════════════════════════════════════════════════════

    private const PROVIDER_DEFAULTS = [
        'openai' => [
            'name'          => 'OpenAI',
            'api_url'       => 'https://api.openai.com/v1/chat/completions',
            'models_url'    => 'https://api.openai.com/v1/models',
            'image_url'     => 'https://api.openai.com/v1/images/generations',
            'default_model' => 'gpt-4o',
            'capabilities'  => ['chat', 'vision', 'image', 'code'],
            'models'        => ['gpt-4o', 'gpt-4o-mini', 'gpt-4o-1', 'o1-preview', 'o1-mini'],
        ],
        'anthropic' => [
            'name'          => 'Anthropic',
            'api_url'       => 'https://api.anthropic.com/v1/messages',
            'default_model' => 'claude-sonnet-4-20250514',
            'capabilities'  => ['chat', 'vision', 'code'],
            'models'        => ['claude-sonnet-4-20250514', 'claude-opus-4-5-20251120', 'claude-haiku-3-5-20250514'],
        ],
        'gemini' => [
            'name'          => 'Google Gemini',
            'api_url'       => 'https://generativelanguage.googleapis.com/v1beta/models',
            'default_model' => 'gemini-2.0-flash',
            'capabilities'  => ['chat', 'vision', 'image', 'code'],
            'models'        => ['gemini-2.0-flash', 'gemini-2.0-flash-exp', 'gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-2.5-pro-preview-06-05'],
        ],
        'groq' => [
            'name'          => 'Groq',
            'api_url'       => 'https://api.groq.com/openai/v1/chat/completions',
            'default_model' => 'llama-3.3-70b-versatile',
            'capabilities'  => ['chat', 'vision', 'code'],
            'models'        => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768', 'gemma2-9b-it'],
        ],
        'xai' => [
            'name'          => 'xAI',
            'api_url'       => 'https://api.x.ai/v1/chat/completions',
            'default_model' => 'grok-2-1212',
            'capabilities'  => ['chat', 'vision', 'code'],
            'models'        => ['grok-2-1212', 'grok-2-08-13', 'grok-beta'],
        ],
        'deepseek' => [
            'name'          => 'DeepSeek',
            'api_url'       => 'https://api.deepseek.com/chat/completions',
            'default_model' => 'deepseek-chat',
            'capabilities'  => ['chat', 'code'],
            'models'        => ['deepseek-chat', 'deepseek-coder'],
        ],
        'mistral' => [
            'name'          => 'Mistral AI',
            'api_url'       => 'https://api.mistral.ai/v1/chat/completions',
            'default_model' => 'mistral-small-latest',
            'capabilities'  => ['chat', 'vision', 'code'],
            'models'        => ['mistral-small-latest', 'mistral-medium-latest', 'mistral-large-latest', 'pixtral-large-2411'],
        ],
        'ollama' => [
            'name'          => 'Ollama',
            'api_url'       => 'http://localhost:11434/api/chat',
            'default_model' => 'llama3.2',
            'capabilities'  => ['chat', 'vision', 'code'],
            'models'        => ['llama3.2', 'llama3.1', 'mistral', 'codellama', 'qwen2.5'],
        ],
        'openrouter' => [
            'name'          => 'OpenRouter',
            'api_url'       => 'https://openrouter.ai/api/v1/chat/completions',
            'default_model' => 'openai/gpt-4o',
            'capabilities'  => ['chat', 'vision', 'image', 'code'],
            'models'        => [],
        ],
        'azure' => [
            'name'          => 'Azure OpenAI',
            'api_url'       => '',
            'default_model' => 'gpt-4o',
            'capabilities'  => ['chat', 'vision', 'image', 'code'],
            'models'        => [],
        ],
        'bedrock' => [
            'name'          => 'AWS Bedrock',
            'api_url'       => '',
            'default_model' => 'anthropic.claude-sonnet-4-20250514',
            'capabilities'  => ['chat', 'vision', 'code'],
            'models'        => [],
        ],
        'custom' => [
            'name'          => 'Custom Endpoint',
            'api_url'       => '',
            'default_model' => '',
            'capabilities'  => ['chat'],
            'models'        => [],
        ],
    ];

    // ═══════════════════════════════════════════════════════════════════════
    // READ
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /api/ai/agents/providers
     * Public — no auth required. Returns the provider registry.
     */
    public function providers(Request $request)
    {
        $providers = collect(self::PROVIDER_DEFAULTS)->map(fn($cfg, $slug) => [
            'slug'          => $slug,
            'name'          => $cfg['name'],
            'api_url'       => $cfg['api_url'],
            'default_model' => $cfg['default_model'],
            'capabilities'  => $cfg['capabilities'],
            'models'        => $cfg['models'],
        ]);
        return response()->json(['providers' => $providers]);
    }

    public function index(Request $request)
    {
        $userId = $request->auth_user['id'];

        $agents = AIProvider::where('user_id', $userId)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn($a) => $this->transform($a));

        $providers = collect(self::PROVIDER_DEFAULTS)->map(fn($cfg, $slug) => [
            'slug'          => $slug,
            'name'          => $cfg['name'],
            'api_url'       => $cfg['api_url'],
            'default_model' => $cfg['default_model'],
            'capabilities'  => $cfg['capabilities'],
            'models'        => $cfg['models'],
        ]);

        return response()->json([
            'agents'    => $agents,
            'providers' => $providers,
            'count'     => $agents->count(),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $userId = $request->auth_user['id'];
        $agent  = AIProvider::where('id', $id)->where('user_id', $userId)->first();

        if (!$agent) {
            return response()->json(['error' => 'Agent not found'], 404);
        }

        return response()->json(['agent' => $this->transform($agent)]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CREATE
    // ═══════════════════════════════════════════════════════════════════════

    public function store(Request $request)
    {
        $userId = $request->auth_user['id'];
        $data   = $request->validate([
            'name'          => 'required|string|max:100',
            'provider'      => 'required|string|in:' . implode(',', array_keys(self::PROVIDER_DEFAULTS)),
            'api_key'       => 'required|string|max:500',
            'base_url'      => 'nullable|string|max:300',
            'default_model' => 'nullable|string|max:100',
            'models'        => 'nullable|array',
            'capabilities'  => 'nullable|array',
            'is_enabled'    => 'nullable|boolean',
            'is_default'    => 'nullable|boolean',
        ]);

        // If setting as default, unset other defaults first
        if (!empty($data['is_default'])) {
            AIProvider::where('user_id', $userId)->update(['is_default' => false]);
        }

        // Auto-populate from provider registry if not provided
        $providerSlug = $data['provider'];
        $defaults     = self::PROVIDER_DEFAULTS[$providerSlug] ?? [];

        $agent = AIProvider::create([
            'user_id'       => $userId,
            'name'          => $data['name'],
            'provider'      => $providerSlug,
            'api_key'       => $data['api_key'],
            'base_url'      => $data['base_url']     ?? $defaults['api_url']     ?? null,
            'default_model' => $data['default_model'] ?? $defaults['default_model'] ?? null,
            'models'        => $data['models']        ?? ($defaults['models']    ?? null),
            'capabilities'  => $data['capabilities']  ?? ($defaults['capabilities'] ?? ['chat']),
            'is_enabled'    => $data['is_enabled']   ?? true,
            'is_default'    => $data['is_default']   ?? false,
            'sort_order'    => AIProvider::where('user_id', $userId)->max('sort_order') + 1,
        ]);

        return response()->json(['agent' => $this->transform($agent)], 201);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // UPDATE
    // ═══════════════════════════════════════════════════════════════════════

    public function update(Request $request, int $id)
    {
        $userId = $request->auth_user['id'];
        $agent  = AIProvider::where('id', $id)->where('user_id', $userId)->first();

        if (!$agent) {
            return response()->json(['error' => 'Agent not found'], 404);
        }

        $data = $request->validate([
            'name'          => 'nullable|string|max:100',
            'provider'      => 'nullable|string|in:' . implode(',', array_keys(self::PROVIDER_DEFAULTS)),
            'api_key'       => 'nullable|string|max:500',
            'base_url'      => 'nullable|string|max:300',
            'default_model' => 'nullable|string|max:100',
            'models'        => 'nullable|array',
            'capabilities'  => 'nullable|array',
            'is_enabled'    => 'nullable|boolean',
            'is_default'    => 'nullable|boolean',
        ]);

        if (!empty($data['is_default'])) {
            AIProvider::where('user_id', $userId)->update(['is_default' => false]);
        }

        $agent->fill(array_filter($data, fn($v) => $v !== null));
        $agent->save();

        return response()->json(['agent' => $this->transform($agent)]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DELETE
    // ═══════════════════════════════════════════════════════════════════════

    public function destroy(Request $request, int $id)
    {
        $userId = $request->auth_user['id'];
        $agent  = AIProvider::where('id', $id)->where('user_id', $userId)->first();

        if (!$agent) {
            return response()->json(['error' => 'Agent not found'], 404);
        }

        $agent->delete();
        return response()->json(['success' => true, 'deleted' => $id]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SET DEFAULT
    // ═══════════════════════════════════════════════════════════════════════

    public function setDefault(Request $request, int $id)
    {
        $userId = $request->auth_user['id'];
        $agent  = AIProvider::where('id', $id)->where('user_id', $userId)->first();

        if (!$agent) {
            return response()->json(['error' => 'Agent not found'], 404);
        }

        AIProvider::where('user_id', $userId)->update(['is_default' => false]);
        $agent->is_default = true;
        $agent->save();

        return response()->json(['agent' => $this->transform($agent)]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TEST CONNECTION
    // ═══════════════════════════════════════════════════════════════════════

    public function test(Request $request, int $id)
    {
        $userId = $request->auth_user['id'];
        $agent  = AIProvider::where('id', $id)->where('user_id', $userId)->first();

        if (!$agent) {
            return response()->json(['error' => 'Agent not found'], 404);
        }

        $result = $this->testConnection($agent);
        return response()->json($result);
    }

    public function testCreate(Request $request)
    {
        $data = $request->validate([
            'provider'  => 'required|string|in:' . implode(',', array_keys(self::PROVIDER_DEFAULTS)),
            'api_key'   => 'required|string|max:500',
            'base_url'  => 'nullable|string|max:300',
            'model'     => 'nullable|string',
        ]);

        $defaults = self::PROVIDER_DEFAULTS[$data['provider']] ?? [];

        $testPayload = [
            'provider' => $data['provider'],
            'api_key'  => $data['api_key'],
            'base_url' => $data['base_url'] ?? $defaults['api_url'] ?? null,
            'model'    => $data['model']    ?? $defaults['default_model'] ?? null,
        ];

        $result = $this->testProviderConnection($testPayload);
        return response()->json($result);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function transform(AIProvider $agent): array
    {
        return [
            'id'            => $agent->id,
            'name'          => $agent->name,
            'provider'      => $agent->provider,
            'provider_label'=> self::PROVIDER_DEFAULTS[$agent->provider]['name'] ?? $agent->provider,
            'api_key'       => $agent->masked_api_key,
            'base_url'      => $agent->base_url,
            'default_model' => $agent->default_model,
            'models'        => $agent->models ?? [],
            'capabilities'  => $agent->capabilities ?? [],
            'is_enabled'    => $agent->is_enabled,
            'is_default'    => $agent->is_default,
            'sort_order'    => $agent->sort_order,
            'created_at'    => $agent->created_at?->toISOString(),
            'updated_at'    => $agent->updated_at?->toISOString(),
        ];
    }

    private function testConnection(AIProvider $agent): array
    {
        return $this->testProviderConnection([
            'provider' => $agent->provider,
            'api_key'  => $agent->api_key,
            'base_url' => $agent->base_url,
            'model'    => $agent->default_model,
        ]);
    }

    private function testProviderConnection(array $cfg): array
    {
        $provider = $cfg['provider'];
        $apiKey   = $cfg['api_key'];
        $baseUrl  = $cfg['base_url']  ?? self::PROVIDER_DEFAULTS[$provider]['api_url'] ?? '';
        $model    = $cfg['model']     ?? self::PROVIDER_DEFAULTS[$provider]['default_model'] ?? '';

        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'API key is required'];
        }

        try {
            return match ($provider) {
                'openai'     => $this->testOpenAI($apiKey, $model),
                'anthropic'  => $this->testAnthropic($apiKey, $baseUrl, $model),
                'gemini'     => $this->testGemini($apiKey, $baseUrl, $model),
                'groq'       => $this->testGroq($apiKey, $model),
                'xai'        => $this->testXAI($apiKey, $model),
                'deepseek'   => $this->testDeepSeek($apiKey, $model),
                'mistral'    => $this->testMistral($apiKey, $model),
                'ollama'     => $this->testOllama($apiKey, $baseUrl, $model),
                'openrouter' => $this->testOpenRouter($apiKey, $model),
                default      => ['success' => false, 'error' => "Provider {$provider} not supported for testing yet"],
            };
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function testOpenAI(string $apiKey, string $model): array
    {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(10)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'    => $model ?: 'gpt-4o',
                'messages'  => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 5,
            ]);

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connected to OpenAI'];
        }
        return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'Request failed'];
    }

    private function testAnthropic(string $apiKey, string $baseUrl, string $model): array
    {
        $url = ($baseUrl ?: 'https://api.anthropic.com') . '/v1/messages';
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(10)->post($url, [
            'model'      => $model ?: 'claude-sonnet-4-20250514',
            'messages'   => [['role' => 'user', 'content' => 'ping']],
            'max_tokens' => 5,
        ]);

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connected to Anthropic'];
        }
        return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'Request failed'];
    }

    private function testGemini(string $apiKey, string $baseUrl, string $model): array
    {
        $modelSlug = ($model ?: 'gemini-2.0-flash') . ':generateContent';
        $url = ($baseUrl ?: 'https://generativelanguage.googleapis.com/v1beta/models') . "/{$modelSlug}?key={$apiKey}";
        $response = \Illuminate\Support\Facades\Http::timeout(10)->post($url, [
            'contents' => [['parts' => [['text' => 'ping']]]],
            'generationConfig' => ['maxOutputTokens' => 5],
        ]);

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connected to Gemini'];
        }
        return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'Request failed'];
    }

    private function testGroq(string $apiKey, string $model): array
    {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(10)
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'    => $model ?: 'llama-3.3-70b-versatile',
                'messages'  => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 5,
            ]);

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connected to Groq'];
        }
        return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'Request failed'];
    }

    private function testXAI(string $apiKey, string $model): array
    {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(10)
            ->post('https://api.x.ai/v1/chat/completions', [
                'model'    => $model ?: 'grok-2-1212',
                'messages'  => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 5,
            ]);

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connected to xAI'];
        }
        return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'Request failed'];
    }

    private function testDeepSeek(string $apiKey, string $model): array
    {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(10)
            ->post('https://api.deepseek.com/chat/completions', [
                'model'    => $model ?: 'deepseek-chat',
                'messages'  => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 5,
            ]);

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connected to DeepSeek'];
        }
        return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'Request failed'];
    }

    private function testMistral(string $apiKey, string $model): array
    {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(10)
            ->post('https://api.mistral.ai/v1/chat/completions', [
                'model'    => $model ?: 'mistral-small-latest',
                'messages'  => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 5,
            ]);

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connected to Mistral'];
        }
        return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'Request failed'];
    }

    private function testOllama(string $apiKey, string $baseUrl, string $model): array
    {
        $url = ($baseUrl ?: 'http://localhost:11434') . '/api/chat';
        $response = \Illuminate\Support\Facades\Http::timeout(10)->post($url, [
            'model'    => $model ?: 'llama3.2',
            'messages'  => [['role' => 'user', 'content' => 'ping']],
            'stream'   => false,
        ]);

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connected to Ollama'];
        }
        return ['success' => false, 'error' => $response->json()['error'] ?? 'Request failed'];
    }

    private function testOpenRouter(string $apiKey, string $model): array
    {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(10)
            ->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => config('app.name')])
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model'    => $model ?: 'openai/gpt-4o',
                'messages'  => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 5,
            ]);

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connected to OpenRouter'];
        }
        return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'Request failed'];
    }
}
