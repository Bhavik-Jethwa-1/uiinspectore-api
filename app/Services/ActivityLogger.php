<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;

class ActivityLogger
{
    /**
     * Log a user action.
     *
     * @param  User|null  $user  The user performing the action
     * @param  string  $action  Short action identifier (e.g. 'login', 'project_created')
     * @param  string|null  $description  Human-readable description
     * @param  array|null  $meta  Additional context (project_id, review_id, etc.)
     * @return ActivityLog|null
     */
    public static function log(User $user, string $action, ?string $description = null, ?array $meta = null): ?ActivityLog
    {
        if (!$user) {
            return null;
        }

        return ActivityLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'description' => $description,
            'meta' => $meta,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
