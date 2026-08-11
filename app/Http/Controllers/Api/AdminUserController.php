<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        // Search: name, email, or id
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('id', $search);
            });
        }

        // Role filter
        if ($request->input('role') === 'admin') {
            $query->where('is_admin', true);
        } elseif ($request->input('role') === 'user') {
            $query->where('is_admin', false);
        }

        // Status filter
        if ($request->input('status') === 'active') {
            $query->where('is_active', true);
        } elseif ($request->input('status') === 'suspended') {
            $query->where('is_active', false);
        }

        // Sort
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
            default: // newest
                $query->orderBy('created_at', 'desc');
        }

        // Pagination
        $perPage = min((int) $request->input('per_page', 20), 100);
        $users = $query->withCount(['projects', 'reviews'])->paginate($perPage);

        return response()->json([
            'users' => $users->items(),
            'total' => $users->total(),
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'per_page' => $users->perPage(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $user = User::withCount(['projects', 'reviews'])->find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Get project & review stats
        $projectsCount = $user->projects_count ?? 0;
        $reviewsCount = $user->reviews_count ?? 0;

        // Last activity: most recent review or project
        $lastActivity = DB::table('projects')
            ->select('projects.created_at as date', DB::raw("'project_created' as type"))
            ->where('user_id', $id)
            ->union(
                DB::table('reviews')
                    ->select('reviews.created_at as date', DB::raw("'review_created' as type"))
                    ->join('projects', 'reviews.project_id', '=', 'projects.id')
                    ->where('projects.user_id', $id)
            )
            ->orderByDesc('date')
            ->first();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'is_active' => $user->is_active,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'last_activity' => $lastActivity?->date,
                'projects_count' => $projectsCount,
                'reviews_count' => $reviewsCount,
            ],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Role change
        if ($request->has('is_admin')) {
            $targetAdmin = (bool) $request->input('is_admin');

            // Prevent removing the last admin
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

        // Status change
        if ($request->has('is_active')) {
            $user->is_active = (bool) $request->input('is_active');
        }

        $user->save();

        // Count total admins after change
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

    public function destroy(int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Prevent deleting the last admin
        if ($user->is_admin) {
            $adminCount = User::where('is_admin', true)->where('is_active', true)->count();
            if ($adminCount <= 1) {
                return response()->json([
                    'message' => 'You cannot remove the last administrator.',
                ], 422);
            }
        }

        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }
}
