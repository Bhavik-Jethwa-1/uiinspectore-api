<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use App\Models\UserSetting;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('id', $search);
            });
        }

        if ($request->input('role') === 'admin') {
            $query->where('is_admin', true);
        } elseif ($request->input('role') === 'user') {
            $query->where('is_admin', false);
        }

        if ($request->input('status') === 'active') {
            $query->where('is_active', true);
        } elseif ($request->input('status') === 'suspended') {
            $query->where('is_active', false);
        }

        $sort = $request->input('sort', 'newest');
        switch ($sort) {
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'name_asc':
                $query->orderBy('name', 'asc');
                break;
            case 'name_desc':
                $query->orderBy('name', 'desc');
                break;
            case 'last_active':
                $query->orderByRaw("COALESCE((SELECT MAX(created_at) FROM activity_logs WHERE activity_logs.user_id = users.id), users.updated_at) DESC");
                break;
            default:
                $query->orderBy('created_at', 'desc');
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $users = $query->withCount(['projects', 'reviews'])->paginate($perPage);

        // Append last_activity to each user item
        $users->getCollection()->transform(function ($user) {
            $user->last_activity = $user->activityLogs()->latest()->value('created_at');
            return $user;
        });

        return response()->json([
            'users' => $users->items(),
            'total' => $users->total(),
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'per_page' => $users->perPage(),
        ]);
    }

    /**
     * GET /api/admin/users/{id}
     * Returns full user detail: user info, paginated projects with reviews, paginated activity logs, settings.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = User::withCount(['projects', 'reviews'])->find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $perPage = min((int) $request->input('per_page', 10), 50);
        $projectsPage = max((int) $request->input('projects_page', 1), 1);
        $activitiesPage = max((int) $request->input('activities_page', 1), 1);

        // Paginated projects with reviews
        $projectsPaginator = $user->projects()
            ->with(['reviews:id,project_id,status,persona,created_at,updated_at'])
            ->orderByDesc('created_at')
            ->paginate($perPage, ['id', 'name', 'description', 'created_at', 'updated_at'], 'projects_page', $projectsPage);

        $projects = collect($projectsPaginator->items())->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'created_at' => $p->created_at,
            'updated_at' => $p->updated_at,
            'reviews_count' => $p->reviews->count(),
            'reviews' => $p->reviews->map(fn($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'persona' => $r->persona,
                'created_at' => $r->created_at,
            ])->values(),
        ]);

        // Paginated activity logs
        $activitiesPaginator = $user->activityLogs()
            ->orderByDesc('created_at')
            ->paginate($perPage, ['id', 'action', 'description', 'meta', 'created_at'], 'activities_page', $activitiesPage);

        $activities = collect($activitiesPaginator->items())->map(fn($a) => [
            'id' => $a->id,
            'action' => $a->action,
            'description' => $a->description,
            'meta' => $a->meta,
            'created_at' => $a->created_at,
        ]);

        // User settings as key-value map
        $settingsMap = $user->settings()
            ->get()
            ->mapWithKeys(fn($s) => [$s->key => $s->value]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'is_active' => $user->is_active,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'projects_count' => $user->projects_count,
                'reviews_count' => $user->reviews_count,
                'last_activity' => $user->activityLogs()->latest()->value('created_at'),
            ],
            'projects' => [
                'data' => $projects,
                'total' => $projectsPaginator->total(),
                'current_page' => $projectsPaginator->currentPage(),
                'last_page' => $projectsPaginator->lastPage(),
                'per_page' => $projectsPaginator->perPage(),
            ],
            'activities' => [
                'data' => $activities,
                'total' => $activitiesPaginator->total(),
                'current_page' => $activitiesPaginator->currentPage(),
                'last_page' => $activitiesPaginator->lastPage(),
                'per_page' => $activitiesPaginator->perPage(),
            ],
            'settings' => $settingsMap,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $currentAdmin = $request->user();

        if ($request->has('is_admin')) {
            $targetAdmin = (bool) $request->input('is_admin');

            if ($currentAdmin && $currentAdmin->id === $user->id) {
                return response()->json([
                    'message' => 'You cannot change your own role.',
                ], 403);
            }

            if (!$targetAdmin && $user->is_admin) {
                $adminCount = User::where('is_admin', true)->where('is_active', true)->count();
                if ($adminCount <= 1) {
                    return response()->json([
                        'message' => 'You cannot remove the last administrator.',
                    ], 422);
                }
            }

            $user->is_admin = $targetAdmin;
        }

        if ($request->has('is_active')) {
            if ($currentAdmin && $currentAdmin->id === $user->id) {
                return response()->json([
                    'message' => 'You cannot change your own account status.',
                ], 403);
            }
            $user->is_active = (bool) $request->input('is_active');
        }

        $user->save();

        // Log the admin action on the target user
        $admin = $request->user();
        if ($admin && $user) {
            $changes = [];
            if (isset($validated['is_active'])) {
                $changes[] = $validated['is_active'] ? 'activated' : 'suspended';
            }
            if (isset($validated['is_admin'])) {
                $changes[] = $validated['is_admin'] ? 'promoted to admin' : 'demoted to user';
            }
            if (!empty($changes)) {
                ActivityLogger::log($admin, 'admin_user_updated', "Updated user '{$user->name}': " . implode(', ', $changes), [
                    'target_user_id' => $user->id,
                ]);
            }
        }

        $remainingAdmins = User::where('is_admin', true)->where('is_active', true)->count();

        return response()->json([
            'message' => 'User updated.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'is_active' => $user->is_active,
                'created_at' => $user->created_at,
                'projects_count' => $user->projects()->count(),
                'reviews_count' => $user->reviews()->count(),
            ],
            'remaining_admins' => $remainingAdmins,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $currentAdmin = $request->user();
        if ($currentAdmin && $currentAdmin->id === $user->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 403);
        }

        if ($user->is_admin) {
            $adminCount = User::where('is_admin', true)->where('is_active', true)->count();
            if ($adminCount <= 1) {
                return response()->json([
                    'message' => 'You cannot remove the last administrator.',
                ], 422);
            }
        }

        $userName = $user->name;
        $userId = $user->id;
        $user->delete();

        ActivityLogger::log($request->user(), 'admin_user_deleted', "Deleted user '{$userName}'", ['deleted_user_id' => $userId]);

        return response()->json(['message' => 'User deleted.']);
    }

    // ---- User Settings ----

    private const USER_SETTING_KEYS = [
        'theme'              => ['type' => 'string',  'values' => ['light', 'dark', 'system']],
        'language'           => ['type' => 'string',  'values' => ['en']],
        'timezone'           => ['type' => 'string',  'values' => null],
        'email_notifications' => ['type' => 'bool',    'values' => ['1', '0', 'true', 'false']],
        'review_notifications' => ['type' => 'bool',  'values' => ['1', '0', 'true', 'false']],
        'ai_review_enabled'  => ['type' => 'bool',   'values' => ['1', '0', 'true', 'false']],
        'reviewer_persona'  => ['type' => 'string',  'values' => ['first_time', 'non_technical', 'junior_developer', 'developer', 'devops', 'designer', 'manager', 'custom']],
        'daily_review_limit' => ['type' => 'int',     'values' => null],
        'allow_retry'        => ['type' => 'bool',   'values' => ['1', '0', 'true', 'false']],
        'allow_login'        => ['type' => 'bool',   'values' => ['1', '0', 'true', 'false']],
        'force_password_reset' => ['type' => 'bool', 'values' => ['1', '0', 'true', 'false']],
    ];

    private const PERSONA_LABELS = [
        'first_time'         => 'First-time user',
        'non_technical'      => 'Non-technical user',
        'junior_developer'   => 'Junior developer',
        'developer'          => 'Developer',
        'devops'             => 'DevOps engineer',
        'designer'           => 'Product designer',
        'manager'            => 'Product manager',
        'custom'             => 'Custom',
    ];

    private const TIMEZONES = [
        'UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
        'Europe/London', 'Europe/Berlin', 'Europe/Paris', 'Europe/Moscow',
        'Asia/Dubai', 'Asia/Kolkata', 'Asia/Bangkok', 'Asia/Singapore', 'Asia/Tokyo', 'Asia/Shanghai',
        'Australia/Sydney', 'Pacific/Auckland',
    ];

    public function updateSettings(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $validated = $request->validate([
            'key'   => 'required|string|max:255',
            'value' => 'nullable|string|max:1000',
        ]);

        $key = $validated['key'];
        $rawValue = $validated['value'];

        if (!array_key_exists($key, self::USER_SETTING_KEYS)) {
            return response()->json([
                'message' => "Unknown setting key '{$key}'.",
            ], 422);
        }

        $schema = self::USER_SETTING_KEYS[$key];

        // Validate enum values
        if ($schema['values'] !== null && $rawValue !== null) {
            if ($schema['type'] === 'bool') {
                if (!in_array($rawValue, ['1', '0', 'true', 'false'], true)) {
                    return response()->json(['message' => "Invalid value for '{$key}'. Expected 1, 0, true, or false."], 422);
                }
            } elseif ($schema['type'] === 'int') {
                if (!ctype_digit($rawValue) && $rawValue !== null) {
                    return response()->json(['message' => "Invalid integer value for '{$key}'."], 422);
                }
            } else {
                if (!in_array($rawValue, $schema['values'], true)) {
                    return response()->json([
                        'message' => "Invalid value for '{$key}'. Allowed: " . implode(', ', $schema['values']),
                    ], 422);
                }
            }
        }

        // Cast bool strings to real bool for storage
        $storedValue = $rawValue;
        if ($schema['type'] === 'bool' && $rawValue !== null) {
            $storedValue = in_array($rawValue, ['1', 'true'], true) ? '1' : '0';
        }

        $user->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $storedValue]
        );

        ActivityLogger::log($request->user(), 'admin_user_setting_updated', "Updated setting '{$key}' for user '{$user->name}'", [
            'target_user_id' => $user->id,
            'setting_key'   => $key,
            'value'         => $storedValue,
        ]);

        $settingsMap = $user->settings()->get()->mapWithKeys(fn($s) => [$s->key => $s->value]);

        return response()->json([
            'message' => 'Setting updated.',
            'settings' => $settingsMap,
        ]);
    }

    public function deleteSetting(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $validated = $request->validate([
            'key' => 'required|string|max:255',
        ]);

        $user->settings()->where('key', $validated['key'])->delete();

        ActivityLogger::log($request->user(), 'admin_user_setting_deleted', "Deleted setting '{$validated['key']}' for user '{$user->name}'", [
            'target_user_id' => $user->id,
            'setting_key'   => $validated['key'],
        ]);

        return response()->json(['message' => 'Setting deleted.']);
    }

    // ---- Admin Actions ----

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $tempPassword = bin2hex(random_bytes(6));
        $user->password = Hash::make($tempPassword);
        $user->save();

        // Force password reset on next login
        $user->settings()->updateOrCreate(['key' => 'force_password_reset'], ['value' => '1']);

        ActivityLogger::log($request->user(), 'admin_password_reset', "Reset password for user '{$user->name}'", [
            'target_user_id' => $user->id,
        ]);

        return response()->json([
            'message'     => 'Password reset successfully.',
            'temp_password' => $tempPassword,
        ]);
    }

    public function suspendUser(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $currentAdmin = $request->user();
        if ($currentAdmin && $currentAdmin->id === $user->id) {
            return response()->json([
                'message' => 'You cannot suspend your own account.',
            ], 403);
        }

        if ($user->is_admin && $user->is_active) {
            $adminCount = User::where('is_admin', true)->where('is_active', true)->count();
            if ($adminCount <= 1) {
                return response()->json([
                    'message' => 'Cannot suspend the last administrator.',
                ], 422);
            }
        }

        $user->is_active = false;
        $user->save();

        // Revoke all tokens
        $user->tokens()->delete();

        ActivityLogger::log($request->user(), 'admin_user_suspended', "Suspended user '{$user->name}'", [
            'target_user_id' => $user->id,
        ]);

        return response()->json(['message' => 'User suspended.', 'is_active' => false]);
    }

    public function activateUser(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $currentAdmin = $request->user();
        if ($currentAdmin && $currentAdmin->id === $user->id) {
            return response()->json([
                'message' => 'You cannot activate your own account.',
            ], 403);
        }

        $user->is_active = true;
        $user->save();

        ActivityLogger::log($request->user(), 'admin_user_activated', "Activated user '{$user->name}'", [
            'target_user_id' => $user->id,
        ]);

        return response()->json(['message' => 'User activated.', 'is_active' => true]);
    }

    public function resetPreferences(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Delete all preference-type settings but not system ones
        $preferenceKeys = array_keys(self::USER_SETTING_KEYS);
        $user->settings()->whereIn('key', $preferenceKeys)->delete();

        ActivityLogger::log($request->user(), 'admin_user_preferences_reset', "Reset preferences for user '{$user->name}'", [
            'target_user_id' => $user->id,
        ]);

        return response()->json(['message' => 'User preferences reset.']);
    }

    // Return supported options for settings dropdowns
    public function settingsMeta(): JsonResponse
    {
        return response()->json([
            'personas'   => self::PERSONA_LABELS,
            'timezones'  => self::TIMEZONES,
            'themes'     => ['light', 'dark', 'system'],
            'languages'  => ['en'],
        ]);
    }
}
