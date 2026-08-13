<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AIResponseException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Review;
use App\Models\Screenshot;
use App\Services\AIReviewService;
use App\Services\ActivityLogger;
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
            'persona' => 'required|in:first_time,non_technical,junior_developer,developer,devops,designer,manager,custom',
            'page_goal' => 'required|string|max:500',
        ]);

        // Verify project belongs to user
        $project = $request->user()->projects()->findOrFail($validated['project_id']);

        $review = $project->reviews()->create([
            'user_id' => $request->user()->id,
            'status' => 'pending',
            'persona' => $validated['persona'],
            'page_goal' => $validated['page_goal'],
        ]);

        ActivityLogger::log($request->user(), 'review_created', "Created review for '{$project->name}'", ['review_id' => $review->id, 'project_id' => $project->id]);

        return response()->json(['review' => $this->formatReview($review)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user->is_admin) {
            // Admins can view any review
            $review = Review::with(['project', 'screenshot', 'score', 'issues', 'annotations', 'suggestions'])
                ->findOrFail($id);
        } else {
            $review = $user->reviews()
                ->with(['project', 'screenshot', 'score', 'issues', 'annotations', 'suggestions'])
                ->findOrFail($id);
        }

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

        ActivityLogger::log($request->user(), 'screenshot_uploaded', "Uploaded screenshot for review #{$review->id}", ['review_id' => $review->id]);

        $screenshotUrl = '/storage/' . $path;

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

            ActivityLogger::log($request->user(), 'review_completed', "Review completed for '{$review->project->name}'", ['review_id' => $review->id, 'project_id' => $review->project_id]);

            return response()->json(['review' => $this->formatReviewFull($review)]);
        } catch (AIResponseException $e) {
            Log::error('AI API error', [
                'review_id' => $id,
                'status' => $e->statusCode,
                'message' => $e->getMessage(),
            ]);

            $review->update(['status' => 'failed']);

            if ($e->isRateLimit()) {
                return response()->json([
                    'error' => 'AI service is busy (rate limited). Please wait a moment and try again.',
                ], 429);
            }

            if ($e->isAuthError()) {
                return response()->json([
                    'error' => 'AI API authentication failed. Please check your API key in Admin → Settings.',
                ], 502);
            }

            return response()->json([
                'error' => 'Analysis failed: ' . $e->getMessage(),
            ], 502);
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
            'screenshot_url' => $review->screenshot ? '/storage/' . $review->screenshot->path : null,
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
            'screenshot_url' => $review->screenshot ? '/storage/' . $review->screenshot->path : null,
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
                'issue' => $a->issue ? [
                    'id' => $a->issue->id,
                    'title' => $a->issue->title,
                    'severity' => $a->issue->severity,
                    'category' => $a->issue->category,
                    'description' => $a->issue->description,
                    'recommendation' => $a->issue->recommendation,
                ] : null,
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
