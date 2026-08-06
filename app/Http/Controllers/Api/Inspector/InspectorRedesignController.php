<?php

namespace App\Http\Controllers\Api\Inspector;

use App\Http\Controllers\Controller;
use App\Models\Inspector\UiProject;
use App\Models\Inspector\UiScreenshot;
use App\Models\Inspector\UiRedesign;
use App\Services\ImageGenerationService;
use App\Services\ImageEnhancementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InspectorRedesignController extends Controller
{
    private ImageGenerationService $imageService;
    private ImageEnhancementService $enhancer;

    public function __construct()
    {
        $this->imageService = new ImageGenerationService();
        $this->enhancer = new ImageEnhancementService();
    }

    private function getUserId(Request $request): ?int
    {
        $auth = $request->get('auth_user');
        return $auth ? (int) $auth['id'] : null;
    }

    // ─── Provider Status ────────────────────────────────────────────────────

    /**
     * GET /api/inspector/redesigns/providers
     * 
     * Returns provider status and availability.
     * Frontend uses this to show available providers and their status.
     */
    public function providerStatus()
    {
        $status = $this->imageService->getProviderStatus();

        // Convert technical errors to user-friendly messages
        $userError = null;
        if (!($status['available'] ?? false)) {
            $error = $status['error'] ?? '';
            if (str_contains($error, 'Could not resolve') || str_contains($error, 'Cannot connect')) {
                $userError = 'AI service is temporarily unavailable. We are working to restore it.';
            } elseif (!empty($error)) {
                $userError = 'AI service is experiencing issues.';
            }
        }

        return response()->json([
            'success' => true,
            'provider' => $status['provider'],
            'name' => $status['name'],
            'available' => $status['available'],
            'status' => $status['status'],
            'error' => $userError ?? $status['error'],
            'user_message' => $userError,
            'models' => $status['models'],
            'model_priority' => $status['model_priority'],
        ]);
    }

    // ─── Generate Redesign ──────────────────────────────────────────────────

    /**
     * POST /api/inspector/projects/{projectId}/redesign
     * 
     * Full pipeline: AI Image Generation → Post-processing → Store result
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
            'design_style'  => ['nullable', 'string', 'max:50'],
            'model'         => ['nullable', 'string', 'max:100'],
            'strength'      => ['nullable', 'numeric', 'min:0.1', 'max:1.0'],
            'post_process'  => ['nullable', 'boolean'],
        ]);

        // Get screenshot
        $screenshot = isset($data['screenshot_id'])
            ? UiScreenshot::where('id', $data['screenshot_id'])
                ->where('ui_project_id', $projectId)->first()
            : $project->screenshots()->first();

        if (!$screenshot) {
            return response()->json([
                'success' => false,
                'error' => 'No screenshot found. Please upload a screenshot first.',
                'error_code' => 'NO_SCREENSHOT',
            ], 400);
        }

        $designStyle = $data['design_style'] ?? 'modern_saas';

        // Create redesign record
        $redesign = UiRedesign::create([
            'ui_project_id'     => $project->id,
            'ui_screenshot_id'  => $screenshot->id,
            'original_image_path' => $screenshot->file_path,
            'design_style'      => $designStyle,
            'status'            => 'generating',
        ]);

        // Generate AI redesign
        $result = $this->imageService->generateRedesign(
            screenshotPath: $screenshot->file_path,
            designStyle: $designStyle,
            options: [
                'model' => $data['model'] ?? null,
                'strength' => $data['strength'] ?? 0.75,
                'post_process' => $data['post_process'] ?? true,
                'user_id' => $userId,
            ]
        );

        if (!$result['success']) {
            $redesign->update([
                'status' => 'failed',
                'error_message' => $result['error'] ?? 'Generation failed',
                'provider' => $result['provider'] ?? 'unknown',
                'model' => $result['model'] ?? 'unknown',
            ]);

            // Return user-friendly error message
            $userMessage = $this->getUserFriendlyError($result);

            return response()->json([
                'success' => false,
                'error' => $userMessage,
                'error_code' => $result['error_code'] ?? 'GENERATION_FAILED',
                'redesign_id' => $redesign->id,
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'can_retry' => $result['can_retry'] ?? true,
            ], 503);
        }

        // Success - update redesign record
        $redesign->update([
            'status'           => 'completed',
            'image_path'       => $result['image_path'],
            'provider'         => $result['provider'],
            'model'            => $result['model'],
            'vision_analysis'  => json_encode([
                'prompt' => $result['prompt_used'] ?? null,
                'post_processing' => $result['post_processing'] ?? null,
            ]),
            'improved_items'   => $result['improvements'] ?? [],
            'regressed_items'  => [],
            'unchanged_items'  => [
                'Layout structure preserved',
                'Navigation elements maintained',
                'Content and text preserved',
            ],
        ]);

        return response()->json([
            'success' => true,
            'redesign' => $this->formatRedesign($redesign),
            'provider' => $result['provider'],
            'model' => $result['model'],
            'generation_time' => $result['generation_time'],
            'original_image_url' => $result['original_image_url'],
            'generated_image_url' => $result['generated_image_url'],
            'post_processing' => $result['post_processing'],
            'improvements' => $result['improvements'] ?? [],
            'status' => 'completed',
        ], 201);
    }

    // ─── Regenerate ─────────────────────────────────────────────────────────

    /**
     * POST /api/inspector/redesigns/{id}/regenerate
     */
    public function regenerate(Request $request, int $id)
    {
        $userId = $this->getUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $redesign = UiRedesign::find($id);
        if (!$redesign) {
            return response()->json(['success' => false, 'error' => 'Redesign not found'], 404);
        }

        $project = UiProject::where('id', $redesign->ui_project_id)->where('user_id', $userId)->first();
        if (!$project) {
            return response()->json(['success' => false, 'error' => 'Not authorized'], 403);
        }

        $data = $request->validate([
            'design_style' => ['nullable', 'string', 'max:50'],
            'model'        => ['nullable', 'string', 'max:100'],
            'strength'     => ['nullable', 'numeric', 'min:0.1', 'max:1.0'],
        ]);

        $redesign->update(['status' => 'generating']);

        $designStyle = $data['design_style'] ?? $redesign->design_style;

        // Get original screenshot
        $screenshotPath = $redesign->original_image_path;
        
        if (!$screenshotPath) {
            $redesign->update(['status' => 'failed', 'error_message' => 'Original screenshot not found']);
            return response()->json(['success' => false, 'error' => 'Original screenshot not found'], 400);
        }

        $fullPath = storage_path('app/public/' . $screenshotPath);
        if (!file_exists($fullPath)) {
            $redesign->update(['status' => 'failed', 'error_message' => 'Screenshot file not found']);
            return response()->json(['success' => false, 'error' => 'Screenshot file not found'], 400);
        }

        // Delete old image
        if ($redesign->image_path && Storage::disk('public')->exists($redesign->image_path)) {
            Storage::disk('public')->delete($redesign->image_path);
        }

        // Generate new redesign
        $result = $this->imageService->generateRedesign(
            screenshotPath: $screenshotPath,
            designStyle: $designStyle,
            options: [
                'model' => $data['model'] ?? null,
                'strength' => $data['strength'] ?? 0.75,
                'user_id' => $userId,
            ]
        );

        if (!$result['success']) {
            $redesign->update([
                'status' => 'failed',
                'error_message' => $result['error'] ?? 'Generation failed',
                'provider' => $result['provider'] ?? 'unknown',
                'model' => $result['model'] ?? 'unknown',
            ]);

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'AI generation failed',
                'error_code' => $result['error_code'] ?? 'GENERATION_FAILED',
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'can_retry' => $result['can_retry'] ?? true,
            ], 503);
        }

        // Success
        $redesign->update([
            'status'           => 'completed',
            'image_path'       => $result['image_path'],
            'design_style'     => $designStyle,
            'provider'         => $result['provider'],
            'model'            => $result['model'],
            'vision_analysis'  => json_encode([
                'prompt' => $result['prompt_used'] ?? null,
                'post_processing' => $result['post_processing'] ?? null,
            ]),
            'improved_items'   => $result['improvements'] ?? [],
            'regressed_items'  => [],
            'unchanged_items'  => [
                'Layout structure preserved',
                'Navigation elements maintained',
            ],
        ]);

        return response()->json([
            'success' => true,
            'redesign' => $this->formatRedesign($redesign),
            'provider' => $result['provider'],
            'model' => $result['model'],
            'generation_time' => $result['generation_time'],
            'original_image_url' => $result['original_image_url'],
            'generated_image_url' => $result['generated_image_url'],
            'status' => 'completed',
        ]);
    }

    // ─── Get Redesign ──────────────────────────────────────────────────────

    /**
     * GET /api/inspector/redesigns/{id}
     */
    public function show(Request $request, int $id)
    {
        $userId = $this->getUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $redesign = UiRedesign::with(['screenshot'])->find($id);
        if (!$redesign) {
            return response()->json(['success' => false, 'error' => 'Redesign not found'], 404);
        }

        $project = UiProject::where('id', $redesign->ui_project_id)->where('user_id', $userId)->first();
        if (!$project) {
            return response()->json(['success' => false, 'error' => 'Not authorized'], 403);
        }

        return response()->json([
            'success' => true,
            'redesign' => $this->formatRedesign($redesign),
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function getUserFriendlyError(array $result): string
    {
        $error = $result['error'] ?? '';
        $provider = $result['provider'] ?? '';

        // HuggingFace DNS/connection errors
        if (str_contains($error, 'Could not resolve') || str_contains($error, 'Cannot connect')) {
            return 'AI redesign service is temporarily unavailable. Please try again in a few minutes.';
        }

        // Model loading errors
        if (str_contains($error, 'loading') || str_contains($error, 'initializing')) {
            return 'AI model is loading. Please wait 30 seconds and try again.';
        }

        // Rate limiting
        if (str_contains($error, 'Rate limit') || str_contains($error, 'rate_limit')) {
            return 'Service is busy. Please wait a moment and try again.';
        }

        // Generic fallback
        return 'AI redesign generation failed. Please try again or contact support if the issue persists.';
    }

    private function formatRedesign(UiRedesign $redesign): array
    {
        return [
            'id'                   => $redesign->id,
            'project_id'           => $redesign->ui_project_id,
            'screenshot_id'       => $redesign->ui_screenshot_id,
            'original_image_path'  => $redesign->original_image_path
                ? "/storage/{$redesign->original_image_path}"
                : null,
            'design_style'        => $redesign->design_style,
            'status'              => $redesign->status,
            'image_url'           => $redesign->image_path
                ? "/storage/{$redesign->image_path}"
                : null,
            'provider'            => $redesign->provider,
            'model'               => $redesign->model,
            'improved_items'      => $redesign->improved_items ?? [],
            'regressed_items'     => $redesign->regressed_items ?? [],
            'unchanged_items'     => $redesign->unchanged_items ?? [],
            'vision_analysis'     => is_string($redesign->vision_analysis)
                ? json_decode($redesign->vision_analysis, true)
                : $redesign->vision_analysis,
            'error_message'       => $redesign->error_message,
            'created_at'          => $redesign->created_at?->toIso8601String(),
            'screenshot'          => $redesign->screenshot ? [
                'id'  => $redesign->screenshot->id,
                'url' => "/storage/{$redesign->screenshot->file_path}",
            ] : null,
        ];
    }
}
