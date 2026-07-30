<?php

namespace App\Models\Billing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = ['name', 'owner_id', 'slug', 'logo_url', 'domain', 'provider', 'provider_org_id'];
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function teamMembers(): HasMany { return $this->hasMany(TeamMember::class); }
    public function members() { return $this->hasManyThrough(User::class, TeamMember::class, 'organization_id', 'id', 'id', 'user_id'); }
    public function activeMembers() { return $this->members()->wherePivot('status', 'active'); }
}
