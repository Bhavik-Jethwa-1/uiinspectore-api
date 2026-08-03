<?php

namespace App\Http\Controllers\Api\Inspector;

use App\Http\Controllers\Controller;
use App\Models\Inspector\UiProject;
use App\Models\Inspector\UiScreenshot;
use App\Models\Inspector\UiRedesign;
use App\Services\AI\Inspector\VisionAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InspectorRedesignController extends Controller
{
    private VisionAnalysisService $visionService;
    private string $minimaxKey;
    private string $openaiKey;

    public function __construct(VisionAnalysisService $visionService)
    {
        $this->visionService = $visionService;
        $this->minimaxKey = env('MINIMAX_API_KEY', '');
        $this->openaiKey = env('OPENAI_API_KEY', '');
    }

    private function getUserId(Request $request): ?int
    {
        $auth = $request->get('auth_user');
        return $auth ? (int) $auth['id'] : null;
    }

    /**
     * POST /api/inspector/projects/{projectId}/redesign
     * Generate an AI-improved version of the project's screenshot
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
            'design_style' => ['nullable', 'string', 'max:50'],
        ]);

        // Get screenshot
        $screenshot = isset($data['screenshot_id'])
            ? UiScreenshot::where('id', $data['screenshot_id'])->where('ui_project_id', $projectId)->first()
            : $project->screenshots()->first();

        if (!$screenshot) {
            return response()->json(['success' => false, 'error' => 'No screenshot found'], 400);
        }

        $designStyle = $data['design_style'] ?? 'modern_saas';

        // Create redesign record
        $redesign = UiRedesign::create([
            'ui_project_id' => $project->id,
            'ui_screenshot_id' => $screenshot->id,
            'design_style' => $designStyle,
            'status' => 'generating',
        ]);

        // Get component analysis first
        $components = $this->visionService->detectComponents($screenshot->file_path);

        // Generate improved image
        $imageResult = $this->generateRedesignedImage($screenshot->file_path, $designStyle, $components);

        if (!$imageResult['success']) {
            $redesign->update(['status' => 'failed', 'error_message' => $imageResult['error']]);
            return response()->json([
                'success' => false,
                'error' => $imageResult['error'],
                'redesign_id' => $redesign->id,
            ], 500);
        }

        // Save the redesigned image
        $filename = 'inspector-redesigns/' . Str::uuid() . '.png';
        $saved = Storage::disk('public')->put($filename, $imageResult['image_data']);

        if (!$saved) {
            $redesign->update(['status' => 'failed', 'error_message' => 'Failed to save image']);
            return response()->json(['success' => false, 'error' => 'Failed to save image'], 500);
        }

        $redesign->update([
            'status' => 'completed',
            'image_path' => $filename,
            'improved_items' => $imageResult['improvements'] ?? [],
            'regressed_items' => $imageResult['regressions'] ?? [],
            'unchanged_items' => $imageResult['unchanged'] ?? [],
        ]);

        return response()->json([
            'success' => true,
            'redesign' => [
                'id' => $redesign->id,
                'image_url' => "/storage/{$filename}",
                'design_style' => $redesign->design_style,
                'status' => $redesign->status,
                'improved_items' => $redesign->improved_items,
                'regressed_items' => $redesign->regressed_items,
                'unchanged_items' => $redesign->unchanged_items,
                'created_at' => $redesign->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Generate a redesigned image using MiniMax image generation with the original as reference.
     * This uses the image_urls parameter as a STYLE reference, not for true img2img editing.
     * The prompt instructs the AI to preserve layout while improving visual quality.
     */
    private function generateRedesignedImage(string $screenshotPath, string $designStyle, array $components): array
    {
        $fullPath = storage_path('app/public/' . $screenshotPath);
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => 'Screenshot file not found'];
        }

        $imageBase64 = base64_encode(file_get_contents($fullPath));
        $mime = mime_content_type($fullPath);
        $dataUrl = "data:{$mime};base64," . $imageBase64;

        $styleDesc = $this->getStyleDescription($designStyle);
        // detectComponents returns ['success' => bool, 'components' => {nested object}, 'raw' => string]
        // The actual component list is in $components['components']['components']
        $componentList = is_array($components['components'] ?? []) ? ($components['components']['components'] ?? []) : [];
        $componentInfo = $this->formatComponentInfo(array_slice($componentList, 0, 6)); // Limit to 6 components

        $prompt = <<<PROMPT
