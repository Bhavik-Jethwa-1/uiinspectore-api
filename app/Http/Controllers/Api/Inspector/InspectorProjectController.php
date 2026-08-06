<?php

namespace App\Http\Controllers\Api\Inspector;

use App\Http\Controllers\Controller;
use App\Models\Inspector\UiProject;
use App\Models\Inspector\UiScreenshot;
use App\Models\Inspector\UiReview;
use App\Models\Inspector\UiRedesign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InspectorProjectController extends Controller
{
    private function getUserId(Request $request): ?int
    {
        $auth = $request->get('auth_user');
        return $auth ? (int) $auth['id'] : null;
    }

    /**
     * GET /api/inspector/projects
     */
    public function index(Request $request)
    {
        $userId = $this->getUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $projects = UiProject::where('user_id', $userId)
            ->with(['screenshots', 'latestReview', 'latestRedesign'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($p) => $this->formatProject($p));

        return response()->json(['success' => true, 'projects' => $projects]);
    }

    /**
     * POST /api/inspector/projects
     */
    public function store(Request $request)
    {
        $userId = $this->getUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'product_type' => ['nullable', 'string', 'max:100'],
            'platform' => ['nullable', 'string', 'max:100'],
        ]);

        $project = UiProject::create([
            'user_id' => $userId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'product_type' => $data['product_type'] ?? null,
            'platform' => $data['platform'] ?? null,
            'status' => 'draft',
        ]);

        return response()->json([
            'success' => true,
            'project' => $this->formatProject($project->load(['screenshots', 'latestReview', 'latestRedesign'])),
        ], 201);
    }

    /**
     * GET /api/inspector/projects/{id}
     */
    public function show(Request $request, int $id)
    {
        $userId = $this->getUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $project = UiProject::where('id', $id)
            ->where('user_id', $userId)
            ->with(['screenshots', 'reviews.annotations', 'reviews.suggestions', 'redesigns'])
            ->first();

        if (!$project) {
            return response()->json(['success' => false, 'error' => 'Project not found'], 404);
        }

        return response()->json([
            'success' => true,
            'project' => $this->formatProjectFull($project),
        ]);
    }

    /**
     * PUT /api/inspector/projects/{id}
     */
    public function update(Request $request, int $id)
    {
        $userId = $this->getUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $project = UiProject::where('id', $id)->where('user_id', $userId)->first();
        if (!$project) {
            return response()->json(['success' => false, 'error' => 'Project not found'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'product_type' => ['nullable', 'string', 'max:100'],
            'platform' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:draft,reviewing,reviewed'],
        ]);

        $project->fill(array_filter($data, fn($v) => $v !== null));
        $project->save();

        return response()->json([
            'success' => true,
            'project' => $this->formatProject($project->load(['screenshots', 'latestReview', 'latestRedesign'])),
        ]);
    }

    /**
     * DELETE /api/inspector/projects/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $userId = $this->getUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $project = UiProject::where('id', $id)->where('user_id', $userId)->first();
        if (!$project) {
            return response()->json(['success' => false, 'error' => 'Project not found'], 404);
        }

        // Delete screenshot files
        foreach ($project->screenshots as $screenshot) {
            if ($screenshot->file_path && Storage::disk('public')->exists($screenshot->file_path)) {
                Storage::disk('public')->delete($screenshot->file_path);
            }
        }

        // Delete redesign images
        foreach ($project->redesigns as $redesign) {
            if ($redesign->image_path && Storage::disk('public')->exists($redesign->image_path)) {
                Storage::disk('public')->delete($redesign->image_path);
            }
        }

        $project->delete();

        return response()->json(['success' => true]);
    }

    // ─── Formatters ────────────────────────────────────────────────────────────

    private function formatProject(UiProject $project): array
    {
        $screenshots = $project->screenshots ?? collect([]);
        $review = $project->latestReview;
        $redesign = $project->latestRedesign;

        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'product_type' => $project->product_type,
            'platform' => $project->platform,
            'status' => $project->status,
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
            'screenshots' => $screenshots->map(fn($s) => [
                'id' => $s->id,
                'file_path' => $s->file_path,
                'file_name' => $s->file_name,
                'variant' => $s->variant,
                'page_goal' => $s->page_goal,
                'persona' => $s->persona,
                'url' => $s->file_path ? "/storage/{$s->file_path}" : null,
            ])->toArray(),
            'review' => $review ? [
                'id' => $review->id,
                'status' => $review->status,
                'scores' => $review->scores,
                'summary' => $review->summary,
                'created_at' => $review->created_at?->toIso8601String(),
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
                    'reason' => $a->reason,
                    'suggested_fix' => $a->suggested_fix,
                    'expected_improvement' => $a->expected_improvement,
                    'difficulty' => $a->difficulty,
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
                ])->toArray(),
            ] : null,
            'redesign' => $redesign ? [
                'id' => $redesign->id,
                'status' => $redesign->status,
                'design_style' => $redesign->design_style,
                'image_url' => $redesign->image_path ? "/storage/{$redesign->image_path}" : null,
                'created_at' => $redesign->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function formatProjectFull(UiProject $project): array
    {
        $base = $this->formatProject($project);

        // Also include singular `review` (latest) and `redesign` (latest) for backward compatibility
        $latestReview = $project->reviews->sortByDesc('created_at')->first();
        $latestRedesign = $project->redesigns->sortByDesc('created_at')->first();
        $base['review'] = $latestReview ? [
            'id' => $latestReview->id,
            'status' => $latestReview->status,
            'scores' => $latestReview->scores,
            'summary' => $latestReview->summary,
            'created_at' => $latestReview->created_at?->toIso8601String(),
            'annotations' => $latestReview->annotations->map(fn($a) => [
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
            'suggestions' => $latestReview->suggestions->map(fn($s) => [
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
        ] : null;
        $base['redesign'] = $latestRedesign ? [
            'id' => $latestRedesign->id,
            'status' => $latestRedesign->status,
            'design_style' => $latestRedesign->design_style,
            'image_url' => $latestRedesign->image_path ? "/storage/{$latestRedesign->image_path}" : null,
            'improved_items' => $latestRedesign->improved_items,
            'regressed_items' => $latestRedesign->regressed_items,
            'unchanged_items' => $latestRedesign->unchanged_items,
            'score_comparison' => $latestRedesign->score_comparison,
            'created_at' => $latestRedesign->created_at?->toIso8601String(),
        ] : null;

        // Include all reviews with annotations and suggestions
        $base['reviews'] = $project->reviews->map(fn($r) => [
            'id' => $r->id,
            'status' => $r->status,
            'scores' => $r->scores,
            'summary' => $r->summary,
            'created_at' => $r->created_at?->toIso8601String(),
            'annotations' => $r->annotations->map(fn($a) => [
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
            'suggestions' => $r->suggestions->map(fn($s) => [
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
        ])->toArray();

        $base['redesigns'] = $project->redesigns->map(fn($rd) => [
            'id' => $rd->id,
            'design_style' => $rd->design_style,
            'status' => $rd->status,
            'image_url' => $rd->image_path ? "/storage/{$rd->image_path}" : null,
            'improved_items' => $rd->improved_items,
            'regressed_items' => $rd->regressed_items,
            'unchanged_items' => $rd->unchanged_items,
            'score_comparison' => $rd->score_comparison,
            'created_at' => $rd->created_at?->toIso8601String(),
        ])->toArray();

        return $base;
    }
}
