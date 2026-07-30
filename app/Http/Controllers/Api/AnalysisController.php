<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Analysis;
use App\Models\Project;
use App\Models\Screenshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AnalysisController extends \Illuminate\Routing\Controller
{
    /**
     * Analysis categories supported by the app.
     */
    private array $allowedTypes = ['ui', 'ux', 'accessibility', 'dashboard', 'landing', 'form', 'navigation'];

    /**
     * OpenClaw gateway credentials (matches the project's other AI controllers).
     */
    private string $openclawUrl = 'http://127.0.0.1:18789';
    private string $openclawToken = 'c11301b2d79af120e1a150539bb2ab0b50d999d1a302a810';

    /**
     * Resolve the authenticated user id.
     * Prefers auth('api')->user() but falls back to the request-level
     * auth_user array set by App\Http\Middleware\ApiAuthMiddleware.
     *
     * Also lazy-syncs the JSON-backed user into the SQL users table so that
     * foreign-key constraints on user_id columns don't fire.
     */
    private function userId(Request $request): ?int
    {
        try {
            $u = auth('api')->user();
            if ($u && isset($u->id)) {
                return (int) $u->id;
            }
        } catch (\Throwable $e) {
            // The 'api' guard isn't configured in this app; fall through to auth_user.
        }
        $authUser = $request->get('auth_user');
        if (is_array($authUser) && isset($authUser['id'])) {
            $this->ensureUserExistsInDb($authUser);
            return (int) $authUser['id'];
        }
        return null;
    }

    /**
     * Make sure an authenticated user (from database/users.json) also has a row
     * in the SQL `users` table so FK constraints don't fire on inserts.
     */
    private function ensureUserExistsInDb(array $authUser): void
    {
        try {
            $id = (int) ($authUser['id'] ?? 0);
            if ($id <= 0) return;
            $existing = \App\Models\User::find($id);
            if ($existing) return;
            \App\Models\User::create([
                'id' => $id,
                'name' => (string) ($authUser['name'] ?? 'User ' . $id),
                'email' => (string) ($authUser['email'] ?? ('user' . $id . '@example.com')),
                'password' => (string) ($authUser['password'] ?? \Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(8)))),
                'created_at' => $authUser['created_at'] ?? now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * Find a project owned by the current user.
     */
    private function findOwnedProject(int $userId, string $projectId): ?Project
    {
        return Project::query()
            ->where('user_id', $userId)
            ->where('id', $projectId)
            ->first();
    }

    /**
     * Best-effort activity log entry.
     */
    private function log(int $userId, int $projectId, string $action, ?string $subjectType = null, ?int $subjectId = null, array $metadata = []): void
    {
        try {
            ActivityLog::create([
                'project_id' => $projectId,
                'user_id' => $userId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * GET /api/projects/{projectId}/analyses
     */
    public function index(Request $request, string $projectId): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwnedProject($userId, $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $query = $project->analyses()->with('screenshot')->orderByDesc('created_at');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($screenshotId = $request->query('screenshot_id')) {
            $query->where('screenshot_id', $screenshotId);
        }

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min($perPage, 200));
        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * POST /api/projects/{projectId}/analyses
     *
     * Creates an analysis row. If `auto_run=true` (default) the AI is invoked
     * immediately; otherwise it stays in `pending` until you call /run.
     */
    public function store(Request $request, string $projectId): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwnedProject($userId, $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $v = Validator::make($request->all(), [
            'screenshot_id' => 'nullable|integer',
            'type' => 'required|string|in:' . implode(',', $this->allowedTypes),
            'auto_run' => 'nullable|boolean',
            'options' => 'nullable|array',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $screenshotId = $request->input('screenshot_id');
        $type = $request->input('type');
        $autoRun = $request->boolean('auto_run', true);

        if ($screenshotId !== null) {
            $screenshot = $project->screenshots()->where('id', $screenshotId)->first();
            if (!$screenshot) {
                return response()->json(['error' => 'Screenshot not found in this project'], 404);
            }
        }

        $analysis = Analysis::create([
            'project_id' => $project->id,
            'screenshot_id' => $screenshotId,
            'user_id' => $userId,
            'type' => $type,
            'status' => $autoRun ? 'running' : 'pending',
            'results' => null,
            'scores' => null,
            'completed_at' => null,
        ]);

        $this->log($userId, $project->id, 'analysis.created', 'analysis', $analysis->id, [
            'description' => "Created {$type} analysis",
            'type' => $type,
            'screenshot_id' => $screenshotId,
        ]);

        if ($autoRun) {
            $this->executeAnalysis($analysis, $project, $request->input('options', []));
            $analysis->refresh();
        }

        return response()->json(['success' => true, 'data' => $analysis], 201);
    }

    /**
     * GET /api/projects/{projectId}/analyses/{analysisId}
     */
    public function show(Request $request, string $projectId, string $analysisId): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwnedProject($userId, $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $analysis = $project->analyses()
            ->with('screenshot')
            ->withCount('issues')
            ->where('id', $analysisId)
            ->first();
        if (!$analysis) {
            return response()->json(['error' => 'Analysis not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $analysis]);
    }

    /**
     * POST /api/projects/{projectId}/analyses/{analysisId}/run
     *
     * Re-runs an existing analysis (refreshes results / scores).
     */
    public function run(Request $request, string $projectId, string $analysisId): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwnedProject($userId, $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $analysis = $project->analyses()->where('id', $analysisId)->first();
        if (!$analysis) {
            return response()->json(['error' => 'Analysis not found'], 404);
        }

        $analysis->status = 'running';
        $analysis->completed_at = null;
        $analysis->save();

        $this->executeAnalysis($analysis, $project, $request->input('options', []));
        $analysis->refresh();

        $this->log($userId, $project->id, 'analysis.run', 'analysis', $analysis->id, [
            'description' => "Ran {$analysis->type} analysis",
        ]);

        return response()->json(['success' => true, 'data' => $analysis]);
    }

    /**
     * DELETE /api/projects/{projectId}/analyses/{analysisId}
     */
    public function destroy(Request $request, string $projectId, string $analysisId): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwnedProject($userId, $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $analysis = $project->analyses()->where('id', $analysisId)->first();
        if (!$analysis) {
            return response()->json(['error' => 'Analysis not found'], 404);
        }

        $type = $analysis->type;
        $analysis->delete();

        $this->log($userId, $project->id, 'analysis.deleted', null, null, [
            'description' => "Deleted {$type} analysis",
        ]);

        return response()->json(['success' => true, 'message' => 'Analysis deleted']);
    }

    /**
     * Run the AI gateway (if reachable) and persist results.
     * Falls back to a deterministic, structured mock when the gateway
     * is unreachable so the UI always sees properly-shaped data.
     */
    private function executeAnalysis(Analysis $analysis, Project $project, array $options = []): void
    {
        $screenshot = null;
        if ($analysis->screenshot_id) {
            $screenshot = Screenshot::find($analysis->screenshot_id);
        }

        $context = [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'url' => $project->url,
                'template' => $project->template,
                'tags' => $project->tags,
            ],
            'screenshot' => $screenshot ? [
                'id' => $screenshot->id,
                'name' => $screenshot->name,
                'file_path' => $screenshot->file_path,
                'file_type' => $screenshot->file_type,
                'version' => $screenshot->version,
            ] : null,
            'type' => $analysis->type,
            'options' => $options,
        ];

        $systemPrompt = $this->buildSystemPrompt($analysis->type);
        $userPrompt = json_encode($context, JSON_UNESCAPED_SLASHES);

        $results = null;
        $scores = null;
        $live = false;

        try {
            $response = Http::timeout(60)->withHeaders([
                'Authorization' => 'Bearer ' . $this->openclawToken,
                'Content-Type' => 'application/json',
            ])->post($this->openclawUrl . '/v1/chat/completions', [
                'model' => 'openclaw',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.4,
                'max_tokens' => 1500,
            ]);

            if ($response->successful()) {
                $content = (string) $response->json('choices.0.message.content', '');
                $parsed = $this->parseJson($content);
                if ($parsed && isset($parsed['scores'], $parsed['findings'])) {
                    $results = $parsed;
                    $scores = $parsed['scores'];
                    $live = true;
                }
            }
        } catch (\Throwable $e) {
            // Gateway down or slow — fall through to mock.
        }

        if (!$live) {
            $mock = $this->buildMockAnalysis($analysis, $project, $screenshot);
            $results = $mock['results'];
            $scores = $mock['scores'];
        }

        // Make sure downstream consumers always have a `findings` shape.
        if (!is_array($results) || !isset($results['findings']) || !is_array($results['findings'])) {
            $results = ['findings' => [], 'summary' => 'Analysis could not be generated.'];
        }

        $analysis->status = 'completed';
        $analysis->results = $results;
        $analysis->scores = $scores;
        $analysis->completed_at = now();
        $analysis->save();
    }

    /**
     * Type-specific system prompt for the AI gateway.
     */
    private function buildSystemPrompt(string $type): string
    {
        $bucket = match ($type) {
            'ui' => ['typography', 'colors', 'spacing', 'hierarchy', 'alignment', 'contrast', 'consistency'],
            'ux' => ['clarity', 'feedback', 'learnability', 'error_handling', 'navigation', 'trust', 'engagement'],
            'accessibility' => ['contrast', 'keyboard', 'aria', 'alt_text', 'focus', 'legibility', 'motion'],
            'dashboard' => ['data_ink_ratio', 'kpi_clarity', 'chart_choice', 'density', 'hierarchy', 'real_time', 'actionability'],
            'landing' => ['hero_clarity', 'value_prop', 'social_proof', 'cta_prominence', 'friction', 'scannability', 'trust_signals'],
            'form' => ['labels', 'validation', 'error_messages', 'affordances', 'grouping', 'keyboard', 'completion_risk'],
            'navigation' => ['menu_clarity', 'findability', 'breadcrumbs', 'deep_linking', 'mobile_nav', 'search', 'wayfinding'],
            default => ['typography', 'colors', 'spacing', 'clarity', 'navigation', 'overall'],
        };

        $schema = json_encode([
            'summary' => 'string',
            'scores' => array_merge(['overall' => '0-100'], array_combine($bucket, array_fill(0, count($bucket), '0-100'))),
            'findings' => [
                'category' => 'string',
                'severity' => 'critical|medium|good',
                'title' => 'string',
                'problem' => 'string',
                'reason' => 'string',
                'business_impact' => 'string',
                'recommendation' => 'string',
                'expected_result' => 'string',
            ],
        ], JSON_PRETTY_PRINT);

        return "You are the {$type} inspector for a UI review platform. "
            . "Score each bucket 0-100. Findings must include a 'severity' field. "
            . "Return ONLY JSON matching this shape:\n{$schema}";
    }

    /**
     * Try to parse JSON content, even if wrapped in markdown fences.
     *
     * @return array<string,mixed>|null
     */
    private function parseJson(string $content): ?array
    {
        $content = trim($content);
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/^```\s*$/m', '', $content);
        $content = trim($content);

        $data = json_decode($content, true);
        if (is_array($data)) {
            return $data;
        }

        if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Deterministic mock scores/findings keyed on (project_id, type, screenshot_id).
     * Lets the UI render something coherent when the gateway is unreachable.
     *
     * @return array{results: array<string,mixed>, scores: array<string,int>}
     */
    private function buildMockAnalysis(Analysis $analysis, Project $project, ?Screenshot $screenshot): array
    {
        $seed = crc32(($project->name ?? '') . '|' . $analysis->type . '|' . ($analysis->screenshot_id ?? '0'));
        mt_srand($seed);

        $buckets = [
            'ui' => ['typography', 'colors', 'spacing', 'hierarchy', 'alignment', 'contrast', 'consistency'],
            'ux' => ['clarity', 'feedback', 'learnability', 'error_handling', 'navigation', 'trust', 'engagement'],
            'accessibility' => ['contrast', 'keyboard', 'aria', 'alt_text', 'focus', 'legibility', 'motion'],
            'dashboard' => ['data_ink_ratio', 'kpi_clarity', 'chart_choice', 'density', 'hierarchy', 'real_time', 'actionability'],
            'landing' => ['hero_clarity', 'value_prop', 'social_proof', 'cta_prominence', 'friction', 'scannability', 'trust_signals'],
            'form' => ['labels', 'validation', 'error_messages', 'affordances', 'grouping', 'keyboard', 'completion_risk'],
            'navigation' => ['menu_clarity', 'findability', 'breadcrumbs', 'deep_linking', 'mobile_nav', 'search', 'wayfinding'],
        ];

        $scoring = $buckets[$analysis->type] ?? $buckets['ui'];
        $scores = [];
        $sum = 0;
        $count = 0;
        foreach ($scoring as $bucket) {
            $value = mt_rand(60, 95);
            $scores[$bucket] = $value;
            $sum += $value;
            $count++;
        }
        $scores['overall'] = $count > 0 ? (int) round($sum / $count) : 0;

        $findings = $this->mockFindings($analysis->type, mt_rand(3, 6));

        $summary = sprintf(
            '%s analysis for "%s" generated %s.',
            strtoupper($analysis->type),
            $project->name,
            $screenshot ? "against screenshot \"" . $screenshot->name . "\"" : "without a specific screenshot"
        );

        return [
            'results' => [
                'id' => $analysis->id,
                'type' => $analysis->type,
                'summary' => $summary,
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                ],
                'screenshot' => $screenshot ? [
                    'id' => $screenshot->id,
                    'name' => $screenshot->name,
                ] : null,
                'findings' => $findings,
                'generated_at' => now()->toIso8601String(),
                'mock' => true,
            ],
            'scores' => $scores,
        ];
    }

    /**
     * Deterministic mocked findings for each analysis type.
     *
     * @return array<int,array<string,mixed>>
     */
    private function mockFindings(string $type, int $count): array
    {
        $catalog = [
            'ui' => [
                ['category' => 'typography', 'severity' => 'medium', 'title' => 'Inconsistent type scale', 'problem' => 'Heading sizes jump 12px in places where a 4-8px step would feel natural.', 'reason' => 'No enforced modular scale in the design tokens.', 'business_impact' => 'Reduces perceived polish and slows design iteration.', 'recommendation' => 'Adopt a 1.25 modular type scale on h1-h6.', 'expected_result' => 'Cleaner, more confident hierarchy.'],
                ['category' => 'colors', 'severity' => 'critical', 'title' => 'Primary CTA blends into hero background', 'problem' => 'The CTA sits on the same hue as the hero gradient.', 'reason' => 'Insufficient contrast between CTA and surrounding surface.', 'business_impact' => 'Drop-off on primary conversions.', 'recommendation' => 'Give the CTA a contrasting fill + 1px inner highlight.', 'expected_result' => 'Higher CTR on the hero action.'],
                ['category' => 'spacing', 'severity' => 'good', 'title' => 'Generous whitespace around hero', 'problem' => 'Vertical padding exceeds the 96-128px band.', 'reason' => 'Past design system required it.', 'business_impact' => 'Positive: improves scan-ability.', 'recommendation' => 'Keep, but cap the bottom padding for shorter viewports.', 'expected_result' => 'No regression; faster mobile paint.'],
            ],
            'ux' => [
                ['category' => 'clarity', 'severity' => 'medium', 'title' => 'Help text only appears after error', 'problem' => 'Inline help is hidden until the first validation error.', 'reason' => 'Form is missing always-on hint pattern.', 'business_impact' => 'Increases support tickets for ambiguous fields.', 'recommendation' => 'Add inline micro-help below each field by default.', 'expected_result' => 'Lower abandonment, fewer invalid submits.'],
                ['category' => 'feedback', 'severity' => 'good', 'title' => 'Toast confirmation after save', 'problem' => 'Toast appears bottom-right on web and bottom on mobile.', 'reason' => 'Different containers per breakpoint.', 'business_impact' => 'Consistent positive feedback.', 'recommendation' => 'Standardize to bottom-center on all breakpoints.', 'expected_result' => 'Unified feedback pattern.'],
                ['category' => 'error_handling', 'severity' => 'critical', 'title' => 'No retry on transient network errors', 'problem' => 'Submits fail silently with no retry CTA.', 'reason' => 'Error boundary swallows network errors.', 'business_impact' => 'Lost submissions during flaky networks.', 'recommendation' => 'Detect network errors, surface inline retry.', 'expected_result' => 'Recoverable failure states.'],
            ],
            'accessibility' => [
                ['category' => 'contrast', 'severity' => 'critical', 'title' => 'Body text below 4.5:1 contrast', 'problem' => 'Muted captions render at ~3.1:1 against background.', 'reason' => 'Color tokens not checked for AA.', 'business_impact' => 'Excludes low-vision users, fails WCAG.', 'recommendation' => 'Darken caption token to #475569 on white.', 'expected_result' => 'AA-compliant body text.'],
                ['category' => 'keyboard', 'severity' => 'medium', 'title' => 'Modal traps focus only on first tab', 'problem' => 'Tab order leaks outside the modal.', 'reason' => 'Focus trap uses stale ref.', 'business_impact' => 'Keyboard-only users escape the modal context.', 'recommendation' => 'Implement the focus trap with a controlled ref queue.', 'expected_result' => 'Keyboard-only flows stay inside modal.'],
                ['category' => 'aria', 'severity' => 'good', 'title' => 'Form fields have descriptive aria-labelledby', 'problem' => 'Labels correctly point at inputs.', 'reason' => 'Built-in label components are used.', 'business_impact' => 'Positive screen-reader experience.', 'recommendation' => 'No change required.', 'expected_result' => 'Maintained AA rating.'],
            ],
            'dashboard' => [
                ['category' => 'kpi_clarity', 'severity' => 'medium', 'title' => 'KPIs lack comparison delta', 'problem' => 'KPI cards show absolute value but no delta vs prior period.', 'reason' => 'KPI component missing slot for delta.', 'business_impact' => 'Operators cannot spot regressions quickly.', 'recommendation' => 'Add delta badge with arrow + color.', 'expected_result' => 'Faster anomaly detection.'],
                ['category' => 'chart_choice', 'severity' => 'good', 'title' => 'Line chart for sequential time-series', 'problem' => 'Right chart for the data shape.', 'reason' => 'Default chart selector picked line.', 'business_impact' => 'Positive cognitive load.', 'recommendation' => 'Keep.', 'expected_result' => 'No change.'],
                ['category' => 'density', 'severity' => 'critical', 'title' => 'Tables render 40+ rows without virtualization', 'problem' => 'DOM balloons above 50k nodes on large accounts.', 'reason' => 'No row virtualization configured.', 'business_impact' => 'Janky scroll on Chromium.', 'recommendation' => 'Wrap table in a virtualized list.', 'expected_result' => '60fps scroll on dense tables.'],
            ],
            'landing' => [
                ['category' => 'hero_clarity', 'severity' => 'good', 'title' => 'Hero states value prop in 6 words', 'problem' => 'Single hero line conveys the offer.', 'reason' => 'Tight copy editing.', 'business_impact' => 'Positive first-impression.', 'recommendation' => 'A/B test on adding proof point.', 'expected_result' => 'Likely conversion lift.'],
                ['category' => 'cta_prominence', 'severity' => 'critical', 'title' => 'Primary CTA collapsed on mobile', 'problem' => 'Hero CTA button shrinks to 32px on small screens.', 'reason' => 'CSS overrides target only desktop.', 'business_impact' => 'Tap target too small for mobile users.', 'recommendation' => 'Floor CTA height at 44px.', 'expected_result' => 'Better mobile CTR.'],
                ['category' => 'friction', 'severity' => 'medium', 'title' => 'Pricing toggle hidden below the fold', 'problem' => 'Annual vs monthly toggle is 3 sections down.', 'reason' => 'Order influenced by sales feedback.', 'business_impact' => 'Users hit monthly pricing by default.', 'recommendation' => 'Surface toggle next to the hero CTA.', 'expected_result' => 'More annual conversions.'],
            ],
            'form' => [
                ['category' => 'labels', 'severity' => 'good', 'title' => 'Always-visible labels above inputs', 'problem' => 'Placeholders do not replace labels.', 'reason' => 'Form library configured for floating labels.', 'business_impact' => 'No loss of context on autofill.', 'recommendation' => 'Keep.', 'expected_result' => 'Continued AA compliance.'],
                ['category' => 'validation', 'severity' => 'critical', 'title' => 'Email validation only on submit', 'problem' => 'No on-blur validation for email field.', 'reason' => 'Validation runs in submit handler only.', 'business_impact' => 'Higher error rate at submit time.', 'recommendation' => 'Enable on-blur validation.', 'expected_result' => 'Lower submit-time errors.'],
                ['category' => 'error_messages', 'severity' => 'medium', 'title' => 'Errors not announced via aria-live', 'problem' => 'Messages render visually but lack live region.', 'reason' => 'Missing aria-live="polite" region.', 'business_impact' => 'Screen-reader users miss error feedback.', 'recommendation' => 'Wrap form errors in aria-live region.', 'expected_result' => 'Screen-reader aware form feedback.'],
            ],
            'navigation' => [
                ['category' => 'menu_clarity', 'severity' => 'good', 'title' => 'Top navigation uses plain-language labels', 'problem' => 'No marketing-speak in nav items.', 'reason' => 'Editorial guideline enforced.', 'business_impact' => 'Lower cognitive friction.', 'recommendation' => 'Keep.', 'expected_result' => 'Stable findability.'],
                ['category' => 'findability', 'severity' => 'medium', 'title' => 'Search results ignore deep filters', 'problem' => 'Filters selected on results page reset on query change.', 'reason' => 'Search reducer drops filter state.', 'business_impact' => 'Users re-apply filters repeatedly.', 'recommendation' => 'Persist filter state in URL.', 'expected_result' => 'Sharable, repeatable searches.'],
                ['category' => 'breadcrumbs', 'severity' => 'critical', 'title' => 'Breadcrumbs missing on detail pages', 'problem' => 'User loses orientation 3 levels deep.', 'reason' => 'Layout hides breadcrumbs on mobile.', 'business_impact' => 'Mobile bounce rate spikes on detail pages.', 'recommendation' => 'Render breadcrumbs above H1 on mobile.', 'expected_result' => 'Reduced mobile bounce.'],
            ],
        ];

        $pool = $catalog[$type] ?? $catalog['ui'];
        $findings = [];
        $i = 0;
        while ($i < $count && !empty($pool)) {
            $idx = ($seed + $i) % count($pool);
            $f = $pool[$idx];
            $f['id'] = 'fnd_' . Str::random(8);
            $findings[] = $f;
            $i++;
        }
        return $findings;
    }
}
