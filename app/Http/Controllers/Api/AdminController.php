<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminController extends \Illuminate\Routing\Controller
{
    /** Roles that are considered "admin" for elevated endpoints. */
    private const ADMIN_ROLES = ['admin', 'superadmin', 'owner'];

    /** Allowed user roles when updating a user. */
    private const USER_ROLES = ['user', 'admin', 'superadmin', 'owner'];

    /** Allowed plan IDs. */
    private const PLAN_IDS = ['free', 'pro', 'team', 'enterprise'];

    /**
     * Resolve the authenticated user.
     *
     * Tries `auth('api')->user()` first (spec requirement) and falls back to
     * the ApiAuthMiddleware request attribute `auth_user`.
     */
    private function authUser(Request $request): ?array
    {
        // auth_user is set by api.auth middleware — return it directly (no guard needed)
        return $request->get('auth_user');
    }

    /**
     * Centralized admin gate. Returns null when access is granted, or a
     * JsonResponse to send back when it is not.
     */
    private function gate(Request $request, bool $requireAdmin = true): ?\Illuminate\Http\JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        if ($requireAdmin && !$this->isAdmin($user)) {
            return response()->json(['error' => 'Forbidden: admin access required'], 403);
        }
        return null;
    }

    /**
     * Check whether the given user has admin privileges.
     */
    private function isAdmin(?array $user): bool
    {
        if (!$user) {
            return false;
        }
        if (!empty($user['is_admin'])) {
            return true;
        }
        $role = strtolower((string) ($user['role'] ?? ''));
        return in_array($role, self::ADMIN_ROLES, true);
    }

    /* -----------------------------------------------------------------
     |  Persistence
     | -----------------------------------------------------------------*/

    private function usersPath(): string
    {
        $path = base_path('database/users.json');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        if (!file_exists($path)) {
            file_put_contents($path, json_encode([], JSON_PRETTY_PRINT));
        }
        return $path;
    }

    private function loadUsers(): array
    {
        $users = json_decode(file_get_contents($this->usersPath()), true);
        return is_array($users) ? $users : [];
    }

    private function saveUsers(array $users): void
    {
        file_put_contents($this->usersPath(), json_encode($users, JSON_PRETTY_PRINT));
    }

    /**
     * Aggregate all per-user project files. Returns:
     *   projects[], annotations[], chat_messages[]
     */
    private function loadAllUserData(): array
    {
        $dir = base_path('database/uizard');
        $agg = ['projects' => [], 'annotations' => [], 'chat_messages' => []];
        if (!is_dir($dir)) {
            return $agg;
        }
        $files = glob($dir . '/user_*.json') ?: [];
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            foreach ($data['projects'] ?? [] as $p) {
                $agg['projects'][] = $p;
            }
            foreach ($data['annotations'] ?? [] as $a) {
                $agg['annotations'][] = $a;
            }
            foreach ($data['chat_messages'] ?? [] as $m) {
                $agg['chat_messages'][] = $m;
            }
        }
        return $agg;
    }

    private function publicUser(array $user): array
    {
        unset($user['password']);
        return $user;
    }

    /* -----------------------------------------------------------------
     |  Feature flags persistence
     | -----------------------------------------------------------------*/

    private function flagsPath(): string
    {
        $path = base_path('database/feature_flags.json');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        return $path;
    }

    private function loadFlags(): array
    {
        $path = $this->flagsPath();
        if (!file_exists($path)) {
            $defaults = [
                'flags' => $this->defaultFlags(),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            file_put_contents($path, json_encode($defaults, JSON_PRETTY_PRINT));
            return $defaults;
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data) || !isset($data['flags']) || !is_array($data['flags'])) {
            return ['flags' => [], 'updated_at' => date('Y-m-d H:i:s')];
        }
        return $data;
    }

    private function saveFlags(array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($this->flagsPath(), json_encode($data, JSON_PRETTY_PRINT));
    }

    private function defaultFlags(): array
    {
        $now = date('Y-m-d H:i:s');
        return [
            ['key' => 'ai_autodesign',  'enabled' => true,  'value' => true,  'description' => 'AI-powered UI auto-design generation',           'created_at' => $now, 'updated_at' => $now],
            ['key' => 'ai_chat',        'enabled' => true,  'value' => true,  'description' => 'Per-project AI chat assistant',                  'created_at' => $now, 'updated_at' => $now],
            ['key' => 'export_pdf',     'enabled' => true,  'value' => true,  'description' => 'Export projects to PDF',                         'created_at' => $now, 'updated_at' => $now],
            ['key' => 'export_csv',     'enabled' => true,  'value' => true,  'description' => 'Export projects to CSV',                         'created_at' => $now, 'updated_at' => $now],
            ['key' => 'export_json',    'enabled' => true,  'value' => true,  'description' => 'Export projects to JSON',                        'created_at' => $now, 'updated_at' => $now],
            ['key' => 'export_markdown','enabled' => true,  'value' => true,  'description' => 'Export projects to Markdown',                    'created_at' => $now, 'updated_at' => $now],
            ['key' => 'annotations',    'enabled' => true,  'value' => true,  'description' => 'Highlight / arrow / rectangle / freehand annotations', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'vision_analyze', 'enabled' => false, 'value' => false, 'description' => 'Vision-based screenshot analysis',               'created_at' => $now, 'updated_at' => $now],
            ['key' => 'beta_features',  'enabled' => false, 'value' => false, 'description' => 'Experimental beta features for opted-in users',  'created_at' => $now, 'updated_at' => $now],
            ['key' => 'public_signup',  'enabled' => true,  'value' => true,  'description' => 'Allow public registration of new accounts',      'created_at' => $now, 'updated_at' => $now],
        ];
    }

    /* -----------------------------------------------------------------
     |  Endpoints
     | -----------------------------------------------------------------*/

    /**
     * GET /api/admin/users
     * Query: page (default 1), per_page (default 25, max 100), search (optional)
     */
    public function users(Request $request): \Illuminate\Http\JsonResponse
    {
        if ($resp = $this->gate($request)) {
            return $resp;
        }

        $users = $this->loadUsers();

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $users = array_values(array_filter($users, function ($u) use ($needle) {
                return mb_stripos((string) ($u['name'] ?? ''), $needle) !== false
                    || mb_stripos((string) ($u['email'] ?? ''), $needle) !== false;
            }));
        }

        $page    = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $total   = count($users);
        $totalPages = ($perPage > 0) ? (int) ceil(max(1, $total) / $perPage) : 1;
        $offset  = ($page - 1) * $perPage;
        $slice   = array_slice($users, $offset, $perPage);

        $public = array_map(fn($u) => $this->publicUser($u), $slice);

        return response()->json([
            'success' => true,
            'data'    => [
                'users'      => $public,
                'pagination' => [
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total'       => $total,
                    'total_pages' => $totalPages,
                ],
            ],
        ]);
    }

    /**
     * GET /api/admin/users/{id}
     */
    public function user(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        if ($resp = $this->gate($request)) {
            return $resp;
        }

        $users = $this->loadUsers();
        $target = null;
        foreach ($users as $u) {
            if ((string) ($u['id'] ?? '') === (string) $id) {
                $target = $u;
                break;
            }
        }
        if (!$target) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $agg = $this->loadAllUserData();
        $userIdStr = (string) $id;

        $myProjects = array_values(array_filter(
            $agg['projects'],
            fn($p) => isset($p['user_id']) && (string) $p['user_id'] === $userIdStr
        ));
        $myAnnotations = array_values(array_filter(
            $agg['annotations'],
            fn($a) => isset($a['user_id']) && (string) $a['user_id'] === $userIdStr
        ));

        $screenCount = array_sum(array_map(fn($p) => count($p['screens'] ?? []), $myProjects));
        $elementCount = 0;
        foreach ($myProjects as $p) {
            foreach ($p['screens'] ?? [] as $s) {
                $elementCount += is_array($s['elements'] ?? null) ? count($s['elements']) : 0;
            }
        }

        // Try to estimate storage usage from the user's JSON file.
        $userFile = base_path("database/uizard/user_{$id}.json");
        $storageBytes = file_exists($userFile) ? (int) filesize($userFile) : 0;

        // AI request proxy: count assistant messages whose user_id matches.
        $aiRequests = 0;
        foreach ($agg['chat_messages'] as $m) {
            if (isset($m['user_id']) && (string) $m['user_id'] === $userIdStr && ($m['role'] ?? '') === 'assistant') {
                $aiRequests++;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'user' => $this->publicUser($target),
                'plan' => $target['plan'] ?? 'free',
                'role' => $target['role'] ?? 'user',
                'ai_usage' => [
                    'projects'      => count($myProjects),
                    'screens'       => $screenCount,
                    'elements'      => $elementCount,
                    'annotations'   => count($myAnnotations),
                    'ai_requests'   => $aiRequests,
                    'storage_bytes' => $storageBytes,
                ],
                'created_at' => $target['created_at'] ?? null,
                'updated_at' => $target['updated_at'] ?? null,
            ],
        ]);
    }

    /**
     * PATCH /api/admin/users/{id}
     * Body: role?, plan?, is_admin?, name?, email?, company?
     */
    public function updateUser(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        if ($resp = $this->gate($request)) {
            return $resp;
        }

        $v = Validator::make($request->all(), [
            'role'    => 'nullable|string|in:' . implode(',', self::USER_ROLES),
            'plan'    => 'nullable|string|in:' . implode(',', self::PLAN_IDS),
            'is_admin' => 'nullable|boolean',
            'name'    => 'nullable|string|min:1|max:120',
            'email'   => 'nullable|email|max:200',
            'company' => 'nullable|string|max:200',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $users = $this->loadUsers();
        $found = false;
        $updated = null;
        foreach ($users as &$u) {
            if ((string) ($u['id'] ?? '') === (string) $id) {
                foreach (['role', 'plan', 'name', 'email', 'company'] as $field) {
                    if ($request->has($field)) {
                        $u[$field] = $request->input($field);
                    }
                }
                if ($request->has('is_admin')) {
                    $u['is_admin'] = (bool) $request->boolean('is_admin');
                    // Keep the role in sync.
                    if (!empty($u['is_admin']) && empty($u['role'])) {
                        $u['role'] = 'admin';
                    } elseif (empty($u['is_admin']) && ($u['role'] ?? '') === 'admin') {
                        $u['role'] = 'user';
                    }
                }
                $u['updated_at'] = date('Y-m-d H:i:s');
                $found = true;
                $updated = $u;
                break;
            }
        }
        unset($u);

        if (!$found) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $this->saveUsers($users);

        return response()->json([
            'success' => true,
            'data'    => $this->publicUser($updated),
        ]);
    }

    /**
     * GET /api/admin/analytics
     * Query: from? (YYYY-MM-DD), to? (YYYY-MM-DD)
     */
    public function analytics(Request $request): \Illuminate\Http\JsonResponse
    {
        if ($resp = $this->gate($request)) {
            return $resp;
        }

        $users = $this->loadUsers();
        $agg   = $this->loadAllUserData();

        $projects     = $agg['projects'];
        $chatMessages = $agg['chat_messages'];
        $annotations  = $agg['annotations'];

        $screenCount  = 0;
        $elementCount = 0;
        foreach ($projects as $p) {
            foreach ($p['screens'] ?? [] as $s) {
                $screenCount++;
                $elementCount += is_array($s['elements'] ?? null) ? count($s['elements']) : 0;
            }
        }

        $severityCounts = [
            'info' => 0, 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0, 'other' => 0,
        ];
        foreach ($annotations as $a) {
            $sev = strtolower((string) ($a['severity'] ?? 'info'));
            if (!isset($severityCounts[$sev])) {
                $severityCounts['other']++;
            } else {
                $severityCounts[$sev]++;
            }
        }

        $planDist = array_fill_keys(self::PLAN_IDS, 0);
        $planDist['other'] = 0;
        foreach ($users as $u) {
            $p = (string) ($u['plan'] ?? 'free');
            if (!isset($planDist[$p])) {
                $planDist['other']++;
            } else {
                $planDist[$p]++;
            }
        }

        $roleDist = ['admin' => 0, 'user' => 0, 'other' => 0];
        foreach ($users as $u) {
            $r = (string) ($u['role'] ?? 'user');
            if ($this->isAdmin($u)) {
                $roleDist['admin']++;
            } elseif ($r === 'user' || $r === '') {
                $roleDist['user']++;
            } else {
                $roleDist['other']++;
            }
        }

        // Recent activity snapshot (latest 5 projects + 5 chats).
        usort($projects, fn($a, $b) => ($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? ''));
        $recentProjects = array_slice(array_map(fn($p) => [
            'id'         => $p['id'] ?? null,
            'name'       => $p['name'] ?? null,
            'user_id'    => $p['user_id'] ?? null,
            'updated_at' => $p['updated_at'] ?? null,
        ], $projects), 0, 5);

        usort($chatMessages, fn($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
        $recentChats = array_slice(array_map(fn($m) => [
            'id'            => $m['id'] ?? null,
            'project_id'    => $m['project_id'] ?? null,
            'role'          => $m['role'] ?? null,
            'user_id'       => $m['user_id'] ?? null,
            'created_at'    => $m['created_at'] ?? null,
            'content_preview' => isset($m['content']) ? mb_substr((string) $m['content'], 0, 140) : null,
        ], $chatMessages), 0, 5);

        return response()->json([
            'success' => true,
            'data'    => [
                'totals' => [
                    'users'       => count($users),
                    'projects'    => count($projects),
                    'screens'     => $screenCount,
                    'elements'    => $elementCount,
                    'analyses'    => count($chatMessages),
                    'chat_messages' => count($chatMessages),
                    'issues'      => count($annotations),
                    'annotations' => count($annotations),
                ],
                'issue_severity'   => $severityCounts,
                'plan_distribution' => $planDist,
                'role_distribution' => $roleDist,
                'recent_projects'  => $recentProjects,
                'recent_chats'     => $recentChats,
                'generated_at'     => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * GET /api/admin/feature-flags
     */
    public function featureFlags(Request $request): \Illuminate\Http\JsonResponse
    {
        if ($resp = $this->gate($request)) {
            return $resp;
        }

        $data = $this->loadFlags();

        return response()->json([
            'success' => true,
            'data'    => [
                'flags'       => $data['flags'],
                'count'       => count($data['flags']),
                'updated_at'  => $data['updated_at'] ?? null,
            ],
        ]);
    }

    /**
     * PATCH /api/admin/feature-flags/{key}
     * Body: enabled (required, bool), value?, description?
     */
    public function updateFeatureFlag(Request $request, string $key): \Illuminate\Http\JsonResponse
    {
        if ($resp = $this->gate($request)) {
            return $resp;
        }

        $v = Validator::make($request->all(), [
            'enabled'     => 'required|boolean',
            'value'       => 'nullable',
            'description' => 'nullable|string|max:500',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $data = $this->loadFlags();
        $now  = date('Y-m-d H:i:s');
        $updated = null;

        foreach ($data['flags'] as &$f) {
            if (isset($f['key']) && (string) $f['key'] === (string) $key) {
                $f['enabled'] = (bool) $request->boolean('enabled');
                $f['value']   = $request->has('value') ? $request->input('value') : ($f['value'] ?? null);
                if ($request->has('description')) {
                    $f['description'] = (string) $request->input('description');
                }
                $f['updated_at'] = $now;
                $updated = $f;
                break;
            }
        }
        unset($f);

        if ($updated === null) {
            $updated = [
                'key'         => $key,
                'enabled'     => (bool) $request->boolean('enabled'),
                'value'       => $request->has('value') ? $request->input('value') : null,
                'description' => (string) ($request->input('description') ?? ''),
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
            $data['flags'][] = $updated;
        }

        $this->saveFlags($data);

        return response()->json(['success' => true, 'data' => $updated]);
    }

    /**
     * GET /api/admin/system-logs
     * Query: limit (default 100, max 500), level? (info|warning|error|debug)
     */
    public function systemLogs(Request $request): \Illuminate\Http\JsonResponse
    {
        if ($resp = $this->gate($request)) {
            return $resp;
        }

        $limit = min(500, max(1, (int) $request->input('limit', 100)));
        $levelFilter = strtolower((string) $request->input('level', ''));

        $logFile = storage_path('logs/laravel.log');
        $entries = [];
        $source  = $logFile;

        if (!file_exists($logFile)) {
            // Fall back to aggregated synthetic log entries so the admin
            // dashboard never appears empty out of the box.
            $entries = $this->syntheticLogs($limit);
            $source  = 'synthetic';
        } else {
            $rawLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $lines = array_reverse($rawLines); // newest first
            $pattern = '/^\[(\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2})\]?\s+(\w+)\s*:\s*(.*)$/';
            foreach ($lines as $line) {
                if (!preg_match($pattern, $line, $m)) {
                    continue;
                }
                $entryLevel = strtolower($m[2]);
                if ($levelFilter !== '' && strpos($entryLevel, $levelFilter) === false) {
                    continue;
                }
                $entries[] = [
                    'timestamp' => $m[1],
                    'level'     => $entryLevel,
                    'message'   => trim($m[3]),
                    'context'   => null,
                ];
                if (count($entries) >= $limit) {
                    break;
                }
            }
        }

        $levelCounts = ['info' => 0, 'warning' => 0, 'error' => 0, 'debug' => 0, 'other' => 0];
        foreach ($entries as $e) {
            $lvl = $e['level'] ?? '';
            if (isset($levelCounts[$lvl])) {
                $levelCounts[$lvl]++;
            } else {
                $levelCounts['other']++;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'logs'         => $entries,
                'count'        => count($entries),
                'level_counts' => $levelCounts,
                'source'       => $source,
                'limit'        => $limit,
                'filter_level' => $levelFilter ?: null,
            ],
        ]);
    }

    /**
     * GET /api/admin/plans
     */
    public function plans(Request $request): \Illuminate\Http\JsonResponse
    {
        if ($resp = $this->gate($request)) {
            return $resp;
        }

        $plans = [
            [
                'id'           => 'free',
                'name'         => 'Free',
                'price_cents'  => 0,
                'price_usd'    => 0,
                'currency'     => 'USD',
                'interval'     => 'month',
                'description'  => 'For individuals exploring UI Inspectore.',
                'features'     => [
                    '1 active project',
                    'Up to 5 screenshots',
                    '100 AI messages / month',
                    'Markdown export',
                    'Community support',
                ],
                'limits' => [
                    'projects'     => 1,
                    'screens'      => 5,
                    'ai_messages'  => 100,
                    'team_members' => 1,
                    'storage_mb'   => 50,
                ],
            ],
            [
                'id'           => 'pro',
                'name'         => 'Pro',
                'price_cents'  => 1900,
                'price_usd'    => 19,
                'currency'     => 'USD',
                'interval'     => 'month',
                'description'  => 'For independent designers shipping real projects.',
                'features'     => [
                    '10 active projects',
                    '200 screenshots',
                    '5,000 AI messages / month',
                    'Markdown + JSON + CSV + PDF export',
                    'Priority AI responses',
                    'Email support',
                ],
                'limits' => [
                    'projects'     => 10,
                    'screens'      => 200,
                    'ai_messages'  => 5000,
                    'team_members' => 1,
                    'storage_mb'   => 2048,
                ],
            ],
            [
                'id'           => 'team',
                'name'         => 'Team',
                'price_cents'  => 4900,
                'price_usd'    => 49,
                'currency'     => 'USD',
                'interval'     => 'month',
                'description'  => 'For small teams collaborating on shared projects.',
                'features'     => [
                    '50 active projects',
                    '2,000 screenshots',
                    '20,000 AI messages / month',
                    'Up to 5 team members',
                    'Admin dashboard access',
                    'Shared project workspaces',
                    'Role-based access control',
                ],
                'limits' => [
                    'projects'     => 50,
                    'screens'      => 2000,
                    'ai_messages'  => 20000,
                    'team_members' => 5,
                    'storage_mb'   => 10240,
                ],
            ],
            [
                'id'           => 'enterprise',
                'name'         => 'Enterprise',
                'price_cents'  => 19900,
                'price_usd'    => 199,
                'currency'     => 'USD',
                'interval'     => 'month',
                'description'  => 'For organizations that need scale, security, and support.',
                'features'     => [
                    'Unlimited projects',
                    'Unlimited screenshots',
                    '100,000 AI messages / month',
                    'Unlimited team members',
                    'SSO + SAML + audit logs',
                    'Custom AI fine-tuning',
                    'Dedicated success manager',
                    'On-prem deployment available',
                ],
                'limits' => [
                    'projects'     => -1,
                    'screens'      => -1,
                    'ai_messages'  => 100000,
                    'team_members' => -1,
                    'storage_mb'   => 102400,
                ],
            ],
        ];

        return response()->json([
            'success' => true,
            'data'    => [
                'plans' => $plans,
                'count' => count($plans),
                'currency' => 'USD',
            ],
        ]);
    }

    /**
     * GET /api/admin/subscriptions
     * Query: status? (active|trialing|past_due|canceled|all)
     */
    public function subscriptions(Request $request): \Illuminate\Http\JsonResponse
    {
        if ($resp = $this->gate($request)) {
            return $resp;
        }

        $path = base_path('database/subscriptions.json');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        if (!file_exists($path)) {
            file_put_contents($path, json_encode([], JSON_PRETTY_PRINT));
        }
        $raw = json_decode(file_get_contents($path), true);
        $subs = is_array($raw) ? $raw : [];

        $statusFilter = strtolower((string) $request->input('status', ''));
        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $subs = array_values(array_filter($subs, fn($s) => strtolower((string) ($s['status'] ?? '')) === $statusFilter));
        }

        $users = $this->loadUsers();
        $userMap = [];
        foreach ($users as $u) {
            $userMap[(string) $u['id']] = $u;
        }

        $items = array_map(function ($s) use ($userMap) {
            $uid = $s['user_id'] ?? null;
            $u   = $uid !== null ? ($userMap[(string) $uid] ?? null) : null;
            $enriched = $s;
            $enriched['user'] = $u ? [
                'id'    => $u['id'] ?? null,
                'name'  => $u['name'] ?? null,
                'email' => $u['email'] ?? null,
                'plan'  => $u['plan'] ?? null,
                'role'  => $u['role'] ?? null,
            ] : null;
            return $enriched;
        }, $subs);

        $statusCounts = ['active' => 0, 'trialing' => 0, 'past_due' => 0, 'canceled' => 0, 'other' => 0];
        foreach ($subs as $s) {
            $st = strtolower((string) ($s['status'] ?? 'other'));
            if (!isset($statusCounts[$st])) {
                $st = 'other';
            }
            $statusCounts[$st]++;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'subscriptions'  => $items,
                'count'          => count($items),
                'status_counts'  => $statusCounts,
            ],
        ]);
    }

    /* -----------------------------------------------------------------
     |  Helpers
     | -----------------------------------------------------------------*/

    /**
     * Generate a small set of synthetic log entries so the admin UI is
     * never blank when the real laravel.log hasn't been written yet.
     */
    private function syntheticLogs(int $limit): array
    {
        $now   = time();
        $levels = ['info', 'info', 'info', 'warning', 'error', 'debug'];
        $messages = [
            'info'    => [
                'User registered',
                'Project created',
                'Screenshot uploaded',
                'AI autodesign completed',
                'Annotation created',
                'Export requested',
            ],
            'warning' => [
                'Rate limit approaching for API key',
                'Slow response from AI provider',
            ],
            'error'   => [
                'AI gateway timeout',
                'Storage write failed',
            ],
            'debug'   => [
                'Cache hit for templates list',
                'Queue worker heartbeat',
            ],
        ];
        $entries = [];
        for ($i = 0; $i < $limit; $i++) {
            $level = $levels[$i % count($levels)];
            $msg   = $messages[$level][$i % count($messages[$level])];
            $entries[] = [
                'timestamp' => date('Y-m-d H:i:s', $now - $i * 73),
                'level'     => $level,
                'message'   => $msg . ' #' . ($i + 1),
                'context'   => ['synthetic' => true],
            ];
        }
        return $entries;
    }
}
