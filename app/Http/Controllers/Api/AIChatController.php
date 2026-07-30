<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\AIEngine;
use App\Services\AI\AIService;
use App\Services\AI\AIServiceLocator;
use App\Services\Billing\WalletService;
use App\Services\Billing\AIUsageService;
use App\Services\Billing\BillingService;
use App\Services\Billing\BillingServiceLocator;
use App\Services\FileAttachmentService;
use App\Services\AccessControlService;
use App\Models\AIPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AIChatController — AI entry point.
 *
 * Routes ALL AI calls through AIService → ProviderManager → Active Provider.
 * Both streaming and non-streaming chat go through the unified AIService facade.
 * Supports file attachments: multipart files[] or JSON base64 attachments[]
 * Uses singleton services for reduced instantiation overhead.
 *
 * Access control (per feature):
 *   1. Subscription check — is feature in user's plan?
 *   2. Usage limit check — is user within monthly limit?
 *   3. Wallet check — does user have sufficient USD balance?
 */
class AIChatController extends Controller
{
    private AIService $service;
    private WalletService $wallet;
    private AIUsageService $aiUsage;
    private AccessControlService $access;
    private FileAttachmentService $fileService;

    public function __construct()
    {
        $this->service = AIServiceLocator::service();
        $this->wallet = BillingServiceLocator::wallet();
        $this->aiUsage = BillingServiceLocator::aiUsage();
        $this->access = new AccessControlService(
            BillingServiceLocator::billing(),
            $this->wallet,
            $this->aiUsage,
        );
        $this->fileService = new FileAttachmentService();
    }

