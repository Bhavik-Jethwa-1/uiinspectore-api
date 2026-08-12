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

        // Load projects with reviews (single query with eager loading)
        $projects = $user->projects()
            ->with(['reviews' => function ($q) {
                $q->orderBy('created_at', 'desc');
            }])
            ->withCount('reviews')
            ->orderBy('updated_at', 'desc')
            ->get();

        // Collect all reviews from all projects
        $allReviews = [];
        foreach ($projects as $project) {
            foreach ($project->reviews as $review) {
                $allReviews[] = [
                    'id' => $review->id,
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'persona' => $review->persona,
                    'page_goal' => $review->page_goal,
                    'status' => $review->status,
                    'scores' => $review->score ? [
                        'overall' => $review->score->overall,
                        'visual_hierarchy' => $review->score->visual_hierarchy,
                        'clarity' => $review->score->clarity,
                        'accessibility' => $review->score->accessibility,
                        'consistency' => $review->score->consistency,
                        'layout' => $review->score->layout,
                        'typography' => $review->score->typography,
                        'ux' => $review->score->ux,
                    ] : null,
                    'created_at' => $review->created_at,
                    'updated_at' => $review->updated_at,
                ];
            }
        }

        // Sort by date descending
        usort($allReviews, fn($a, $b) => new \DateTime($b['created_at']) <=> new \DateTime($a['created_at']));

        // Compute stats
        $totalProjects = $projects->count();
        $totalReviews = count($allReviews);
        $completedReviews = collect($allReviews)->where('status', 'completed')->count();
        $avgScore = $completedReviews > 0
            ? round(collect($allReviews)
                ->where('status', 'completed')
                ->whereNotNull('scores')
                ->filter(fn($r) => isset($r['scores']['overall']))
                ->avg('scores.overall'))
            : null;

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
            'projects' => $projects->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'reviews_count' => $p->reviews_count,
                'created_at' => $p->created_at,
                'updated_at' => $p->updated_at,
            ]),
            'reviews' => $allReviews,
            'recent_reviews' => array_slice($allReviews, 0, 10),
        ]);
    }
}
