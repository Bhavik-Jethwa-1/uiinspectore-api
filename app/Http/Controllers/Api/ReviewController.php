<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Review;
use App\Models\Screenshot;
use App\Services\AIReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReviewController extends Controller
{
    public function __construct(private AIReviewService $aiService)
    {
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $review = $request->user()
            ->reviews()
            ->findOrFail($id);

        $review->delete();

        return response()->json(['message' => 'Review deleted']);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'persona' => 'required|in:first-time,non-technical,junior-developer,developer,devops,designer,manager,custom',
            'page_goal' => 'required|string|max:500',
        ]);

        // Verify project belongs to user
        $project = $request->user()->projects()->findOrFail($validated['project_id']);

        $review = $project->reviews()->create([
            'status' => 'pending',
            'persona' => $validated['persona'],
            'page_goal' => $validated['page_goal'],
        ]);

        return response()->json(['review' => $this->formatReview($review)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $review = $request->user()
            ->reviews()
            ->with(['project', 'screenshot', 'score', 'issues', 'annotations', 'suggestions'])
            ->findOrFail($id);

        return response()->json(['review' => $this->formatReviewFull($review)]);
    }

    public function uploadScreenshot(Request $request, int $id): JsonResponse
    {
        $review = $request->user()
            ->reviews()
            ->where('status', 'pending')
            ->findOrFail($id);

        $validated = $request->validate([
            'image' => 'required|image|mimes:png,jpg,jpeg,webp|max:10240', // 10MB max
        ]);

        $file = $validated['image'];

        // Get image dimensions
        [$width, $height] = getimagesize($file);

        // Store the file
        $storedName = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('screenshots', $storedName, 'local');

        // Create screenshot record
        $screenshot = Screenshot::create([
            'project_id' => $review->project_id,
            'review_id' => $review->id,
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
        ]);

        // Update review with screenshot
        $review->update(['screenshot_id' => $screenshot->id]);

        $screenshotUrl = url('/api/storage/' . $path);

        return response()->json([
            'screenshot' => $screenshot,
            'screenshot_url' => $screenshotUrl,
        ]);
    }

    public function analyze(Request $request, int $id): JsonResponse
    {
        $review = $request->user()
            ->reviews()
            ->with('screenshot')
            ->findOrFail($id);

        if (!$review->screenshot_id) {
            return response()->json(['error' => 'Please upload a screenshot first'], 400);
        }

        if ($review->status === 'completed') {
            return response()->json(['error' => 'This review has already been analyzed'], 400);
        }

        $screenshot = $review->screenshot;

        // Update status to analyzing
        $review->update(['status' => 'analyzing']);

        try {
            // Call AI service
            $aiData = $this->aiService->analyze($review, $screenshot);

            // Save results
            $this->aiService->saveReviewResults($review, $aiData);

            // Reload with relations
            $review->load(['score', 'issues', 'annotations', 'suggestions']);

            return response()->json(['review' => $this->formatReviewFull($review)]);
        } catch (\Exception $e) {
            Log::error('AI analysis failed', [
                'review_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $review->update(['status' => 'failed']);

            return response()->json([
                'error' => 'Analysis failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function formatReview(Review $review): array
    {
        return [
            'id' => $review->id,
            'project_id' => $review->project_id,
            'project_name' => $review->project?->name,
            'status' => $review->status,
            'persona' => $review->persona,
            'page_goal' => $review->page_goal,
            'screenshot_url' => $review->screenshot ? url('/api/storage/' . $review->screenshot->path) : null,
            'created_at' => $review->created_at,
            'updated_at' => $review->updated_at,
        ];
    }

    private function formatReviewFull(Review $review): array
    {
        return [
            'id' => $review->id,
            'project_id' => $review->project_id,
            'project_name' => $review->project?->name,
            'status' => $review->status,
            'persona' => $review->persona,
            'page_goal' => $review->page_goal,
            'screenshot_url' => $review->screenshot ? url('/api/storage/' . $review->screenshot->path) : null,
            'created_at' => $review->created_at,
            'updated_at' => $review->updated_at,
            'scores' => $review->score ? [
                'visualHierarchy' => $review->score->visual_hierarchy,
                'clarity' => $review->score->clarity,
                'accessibility' => $review->score->accessibility,
                'consistency' => $review->score->consistency,
                'layout' => $review->score->layout,
                'typography' => $review->score->typography,
                'ux' => $review->score->ux,
                'overall' => $review->score->overall,
                'summary' => $review->score->summary,
                'strengths' => $review->score->strengths ?? [],
            ] : null,
            'issues' => $review->issues->map(fn($i) => [
                'id' => $i->id,
                'title' => $i->title,
                'severity' => $i->severity,
                'category' => $i->category,
                'description' => $i->description,
                'whyItMatters' => $i->why_it_matters,
                'recommendation' => $i->recommendation,
            ]),
            'annotations' => $review->annotations->map(fn($a) => [
                'id' => $a->id,
                'issue_id' => $a->review_issue_id,
                'x' => $a->x,
                'y' => $a->y,
                'width' => $a->width,
                'height' => $a->height,
            ]),
            'suggestions' => $review->suggestions->map(fn($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'priority' => $s->priority,
                'category' => $s->category,
                'problem' => $s->problem,
                'recommendation' => $s->recommendation,
                'expectedImpact' => $s->expected_impact,
            ]),
        ];
    }
}
