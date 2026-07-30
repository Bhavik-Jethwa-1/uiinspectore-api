<?php

namespace App\Http\Controllers\Api\AI;

use App\Http\Controllers\Controller;
use App\Models\AI\AIConversation;
use App\Models\AI\AIMessage;
use App\Models\AI\AIProvider;
use App\Services\AI\Providers\ProviderManager;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\{DB, Log};
use Illuminate\Support\Str;

class AIStudioController extends Controller
{
    public function __construct(
        private ProviderManager $providers,
    ) {}

    // ─── Conversations ─────────────────────────────────────────────────────────

    public function listConversations(Request $req): JsonResponse
    {
        $userId = $req->get('db_user')->id ?? $req->get('auth_user')['id'] ?? null;
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $q = AIConversation::where('user_id', $userId)
            ->where('is_archived', false)
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at');

        if ($req->q) {
            $q->where('title', 'like', '%' . $req->q . '%');
        }
        if ($req->folder) {
            $q->where('folder', $req->folder);
        }

        $conversations = $q->take(100)->get([
            'id', 'title', 'provider', 'model', 'folder',
            'is_pinned', 'is_archived', 'updated_at', 'created_at',
        ]);

        return response()->json(['success' => true, 'data' => $conversations]);
    }

    public function createConversation(Request $req): JsonResponse
    {
        $userId = $req->get('db_user')->id ?? $req->get('auth_user')['id'] ?? null;
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $convo = AIConversation::create([
            'user_id'      => $userId,
            'title'        => $req->title ?: 'New Chat',
            'provider'     => $req->provider ?: 'openai',
            'model'        => $req->model ?: '',
            'system_prompt'=> $req->system_prompt ?: null,
            'temperature'  => (float) ($req->temperature ?: 0.7),
            'max_tokens'   => (int) ($req->max_tokens ?: 4096),
            'folder'       => $req->folder ?: null,
        ]);

        return response()->json(['success' => true, 'data' => $convo], 201);
    }

    public function getConversation(Request $req, string $id): JsonResponse
    {
        $userId = $req->get('db_user')->id ?? $req->get('auth_user')['id'] ?? null;
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $convo = AIConversation::where('id', $id)->where('user_id', $userId)->first();
        if (!$convo) return response()->json(['error' => 'Not found'], 404);

        return response()->json(['success' => true, 'data' => $convo]);
    }

    public function updateConversation(Request $req, string $id): JsonResponse
    {
        $userId = $req->get('db_user')->id ?? $req->get('auth_user')['id'] ?? null;
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $convo = AIConversation::where('id', $id)->where('user_id', $userId)->first();
        if (!$convo) return response()->json(['error' => 'Not found'], 404);

        $fields = ['title', 'provider', 'model', 'system_prompt', 'temperature', 'max_tokens', 'folder'];
        foreach ($fields as $f) {
            if ($req->has($f)) {
                $convo->$f = $req->$f;
            }
        }
        if ($req->has('is_pinned'))    $convo->is_pinned   = (bool) $req->is_pinned;
        if ($req->has('is_archived'))  $convo->is_archived = (bool) $req->is_archived;
        $convo->save();

        return response()->json(['success' => true, 'data' => $convo]);
    }

    public function deleteConversation(Request $req, string $id): JsonResponse
    {
        $userId = $req->get('db_user')->id ?? $req->get('auth_user')['id'] ?? null;
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $convo = AIConversation::where('id', $id)->where('user_id', $userId)->first();
        if (!$convo) return response()->json(['error' => 'Not found'], 404);

        $convo->delete(); // cascades to messages

        return response()->json(['success' => true]);
    }

    public function clearHistory(Request $req): JsonResponse
    {
        $userId = $req->get('db_user')->id ?? $req->get('auth_user')['id'] ?? null;
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        AIConversation::where('user_id', $userId)->delete();

        return response()->json(['success' => true, 'deleted' => true]);
    }

    public function pinConversation(Request $req, string $id): JsonResponse
    {
        $userId = $req->get('db_user')->id ?? $req->get('auth_user')['id'] ?? null;
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $convo = AIConversation::where('id', $id)->where('user_id', $userId)->first();
        if (!$convo) return response()->json(['error' => 'Not found'], 404);

        $convo->is_pinned = !$convo->is_pinned;
        $convo->save();

        return response()->json(['success' => true, 'is_pinned' => $convo->is_pinned]);
    }

