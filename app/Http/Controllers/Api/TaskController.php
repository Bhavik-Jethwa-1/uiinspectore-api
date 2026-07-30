<?php

namespace App\Http\Controllers\Api;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TaskController extends \Illuminate\Routing\Controller
{
    /**
     * Allowed values for status/priority enums (kept in sync with migrations).
     */
    private const ALLOWED_STATUSES = ['todo', 'in_progress', 'done'];
    private const ALLOWED_PRIORITIES = ['low', 'medium', 'high'];

    /**
     * Resolve the authenticated API user.
     */
    private function authUser(Request $request): ?\App\Models\User
    {
        $user = auth('api')->user();
        if ($user) {
            return $user;
        }

        // Fallback: read auth_user attribute set by ApiAuthMiddleware
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
     * Validate the project belongs to the current user.
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
     * GET /api/projects/{projectId}/tasks
     * List tasks for a project with optional filters.
     */
    public function index(Request $request, string $projectId): JsonResponse
    {
        $check = $this->ensureProject($request, (int) $projectId);
        if ($check instanceof JsonResponse) {
            return $check;
        }

        $query = Task::query()->where('project_id', (int) $projectId);

        foreach (['status', 'priority'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('assignee_id')) {
            $query->where('assignee_id', (int) $request->input('assignee_id'));
        }

        if ($request->filled('issue_id')) {
            $query->where('issue_id', (int) $request->input('issue_id'));
        }

        if ($request->filled('search')) {
            $term = (string) $request->input('search');
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                  ->orWhere('description', 'like', "%{$term}%");
            });
        }

        // Sort: open tasks first (todo, in_progress), then by due_date asc, then newest
        $query->orderByRaw(
            "CASE status WHEN 'todo' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'done' THEN 2 ELSE 3 END ASC"
        )->orderBy('due_date', 'asc')->orderBy('created_at', 'desc');

        $perPage = max(1, min(200, (int) $request->input('per_page', 50)));
        $tasks = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $tasks->items(),
            'meta' => [
                'total' => $tasks->total(),
                'per_page' => $tasks->perPage(),
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/projects/{projectId}/tasks
     * Create a new task.
     */
    public function store(Request $request, string $projectId): JsonResponse
    {
        $check = $this->ensureProject($request, (int) $projectId);
        if ($check instanceof JsonResponse) {
            return $check;
        }

        $validator = Validator::make(array_merge($request->all(), ['project_id' => (int) $projectId]), [
            'project_id' => 'required|integer|exists:projects,id',
            'issue_id' => 'nullable|integer|exists:issues,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:' . implode(',', self::ALLOWED_STATUSES),
            'priority' => 'nullable|string|in:' . implode(',', self::ALLOWED_PRIORITIES),
            'assignee_id' => 'nullable|integer',
            'due_date' => 'nullable|date',
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
        $data['status'] = $data['status'] ?? 'todo';
        $data['priority'] = $data['priority'] ?? 'medium';

        // Verify issue belongs to same project
        if (!empty($data['issue_id'])) {
            $issue = Issue::find($data['issue_id']);
            if (!$issue || (int) $issue->project_id !== (int) $projectId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Issue does not belong to this project',
                ], 422);
            }
        }

        $task = Task::create($data);

        return response()->json([
            'success' => true,
            'data' => $task->fresh(),
        ], 201);
    }

    /**
     * GET /api/tasks/{id}
     * Show a single task with comments.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $task = Task::with(['project', 'issue'])->find((int) $id);
        if (!$task) {
            return response()->json(['success' => false, 'error' => 'Task not found'], 404);
        }

        $project = Project::find($task->project_id);
        if (!$project || (int) $project->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $comments = \Illuminate\Support\Facades\DB::table('comments')
            ->where('commentable_type', Task::class)
            ->where('commentable_id', $task->id)
            ->orderBy('created_at', 'asc')
            ->get();

        $payload = $task->toArray();
        $payload['comments'] = $comments;

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    /**
     * PUT /api/tasks/{id}
     * Update a task.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $task = Task::find((int) $id);
        if (!$task) {
            return response()->json(['success' => false, 'error' => 'Task not found'], 404);
        }

        $project = Project::find($task->project_id);
        if (!$project || (int) $project->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'issue_id' => 'sometimes|nullable|integer|exists:issues,id',
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'status' => 'sometimes|string|in:' . implode(',', self::ALLOWED_STATUSES),
            'priority' => 'sometimes|string|in:' . implode(',', self::ALLOWED_PRIORITIES),
            'assignee_id' => 'sometimes|nullable|integer',
            'due_date' => 'sometimes|nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Verify issue_id belongs to same project if provided
        if (!empty($data['issue_id'])) {
            $issue = Issue::find($data['issue_id']);
            if (!$issue || (int) $issue->project_id !== (int) $task->project_id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Issue does not belong to this project',
                ], 422);
            }
        }

        $task->fill($data);
        $task->save();

        return response()->json([
            'success' => true,
            'data' => $task->fresh(),
        ]);
    }

    /**
     * DELETE /api/tasks/{id}
     * Delete a task.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $task = Task::find((int) $id);
        if (!$task) {
            return response()->json(['success' => false, 'error' => 'Task not found'], 404);
        }

        $project = Project::find($task->project_id);
        if (!$project || (int) $project->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $task->delete();

        return response()->json([
            'success' => true,
            'data' => ['id' => (int) $id, 'message' => 'Task deleted'],
        ]);
    }

    /**
     * POST /api/projects/{projectId}/tasks/from-issue/{issueId}
     * Create a new task pre-filled from an existing issue.
     *
     * Optional overrides via JSON body: title, description, status, priority, assignee_id, due_date
     */
    public function convertFromIssue(Request $request, string $projectId, string $issueId): JsonResponse
    {
        $check = $this->ensureProject($request, (int) $projectId);
        if ($check instanceof JsonResponse) {
            return $check;
        }

        $issue = Issue::find((int) $issueId);
        if (!$issue) {
            return response()->json(['success' => false, 'error' => 'Issue not found'], 404);
        }

        if ((int) $issue->project_id !== (int) $projectId) {
            return response()->json([
                'success' => false,
                'error' => 'Issue does not belong to this project',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'status' => 'sometimes|string|in:' . implode(',', self::ALLOWED_STATUSES),
            'priority' => 'sometimes|string|in:' . implode(',', self::ALLOWED_PRIORITIES),
            'assignee_id' => 'sometimes|nullable|integer',
            'due_date' => 'sometimes|nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Severity → priority mapping when not explicitly provided
        $severityToPriority = [
            'critical' => 'high',
            'medium' => 'medium',
            'good' => 'low',
        ];

        $user = $this->authUser($request);

        $defaultDescription = trim(implode("\n\n", array_filter([
            $issue->description,
            $issue->problem ? "Problem:\n" . $issue->problem : null,
            $issue->reason ? "Reason:\n" . $issue->reason : null,
            $issue->recommendation ? "Recommendation:\n" . $issue->recommendation : null,
        ])));

        $task = Task::create([
            'project_id' => (int) $projectId,
            'issue_id' => $issue->id,
            'user_id' => $user?->id ?? $issue->user_id,
            'title' => $request->input('title', $issue->title),
            'description' => $request->has('description')
                ? $request->input('description')
                : $defaultDescription,
            'status' => $request->input('status', 'todo'),
            'priority' => $request->input(
                'priority',
                $severityToPriority[$issue->severity] ?? 'medium'
            ),
            'assignee_id' => $request->input('assignee_id'),
            'due_date' => $request->input('due_date'),
        ]);

        return response()->json([
            'success' => true,
            'data' => $task->fresh(),
        ], 201);
    }
}