    // ─── POST /api/ai/chat ─────────────────────────────────────────────────────
    /**
     * Single-provider non-streaming chat.
     *
     * Accepts: {messages: [...], model?, max_tokens?, temperature?, top_p?, stop?}
     * Returns: {reply, model, provider, usage?, finish_reason?}
     */
    public function chat(Request $request)
    {
        $messages = $request->input('messages', []);
        if (empty($messages) && $request->filled('message')) {
            $messages = [['role' => 'user', 'content' => (string) $request->input('message')]];
        }
        if (empty($messages)) {
            return response()->json(['error' => 'No messages provided'], 400);
        }

        // Process file attachments (multipart or base64 JSON)
        $attachments = $this->fileService->processAttachments($request);
        if (!empty($attachments)) {
            $messages = $this->fileService->injectIntoMessages($messages, $attachments);
        }

        $opts = [
            'model'       => $request->input('model'),
            'provider'    => $request->input('provider'),
            'max_tokens'  => $request->input('max_tokens', 2000),
            'temperature' => $request->input('temperature', 0.7),
            'top_p'       => $request->input('top_p'),
            'stop'        => $request->input('stop'),
            'system'      => "You are a friendly, knowledgeable AI assistant. You're helpful, conversational, and genuinely enjoy helping users solve problems. Reply in a natural, conversational tone — like you're chatting with a friend who happens to be an expert. Be direct and practical, not stiff or overly formal. When providing code or technical info, be clear and concise. When giving opinions or suggestions, be confident but not arrogant. Use casual connective phrases naturally — 'Sure thing!', 'Here's what I'd do...', 'No problem!', etc. Don't pad your answers or state the obvious. Just get to the point and be genuinely useful.",
        ];

        // ── Access Control ───────────────────────────────────────────────────
        $userId = $request->get('auth_user')['id'];
        $authUser = $request->get('auth_user');
        $dbUser = $request->user();
        $providerName = $opts['provider'] ?? $this->service->primaryProviderName() ?? 'minimax';
        $modelName = $opts['model'] ?? 'MiniMax-M3';
        $pricingFeature = 'chat'; // AI pricing table feature key
        $planFeature = 'ai_chat'; // Plan feature name for subscription gate

        // Estimate cost (rough: 10 tokens per word, avg 5 chars/word = 50 chars input)
        $inputText = is_array($messages) ? implode(' ', array_column($messages, 'content')) : '';
        $estInputTokens = (int) (strlen($inputText) / 4);
        $estOutputTokens = (int) ($opts['max_tokens'] ?? 2000);
        $estimatedCost = AIPricing::getCost($providerName, $modelName, $pricingFeature, $estInputTokens, $estOutputTokens);

        // STEP 1: Subscription feature access
        $access = $this->access->canUseAI($dbUser, $providerName, $modelName, $planFeature, $estInputTokens, $estOutputTokens);
        if (!$access['allowed']) {
            if (($access['recharge_required'] ?? false)) {
                // Wallet issue — user has subscription but no wallet balance
                return response()->json([
                    'error' => 'insufficient_balance',
                    'message' => $access['message'],
                    'wallet_balance' => $access['available_balance'],
                    'required' => $access['cost'],
                    'shortage' => $access['shortage'],
                    'is_low_balance' => $access['is_low_balance'],
                    'recharge_url' => '/app/billing?section=wallet',
                    'code' => 'insufficient_wallet_balance',
                ], 402);
            }
            // Subscription/plan issue
            return response()->json([
                'error' => 'feature_not_available',
                'message' => $access['message'],
                'current_plan' => $access['current_plan'] ?? null,
                'upgrade_required' => true,
                'upgrade_url' => '/app/pricing',
                'code' => $access['reason'],
            ], 403);
        }

        // ── Execute AI ───────────────────────────────────────────────────────
        $result = $this->service->chat($messages, $opts);

        if (isset($result['error'])) {
            $status = $result['status'] ?? 500;
            return response()->json([
                'error'    => $result['error'],
                'provider' => $result['provider'] ?? null,
                'model'    => $result['model'] ?? null,
            ], $status);
        }

        // ── Record Usage & Deduct Wallet ──────────────────────────────────
        if (isset($result['usage']) && $estimatedCost > 0) {
            $actualInput = $result['usage']['prompt_tokens'] ?? $estInputTokens;
            $actualOutput = $result['usage']['completion_tokens'] ?? 0;
            $actualCost = AIPricing::getCost($providerName, $modelName, $pricingFeature, $actualInput, $actualOutput);

            $session = $this->aiUsage->startSession($userId, $providerName, $modelName, $pricingFeature, $actualInput, $actualOutput);
            if ($session[0] && $session[2]) {
                $this->aiUsage->confirmSession($userId, $session[2]->request_id, $actualInput, $actualOutput);
            }
        }

        $result['wallet_balance'] = $this->wallet->getWalletInfo($userId)['wallet']['available_balance'] ?? 0;
        return response()->json($result);
    }