    // ─── Messages ────────────────────────────────────────────────────────────

    public function listMessages(Request $req, string $conversationId): JsonResponse
    {
        $userId = $req->get('db_user')->id ?? $req->get('auth_user')['id'] ?? null;
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $convo = AIConversation::where('id', $conversationId)->where('user_id', $userId)->first();
        if (!$convo) return response()->json(['error' => 'Not found'], 404);

        $messages = AIMessage::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->get(['id', 'role', 'content', 'attachments', 'metadata', 'created_at']);

        return response()->json(['success' => true, 'data' => $messages]);
    }

    public function sendMessage(Request $req): JsonResponse
    {
        $userId = $req->get('db_user')->id ?? $req->get('auth_user')['id'] ?? null;
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $providerName = $req->provider ?: 'openai';
        $model = $req->model ?: '';
        $conversationId = $req->conversation_id ?: null;

        // Get or create conversation
        $convo = null;
        if ($conversationId) {
            $convo = AIConversation::where('id', $conversationId)->where('user_id', $userId)->first();
        }

        if (!$convo) {
            $convo = AIConversation::create([
                'user_id'     => $userId,
                'title'       => Str::limit(strip_tags($req->message ?? 'New Chat'), 60),
                'provider'    => $providerName,
                'model'       => $model,
                'temperature' => (float) ($req->temperature ?: 0.7),
                'max_tokens'  => (int) ($req->max_tokens ?: 4096),
            ]);
        }

        // Build messages array for AI
        $history = $this->buildMessages($convo, $req->message, $req->attachments);

        // Call AI provider
        $startTime = microtime(true);
        $result = $this->callProvider($providerName, $model, $history, [
            'temperature' => (float) ($req->temperature ?: $convo->temperature),
            'max_tokens'  => (int) ($req->max_tokens ?: $convo->max_tokens),
            'stream'      => false,
        ]);
        $latencyMs = round((microtime(true) - $startTime) * 1000);

        // Save user message
        $userMsg = AIMessage::create([
            'conversation_id' => $convo->id,
            'user_id'        => $userId,
            'role'           => 'user',
            'content'        => $req->message,
            'attachments'    => $req->attachments ?: null,
            'created_at'     => now(),
        ]);

        // Save assistant response
        $assistantMeta = [
            'provider'     => $result['provider'] ?? $providerName,
            'model'        => $result['model'] ?? $model,
            'latency_ms'   => $latencyMs,
            'input_tokens'  => $result['usage']['input_tokens'] ?? null,
            'output_tokens' => $result['usage']['output_tokens'] ?? null,
            'total_tokens' => ($result['usage']['input_tokens'] ?? 0) + ($result['usage']['output_tokens'] ?? 0),
            'cost'         => $result['cost'] ?? null,
            'stopped'      => $result['stopped'] ?? true,
            'error'        => $result['error'] ?? null,
        ];

        $assistantMsg = AIMessage::create([
            'conversation_id' => $convo->id,
            'user_id'        => $userId,
            'role'           => 'assistant',
            'content'        => $result['reply'] ?? $result['error'] ?? 'No response',
            'metadata'       => $assistantMeta,
            'created_at'     => now(),
        ]);

        // Update conversation title if it's still "New Chat"
        if ($convo->title === 'New Chat' && !empty($req->message)) {
            $convo->title = Str::limit(strip_tags($req->message), 60);
        }
        $convo->provider = $providerName;
        $convo->model   = $model;
        $convo->save();

        return response()->json([
            'success' => empty($result['error']),
            'error'   => $result['error'] ?? null,
            'data'    => [
                'conversation' => $convo->only(['id', 'title', 'provider', 'model']),
                'user_message'  => $userMsg,
                'assistant_message' => $assistantMsg,
            ],
        ]);
    }

    public function streamMessage(Request $req): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $userId = $req->get('db_user')->id ?? $req->get('auth_user')['id'] ?? null;
        if (!$userId) {
            return response()->stream(function () {
                echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
            }, 401);
        }

