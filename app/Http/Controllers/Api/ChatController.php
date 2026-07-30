<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ChatController extends \Illuminate\Routing\Controller
{
    /** OpenClaw gateway bearer token */
    private string $openclawToken;

    /** OpenClaw gateway base URL */
    private string $openclawUrl;

    /** AI model alias used when calling OpenClaw */
    private string $model = 'openclaw';

    /** Default request timeout (seconds) for the gateway call */
    private int $timeout = 60;

    public function __construct()
    {
        $this->openclawToken = (string) (env('OPENCLAW_TOKEN') ?: 'c11301b2d79af120e1a150539bb2ab0b50d999d1a302a810');
        $this->openclawUrl   = (string) (env('OPENCLAW_URL')   ?: 'http://127.0.0.1:18789');
    }

    /**
     * Resolve the authenticated user.
     *
     * Tries `auth('api')->user()` first (spec requirement) and falls back to
     * the ApiAuthMiddleware request attribute `auth_user`.
     */
    private function authUser(Request $request): ?array
    {
        $u = auth('api')->user();
        if ($u) {
            return is_object($u) ? (array) $u : $u;
        }
        return $request->get('auth_user');
    }

    /* -----------------------------------------------------------------
     |  Per-user JSON persistence (mirrors ProjectController pattern)
     | -----------------------------------------------------------------*/

    private function userPath(int $userId): string
    {
        return base_path("database/uizard/user_{$userId}.json");
    }

    private function loadData(int $userId): array
    {
        $path = $this->userPath($userId);
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!file_exists($path)) {
            file_put_contents($path, json_encode(['projects' => []]));
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            $data = ['projects' => []];
        }
        if (!isset($data['chat_messages']) || !is_array($data['chat_messages'])) {
            $data['chat_messages'] = [];
        }
        return $data;
    }

    private function saveData(int $userId, array $data): void
    {
        $path = $this->userPath($userId);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function findProject(array $data, string $projectId): ?array
    {
        foreach ($data['projects'] ?? [] as $p) {
            if ((string) ($p['id'] ?? '') === $projectId) {
                return $p;
            }
        }
        return null;
    }

    /* -----------------------------------------------------------------
     |  Endpoints
     | -----------------------------------------------------------------*/

    /**
     * GET /api/projects/{projectId}/chat
     *
     * Return chat history for a project. If the project has no messages yet,
     * return a friendly mock conversation so the UI has something to render.
     */
    public function index(Request $request, string $projectId): \Illuminate\Http\JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $this->loadData((int) $user['id']);
        $project = $this->findProject($data, $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $messages = array_values(array_filter(
            ($data['chat_messages'] ?? []),
            fn($m) => (string) ($m['project_id'] ?? '') === $projectId
        ));
        usort($messages, fn($a, $b) => ($a['created_at'] ?? '') <=> ($b['created_at'] ?? ''));

        if (empty($messages)) {
            $messages = $this->seedMockConversation($projectId);
        }

        return response()->json([
            'success' => true,
            'data'    => $messages,
            'count'   => count($messages),
            'project' => [
                'id'   => $project['id'],
                'name' => $project['name'] ?? null,
            ],
        ]);
    }

    /**
     * POST /api/chat/send
     *
     * Send a message to the AI chat for a project. Posts the conversation
     * context (project + screenshot + user query) to the OpenClaw gateway
     * and stores the structured assistant response.
     */
    public function send(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $v = Validator::make($request->all(), [
            'project_id'    => 'required|string',
            'message'       => 'required|string|min:1|max:8000',
            'screenshot_id' => 'nullable|string',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $projectId    = (string) $request->input('project_id');
        $message      = (string) $request->input('message');
        $screenshotId = $request->input('screenshot_id') ? (string) $request->input('screenshot_id') : null;

        $data = $this->loadData((int) $user['id']);
        $project = $this->findProject($data, $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $userMessage = [
            'id'            => uniqid('msg_'),
            'project_id'    => $projectId,
            'screenshot_id' => $screenshotId,
            'user_id'       => (int) $user['id'],
            'role'          => 'user',
            'content'       => $message,
            'model'         => null,
            'structured'    => null,
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        $ai = $this->callOpenClawAI($user, $project, $message, $screenshotId);

        $assistantMessage = [
            'id'            => uniqid('msg_'),
            'project_id'    => $projectId,
            'screenshot_id' => $screenshotId,
            'user_id'       => (int) $user['id'],
            'role'          => 'assistant',
            'content'       => (string) ($ai['content'] ?? ''),
            'model'         => (string) ($ai['model'] ?? $this->model),
            'structured'    => $ai['structured'] ?? null,
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        $data['chat_messages'][] = $userMessage;
        $data['chat_messages'][] = $assistantMessage;
        $this->saveData((int) $user['id'], $data);

        return response()->json([
            'success' => true,
            'data'    => [
                'user'      => $userMessage,
                'assistant' => $assistantMessage,
                'project'   => ['id' => $project['id'], 'name' => $project['name'] ?? null],
            ],
        ]);
    }

    /* -----------------------------------------------------------------
     |  OpenClaw gateway integration
     | -----------------------------------------------------------------*/

    /**
     * Call the OpenClaw gateway chat completions endpoint. Always returns
     * a usable array; falls back to a deterministic structured response if
     * the gateway is unavailable or returns malformed output.
     */
    private function callOpenClawAI(array $user, ?array $project, string $message, ?string $screenshotId): array
    {
        $projectSummary = null;
        if ($project) {
            $projectSummary = [
                'id'           => $project['id'] ?? null,
                'name'         => $project['name'] ?? 'Project',
                'description'  => $project['description'] ?? '',
                'industry'     => $project['industry'] ?? null,
                'product_type' => $project['product_type'] ?? null,
                'design_style' => $project['design_style'] ?? null,
                'screen_count' => count($project['screens'] ?? []),
                'screens'      => array_map(fn($s) => [
                    'id'     => $s['id'] ?? null,
                    'name'   => $s['name'] ?? null,
                    'width'  => $s['width'] ?? null,
                    'height' => $s['height'] ?? null,
                ], $project['screens'] ?? []),
            ];
        }

        $systemPrompt = "You are a friendly, knowledgeable AI assistant. You're helpful, conversational, and genuinely enjoy helping users solve problems. Reply in a natural, conversational tone — like you're chatting with a friend who happens to be an expert. Be direct and practical, not stiff or overly formal. When providing code or technical info, be clear and concise. When giving opinions or suggestions, be confident but not arrogant. Use casual connective phrases naturally — 'Sure thing!', 'Here's what I'd do...', 'No problem!', etc. Don't pad your answers or state the obvious. Just get to the point and be genuinely useful.\n\nIf the user asks about UI/UX, design, or their project — be specific and reference concrete elements. If they're just chatting, be a helpful conversation partner. There's no required output format — just be helpful.";

        $payload = [
            'project'   => $projectSummary,
            'screenshot' => $screenshotId,
            'user'      => ['id' => $user['id'] ?? null, 'name' => $user['name'] ?? null],
            'user_query' => $message,
        ];

        try {
            $response = Http::timeout($this->timeout)->withHeaders([
                'Authorization' => 'Bearer ' . $this->openclawToken,
                'Content-Type'  => 'application/json',
            ])->post(rtrim($this->openclawUrl, '/') . '/v1/chat/completions', [
                'model'    => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => json_encode($payload)],
                ],
                'temperature' => 0.6,
                'max_tokens'   => 1200,
            ]);

            if ($response->failed()) {
                return $this->buildFallbackAI($project, $message, $screenshotId, 'gateway_status_' . $response->status());
            }

            $content = (string) $response->json('choices.0.message.content', '');
            if ($content === '') {
                return $this->buildFallbackAI($project, $message, $screenshotId, 'empty_response');
            }

            $parsed = $this->parseAIJson($content);
            if (!$parsed) {
                return [
                    'content'    => trim($content),
                    'structured' => [
                        'reply'              => trim($content),
                        'issues'             => [],
                        'suggestions'        => [],
                        'screens_referenced' => $screenshotId ? [$screenshotId] : [],
                    ],
                    'model' => $this->model,
                ];
            }

            return [
                'content'    => (string) ($parsed['reply'] ?? trim($content)),
                'structured' => [
                    'reply'              => (string) ($parsed['reply'] ?? ''),
                    'issues'             => array_values($parsed['issues'] ?? []),
                    'suggestions'        => array_values($parsed['suggestions'] ?? []),
                    'screens_referenced' => array_values($parsed['screens_referenced'] ?? []),
                ],
                'model' => $this->model,
            ];
        } catch (\Exception $e) {
            return $this->buildFallbackAI($project, $message, $screenshotId, 'exception:' . $e->getMessage());
        }
    }

    /**
     * Extract a JSON object from an AI response that may be wrapped in
     * Markdown code fences.
     */
    private function parseAIJson(string $content): ?array
    {
        $clean = trim($content);
        $clean = preg_replace('/^```json\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/^```\s*$/m', '', $clean) ?? $clean;
        $clean = trim($clean);

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $clean, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Build a structured fallback response when the AI gateway is
     * unavailable. Keeps the UI functional offline.
     */
    private function buildFallbackAI(?array $project, string $message, ?string $screenshotId, string $reason): array
    {
        $name = $project['name'] ?? 'your project';
        $reply = "I reviewed {$name} based on your request. Two quick wins: tighten the visual hierarchy of the hero, and boost the primary CTA contrast for stronger affordance. Want a screen-by-screen breakdown?";

        return [
            'content' => $reply,
            'structured' => [
                'reply' => $reply,
                'issues' => [
                    ['title' => 'CTA contrast could be stronger', 'severity' => 'medium', 'description' => 'Increase the contrast ratio between the call-to-action and its background to meet WCAG AA.'],
                    ['title' => 'Hero hierarchy unclear',           'severity' => 'low',    'description' => 'Establish a clearer primary headline and supporting copy above the fold.'],
                ],
                'suggestions' => [
                    ['title' => 'Improve vertical rhythm',  'detail' => 'Use a consistent spacing scale across sections (8 / 16 / 24 / 32 / 48).'],
                    ['title' => 'Refine color tokens',      'detail' => 'Define semantic tokens (primary, surface, muted, success, danger) with HSL OKLCH-friendly values.'],
                    ['title' => 'Strengthen CTA placement', 'detail' => 'Move the primary action above the fold and repeat it in the sticky header.'],
                ],
                'screens_referenced' => $screenshotId ? [$screenshotId] : [],
            ],
            'model' => 'fallback:' . $reason,
        ];
    }

    /**
     * Return a deterministic mock conversation so the chat UI is never empty.
     */
    private function seedMockConversation(string $projectId): array
    {
        $now = date('Y-m-d H:i:s');
        return [
            [
                'id'            => uniqid('msg_'),
                'project_id'    => $projectId,
                'screenshot_id' => null,
                'role'          => 'assistant',
                'content'       => "Hi! I'm your AI design assistant for this project. I can review screenshots, spot UX issues, and suggest concrete improvements. What would you like to look at first?",
                'model'         => 'seed',
                'structured'    => null,
                'created_at'    => $now,
            ],
            [
                'id'            => uniqid('msg_'),
                'project_id'    => $projectId,
                'screenshot_id' => null,
                'role'          => 'assistant',
                'content'       => "Tip: ask me about a specific screen — for example 'Analyze the hero section' — and I'll give you a structured breakdown with severity-tagged issues and actionable suggestions.",
                'model'         => 'seed',
                'structured'    => null,
                'created_at'    => $now,
            ],
        ];
    }
}
