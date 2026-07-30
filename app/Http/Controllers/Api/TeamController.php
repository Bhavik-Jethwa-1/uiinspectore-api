<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TeamController extends \Illuminate\Routing\Controller
{
    /**
     * Allowed role names for project members.
     */
    private array $roles = ['owner', 'admin', 'editor', 'viewer'];

    /**
     * Per-project directory under database/teams.
     */
    private function projectPath(string $projectId): string
    {
        $dir = base_path('database/teams');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . "/project_{$projectId}.json";
        if (!file_exists($path)) {
            file_put_contents($path, json_encode([
                'members' => [],
                'activities' => [],
            ], JSON_PRETTY_PRINT));
        }
        return $path;
    }

    /**
     * Load team record for a project.
     */
    private function loadProject(string $projectId): array
    {
        $path = $this->projectPath($projectId);
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) return ['members' => [], 'activities' => []];
        if (!isset($data['members']) || !is_array($data['members'])) $data['members'] = [];
        if (!isset($data['activities']) || !is_array($data['activities'])) $data['activities'] = [];
        return $data;
    }

    /**
     * Persist team record for a project.
     */
    private function saveProject(string $projectId, array $data): void
    {
        $path = $this->projectPath($projectId);
        file_put_contents($path, json_encode([
            'members' => array_values($data['members'] ?? []),
            'activities' => array_values($data['activities'] ?? []),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Append a new activity entry to the project log.
     */
    private function logActivity(string $projectId, array $data, string $type, string $message, array $meta = []): void
    {
        $record = $this->loadProject($projectId);
        $entry = [
            'id' => 'act_' . bin2hex(random_bytes(6)),
            'type' => $type,
            'message' => $message,
            'actor' => [
                'id' => $data['id'] ?? null,
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'role' => $data['role'] ?? null,
            ],
            'meta' => $meta,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $record['activities'][] = $entry;
        if (count($record['activities']) > 200) {
            $record['activities'] = array_slice($record['activities'], -200);
        }
        $this->saveProject($projectId, $record);
    }

    /**
     * Confirm the project exists in the requesting user's projects.
     */
    private function loadUserProject(int $userId, string $projectId): ?array
    {
        $path = base_path("database/uizard/user_{$userId}.json");
        if (!file_exists($path)) return null;
        $data = json_decode(file_get_contents($path), true) ?? ['projects' => []];
        foreach ($data['projects'] ?? [] as $p) {
            if (($p['id'] ?? null) === $projectId) return $p;
        }
        return null;
    }

    /**
     * Public member payload.
     */
    private function publicMember(array $member): array
    {
        return [
            'id' => $member['id'],
            'user_id' => $member['user_id'] ?? null,
            'email' => $member['email'] ?? null,
            'name' => $member['name'] ?? null,
            'role' => $member['role'],
            'avatar' => $member['avatar'] ?? null,
            'status' => $member['status'] ?? 'active',
            'invited_at' => $member['invited_at'] ?? null,
            'joined_at' => $member['joined_at'] ?? null,
            'last_active_at' => $member['last_active_at'] ?? null,
        ];
    }

    /**
     * GET /api/projects/{projectId}/team
     * List members of the authenticated user's project.
     */
    public function index(Request $request, string $projectId): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->loadUserProject((int) $user['id'], $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $record = $this->loadProject($projectId);
        // Ensure owner is always present as a member.
        $members = $record['members'];
        $hasOwner = false;
        foreach ($members as $m) {
            if (($m['role'] ?? '') === 'owner') { $hasOwner = true; break; }
        }
        if (!$hasOwner) {
            $members[] = [
                'id' => 'mbr_' . bin2hex(random_bytes(6)),
                'user_id' => (int) $user['id'],
                'email' => $user['email'] ?? null,
                'name' => $user['name'] ?? 'Owner',
                'role' => 'owner',
                'avatar' => $user['avatar'] ?? null,
                'status' => 'active',
                'invited_at' => date('Y-m-d H:i:s'),
                'joined_at' => date('Y-m-d H:i:s'),
            ];
            $record['members'] = $members;
            $this->saveProject($projectId, $record);
        }

        return response()->json([
            'success' => true,
            'data' => array_map(fn($m) => $this->publicMember($m), $members),
        ]);
    }

    /**
     * POST /api/team/invite
     * Invite a new member to the project.
     */
    public function invite(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $v = Validator::make($request->all(), [
            'project_id' => 'required|string',
            'email' => 'required|email',
            'role' => 'required|string|in:' . implode(',', $this->roles),
            'name' => 'sometimes|string|max:255',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $projectId = $request->input('project_id');
        $email = strtolower((string) $request->input('email'));
        $role = $request->input('role');
        $name = $request->input('name');

        // Only owner/admin may invite.
        $record = $this->loadProject($projectId);
        $inviterMember = null;
        foreach ($record['members'] as $m) {
            if (($m['user_id'] ?? null) == $user['id']) {
                $inviterMember = $m;
                break;
            }
        }
        // If user is the project owner (no team file yet, they own it via uizard file).
        if (!$inviterMember) {
            $project = $this->loadUserProject((int) $user['id'], $projectId);
            if (!$project) {
                return response()->json(['error' => 'Project not found'], 404);
            }
            // Allow owner to invite, otherwise reject.
            $inviterMember = [
                'user_id' => (int) $user['id'],
                'role' => 'owner',
            ];
        }
        if (!in_array($inviterMember['role'], ['owner', 'admin'], true)) {
            return response()->json(['error' => 'Only owners and admins can invite members'], 403);
        }

        // Check for duplicate email.
        foreach ($record['members'] as $m) {
            if (strtolower((string) ($m['email'] ?? '')) === $email) {
                return response()->json(['error' => 'A member with this email already exists on the project'], 422);
            }
        }

        $member = [
            'id' => 'mbr_' . bin2hex(random_bytes(6)),
            'user_id' => null, // Will be set when the invitee joins.
            'email' => $email,
            'name' => $name ?: explode('@', $email)[0],
            'role' => $role,
            'avatar' => null,
            'status' => 'invited',
            'invited_at' => date('Y-m-d H:i:s'),
            'joined_at' => null,
            'last_active_at' => null,
            'invited_by' => (int) $user['id'],
            'invite_token' => bin2hex(random_bytes(12)),
        ];

        $record['members'][] = $member;
        $this->saveProject($projectId, $record);

        $this->logActivity($projectId, [
            'id' => (int) $user['id'],
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => $inviterMember['role'],
        ], 'member_invited', sprintf('Invited %s as %s', $email, $role), [
            'invited_email' => $email,
            'role' => $role,
            'member_id' => $member['id'],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'member' => $this->publicMember($member),
                'invite_url' => url('/invite/' . $member['invite_token']),
            ],
            'message' => 'Invite sent',
        ]);
    }

    /**
     * PUT /api/team/{memberId}/role
     * Update the role of an existing team member.
     */
    public function updateRole(Request $request, string $memberId): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $v = Validator::make($request->all(), [
            'project_id' => 'required|string',
            'role' => 'required|string|in:' . implode(',', $this->roles),
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $projectId = $request->input('project_id');
        $newRole = $request->input('role');

        $project = $this->loadUserProject((int) $user['id'], $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $record = $this->loadProject($projectId);

        // Authorize: requester must be owner/admin.
        $isAuthorized = false;
        foreach ($record['members'] as $m) {
            if (($m['user_id'] ?? null) == $user['id'] && in_array($m['role'], ['owner', 'admin'], true)) {
                $isAuthorized = true;
                break;
            }
        }
        // Project creator is implicitly owner.
        if (!$isAuthorized && (int) ($project['owner_id'] ?? 0) === (int) $user['id']) {
            $isAuthorized = true;
        }
        if (!$isAuthorized) {
            // Fall back: if no team members exist yet, the requesting user is the implicit owner.
            if (count($record['members']) === 0 && (int) ($project['owner_id'] ?? 0) === 0) {
                $isAuthorized = true;
            }
        }
        if (!$isAuthorized) {
            return response()->json(['error' => 'Only owners and admins can change roles'], 403);
        }

        $updated = null;
        foreach ($record['members'] as &$m) {
            if (($m['id'] ?? null) === $memberId) {
                // Owner role cannot be reassigned away.
                if (($m['role'] ?? '') === 'owner' && $newRole !== 'owner') {
                    return response()->json(['error' => 'Owner role cannot be changed'], 422);
                }
                $oldRole = $m['role'] ?? null;
                $m['role'] = $newRole;
                $m['updated_at'] = date('Y-m-d H:i:s');
                $updated = $m;
                break;
            }
        }
        unset($m);

        if (!$updated) {
            return response()->json(['error' => 'Member not found'], 404);
        }

        $this->saveProject($projectId, $record);

        $this->logActivity($projectId, [
            'id' => (int) $user['id'],
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => 'admin',
        ], 'role_changed', sprintf('Changed %s role to %s', ($updated['email'] ?? $updated['name'] ?? 'a member'), $newRole), [
            'member_id' => $memberId,
            'old_role' => $oldRole ?? null,
            'new_role' => $newRole,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->publicMember($updated),
            'message' => 'Role updated',
        ]);
    }

    /**
     * DELETE /api/team/{memberId}
     * Remove a member from the project team.
     */
    public function remove(Request $request, string $memberId): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $v = Validator::make($request->all(), [
            'project_id' => 'required|string',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $projectId = $request->input('project_id');

        $project = $this->loadUserProject((int) $user['id'], $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $record = $this->loadProject($projectId);

        // Authorize: owner or admin.
        $isAuthorized = false;
        $requesterRole = null;
        foreach ($record['members'] as $m) {
            if (($m['user_id'] ?? null) == $user['id']) {
                $requesterRole = $m['role'];
                if (in_array($m['role'], ['owner', 'admin'], true)) {
                    $isAuthorized = true;
                }
                break;
            }
        }
        if (!$isAuthorized && count($record['members']) === 0) {
            $isAuthorized = true;
            $requesterRole = 'owner';
        }
        if (!$isAuthorized) {
            return response()->json(['error' => 'Only owners and admins can remove members'], 403);
        }

        $removed = null;
        $remaining = [];
        foreach ($record['members'] as $m) {
            if (($m['id'] ?? null) === $memberId) {
                // Owners cannot be removed.
                if (($m['role'] ?? '') === 'owner') {
                    return response()->json(['error' => 'Cannot remove the project owner'], 422);
                }
                // Non-admins can only remove themselves.
                if ($requesterRole === 'admin' && (int) ($m['user_id'] ?? 0) === (int) $user['id']) {
                    return response()->json(['error' => 'Admins cannot remove themselves; transfer ownership first'], 422);
                }
                $removed = $m;
            } else {
                $remaining[] = $m;
            }
        }

        if (!$removed) {
            return response()->json(['error' => 'Member not found'], 404);
        }

        $record['members'] = $remaining;
        $this->saveProject($projectId, $record);

        $this->logActivity($projectId, [
            'id' => (int) $user['id'],
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => $requesterRole,
        ], 'member_removed', sprintf('Removed %s from the project', ($removed['email'] ?? $removed['name'] ?? 'a member')), [
            'removed_member_id' => $memberId,
            'removed_email' => $removed['email'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => ['id' => $memberId, 'removed_at' => date('Y-m-d H:i:s')],
            'message' => 'Member removed',
        ]);
    }

    /**
     * GET /api/projects/{projectId}/activities
     * Get the project activity feed.
     */
    public function activities(Request $request, string $projectId): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->loadUserProject((int) $user['id'], $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $record = $this->loadProject($projectId);
        $activities = $record['activities'] ?? [];

        // Newest first.
        usort($activities, fn($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));

        // Optional filter by type.
        $type = $request->query('type');
        if ($type) {
            $activities = array_values(array_filter($activities, fn($a) => ($a['type'] ?? '') === $type));
        }

        // Pagination.
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min(200, $limit));
        $offset = (int) $request->query('offset', 0);
        $offset = max(0, $offset);

        $total = count($activities);
        $page = array_slice($activities, $offset, $limit);

        return response()->json([
            'success' => true,
            'data' => $page,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($page),
                'has_more' => ($offset + count($page)) < $total,
            ],
        ]);
    }
}
