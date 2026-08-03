<?php

namespace App\Http\Controllers\Api\Inspector;

use App\Http\Controllers\Controller;
use App\Models\Inspector\UiProject;
use App\Models\Inspector\UiScreenshot;
use App\Models\Inspector\UiReview;
use App\Models\Inspector\UiAnnotation;
use App\Models\Inspector\UiSuggestion;
use App\Services\AI\Inspector\VisionAnalysisService;
use Illuminate\Http\Request;

class InspectorReviewController extends Controller
{
    private VisionAnalysisService $visionService;

    public function __construct(VisionAnalysisService $visionService)
    {
        $this->visionService = $visionService;
    }

    private function getUserId(Request $request): ?int
    {
        $auth = $request->get('auth_user');
        return $auth ? (int) $auth['id'] : null;
    }

    /**
     * POST /api/inspector/projects/{projectId}/review
     * Generate AI review for a project
     */
    public function generate(Request $request, int $projectId)
    {
        $userId = $this->getUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $project = UiProject::where('id', $projectId)->where('user_id', $userId)->first();
        if (!$project) {
            return response()->json(['success' => false, 'error' => 'Project not found'], 404);
        }

        $data = $request->validate([
            'screenshot_id' => ['nullable', 'integer'],
            'page_goal' => ['nullable', 'string', 'max:500'],
            'persona' => ['nullable', 'string', 'max:50'],
        ]);

        // Get screenshot
        $screenshot = isset($data['screenshot_id'])
            ? UiScreenshot::where('id', $data['screenshot_id'])->where('ui_project_id', $projectId)->first()
            : $project->screenshots()->first();

        if (!$screenshot) {
            return response()->json(['success' => false, 'error' => 'No screenshot found. Please upload one first.'], 400);
        }

        // Create review record
        $review = UiReview::create([
            'ui_project_id' => $project->id,
            'ui_screenshot_id' => $screenshot->id,
            'status' => 'analyzing',
            'scores' => null,
            'summary' => null,
            'review_data' => null,
        ]);

        // Run AI analysis
        $result = $this->visionService->analyze($screenshot->file_path, [
            'page_goal' => $data['page_goal'] ?? $screenshot->page_goal ?? 'General use',
            'persona' => $data['persona'] ?? $screenshot->persona ?? 'general',
        ]);

        if (!$result['success']) {
            $review->update(['status' => 'failed', 'error_message' => $result['error']]);
            return response()->json([
                'success' => false,
                'error' => $result['error'],
                'review_id' => $review->id,
            ], 500);
        }

        $analysis = $result['analysis'];

        // Save review data
        $review->update([
            'status' => 'completed',
            'scores' => $analysis['scores'] ?? null,
            'summary' => $analysis['summary'] ?? null,
            'review_data' => $analysis,
        ]);

        // Create annotations
        $annotations = $analysis['annotations'] ?? [];
        foreach ($annotations as $idx => $ann) {
            UiAnnotation::create([
                'ui_review_id' => $review->id,
                'number' => $ann['number'] ?? ($idx + 1),
                'type' => $ann['type'] ?? 'issue',
                'severity' => $ann['severity'] ?? 'minor',
                'x' => $ann['x'] ?? 50,
                'y' => $ann['y'] ?? 50,
                'width' => $ann['width'] ?? 20,
                'height' => $ann['height'] ?? 10,
                'title' => $ann['title'] ?? 'Issue',
                'description' => $ann['description'] ?? null,
                'suggested_fix' => $ann['suggested_fix'] ?? null,
                'expected_improvement' => $ann['expected_improvement'] ?? null,
                'difficulty' => $ann['difficulty'] ?? null,
                'component_type' => $ann['component_type'] ?? null,
            ]);
        }

        // Create suggestions
        $suggestions = $analysis['suggestions'] ?? [];
        foreach ($suggestions as $sug) {
            UiSuggestion::create([
                'ui_review_id' => $review->id,
                'category' => $sug['category'] ?? 'general',
                'title' => $sug['title'] ?? 'Suggestion',
                'description' => $sug['description'] ?? '',
                'suggested_fix' => $sug['suggested_fix'] ?? null,
                'expected_improvement' => $sug['expected_improvement'] ?? null,
                'difficulty' => $sug['difficulty'] ?? null,
                'priority' => $sug['priority'] ?? 'medium',
                'implemented' => false,
            ]);
        }

        // Update project status
        $project->update(['status' => 'reviewed']);

        // Reload with relations
        $review->load(['annotations', 'suggestions']);

        return response()->json([
            'success' => true,
            'review' => $this->formatReview($review, $screenshot),
        ], 201);
    }

    /**
     * GET /api/inspector/reviews/{id}
     */
    public function show(Request $request, int $id)
    {
        $userId = $this->getUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $review = UiReview::with(['annotations', 'suggestions', 'screenshot'])->find($id);
        if (!$review) {
            return response()->json(['success' => false, 'error' => 'Review not found'], 404);
        }

        $project = UiProject::where('id', $review->ui_project_id)->where('user_id', $userId)->first();
        if (!$project) {
            return response()->json(['success' => false, 'error' => 'Not authorized'], 403);
        }

        return response()->json([
            'success' => true,
            'review' => $this->formatReview($review, $review->screenshot),
        ]);
    }

    /**
     * GET /api/inspector/projects/{projectId}/reviews
     */
    public function forProject(Request $request, int $projectId)
    {
        $userId = $this->getUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $project = UiProject::where('id', $projectId)->where('user_id', $userId)->first();
        if (!$project) {
            return response()->json(['success' => false, 'error' => 'Project not found'], 404);
        }

        $reviews = $project->reviews()
            ->with(['annotations', 'suggestions', 'screenshot'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($r) => $this->formatReview($r, $r->screenshot));

        return response()->json(['success' => true, 'reviews' => $reviews]);
    }

    // ─── Formatters ──────────────────────────────────────────────────────────

    private function formatReview(UiReview $review, ?UiScreenshot $screenshot): array
    {
        return [
            'id' => $review->id,
            'project_id' => $review->ui_project_id,
            'screenshot_id' => $review->ui_screenshot_id,
            'status' => $review->status,
            'scores' => $review->scores,
            'summary' => $review->summary,
            'error_message' => $review->error_message,
            'created_at' => $review->created_at?->toIso8601String(),
            'screenshot' => $screenshot ? [
                'id' => $screenshot->id,
                'file_path' => $screenshot->file_path,
                'url' => "/storage/{$screenshot->file_path}",
                'page_goal' => $screenshot->page_goal,
                'persona' => $screenshot->persona,
            ] : null,
            'annotations' => $review->annotations->map(fn($a) => [
                'id' => $a->id,
                'number' => $a->number,
                'type' => $a->type,
                'severity' => $a->severity,
                'x' => $a->x,
                'y' => $a->y,
                'width' => $a->width,
                'height' => $a->height,
                'title' => $a->title,
                'description' => $a->description,
                'suggested_fix' => $a->suggested_fix,
                'expected_improvement' => $a->expected_improvement,
                'difficulty' => $a->difficulty,
                'component_type' => $a->component_type,
            ])->toArray(),
            'suggestions' => $review->suggestions->map(fn($s) => [
                'id' => $s->id,
                'category' => $s->category,
                'title' => $s->title,
                'description' => $s->description,
                'suggested_fix' => $s->suggested_fix,
                'expected_improvement' => $s->expected_improvement,
                'difficulty' => $s->difficulty,
                'priority' => $s->priority,
                'implemented' => $s->implemented,
            ])->toArray(),
        ];
    }
}