    // ─── POST /api/ai/stream (SSE) ─────────────────────────────────────
    /**
     * Single-provider streaming chat.
     *
     * Accepts: {messages: [...], model?, max_tokens?, temperature?, top_p?, stop?}
     * Returns: SSE stream of {delta, done, reply?, model, provider}
     */
    public function stream(Request $request)
    {
        $messages = $request->input('messages', []);
        if (empty($messages) && $request->filled('message')) {
            $messages = [['role' => 'user', 'content' => (string) $request->input('message')]];
        }
        if (empty($messages)) {
            return response()->json(['error' => 'No messages provided'], 400);
        }

        // Process file attachments (multipart or base64 JSON)
        $attachments = $this->fileService->processAttachments($request);
        if (!empty($attachments)) {
            $messages = $this->fileService->injectIntoMessages($messages, $attachments);
        }

        $opts = [
            'model'       => $request->input('model'),
            'provider'    => $request->input('provider'),
            'max_tokens'  => $request->input('max_tokens', 2000),
            'temperature' => $request->input('temperature', 0.7),
            'top_p'       => $request->input('top_p'),
            'stop'        => $request->input('stop'),
            'system'      => "You are a friendly, knowledgeable AI assistant. You're helpful, conversational, and genuinely enjoy helping users solve problems. Reply in a natural, conversational tone — like you're chatting with a friend who happens to be an expert. Be direct and practical, not stiff or overly formal. When providing code or technical info, be clear and concise. If giving opinions or suggestions, be confident but not arrogant. Use casual connective phrases naturally — 'Sure thing!', 'Here's what I'd do...', 'No problem!', etc. Don't pad your answers or state the obvious. Just get to the point and be genuinely useful.",
        ];

        // ── User identity ──────────────────────────────────────────────────
        $authUser = $request->get('auth_user');
        $userName = $request->input('user_name') ?? ($authUser['name'] ?? null);
        if ($userName) {
            $opts['system'] = "The user's name is {$userName}. " . $opts['system'];
        }

        // ── Access Control ───────────────────────────────────────────────────
        $userId = $authUser['id'];
        $dbUser = $request->user();
        $providerName = $opts['provider'] ?? $this->service->primaryProviderName() ?? 'minimax';
        $modelName = $opts['model'] ?? 'MiniMax-M3';
        $pricingFeature = 'chat'; // AI pricing table feature key
        $planFeature = 'ai_chat'; // Plan feature name for subscription gate
        $inputText = is_array($messages) ? implode(' ', array_column($messages, 'content')) : '';
        $estInputTokens = (int) (strlen($inputText) / 4);
        $estOutputTokens = (int) ($opts['max_tokens'] ?? 2000);
        $estimatedCost = AIPricing::getCost($providerName, $modelName, $pricingFeature, $estInputTokens, $estOutputTokens);

        // STEP 1: Subscription feature access + STEP 2: Wallet check
        $access = $this->access->canUseAI($dbUser, $providerName, $modelName, $planFeature, $estInputTokens, $estOutputTokens);
        if (!$access['allowed']) {
            if (($access['recharge_required'] ?? false)) {
                return response()->json([
                    'error' => 'insufficient_balance',
                    'message' => $access['message'],
                    'wallet_balance' => $access['available_balance'],
                    'required' => $access['cost'],
                    'shortage' => $access['shortage'],
                    'is_low_balance' => $access['is_low_balance'],
                    'recharge_url' => '/app/billing?section=wallet',
                    'code' => 'insufficient_wallet_balance',
                ], 402);
            }
            return response()->json([
                'error' => 'feature_not_available',
                'message' => $access['message'],
                'current_plan' => $access['current_plan'] ?? null,
                'upgrade_required' => true,
                'upgrade_url' => '/app/pricing',
                'code' => $access['reason'],
            ], 403);
        }

        return response()->stream(function () use ($messages, $opts, $userId, $providerName, $modelName, $pricingFeature, $estInputTokens, $estimatedCost) {
            $fullReply = '';
            $providerName = $this->service->primaryProviderName();

            foreach ($this->service->streamChat($messages, $opts) as $chunk) {
                if (connection_aborted()) {
                    break;
                }

                if (isset($chunk['error'])) {
                    echo "data: " . json_encode(['error' => $chunk['error'], 'done' => true]) . "\n\n";
                    flush();
                    break;
                }

                $delta = $chunk['delta'] ?? '';
                $done  = $chunk['done']  ?? false;

                if ($delta !== '') {
                    $fullReply .= $delta;
                    echo "data: " . json_encode([
                        'delta'    => $delta,
                        'done'     => false,
                        'model'    => $opts['model'] ?? $providerName ?? 'minimax',
                        'provider' => $providerName,
                    ]) . "\n\n";
                    flush();
                }

                if ($done) {
                    echo "data: " . json_encode([
                        'delta'    => '',
                        'done'     => true,
                        'reply'    => $fullReply,
                        'model'    => $opts['model'] ?? $providerName ?? 'minimax',
                        'provider' => $providerName,
                    ]) . "\n\n";
                    flush();
                    break;
                }

                if (strlen($fullReply) > 50000) {
                    echo "data: " . json_encode(['done' => true, 'reply' => $fullReply]) . "\n\n";
                    flush();
                    break;
                }
            }

            echo "data: [DONE]\n\n";
            flush();

            // ── Record usage after stream ──────────────────────────────────────
            if ($estimatedCost > 0) {
                $outputTokens = (int) (strlen($fullReply) / 4);
                $actualCost = AIPricing::getCost($providerName, $modelName, $pricingFeature, $estInputTokens, $outputTokens);
                $session = $this->aiUsage->startSession($userId, $providerName, $modelName, $pricingFeature, $estInputTokens, $outputTokens);
                if ($session[0] && $session[2]) {
                    $this->aiUsage->confirmSession($userId, $session[2]->request_id, $estInputTokens, $outputTokens);
                }
            }
        }, 200, [
            'Content-Type'        => 'text/event-stream',
            'Cache-Control'       => 'no-cache',
            'Connection'          => 'keep-alive',
            'X-Accel-Buffering'   => 'no',
        ]);
    }

