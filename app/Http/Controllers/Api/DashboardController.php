<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard
     * Returns all data needed for the user dashboard in ONE request.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $projectsPerPage = min((int) $request->input('projects_per_page', 10), 50);
        $reviewsPerPage = min((int) $request->input('reviews_per_page', 10), 50);
        $projectsPage = max((int) $request->input('projects_page', 1), 1);
        $reviewsPage = max((int) $request->input('reviews_page', 1), 1);

        // Load paginated projects
        $projectsPaginator = $user->projects()
            ->withCount('reviews')
            ->orderBy('updated_at', 'desc')
            ->paginate($projectsPerPage, ['id', 'name', 'description', 'created_at', 'updated_at'], 'projects_page', $projectsPage);

        $projects = collect($projectsPaginator->items())->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'reviews_count' => $p->reviews_count,
            'created_at' => $p->created_at,
            'updated_at' => $p->updated_at,
        ]);

        // Load all reviews for stats (lightweight query)
        $allReviewsQuery = \App\Models\Review::query()
            ->join('projects', 'reviews.project_id', '=', 'projects.id')
            ->where('projects.user_id', $user->id)
            ->with('score')
            ->orderBy('reviews.created_at', 'desc');

        $allReviewsForStats = (clone $allReviewsQuery)->get();

        // Compute stats from all reviews
        $totalProjects = $user->projects()->count();
        $totalReviews = $allReviewsForStats->count();
        $completedReviews = $allReviewsForStats->where('status', 'completed')->count();
        $avgScore = $completedReviews > 0
            ? round($allReviewsForStats
                ->where('status', 'completed')
                ->filter(fn($r) => $r->score && isset($r->score->overall))
                ->avg('score.overall'))
            : null;

        // Paginated reviews
        $reviewsPaginator = (clone $allReviewsQuery)
            ->select(['reviews.id', 'reviews.project_id', 'reviews.persona', 'reviews.page_goal', 'reviews.status', 'reviews.created_at', 'reviews.updated_at', 'projects.name as project_name'])
            ->paginate($reviewsPerPage, ['reviews.id', 'reviews.project_id', 'reviews.persona', 'reviews.page_goal', 'reviews.status', 'reviews.created_at', 'reviews.updated_at', 'projects.name as project_name'], 'reviews_page', $reviewsPage);

        // Load scores for paginated items
        $reviewIds = collect($reviewsPaginator->items())->pluck('id');
        $scores = \App\Models\ReviewScore::whereIn('review_id', $reviewIds)->get()->keyBy('review_id');
        $reviews = collect($reviewsPaginator->items())->map(function ($review) use ($scores) {
            $review->scores = $scores->has($review->id) ? ['overall' => $scores[$review->id]->overall] : null;
            return $review;
        });

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
            ],
            'stats' => [
                'total_projects' => $totalProjects,
                'total_reviews' => $totalReviews,
                'completed_reviews' => $completedReviews,
                'avg_score' => $avgScore,
            ],
            'projects' => $projects,
            'reviews' => $reviews,
            'projects_meta' => [
                'total' => $projectsPaginator->total(),
                'current_page' => $projectsPaginator->currentPage(),
                'last_page' => $projectsPaginator->lastPage(),
                'per_page' => $projectsPaginator->perPage(),
            ],
            'reviews_meta' => [
                'total' => $reviewsPaginator->total(),
                'current_page' => $reviewsPaginator->currentPage(),
                'last_page' => $reviewsPaginator->lastPage(),
                'per_page' => $reviewsPaginator->perPage(),
            ],
        ]);
    }
}
