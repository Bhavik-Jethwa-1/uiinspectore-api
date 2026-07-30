<?php

namespace App\Http\Controllers\Api;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Screenshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IssueController extends \Illuminate\Routing\Controller
{
    /**
     * Allowed values for type/severity/status enums (kept in sync with migrations).
     */
    private const ALLOWED_TYPES = ['ui', 'ux', 'accessibility', 'conversion'];
    private const ALLOWED_SEVERITIES = ['critical', 'medium', 'good'];
    private const ALLOWED_STATUSES = ['open', 'in_progress', 'resolved', 'ignored'];

    /**
     * Resolve the authenticated API user.
     */
    private function authUser(Request $request): ?\App\Models\User
    {
        $user = auth('api')->user();
        if ($user) {
            return $user;
        }

        // Fallback: read auth_user attribute set by ApiAuthMiddleware so the
        // controller works even before a real "api" guard is wired up.
        $payload = $request->attributes->get('auth_user') ?? $request->input('auth_user');
        if ($payload && isset($payload['id'])) {
            $existing = \App\Models\User::find($payload['id']);
            if ($existing) {
                return $existing;
            }
            $hydrated = new \App\Models\User();
            $hydrated->setRawAttributes([
                'id' => (int) $payload['id'],
                'name' => $payload['name'] ?? '',
                'email' => $payload['email'] ?? '',
            ], true);
            $hydrated->exists = true;
            return $hydrated;
        }

        return null;
    }

    /**
     * Validate that the current user owns the project (or that it exists).
     */
    private function ensureProject(Request $request, int $projectId): JsonResponse|Project
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $project = Project::find($projectId);
        if (!$project) {
            return response()->json(['success' => false, 'error' => 'Project not found'], 404);
        }

        if ((int) $project->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        return $project;
    }

    /**
     * GET /api/projects/{projectId}/issues
     * List issues for a project with optional filters.
     */
    public function index(Request $request, string $projectId): JsonResponse
    {
        $check = $this->ensureProject($request, (int) $projectId);
        if ($check instanceof JsonResponse) {
            return $check;
        }

        $query = Issue::query()->where('project_id', (int) $projectId);

        foreach (['type', 'severity', 'status', 'category'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('screenshot_id')) {
            $query->where('screenshot_id', (int) $request->input('screenshot_id'));
        }

        if ($request->filled('search')) {
            $term = (string) $request->input('search');
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                  ->orWhere('description', 'like', "%{$term}%")
                  ->orWhere('category', 'like', "%{$term}%");
            });
        }

