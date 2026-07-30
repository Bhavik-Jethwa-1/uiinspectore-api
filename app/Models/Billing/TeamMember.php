<?php

namespace App\Models\Billing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMember extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'role', 'status', 'invite_token', 'invited_at', 'joined_at'];
    protected $casts = ['invited_at' => 'datetime', 'joined_at' => 'datetime'];

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isOwner(): bool  { return $this->role === 'owner'; }
    public function isAdmin(): bool { return $this->role === 'admin' || $this->role === 'owner'; }
    public function isEditor(): bool { return in_array($this->role, ['admin', 'editor', 'owner']); }

    public static function ROLES(): array
    {
        return ['owner', 'admin', 'editor', 'viewer'];
    }

    public static function ROLE_PERMISSIONS(): array
    {
        return [
            'owner'  => ['manage_billing' => true, 'manage_projects' => true, 'invite_users' => true, 'delete_projects' => true, 'export' => true, 'api_access' => true, 'manage_members' => true],
            'admin'  => ['manage_billing' => true, 'manage_projects' => true, 'invite_users' => true, 'delete_projects' => true, 'export' => true, 'api_access' => true, 'manage_members' => true],
            'editor' => ['manage_billing' => false, 'manage_projects' => true, 'invite_users' => false, 'delete_projects' => false, 'export' => true, 'api_access' => false, 'manage_members' => false],
            'viewer' => ['manage_billing' => false, 'manage_projects' => false, 'invite_users' => false, 'delete_projects' => false, 'export' => false, 'api_access' => false, 'manage_members' => false],
        ];
    }

    public function can(string $permission): bool
    {
        $perms = self::ROLE_PERMISSIONS()[$this->role] ?? [];
        return $perms[$permission] ?? false;
    }
}