    // ─── GET /api/ai/providers ───────────────────────────────────────────────
    /**
     * Returns all registered providers from ProviderManager.
     */
    public function providers(Request $request)
    {
        $list = $this->service->manager()->listProviders();
        $out  = [];
        foreach ($list as $name => $info) {
            $out[] = [
                'slug'         => $name,
                'name'         => $info['label'],
                'available'    => $info['available'],
                'isPrimary'    => $info['isPrimary'],
                'model'        => $info['model'],
                'capabilities' => $info['capabilities'] ?? ['chat', 'image', 'vision'],
                'is_unified'   => $info['isPrimary'],
            ];
        }

        Log::debug('AI_PROVIDERS_LIST', ['count' => count($out), 'primary' => $this->service->primaryProviderName()]);

        return response()->json([
            'providers' => $out,
            'primary'   => $this->service->primaryProviderName(),
        ]);
    }

    // ─── POST /api/ai/health ────────────────────────────────────────────────
    /**
     * Returns the AIService health (chat + image provider status).
     */
    public function health(Request $request)
    {
        $mgr = $this->service->manager();
        $providers = $mgr->listProviders();
        $status = ['status' => 'healthy', 'providers' => [], 'primary' => $this->service->primaryProviderName()];

        foreach ($providers as $name => $info) {
            $status['providers'][$name] = [
                'available' => $info['available'],
                'isPrimary' => $info['isPrimary'],
                'model'     => $info['model'],
            ];
            if ($info['isPrimary'] && empty($info['available'])) {
                $status['status'] = 'degraded';
            }
        }

        if (!$this->service->anyAvailable()) {
            $status['status'] = 'unhealthy';
        }

        return response()->json($status);
    }

    // ─── GET /api/ai/settings ───────────────────────────────────────────────
    /**
     * Settings — single provider, no per-user keys.
     */
    public function getSettings(Request $request)
    {
        $settings = $this->service->getSettings();
        $providers = $this->service->listProviders();
        return response()->json([
            'primary_provider' => $settings['primary'] ?? 'minimax',
            'providers'        => $providers,
            'provider_keys'     => (object) [],
        ]);
    }

    // ─── POST /api/ai/settings ─────────────────────────────────────────────
    /**
     * Settings save — accepted for backward compatibility but ignored.
     * No per-user provider keys are stored.
     */
    public function saveSettings(Request $request)
    {
        $this->service->updateSettings($request->only(['primary', 'openai', 'minimax']));
        return response()->json(['success' => true, 'note' => 'Settings updated']);
    }