        $perPage = max(1, min(200, (int) $request->input('per_page', 50)));
        $issues = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $issues->items(),
            'meta' => [
                'total' => $issues->total(),
                'per_page' => $issues->perPage(),
                'current_page' => $issues->currentPage(),
                'last_page' => $issues->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/projects/{projectId}/issues
     * Create a new issue.
     */
    public function store(Request $request, string $projectId): JsonResponse
    {
        $check = $this->ensureProject($request, (int) $projectId);
        if ($check instanceof JsonResponse) {
            return $check;
        }

        $validator = Validator::make(array_merge($request->all(), ['project_id' => (int) $projectId]), [
            'project_id' => 'required|integer|exists:projects,id',
            'screenshot_id' => 'nullable|integer|exists:screenshots,id',
            'type' => 'required|string|in:' . implode(',', self::ALLOWED_TYPES),
            'severity' => 'required|string|in:' . implode(',', self::ALLOWED_SEVERITIES),
            'category' => 'required|string|max:120',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'problem' => 'nullable|string',
            'reason' => 'nullable|string',
            'business_impact' => 'nullable|string',
            'recommendation' => 'nullable|string',
            'expected_result' => 'nullable|string',
            'status' => 'nullable|string|in:' . implode(',', self::ALLOWED_STATUSES),
            'x' => 'nullable|integer',
            'y' => 'nullable|integer',
            'width' => 'nullable|integer',
            'height' => 'nullable|integer',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $this->authUser($request);
        $data = $validator->validated();
        $data['project_id'] = (int) $projectId;
        $data['user_id'] = $user?->id ?? ($data['user_id'] ?? null);
        $data['status'] = $data['status'] ?? 'open';

        // Ensure screenshot belongs to the same project if provided
        if (!empty($data['screenshot_id'])) {
            $shot = Screenshot::find($data['screenshot_id']);
            if (!$shot || (int) $shot->project_id !== (int) $projectId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Screenshot does not belong to this project',
                ], 422);
            }
        }

        $issue = Issue::create($data);

        return response()->json([
            'success' => true,
            'data' => $issue->fresh(),
        ], 201);
    }

    /**
     * GET /api/issues/{id}
     * Show a single issue with its comments.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $issue = Issue::with(['project', 'screenshot'])->find((int) $id);
        if (!$issue) {
            return response()->json(['success' => false, 'error' => 'Issue not found'], 404);
        }

        // Authorization: only project owner can view (extend to team_members later)
        $project = Project::find($issue->project_id);
        if (!$project || (int) $project->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $comments = \Illuminate\Support\Facades\DB::table('comments')
            ->where('commentable_type', Issue::class)
            ->where('commentable_id', $issue->id)
            ->orderBy('created_at', 'asc')
            ->get();

        $payload = $issue->toArray();
        $payload['comments'] = $comments;

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    /**
     * PUT /api/issues/{id}
     * Update an issue.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $issue = Issue::find((int) $id);
        if (!$issue) {
            return response()->json(['success' => false, 'error' => 'Issue not found'], 404);
        }

        $project = Project::find($issue->project_id);
        if (!$project || (int) $project->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'screenshot_id' => 'sometimes|nullable|integer|exists:screenshots,id',
            'type' => 'sometimes|string|in:' . implode(',', self::ALLOWED_TYPES),
            'severity' => 'sometimes|string|in:' . implode(',', self::ALLOWED_SEVERITIES),
            'category' => 'sometimes|string|max:120',
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'problem' => 'sometimes|nullable|string',
            'reason' => 'sometimes|nullable|string',
            'business_impact' => 'sometimes|nullable|string',
            'recommendation' => 'sometimes|nullable|string',
            'expected_result' => 'sometimes|nullable|string',
            'status' => 'sometimes|string|in:' . implode(',', self::ALLOWED_STATUSES),
            'x' => 'sometimes|nullable|integer',
            'y' => 'sometimes|nullable|integer',
            'width' => 'sometimes|nullable|integer',
            'height' => 'sometimes|nullable|integer',
            'metadata' => 'sometimes|nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $issue->fill($validator->validated());
        $issue->save();

        return response()->json([
            'success' => true,
            'data' => $issue->fresh(),
        ]);
    }

    /**
     * DELETE /api/issues/{id}
     * Delete an issue.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $issue = Issue::find((int) $id);
        if (!$issue) {
            return response()->json(['success' => false, 'error' => 'Issue not found'], 404);
        }

        $project = Project::find($issue->project_id);
        if (!$project || (int) $project->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $issue->delete();

        return response()->json([
            'success' => true,
            'data' => ['id' => (int) $id, 'message' => 'Issue deleted'],
        ]);
    }

    /**
     * POST /api/projects/{projectId}/issues/bulk-update
     * Update status (and optionally other fields) on multiple issues at once.
     *
     * Body shapes accepted:
     *  - { ids: [1,2,3], status: "resolved" }
     *  - { issues: [ {id: 1, status: "resolved"}, {id: 2, status: "ignored"} ] }
     */
    public function bulkUpdate(Request $request, string $projectId): JsonResponse
    {
        $check = $this->ensureProject($request, (int) $projectId);
        if ($check instanceof JsonResponse) {
            return $check;
        }

        $payload = $request->all();

        // Normalize to a list of [id => fields]
        $updates = [];
        if (isset($payload['issues']) && is_array($payload['issues'])) {
            foreach ($payload['issues'] as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }
                $rowCopy = $row;
                $id = (int) $rowCopy['id'];
                unset($rowCopy['id']);
                $updates[$id] = $rowCopy;
            }
        } elseif (isset($payload['ids']) && is_array($payload['ids'])) {
            $common = $payload;
            unset($common['ids']);
            foreach ($payload['ids'] as $id) {
                $updates[(int) $id] = $common;
            }
        } else {
            return response()->json([
                'success' => false,
                'error' => 'Provide either "ids" + fields or an "issues" array',
            ], 422);
        }

        if (empty($updates)) {
            return response()->json(['success' => false, 'error' => 'No issues to update'], 422);
        }

        $validator = Validator::make(
            ['updates' => $updates],
            ['updates' => 'required|array|min:1']
        );
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $allowedFields = [
            'status', 'severity', 'category', 'title', 'description', 'problem',
            'reason', 'business_impact', 'recommendation', 'expected_result',
            'x', 'y', 'width', 'height', 'metadata', 'screenshot_id',
        ];

        $updated = 0;
        $skipped = [];
        foreach ($updates as $id => $fields) {
            $issue = Issue::where('id', $id)
                ->where('project_id', (int) $projectId)
                ->first();
            if (!$issue) {
                $skipped[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }

            $clean = [];
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $fields)) {
                    $clean[$field] = $fields[$field];
                }
            }

            // Validate status / severity / type enums if provided
            $fieldValidator = Validator::make($clean, [
                'status' => 'sometimes|in:' . implode(',', self::ALLOWED_STATUSES),
                'severity' => 'sometimes|in:' . implode(',', self::ALLOWED_SEVERITIES),
                'type' => 'sometimes|in:' . implode(',', self::ALLOWED_TYPES),
                'screenshot_id' => 'sometimes|nullable|integer|exists:screenshots,id',
            ]);
            if ($fieldValidator->fails()) {
                $skipped[] = ['id' => $id, 'reason' => 'invalid_fields', 'errors' => $fieldValidator->errors()->toArray()];
                continue;
            }

            $issue->fill($clean);
            $issue->save();
            $updated++;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'updated' => $updated,
                'skipped' => $skipped,
                'total' => count($updates),
            ],
        ]);
    }

    /**
     * GET /api/projects/{projectId}/issues/statistics
     * Aggregate counts by severity, type, and status for a project.
     */
    public function statistics(Request $request, string $projectId): JsonResponse
    {
        $check = $this->ensureProject($request, (int) $projectId);
        if ($check instanceof JsonResponse) {
            return $check;
        }

        $base = Issue::query()->where('project_id', (int) $projectId);

        $total = (clone $base)->count();

        $bySeverity = (clone $base)
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        $byType = (clone $base)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $byCategory = (clone $base)
            ->selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->pluck('count', 'category')
            ->toArray();

        // Fill missing buckets with zero so the UI gets a stable shape
        $fill = function (array $current, array $defaults): array {
            $out = array_fill_keys($defaults, 0);
            foreach ($current as $k => $v) {
                $out[$k] = (int) $v;
            }
            return $out;
        };

        return response()->json([
            'success' => true,
            'data' => [
                'project_id' => (int) $projectId,
                'total' => $total,
                'by_severity' => $fill($bySeverity, self::ALLOWED_SEVERITIES),
                'by_type' => $fill($byType, self::ALLOWED_TYPES),
                'by_status' => $fill($byStatus, self::ALLOWED_STATUSES),
                'by_category' => $byCategory,
            ],
        ]);
    }
}
