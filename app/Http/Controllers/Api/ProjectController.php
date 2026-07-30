<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Analysis;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Report;
use App\Models\Screenshot;
use App\Models\Task;
use App\Models\TeamMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProjectController extends \Illuminate\Routing\Controller
{
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
        static $synced = [];
        try {
            $id = (int) ($authUser['id'] ?? 0);
            if ($id <= 0 || isset($synced[$id])) return;
            $synced[$id] = true;
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
            // If the user table isn't usable, swallow and let the caller surface the FK error.
        }
    }

    /**
     * Find a project that belongs to this user.
     */
    private function findOwned(int $userId, string $id): ?Project
    {
        return Project::query()
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first();
    }

    /**
     * Append a row to the activity timeline (best-effort).
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
            // Activity log is best-effort; don't break the user request.
        }
    }

    /**
     * GET /api/projects
     *
     * Filters: status, search, tags (csv)
     */
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $query = Project::query()->where('user_id', $userId);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('url', 'like', $like);
            });
        }

        if ($tags = $request->query('tags')) {
            $tagList = array_filter(array_map('trim', explode(',', (string) $tags)));
            if (!empty($tagList)) {
                // tags is stored as JSON; SQLite supports json_each; this is portable enough for our MySQL/PG paths too.
                foreach ($tagList as $tag) {
                    $query->whereJsonContains('tags', $tag);
                }
            }
        }

        $sortBy = $request->query('sort_by', 'updated_at');
        $sortDir = $request->query('sort_dir', 'desc');
        if (!in_array($sortBy, ['id', 'name', 'created_at', 'updated_at', 'status'], true)) {
            $sortBy = 'updated_at';
        }
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min($perPage, 200));

        // Eager load counts + screenshots to avoid N+1 queries (was: 3 queries per project = 150+ for 50 items)
        $projects = $query->withCount(['screenshots', 'issues'])
            ->with(['screenshots' => fn($q) => $q->orderBy('created_at', 'asc')->take(6)])
            ->paginate($perPage);

        // Decorate with thumbnails — no additional queries needed
        $projects->getCollection()->transform(function (Project $project) {
            $screenshots = $project->screenshots;
            $settings = is_array($project->settings) ? $project->settings : [];
            $templateScreens = $settings['screens'] ?? [];
            if ($screenshots->isEmpty() && !empty($templateScreens)) {
                $project->screens = $templateScreens;
                $firstScreen = $templateScreens[0] ?? null;
                $firstElement = $firstScreen['elements'][0] ?? null;
                $bg = $firstElement['props']['backgroundColor'] ?? $firstScreen['background'] ?? null;
                if ($bg) {
                    $project->thumbnail = 'data:image/svg+xml,' . rawurlencode(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="225"><rect fill="' . htmlspecialchars($bg) . '" width="400" height="225"/></svg>'
                    );
                }
            } else {
                $project->screens = $screenshots;
                $project->thumbnail = $screenshots->first()?->url ?? null;
            }
            return $project;
        });

        return response()->json([
            'success' => true,
            'data' => $projects->items(),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    /**
     * POST /api/projects
     */
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'url' => 'nullable|string|max:2048|url',
            'template' => 'nullable|string|max:255',
            'template_id' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,archived,draft',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:64',
            'settings' => 'nullable|array',
            'screens' => 'nullable|array',
            'device' => 'nullable|string|in:web,mobile,tablet',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $settings = $request->input('settings', []);
        // Store template screens in settings
        if ($request->has('screens')) {
            $settings['screens'] = $request->input('screens');
        }
        if ($request->has('device')) {
            $settings['device'] = $request->input('device');
        }

        $project = Project::create([
            'user_id' => $userId,
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'url' => $request->input('url'),
            'template' => $request->input('template_id') ?: $request->input('template'),
            'status' => $request->input('status', 'active'),
            'tags' => $request->input('tags', []),
            'settings' => $settings,
        ]);

        $this->log($userId, $project->id, 'project.created', 'project', $project->id, ['description' => "Created project \"{$project->name}\""]);

        $project->screenshots_count = 0;
        $project->issues_count = 0;

        return response()->json(['success' => true, 'data' => $project], 201);
    }

    /**
     * GET /api/projects/{id}
     */
    public function show(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = Project::withCount(['screenshots', 'issues', 'analyses', 'tasks', 'teamMembers', 'reports'])
            ->find($id);
        if (!$project || (int) $project->user_id !== (int) $userId) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $project->screenshots_count = (int) $project->screenshots_count;
        $project->issues_count = (int) $project->issues_count;
        $project->analyses_count = (int) $project->analyses_count;
        $project->tasks_count = (int) $project->tasks_count;
        $project->team_count = (int) $project->team_members_count;
        $project->reports_count = (int) $project->reports_count;

        // Return screens from settings (settings is already cast to array by Eloquent)
        $settings = is_array($project->settings) ? $project->settings : [];
        if (!empty($settings['screens'])) {
            $project->screens = $settings['screens'];
        }
        if (!empty($settings['device'])) {
            $project->device = $settings['device'];
        }

        return response()->json(['success' => true, 'data' => $project]);
    }

    /**
     * PUT /api/projects/{id}
     */
    public function update(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwned($userId, $id);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $v = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'url' => 'sometimes|nullable|string|max:2048|url',
            'template' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|string|in:active,archived,draft',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:64',
            'settings' => 'sometimes|array',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $changes = [];
        foreach (['name', 'description', 'url', 'template', 'status', 'tags', 'settings'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = $request->input($field);
            }
        }
        if (!empty($changes)) {
            $project->fill($changes);
            $project->save();
        }

        $this->log($userId, $project->id, 'project.updated', 'project', $project->id, ['description' => "Updated project \"{$project->name}\"", 'fields' => array_keys($changes)]);

        $project->screenshots_count = (int) $project->screenshots()->count();
        $project->issues_count = (int) $project->issues()->count();

        return response()->json(['success' => true, 'data' => $project]);
    }

    /**
     * POST /api/projects/{id}/duplicate
     */
    public function duplicate(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $source = $this->findOwned($userId, $id);
        if (!$source) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $newName = $request->input('name') ?: ($source->name . ' (Copy)');

        $copy = Project::create([
            'user_id' => $userId,
            'name' => $newName,
            'description' => $source->description,
            'url' => $source->url,
            'template' => $source->template,
            'status' => 'draft',
            'tags' => $source->tags,
            'settings' => $source->settings,
        ]);

        $this->log($userId, $copy->id, 'project.duplicated', 'project', $copy->id, [
            'description' => "Duplicated project \"{$source->name}\" as \"{$copy->name}\"",
            'source_project_id' => $source->id,
        ]);

        $copy->screenshots_count = 0;
        $copy->issues_count = 0;

        return response()->json(['success' => true, 'data' => $copy], 201);
    }

    /**
     * POST /api/projects/{id}/archive
     */
    public function archive(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwned($userId, $id);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $project->status = 'archived';
        $project->archived_at = now();
        $project->save();

        $this->log($userId, $project->id, 'project.archived', 'project', $project->id, ['description' => "Archived project \"{$project->name}\""]);

        return response()->json(['success' => true, 'data' => $project]);
    }

    /**
     * DELETE /api/projects/{id}
     */
    public function destroy(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwned($userId, $id);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $name = $project->name;
        $project->delete();

        $this->log($userId, (int) $id, 'project.deleted', null, null, ['description' => "Deleted project \"{$name}\""]);

        return response()->json(['success' => true, 'message' => 'Project deleted']);
    }

    /**
     * GET /api/projects/templates
     */
    public function templates(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = ProjectTemplate::query();

        $category = $request->query('category');
        if ($category) {
            $query->where(function ($q) use ($category) {
                $q->whereJsonContains('categories', $category)
                    ->orWhere('description', 'like', '%' . $category . '%');
            });
        }

        $userId = $this->userId($request);
        // Show the user's own templates plus any public ones.
        if ($userId !== null) {
            $query->where(function ($q) use ($userId) {
                $q->where('is_public', true)->orWhere('user_id', $userId);
            });
        } else {
            $query->where('is_public', true);
        }

        $templates = $query->orderBy('name')->get();

        // Always also include the built-in starter catalog (mirrors TemplateController)
        $builtIn = $this->builtInTemplates();
        if ($category) {
            $builtIn = array_values(array_filter($builtIn, fn ($t) => ($t['category'] ?? null) === $category));
        }

        return response()->json([
            'success' => true,
            'data' => [
                'templates' => $templates,
                'built_in' => $builtIn,
                'count' => $templates->count() + count($builtIn),
            ],
        ]);
    }

    /**
     * GET /api/projects/categories
     */
    public function categories(Request $request): \Illuminate\Http\JsonResponse
    {
        $categories = [];
        foreach (ProjectTemplate::pluck('categories') as $cats) {
            if (is_array($cats)) {
                $categories = array_merge($categories, $cats);
            }
        }
        foreach ($this->builtInTemplates() as $t) {
            if (!empty($t['category'])) {
                $categories[] = $t['category'];
            }
        }
        $categories = array_values(array_unique(array_filter($categories)));

        // Fallback list so the UI never gets an empty array.
        if (empty($categories)) {
            $categories = ['dashboard', 'mobile', 'marketing', 'ecommerce', 'portfolio', 'blog', 'product', 'landing'];
        }

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * GET /api/projects/tags
     */
    public function tags(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $counts = [];
        $projects = Project::query()
            ->where('user_id', $userId)
            ->whereNotNull('tags')
            ->get(['tags']);

        foreach ($projects as $p) {
            $tags = $p->tags;
            if (is_string($tags)) {
                $tags = json_decode($tags, true);
            }
            if (!is_array($tags)) continue;
            foreach ($tags as $tag) {
                $tag = (string) $tag;
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        $tags = [];
        foreach ($counts as $name => $count) {
            $tags[] = ['name' => $name, 'count' => $count];
        }
        usort($tags, fn ($a, $b) => ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']));

        return response()->json([
            'success' => true,
            'data' => $tags,
        ]);
    }

    /**
     * GET /api/projects/{id}/team
     */
    public function team(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwned($userId, $id);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $members = $project->teamMembers()->orderBy('created_at')->get();

        return response()->json(['success' => true, 'data' => $members]);
    }

    /**
     * POST /api/projects/{id}/team
     */
    public function addMember(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwned($userId, $id);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $v = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'role' => 'required|string|in:owner,admin,editor,viewer',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $email = strtolower(trim($request->input('email')));
        $role = $request->input('role');

        // Prevent duplicate pending/accepted invites for the same email on the same project.
        $existing = $project->teamMembers()->where('email', $email)->first();
        if ($existing) {
            return response()->json([
                'error' => 'This email is already invited to this project',
                'data' => $existing,
            ], 409);
        }

        $member = TeamMember::create([
            'project_id' => $project->id,
            'user_id' => $userId, // inviter; assigned when invitee accepts
            'email' => $email,
            'role' => $role,
            'status' => 'pending',
            'invited_at' => now(),
        ]);

        $this->log($userId, $project->id, 'team.member_invited', 'team_member', $member->id, [
            'description' => "Invited {$email} as {$role}",
            'email' => $email,
            'role' => $role,
        ]);

        return response()->json(['success' => true, 'data' => $member], 201);
    }

    /**
     * DELETE /api/projects/{id}/team/{memberId}
     */
    public function removeMember(Request $request, string $id, string $memberId): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwned($userId, $id);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $member = $project->teamMembers()->where('id', $memberId)->first();
        if (!$member) {
            return response()->json(['error' => 'Team member not found'], 404);
        }

        // Owners cannot be removed.
        if ($member->role === 'owner') {
            return response()->json(['error' => 'Cannot remove the project owner'], 422);
        }

        $email = $member->email;
        $member->delete();

        $this->log($userId, $project->id, 'team.member_removed', 'team_member', (int) $memberId, [
            'description' => "Removed {$email} from the team",
            'email' => $email,
        ]);

        return response()->json(['success' => true, 'message' => 'Team member removed']);
    }

    /**
     * GET /api/projects/{id}/timeline
     */
    public function timeline(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwned($userId, $id);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $limit = (int) $request->query('limit', 100);
        $limit = max(1, min($limit, 500));

        $logs = ActivityLog::query()
            ->where('project_id', $project->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $logs,
            'meta' => ['count' => $logs->count()],
        ]);
    }

    /**
     * Static built-in template catalog (also exposed via /api/templates).
     */
    private function builtInTemplates(): array
    {
        return [
            [
                'id' => 'tmpl_saas_dashboard',
                'name' => 'SaaS Dashboard',
                'description' => 'Complete SaaS dashboard with charts, stats, and navigation',
                'category' => 'dashboard',
                'is_public' => true,
                'is_built_in' => true,
            ],
            [
                'id' => 'tmpl_mobile_app',
                'name' => 'Mobile App',
                'description' => 'iOS/Android style mobile app with bottom navigation',
                'category' => 'mobile',
                'is_public' => true,
                'is_built_in' => true,
            ],
            [
                'id' => 'tmpl_landing_page',
                'name' => 'Landing Page',
                'description' => 'Modern SaaS landing page with hero and pricing',
                'category' => 'marketing',
                'is_public' => true,
                'is_built_in' => true,
            ],
            [
                'id' => 'tmpl_e_commerce',
                'name' => 'E-Commerce',
                'description' => 'Online store with product grid and cart',
                'category' => 'ecommerce',
                'is_public' => true,
                'is_built_in' => true,
            ],
            [
                'id' => 'tmpl_portfolio',
                'name' => 'Designer Portfolio',
                'description' => 'Minimalist portfolio for designers and creatives',
                'category' => 'portfolio',
                'is_public' => true,
                'is_built_in' => true,
            ],
            [
                'id' => 'tmpl_blog',
                'name' => 'Blog / CMS',
                'description' => 'Content-focused blog with article layout',
                'category' => 'blog',
                'is_public' => true,
                'is_built_in' => true,
            ],
        ];
    }
}