    // ─── POST /api/ai/image ───────────────────────────────────────────────────────
    /**
     * Image generation — routes through AIService (multi-provider).
     */
    public function image(Request $request)
    {
        $requestId = uniqid('img_', true);
        $startTime = microtime(true);

        $prompt = trim((string) $request->input('prompt', ''));
        $size   = $request->input('size', '1024x1024');
        $n      = (int) $request->input('n', 1);

        if (!$prompt) {
            return response()->json([
                'success'    => false,
                'error'      => 'Prompt is required',
                'request_id' => $requestId,
            ], 400);
        }

        if ($n < 1 || $n > 4) {
            return response()->json([
                'success'    => false,
                'error'      => 'n must be between 1 and 4',
                'request_id' => $requestId,
            ], 400);
        }

        $result = $this->service->image($prompt, [
            'size'    => $size,
            'n'       => $n,
            'timeout' => (int) $request->input('timeout', 120),
        ]);

        if (isset($result['error'])) {
            $duration = round((microtime(true) - $startTime) * 1000, 1);
            Log::warning('IMAGE_FAILED', [
                'request_id'  => $requestId,
                'provider'    => $result['provider'] ?? null,
                'model'       => $result['model'] ?? null,
                'size'        => $size,
                'error'       => $result['error'],
                'duration_ms' => $duration,
            ]);

            return response()->json([
                'success'    => false,
                'provider'   => $result['provider'] ?? null,
                'model'      => $result['model'] ?? null,
                'error'      => $result['error'],
                'request_id' => $requestId,
            ], $result['status'] ?? 500);
        }

        $duration = round((microtime(true) - $startTime) * 1000, 1);
        Log::info('IMAGE_OK', [
            'request_id'  => $requestId,
            'provider'    => $result['provider'] ?? null,
            'model'       => $result['model'] ?? null,
            'size'        => $size,
            'count'       => count($result['images'] ?? []),
            'duration_ms' => $duration,
        ]);

        return response()->json([
            'success'    => true,
            'provider'   => $result['provider'] ?? null,
            'model'      => $result['model'] ?? null,
            'images'     => $result['images'] ?? [],
            'prompt'     => $prompt,
            'size'       => $size,
            'request_id' => $requestId,
            'duration_ms'=> $duration,
        ]);
    }

    // ─── POST /api/ai/engine ───────────────────────────────────────────────────────
    /**
     * Unified AI Engine endpoint.
     *
     * POST /api/ai/engine
     *
     * Body: {type?, prompt?, messages?, image_url?, model?, ...}
     * Response: {success, type, provider, request_id, duration_ms, ...}
     */
    public function engine(Request $request)
    {
        $startTime = microtime(true);
        $requestId = uniqid('eng_', true);

        $engine = AIServiceLocator::engine();

        $result = $engine->execute($request->all());

        $duration = round((microtime(true) - $startTime) * 1000, 1);
        $result['duration_ms'] = $duration;

        if (!empty($result['success'])) {
            return response()->json($result, 200);
        }

        return response()->json($result, 400);
    }

    // ─── POST /api/ai/engine/stream ──────────────────────────────────────────────
    /**
     * Streaming version of the AI Engine.
     *
     * POST /api/ai/engine/stream
     * Body: {messages: [...], type?, model?, ...}
     * Response: SSE stream of {delta, done, error?}
     */
    public function engineStream(Request $request)
    {
        $messages = $request->input('messages', []);
        if (empty($messages) && $request->filled('message')) {
            $messages = [['role' => 'user', 'content' => (string) $request->input('message')]];
        }
        if (empty($messages)) {
            return response()->json(['error' => 'No messages provided'], 400);
        }

        $engine = AIServiceLocator::engine();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $opts = $request->all();
        foreach ($engine->streamChat($messages, $opts) as $chunk) {
            if (connection_aborted()) {
                break;
            }

            $delta = $chunk['delta'] ?? '';
            $done  = $chunk['done']  ?? false;
            $error = $chunk['error'] ?? null;

            if ($error) {
                echo "data: " . json_encode(['error' => $error, 'done' => true]) . "\n\n";
            } else {
                echo "data: " . json_encode(['delta' => $delta, 'done' => $done]) . "\n\n";
            }

            ob_flush();
            flush();
        }

        return response('', 200)->header('Content-Type', 'text/plain');
    }
}
