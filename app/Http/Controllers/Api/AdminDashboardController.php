<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * GET /api/admin/dashboard
     * Returns all data needed for the admin overview page in ONE request.
     */
    public function index(Request $request): JsonResponse
    {
        // Stats - single query each
        $totalUsers = User::count();
        $totalProjects = Project::count();
        $totalReviews = Review::count();
        $completedReviews = Review::where('status', 'completed')->count();
        $pendingReviews = Review::where('status', 'pending')->count();
        $failedReviews = Review::where('status', 'failed')->count();
        $activeUsers = User::where('is_active', true)->count();

        $avgScore = Review::where('status', 'completed')
            ->whereNotNull('review_scores.overall')
            ->join('review_scores', 'reviews.id', '=', 'review_scores.review_id')
            ->avg('review_scores.overall');

        $recentUsers = User::orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'email', 'is_admin', 'is_active', 'created_at']);

        $recentReviews = Review::with('project:id,name', 'score')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'project_id' => $r->project_id,
                'project_name' => $r->project?->name,
                'persona' => $r->persona,
                'page_goal' => $r->page_goal,
                'status' => $r->status,
                'scores' => $r->score ? ['overall' => $r->score->overall] : null,
                'created_at' => $r->created_at,
            ]);

        $recentProjects = Project::withCount('reviews')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'created_at'])
            ->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'reviews_count' => $p->reviews_count,
                'created_at' => $p->created_at,
            ]);

        return response()->json([
            'stats' => [
                'total_users' => $totalUsers,
                'total_projects' => $totalProjects,
                'total_reviews' => $totalReviews,
                'completed_reviews' => $completedReviews,
                'pending_reviews' => $pendingReviews,
                'failed_reviews' => $failedReviews,
                'active_users' => $activeUsers,
                'avg_score' => $avgScore ? round($avgScore, 1) : null,
            ],
            'recent_users' => $recentUsers,
            'recent_reviews' => $recentReviews,
            'recent_projects' => $recentProjects,
        ]);
    }

    /**
     * GET /api/admin/reviews
     * Returns all reviews with project info — NO N+1 queries.
     */
    public function reviews(Request $request): JsonResponse
    {
        $query = Review::with(['project:id,name,user_id', 'project.user:id,name', 'score'])
            ->orderBy('created_at', 'desc');

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('page_goal', 'like', "%{$search}%")
                    ->orWhereHas('project', fn($pq) => $pq->where('name', 'like', "%{$search}%"));
            });
        }

        // Status filter
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Sort
        $sort = $request->sort ?? 'newest';
        match ($sort) {
            'oldest' => $query->orderBy('created_at', 'asc'),
            'score_high' => $query->orderByRaw('(SELECT overall FROM review_scores WHERE review_id = reviews.id LIMIT 1) DESC NULLS LAST'),
            'score_low' => $query->orderByRaw('(SELECT overall FROM review_scores WHERE review_id = reviews.id LIMIT 1) ASC NULLS LAST'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $perPage = min((int) $request->per_page ?: 20, 100);
        $paginator = $query->paginate($perPage);

        $reviews = collect($paginator->items())->map(fn($r) => [
            'id' => $r->id,
            'project_id' => $r->project_id,
            'project_name' => $r->project?->name,
            'persona' => $r->persona,
            'page_goal' => $r->page_goal,
            'user_name' => $r->project?->user?->name,
            'status' => $r->status,
            'scores' => $r->score ? [
                'overall' => $r->score->overall,
                'visual_hierarchy' => $r->score->visual_hierarchy,
                'clarity' => $r->score->clarity,
                'accessibility' => $r->score->accessibility,
                'consistency' => $r->score->consistency,
                'layout' => $r->score->layout,
                'typography' => $r->score->typography,
                'ux' => $r->score->ux,
            ] : null,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ]);

        return response()->json([
            'reviews' => $reviews,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    /**
     * GET /api/admin/projects
     * Returns all projects with review counts — single efficient query.
     */
    public function projects(Request $request): JsonResponse
    {
        $query = Project::withCount('reviews')
            ->with(['user:id,name,email']);

        if ($request->has('search') && $request->search) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Sort
        $sort = $request->sort ?? 'newest';
        match ($sort) {
            'oldest' => $query->orderBy('created_at', 'asc'),
            'name_asc' => $query->orderBy('name', 'asc'),
            'name_desc' => $query->orderBy('name', 'desc'),
            'reviews_desc' => $query->orderBy('reviews_count', 'desc'),
            'reviews_asc' => $query->orderBy('reviews_count', 'asc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $perPage = min((int) ($request->per_page ?: 20), 100);
        $paginator = $query->paginate($perPage);

        $projects = collect($paginator->items())->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'reviews_count' => $p->reviews_count,
            'user' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'email' => $p->user->email] : null,
            'created_at' => $p->created_at,
        ]);

        return response()->json([
            'projects' => $projects,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    /**
     * GET /api/admin/projects/{id}
     * Returns a single project with all its reviews.
     */
    public function project(Request $request, int $id): JsonResponse
    {
        $project = Project::withCount('reviews')
            ->with(['user:id,name,email'])
            ->find($id);

        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $reviews = Review::with(['score', 'project'])
            ->where('project_id', $id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'persona' => $r->persona,
                'page_goal' => $r->page_goal,
                'status' => $r->status,
                'scores' => $r->score ? [
                    'overall' => $r->score->overall,
                    'visual_hierarchy' => $r->score->visual_hierarchy,
                    'clarity' => $r->score->clarity,
                    'accessibility' => $r->score->accessibility,
                    'consistency' => $r->score->consistency,
                    'layout' => $r->score->layout,
                    'typography' => $r->score->typography,
                    'ux' => $r->score->ux,
                ] : null,
                'created_at' => $r->created_at,
            ]);

        $avgScore = $reviews
            ->filter(fn($r) => $r['scores'] && $r['scores']['overall'])
            ->avg('scores.overall');

        return response()->json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'user' => $project->user ? ['id' => $project->user->id, 'name' => $project->user->name, 'email' => $project->user->email] : null,
                'reviews_count' => $project->reviews_count,
                'avg_score' => $avgScore ? round($avgScore, 1) : null,
                'created_at' => $project->created_at,
                'updated_at' => $project->updated_at,
            ],
            'reviews' => $reviews,
        ]);
    }
}
