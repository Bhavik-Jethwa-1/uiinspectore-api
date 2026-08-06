<?php

namespace App\Http\Controllers\Api\Inspector;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AI\FreeImageService;
use App\Services\AI\ProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * InspectorImageController — free AI image generation for UI Inspector.
 *
 * Uses Pollinations AI (default) + HuggingFace (fallback).
 * Never blocks on billing. Always returns a proper response.
 */
class ImageGeneratorController extends Controller
{
    public function __construct(
        private FreeImageService $freeImageService
    ) {}

    // ─── Public API ───────────────────────────────────────────────────────

    /**
     * GET /api/inspector/image-generator
     * List all generations for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return $this->ok(false, 'Unauthorized', 401);

        $generations = DB::table('ai_image_generations')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();

        return $this->ok(true, null, 200, [
            'generations' => $generations->map(fn($g) => $this->formatGeneration($g)),
        ]);
    }

    /**
     * GET /api/inspector/image-generator/{id}
     * Get a single generation.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return $this->ok(false, 'Unauthorized', 401);

        $g = DB::table('ai_image_generations')->where('id', $id)->where('user_id', $user->id)->first();
        if (!$g) return $this->ok(false, 'Not found', 404);

        return $this->ok(true, null, 200, ['generation' => $this->formatGeneration($g)]);
    }

    /**
     * POST /api/inspector/image-generator/generate
     *
     * Generate an improved UI image from an original screenshot.
     *
     * Body:
     *   prompt: string (optional — uses default redesign prompt if omitted)
     *   originalImagePath: string (storage path, e.g. "inspector-screenshots/uuid.png")
     *   width: int (default 1024)
     *   height: int (default 1024)
     *   model: string (optional)
     *   style: string (optional)
     */
    public function generate(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return $this->ok(false, 'Unauthorized', 401);

        $validated = $request->validate([
            'prompt' => 'nullable|string|max:2000',
            'originalImagePath' => 'nullable|string|max:500',
            'width' => 'nullable|integer|min:256|max:2048',
            'height' => 'nullable|integer|min:256|max:2048',
            'model' => 'nullable|string',
            'style' => 'nullable|string',
        ]);

        // ── Resolve input image ──────────────────────────────────────
        $originalImagePath = $validated['originalImagePath'] ?? null;
        $inputImageFullPath = null;

        if ($originalImagePath) {
            // Try public storage first
            $inputImageFullPath = storage_path('app/public/' . ltrim($originalImagePath, '/'));
            if (!file_exists($inputImageFullPath)) {
                // Try without 'public/' prefix
                $inputImageFullPath = storage_path('app/' . ltrim($originalImagePath, '/'));
            }
            if (!file_exists($inputImageFullPath)) {
                $inputImageFullPath = null;
            }
        }

        $prompt = $validated['prompt'] ?? FreeImageService::REDESIGN_PROMPT;
        $width = (int)($validated['width'] ?? 1024);
        $height = (int)($validated['height'] ?? 1024);
        $style = $validated['style'] ?? 'natural';

        // Create pending generation record
        $generationId = DB::table('ai_image_generations')->insertGetId([
            'user_id' => $user->id,
            'provider' => 'pollinations',
            'model' => 'flux',
            'prompt' => $prompt,
            'style' => $style,
            'original_image_path' => $originalImagePath,
            'status' => 'generating',
            'size' => "{$width}x{$height}",
            'quality' => 'high',
            'n' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── Generate (auto-fallback: Pollinations → HuggingFace) ───
        $options = [
            'width' => $width,
            'height' => $height,
            'style' => $style,
            'model' => $validated['model'] ?? null,
        ];

        $result = $this->freeImageService->generateUIImage($inputImageFullPath, $prompt, $options);

        if ($result['success']) {
            DB::table('ai_image_generations')->where('id', $generationId)->update([
                'status' => 'completed',
                'provider' => $result['provider'] ?? 'pollinations',
                'model' => $result['model'] ?? 'flux',
                'generated_image_path' => $result['imagePath'],
                'revised_prompt' => $result['revisedPrompt'] ?? null,
                'generation_time_ms' => $result['generationTimeMs'] ?? null,
                'cost_usd' => 0,
                'updated_at' => now(),
            ]);

            $g = DB::table('ai_image_generations')->where('id', $generationId)->first();
            $warning = $result['warning'] ?? null;

            return $this->ok(true, null, 200, [
                'generation' => $this->formatGeneration($g),
                'warning' => $warning,
                'status' => 'completed',
            ]);
        }

        // All providers failed
        DB::table('ai_image_generations')->where('id', $generationId)->update([
            'status' => 'failed',
            'error_message' => $result['error'] ?? 'All providers failed',
            'error_code' => $result['errorCode'] ?? 'ALL_FAILED',
            'updated_at' => now(),
        ]);

        $g = DB::table('ai_image_generations')->where('id', $generationId)->first();

        // Return 200 with error body — never HTTP 500
        return $this->ok(false, $result['error'] ?? 'Provider unavailable. Try again.', 200, [
            'generation' => $this->formatGeneration($g),
            'status' => 'failed',
            'providers_tried' => $result['providers_tried'] ?? [],
        ]);
    }

    /**
     * POST /api/inspector/image-generator/upload
     *
     * Upload an image (original screenshot) to use in generation.
     * Multipart form with 'image' field.
     */
    public function upload(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return $this->ok(false, 'Unauthorized', 401);

        $request->validate([
            'image' => 'required|file|mimes:png,jpg,jpeg,webp|max:10240',
        ]);

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension();
        $filename = 'ai-images/' . Str::uuid() . '.' . $ext;

        $stored = Storage::disk('public')->put($filename, file_get_contents($file->getRealPath()));
        if (!$stored) {
            return $this->ok(false, 'Upload failed', 200);
        }

        return $this->ok(true, null, 200, [
            'imagePath' => $filename,
            'imageUrl' => url(Storage::url($filename)),
        ]);
    }

    /**
     * DELETE /api/inspector/image-generator/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return $this->ok(false, 'Unauthorized', 401);

        $g = DB::table('ai_image_generations')->where('id', $id)->where('user_id', $user->id)->first();
        if (!$g) return $this->ok(false, 'Not found', 404);

        // Delete generated image file if exists
        if ($g->generated_image_path) {
            $fullPath = storage_path('app/public/' . ltrim($g->generated_image_path, '/'));
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        DB::table('ai_image_generations')->where('id', $id)->delete();

        return $this->ok(true, null, 200);
    }

    /**
     * GET /api/inspector/image-generator/providers
     * Status of all free providers.
     */
    public function providerStatus(): JsonResponse
    {
        $status = $this->freeImageService->providerStatus();
        $providers = [];
        foreach ($status as $id => $s) {
            $providers[] = [
                'id' => $id,
                'name' => $id === 'pollinations' ? 'Pollinations AI' : 'HuggingFace Inference',
                'available' => $s['available'],
                'hasToken' => $s['hasToken'] ?? false,
                'warning' => $id === 'pollinations'
                    ? 'This provider does not support true UI redesign. Results may vary.'
                    : null,
            ];
        }
        return response()->json(['success' => true, 'providers' => $providers]);
    }

    // ─── Private ──────────────────────────────────────────────────────────

    private function getUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (!$token) return null;
        return DB::table('personal_access_tokens')
            ->join('users', 'users.id', '=', 'personal_access_tokens.tokenable_id')
            ->where('personal_access_tokens.token', hash('sha256', $token))
            ->where('personal_access_tokens.tokenable_type', 'App\\Models\\User')
            ->first();
    }

    private function formatGeneration(object $g): array
    {
        $origPath = $g->original_image_path ?? null;
        $genPath = $g->generated_image_path ?? null;

        return [
            'id' => $g->id,
            'prompt' => $g->prompt,
            'provider' => $g->provider,
            'model' => $g->model,
            'style' => $g->style,
            'size' => $g->size,
            'status' => $g->status,
            'warning' => $g->provider === 'pollinations'
                ? 'This provider does not support true UI redesign. Results may vary.'
                : null,
            'originalImageUrl' => $origPath ? url(Storage::url($origPath)) : null,
            'generatedImageUrl' => $genPath ? url(Storage::url($genPath)) : null,
            'originalImagePath' => $origPath,
            'generatedImagePath' => $genPath,
            'revisedPrompt' => $g->revised_prompt ?? null,
            'generationTimeMs' => $g->generation_time_ms ?? null,
            'costUsd' => (float)($g->cost_usd ?? 0),
            'errorMessage' => $g->error_message ?? null,
            'errorCode' => $g->error_code ?? null,
            'createdAt' => $g->created_at,
        ];
    }

    /**
     * Always return 200 — never HTTP 500 for business errors.
     */
    private function ok(bool $success, ?string $error, int $httpStatus = 200, array $data = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => $success,
            'error' => $error,
        ], $data), $httpStatus < 400 ? 200 : $httpStatus);
    }
}
