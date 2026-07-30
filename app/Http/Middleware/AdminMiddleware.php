<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * AdminMiddleware — requires the request to be authenticated as an admin.
 *
 * Must run AFTER api.auth so `$request->auth_user` is populated.
 *
 * Checks the user record loaded by ApiAuthMiddleware for either:
 *   - `is_admin` flag (truthy)
 *   - role in [admin, superadmin, owner]
 *
 * Returns 401 when unauthenticated, 403 when not an admin.
 */
class AdminMiddleware
{
    /** Roles considered admin. Keep in sync with AdminController::ADMIN_ROLES */
    private const ADMIN_ROLES = ['admin', 'superadmin', 'owner'];

    public function handle(Request $request, Closure $next)
    {
        // Resolve the user payload set by ApiAuthMiddleware
        $user = $request->get('auth_user');

        // Fall back to auth()->user() for cases where the guard was used directly
        if (!$user) {
            $authUser = auth()->user();
            if ($authUser) {
                $user = is_object($authUser)
                    ? ['id' => $authUser->id, 'role' => $authUser->role ?? 'user', 'is_admin' => $authUser->is_admin ?? false]
                    : (array) $authUser;
            }
        }

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!$this->isAdmin($user)) {
            return response()->json([
                'error' => 'Forbidden: admin access required',
            ], 403);
        }

        return $next($request);
    }

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
}