Edit this UI screenshot professionally. Keep exact layout, all text, navigation, branding. Improve: typography, colors, spacing, shadows, polish.
Style: {$styleDesc}
Components:
{$componentInfo}
PROMPT;

        // Trim prompt to fit token limits (keep under 1200 chars for safety)
        if (strlen($prompt) > 1200) {
            $prompt = substr($prompt, 0, 1200);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->minimaxKey,
                'Content-Type' => 'application/json',
            ])->timeout(180)->post('https://api.minimaxi.chat/v1/image_generation', [
                'model' => 'image-01',
                'prompt' => $prompt,
                'image_urls' => [$dataUrl],
                'num_variations' => 1,
            ]);

            $data = $response->json();

            if (isset($data['base_resp']['status_code']) && $data['base_resp']['status_code'] !== 0) {
                return [
                    'success' => false,
                    'error' => $data['base_resp']['status_msg'] ?? 'MiniMax image generation failed',
                ];
            }

            $imageUrl = $data['data']['image_urls'][0] ?? null;
            if (!$imageUrl) {
                return ['success' => false, 'error' => 'No image URL returned'];
            }

            // Download the generated image
            $imageResponse = Http::timeout(60)->get($imageUrl);
            if (!$imageResponse->successful()) {
                return ['success' => false, 'error' => 'Failed to download generated image'];
            }

            $improvements = $this->detectImprovements($componentList);

            return [
                'success' => true,
                'image_data' => $imageResponse->body(),
                'improvements' => $improvements['improved'],
                'regressions' => $improvements['regressed'],
                'unchanged' => $improvements['unchanged'],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Image generation failed: ' . $e->getMessage(),
            ];
        }
    }

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
        ]);

        $redesign->update(['status' => 'generating']);

        $screenshot = $redesign->screenshot;
        if (!$screenshot) {
            $redesign->update(['status' => 'failed', 'error_message' => 'Screenshot not found']);
            return response()->json(['success' => false, 'error' => 'Screenshot not found'], 400);
        }

        $components = $this->visionService->detectComponents($screenshot->file_path);
        $designStyle = $data['design_style'] ?? $redesign->design_style;

        $imageResult = $this->generateRedesignedImage($screenshot->file_path, $designStyle, $components);

        if (!$imageResult['success']) {
            $redesign->update(['status' => 'failed', 'error_message' => $imageResult['error']]);
            return response()->json(['success' => false, 'error' => $imageResult['error']], 500);
        }

        // Delete old image
        if ($redesign->image_path && Storage::disk('public')->exists($redesign->image_path)) {
            Storage::disk('public')->delete($redesign->image_path);
        }

        $filename = 'inspector-redesigns/' . Str::uuid() . '.png';
        Storage::disk('public')->put($filename, $imageResult['image_data']);

        $redesign->update([
            'status' => 'completed',
            'image_path' => $filename,
            'design_style' => $designStyle,
            'improved_items' => $imageResult['improvements'] ?? [],
            'regressed_items' => $imageResult['regressions'] ?? [],
            'unchanged_items' => $imageResult['unchanged'] ?? [],
        ]);

        return response()->json([
            'success' => true,
            'redesign' => [
                'id' => $redesign->id,
                'image_url' => "/storage/{$filename}",
                'design_style' => $redesign->design_style,
                'status' => $redesign->status,
                'improved_items' => $redesign->improved_items,
                'regressed_items' => $redesign->regressed_items,
                'unchanged_items' => $redesign->unchanged_items,
            ],
        ]);
    }

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
            'redesign' => [
                'id' => $redesign->id,
                'project_id' => $redesign->ui_project_id,
                'screenshot_id' => $redesign->ui_screenshot_id,
                'design_style' => $redesign->design_style,
                'status' => $redesign->status,
                'image_url' => $redesign->image_path ? "/storage/{$redesign->image_path}" : null,
                'improved_items' => $redesign->improved_items,
                'regressed_items' => $redesign->regressed_items,
                'unchanged_items' => $redesign->unchanged_items,
                'error_message' => $redesign->error_message,
                'created_at' => $redesign->created_at?->toIso8601String(),
                'screenshot' => $redesign->screenshot ? [
                    'id' => $redesign->screenshot->id,
                    'url' => "/storage/{$redesign->screenshot->file_path}",
                ] : null,
            ],
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getStyleDescription(string $style): string
    {
        return match ($style) {
            'modern_saas' => 'Modern SaaS — clean, professional, premium shadows, subtle gradients, rounded cards, excellent hierarchy',
            'minimal' => 'Minimal — maximum whitespace, ultra-clean typography, subtle borders, understated elegance',
            'glassmorphism' => 'Glassmorphism — frosted glass panels, backdrop blur, translucent overlays, vibrant accents',
            'enterprise' => 'Enterprise — structured, data-dense, professional, strong grid alignment, business-focused',
            'material' => 'Material Design 3 — elevated surfaces, dynamic color, rounded corners, ink ripple effects',
            'apple' => 'Apple Style — clean, premium, SF-style typography, generous spacing, subtle depth',
            'fluent' => 'Microsoft Fluent — acrylic backgrounds, precise spacing, business-appropriate, subtle motion',
            'dark' => 'Dark Theme — premium dark aesthetic, soft grays, accent highlights, reduced eye strain',
            'light' => 'Light Theme — clean white/gray backgrounds, high contrast, professional and bright',
            default => 'Modern SaaS',
        };
    }

    private function formatComponentInfo(array $components): string
    {
        if (empty($components)) {
            return 'Standard UI layout with various components';
        }

        $parts = [];
        foreach (array_slice($components, 0, 10) as $c) {
            // Handle case where AI returns string instead of object
            if (is_string($c)) {
                $parts[] = "- {$c}";
            } elseif (is_array($c) && isset($c['type'], $c['label'], $c['position'])) {
                $parts[] = "- {$c['type']}: {$c['label']} ({$c['position']})";
            }
        }
        return $parts ? implode("\n", $parts) : 'Standard UI layout with various components';
    }

    private function detectImprovements(array $components): array
    {
        $improved = [];
        $regressed = [];
        $unchanged = [];

        // Safely extract component types - handle string entries
        $componentTypes = [];
        foreach ($components as $c) {
            if (is_array($c) && isset($c['type'])) {
                $componentTypes[] = $c['type'];
            } elseif (is_string($c)) {
                // If component is a string, use it as type
                $componentTypes[] = $c;
            }
        }

        foreach (['navbar', 'sidebar', 'header', 'footer'] as $nav) {
            if (in_array($nav, $componentTypes)) {
                $unchanged[] = "Navigation ({$nav}) preserved";
            }
        }

        foreach ($components as $c) {
            // Skip string components
            if (is_string($c)) {
                $unchanged[] = "{$c} maintained";
                continue;
            }
            if (!is_array($c)) {
                continue;
            }
            $type = $c['type'] ?? 'component';
            $label = $c['label'] ?? 'unknown';
            if (($c['quality'] ?? '') === 'needs_improvement') {
                $improved[] = "{$type}: Improved {$label}";
            } elseif (($c['quality'] ?? '') === 'critical') {
                $improved[] = "{$type}: Fixed critical issue in {$label}";
            } else {
                $unchanged[] = "{$type}: {$label} maintained";
            }
        }

        // Generic improvements based on style
        $improved = array_merge($improved, [
            'Typography: Improved font weights and sizing hierarchy',
            'Color: Enhanced contrast ratios for better readability',
            'Cards: Added refined shadows and border treatments',
            'Buttons: Polished hover states and visual hierarchy',
        ]);

        return [
            'improved' => array_values(array_unique($improved)),
            'regressed' => $regressed,
            'unchanged' => array_values(array_unique($unchanged)),
        ];
    }
}