        $providerName = $req->provider ?: 'openai';
        $model = $req->model ?: '';
        $conversationId = $req->conversation_id ?: null;

        // Get or create conversation
        $convo = null;
        if ($conversationId) {
            $convo = AIConversation::where('id', $conversationId)->where('user_id', $userId)->first();
        }

        if (!$convo) {
            $convo = AIConversation::create([
                'user_id'     => $userId,
                'title'       => Str::limit(strip_tags($req->message ?? 'New Chat'), 60),
                'provider'    => $providerName,
                'model'       => $model,
                'temperature' => (float) ($req->temperature ?: 0.7),
                'max_tokens'  => (int) ($req->max_tokens ?: 4096),
            ]);
        }

        // Save user message
        $userMsg = AIMessage::create([
            'conversation_id' => $convo->id,
            'user_id'        => $userId,
            'role'           => 'user',
            'content'        => $req->message,
            'attachments'    => $req->attachments ?: null,
            'created_at'     => now(),
        ]);

        $history = $this->buildMessages($convo, $req->message, $req->attachments);
        $startTime = microtime(true);
        $fullReply = '';

        return response()->stream(function () use ($req, $providerName, $model, $history, $convo, $userId, $userMsg, &$fullReply, $startTime) {
            $opts = [
                'temperature' => (float) ($req->temperature ?: $convo->temperature),
                'max_tokens'  => (int) ($req->max_tokens ?: $convo->max_tokens),
                'stream'      => true,
            ];

            $stream = $this->streamProvider($providerName, $model, $history, $opts);

            $buffer = '';
            foreach ($stream as $chunk) {
                if (isset($chunk['error'])) {
                    echo "data: " . json_encode(['error' => $chunk['error'], 'done' => true]) . "\n\n";
                    flush();
                    break;
                }

                if (isset($chunk['delta'])) {
                    $fullReply .= $chunk['delta'];
                    $buffer .= $chunk['delta'];
                    // Send immediately to client
                    echo "data: " . json_encode([
                        'delta'  => $chunk['delta'],
                        'buffer' => $buffer,
                        'done'   => false,
                    ]) . "\n\n";
                    flush();
                }

                if ($chunk['done'] ?? false) {
                    $latencyMs = round((microtime(true) - $startTime) * 1000);
                    // Save assistant message
                    $assistantMeta = [
                        'provider'      => $chunk['provider'] ?? $providerName,
                        'model'         => $chunk['model'] ?? $model,
                        'latency_ms'    => $latencyMs,
                        'input_tokens'  => $chunk['usage']['input_tokens'] ?? null,
                        'output_tokens' => $chunk['usage']['output_tokens'] ?? null,
                        'total_tokens'  => ($chunk['usage']['input_tokens'] ?? 0) + ($chunk['usage']['output_tokens'] ?? 0),
                        'cost'          => $chunk['cost'] ?? null,
                        'stopped'       => $chunk['stopped'] ?? true,
                    ];

                    $assistantMsg = AIMessage::create([
                        'conversation_id' => $convo->id,
                        'user_id'        => $userId,
                        'role'           => 'assistant',
                        'content'        => $fullReply,
                        'metadata'       => $assistantMeta,
                        'created_at'     => now(),
                    ]);

                    // Update conversation
                    $convo->provider = $providerName;
                    $convo->model   = $model;
                    if ($convo->title === 'New Chat') {
                        $convo->title = Str::limit(strip_tags($req->message ?? 'New Chat'), 60);
                    }
                    $convo->save();

                    echo "data: " . json_encode([
                        'done'    => true,
                        'message_id' => $assistantMsg->id,
                        'metadata'   => $assistantMeta,
                    ]) . "\n\n";
                    flush();
                }
            }
        }, 200, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // ─── Providers & Models ──────────────────────────────────────────────────

    public function listProviders(Request $req): JsonResponse
    {
        $dbProviders = AIProvider::where('enabled', true)
            ->orderBy('priority')
            ->get(['id', 'name', 'slug', 'type', 'base_url', 'models', 'health_status', 'priority']);

        // Also include providers from ProviderManager
        $managerProviders = $this->providers->listProviders();

        $result = [];
        foreach ($dbProviders as $p) {
            $result[] = [
                'id'          => $p->id,
                'name'        => $p->name,
                'slug'        => $p->slug,
                'type'        => $p->type,
                'base_url'    => $p->base_url,
                'models'      => $p->models ?: [],
                'is_healthy'  => $p->isHealthy(),
                'is_configured' => !empty($p->api_key) || in_array($p->slug, ['openrouter', 'groq']),
            ];
        }

        // If no DB providers, use manager providers
        if (empty($result)) {
            foreach ($managerProviders as $slug => $info) {
                $result[] = [
                    'id'          => $slug,
                    'name'        => $info['label'],
                    'slug'        => $slug,
                    'type'        => 'chat',
                    'base_url'    => null,
                    'models'      => $this->getModelsForProvider($slug),
                    'is_healthy'  => $info['available'],
                    'is_configured' => $info['available'],
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function listModels(Request $req, string $provider): JsonResponse
    {
        $dbProvider = AIProvider::where('slug', $provider)->where('enabled', true)->first();

        $models = [];
        if ($dbProvider && !empty($dbProvider->models)) {
            $models = $dbProvider->models;
        } else {
            $models = $this->getModelsForProvider($provider);
        }

        return response()->json(['success' => true, 'data' => $models]);
    }

    public function healthCheck(Request $req, string $provider): JsonResponse
    {
        $dbProvider = AIProvider::where('slug', $provider)->first();
        if (!$dbProvider) {
            // Use ProviderManager
            $mgrProvider = $this->providers->getProvider($provider);
            if (!$mgrProvider) {
                return response()->json(['error' => 'Provider not found'], 404);
            }
            $isHealthy = $mgrProvider->isAvailable();
            return response()->json([
                'success' => true,
                'data'    => ['provider' => $provider, 'healthy' => $isHealthy],
            ]);
        }

        // Simple health check: try to call the provider with a minimal request
        try {
            $result = $this->callProvider($provider, '', [[
                'role' => 'user',
                'content' => 'Hi',
            ]], ['max_tokens' => 2]);

            $isHealthy = empty($result['error']);
            $dbProvider->update([
                'health_status'      => $isHealthy ? 'healthy' : 'unhealthy',
                'health_checked_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            $isHealthy = false;
            $dbProvider->update([
                'health_status'     => 'unhealthy',
                'health_checked_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => ['provider' => $provider, 'healthy' => $isHealthy],
        ]);
    }

    // ─── Settings ────────────────────────────────────────────────────────────

    public function getUserSettings(Request $req): JsonResponse
    {
        $userId = $req->get('db_user')->id ?? $req->get('auth_user')['id'] ?? null;
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $settings = \App\Models\AI\AIProviderSetting::where('user_id', $userId)->first();
        $defaults = [
            'chat_provider'   => 'openai',
            'chat_model'      => 'gpt-4o',
            'image_provider'  => 'openai',
            'image_model'     => 'dall-e-3',
            'temperature'     => 0.7,
            'max_tokens'     => 4096,
            'streaming'      => true,
            'response_lang'  => 'en',
        ];

        if (!$settings) {
            return response()->json(['success' => true, 'data' => $defaults]);
        }

        return response()->json(['success' => true, 'data' => array_merge($defaults, [
            'chat_provider'  => $settings->chat_provider,
            'chat_model'     => $settings->chat_model,
            'image_provider' => $settings->image_provider,
            'image_model'    => $settings->image_model,
        ])]);
    }

    public function saveUserSettings(Request $req): JsonResponse
    {
        $userId = $req->get('db_user')->id ?? $req->get('auth_user')['id'] ?? null;
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $settings = \App\Models\AI\AIProviderSetting::updateOrCreate(
            ['user_id' => $userId],
            [
                'chat_provider'  => $req->chat_provider ?: 'openai',
                'chat_model'     => $req->chat_model ?: null,
                'image_provider' => $req->image_provider ?: 'openai',
                'image_model'    => $req->image_model ?: null,
            ]
        );

        return response()->json(['success' => true, 'data' => $settings]);
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private function buildMessages(AIConversation $convo, string $newUserMessage, ?array $attachments): array
    {
        $messages = [];

        // System prompt
        if (!empty($convo->system_prompt)) {
            $messages[] = ['role' => 'system', 'content' => $convo->system_prompt];
        }

        // Previous messages (last 20 to save tokens)
        $prevMessages = AIMessage::where('conversation_id', $convo->id)
            ->orderBy('created_at', 'asc')
            ->take(40)
            ->get(['role', 'content']);

        foreach ($prevMessages as $msg) {
            $messages[] = ['role' => $msg->role, 'content' => $msg->content];
        }

        // New user message
        $messages[] = ['role' => 'user', 'content' => $newUserMessage];

        return $messages;
    }

    private function callProvider(string $provider, string $model, array $messages, array $opts): array
    {
        try {
            $pm = \App\Services\AI\Providers\ProviderManager::getInstance();

            if (method_exists($pm, 'chat')) {
                $result = $pm->chat($messages, array_merge($opts, [
                    'provider' => $provider,
                    'model'    => $model,
                ]));
                return $result;
            }

            // Direct provider call
            $prov = $pm->getProvider($provider);
            if (!$prov || !$prov->isAvailable()) {
                // Try fallback
                $all = $pm->listProviders();
                foreach ($all as $name => $info) {
                    if ($info['available'] && $name !== $provider) {
                        $prov = $pm->getProvider($name);
                        if ($prov) {
                            $result = $prov->chat($messages, $opts);
                            $result['provider'] = $name;
                            return $result;
                        }
                    }
                }
                return ['error' => 'No AI provider available', 'reply' => ''];
            }

            return $prov->chat($messages, $opts);
        } catch (\Throwable $e) {
            Log::error('AIStudio: Provider error', ['provider' => $provider, 'error' => $e->getMessage()]);
            return ['error' => $e->getMessage(), 'reply' => ''];
        }
    }

    private function streamProvider(string $provider, string $model, array $messages, array $opts): \Generator
    {
        try {
            $pm = \App\Services\AI\Providers\ProviderManager::getInstance();

            if (method_exists($pm, 'streamChat')) {
                yield from $pm->streamChat($messages, array_merge($opts, [
                    'provider' => $provider,
                    'model'    => $model,
                ]));
                return;
            }

            // Fallback to non-streaming
            $result = $this->callProvider($provider, $model, $messages, $opts);
            if (!empty($result['error'])) {
                yield ['error' => $result['error'], 'done' => true];
                return;
            }

            $reply = $result['reply'] ?? '';
            foreach (mb_str_split($reply, 1) as $ch) {
                yield ['delta' => $ch, 'done' => false];
            }
            yield ['delta' => '', 'done' => true, 'reply' => $reply, 'provider' => $result['provider'] ?? $provider];
        } catch (\Throwable $e) {
            Log::error('AIStudio: Stream error', ['provider' => $provider, 'error' => $e->getMessage()]);
            yield ['error' => $e->getMessage(), 'done' => true];
        }
    }

    private function getModelsForProvider(string $provider): array
    {
        return match ($provider) {
            'openai'    => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo'],
            'anthropic' => ['claude-opus-4', 'claude-sonnet-4', 'claude-3-5-sonnet', 'claude-3-opus', 'claude-3-haiku'],
            'gemini'    => ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-1.5-pro', 'gemini-1.5-flash'],
            'deepseek'  => ['deepseek-chat', 'deepseek-coder'],
            'groq'      => ['llama-3.3-70b-versatile', 'mixtral-8x7b-32768', 'llama-3.1-8b-instant'],
            'minimax'   => ['MiniMax-M3', 'MiniMax-M2'],
            'mistral'   => ['mistral-large', 'mistral-small', 'mistral-medium'],
            'openrouter'=> ['openrouter/auto', 'openrouter/openai/gpt-4o', 'openrouter/anthropic/claude-sonnet'],
            'ollama'    => ['llama3', 'llama3.1', 'mistral', 'codellama'],
            'azure'     => ['gpt-4o', 'gpt-4-turbo', 'gpt-35-turbo'],
            default      => [],
        };
    }
}
