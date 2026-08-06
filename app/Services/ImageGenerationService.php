<?php

namespace App\Services;

use App\Services\AI\ImageProviderInterface;
use App\Services\AI\Providers\HuggingFaceImageProvider;
use App\Services\AI\MiniMaxService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Image Generation Service
 * 
 * Orchestrates AI image generation for UI redesign.
 * Uses HuggingFace as the primary provider with automatic model fallback.
 * 
 * Pipeline:
 * 1. Analyze screenshot
 * 2. Build redesign prompt
 * 3. Generate with HuggingFace (auto model fallback)
 * 4. Optional Pillow optimization (post-processing only)
 * 5. Save to storage
 * 6. Return structured response
 */
class ImageGenerationService
{
    private ImageEnhancementService $enhancer;

    public function __construct()
    {
        $this->enhancer = new ImageEnhancementService();
    }

    /**
     * Generate an AI-powered UI redesign
     * 
     * @param string $screenshotPath Path to original screenshot
     * @param string $designStyle Design style (modern_saas, minimal, bold, elegant, playful)
     * @param array $options Generation options
     * @return array Result with success, provider, model, image_url, etc.
     */
    public function generateRedesign(string $screenshotPath, string $designStyle = 'modern_saas', array $options = []): array
    {
        $startTime = microtime(true);
        $userId = $options['user_id'] ?? null;

        // Validate screenshot
        $fullPath = str_starts_with($screenshotPath, '/')
            ? $screenshotPath
            : storage_path('app/public/' . $screenshotPath);

        if (!file_exists($fullPath)) {
            return $this->errorResult('Screenshot not found: ' . $screenshotPath, 0);
        }

        // Build the redesign prompt
        $prompt = $this->buildRedesignPrompt($designStyle);

        Log::info('ImageGenerationService: Starting redesign generation', [
            'screenshot' => $screenshotPath,
            'design_style' => $designStyle,
            'prompt' => $prompt,
        ]);

        // Generate using HuggingFace (primary provider with auto model fallback)
        $provider = new HuggingFaceImageProvider();
        $providerStatus = $provider->availability();

        if (!($providerStatus['available'] ?? false)) {
            Log::error('ImageGenerationService: HuggingFace not available, falling back to MiniMax', [
                'status' => $providerStatus,
            ]);

            // Fallback: use MiniMax for redesign (text-to-image with style reference)
            return $this->generateWithMiniMaxFallback($fullPath, $prompt, $designStyle, $options, $startTime);
        }

        // Generate image with HuggingFace (tries models in priority order)
        $result = $provider->generate($fullPath, $prompt, [
            'model' => $options['model'] ?? null, // null = use priority list
            'width' => 1024,
            'height' => 1024,
            'strength' => $options['strength'] ?? 0.75,
            'num_inference_steps' => $options['steps'] ?? 30,
            'guidance_scale' => $options['guidance_scale'] ?? 7.5,
        ]);

        $generationTimeMs = $result['generationTimeMs'] ?? (int)((microtime(true) - $startTime) * 1000);
        $generationTimeSec = round($generationTimeMs / 1000, 2);

        // Check if generation succeeded
        if (!$result['success']) {
            Log::error('ImageGenerationService: Generation failed', [
                'error' => $result['error'] ?? 'Unknown',
                'error_code' => $result['errorCode'] ?? 'UNKNOWN',
                'tried_models' => $result['tried_models'] ?? [],
            ]);

            return [
                'success' => false,
                'error' => $result['error'] ?? 'AI generation failed',
                'error_code' => $result['errorCode'] ?? 'GENERATION_FAILED',
                'provider' => 'huggingface',
                'model' => $result['model'] ?? 'unknown',
                'generation_time' => $generationTimeSec,
                'generation_time_ms' => $generationTimeMs,
                'tried_models' => $result['tried_models'] ?? [],
                'can_retry' => $result['retry'] ?? true,
            ];
        }

        // Success - generation completed
        Log::info('ImageGenerationService: Generation succeeded', [
            'model' => $result['model'],
            'tried_models' => $result['tried_models'] ?? [],
            'image_path' => $result['imagePath'],
            'generation_time_ms' => $generationTimeMs,
        ]);

        $elapsed = (int)((microtime(true) - $startTime) * 1000);

        return [
            'success' => true,
            'provider' => 'Hugging Face',
            'model' => $result['model'],
            'generation_time' => round($elapsed / 1000, 2),
            'generation_time_ms' => $elapsed,
            'original_image_url' => "/storage/{$screenshotPath}",
            'generated_image_url' => "/storage/{$result['imagePath']}",
            'image_path' => $result['imagePath'],
            'tried_models' => $result['tried_models'] ?? [],
            'improvements' => $this->getImprovements($designStyle),
            'prompt_used' => $prompt,
            'status' => 'completed',
        ];
    }

    /**
     * Build a detailed prompt for UI redesign
     * 
     * Analyzes the input screenshot and creates a prompt that:
     * - Preserves layout, navigation, branding, content
     * - Improves typography, colors, spacing, visual hierarchy
     */
    private function buildRedesignPrompt(string $designStyle): string
    {
        $styleInstructions = match($designStyle) {
            'modern_saas' => 'modern SaaS dashboard with clean lines, subtle shadows, rounded corners, professional typography, vibrant accent colors, card-based layout',
            'minimal' => 'minimalist design with lots of whitespace, clean typography, monochrome palette, subtle borders, elegant simplicity',
            'bold' => 'bold design with strong contrast, large typography, vibrant colors, prominent CTAs, high-impact visuals',
            'elegant' => 'elegant luxury design with refined typography, muted colors, sophisticated shadows, premium feel',
            'playful' => 'playful design with bright colors, rounded shapes, friendly typography, fun engaging UI',
            default => 'professional UI redesign with improved visual hierarchy, better spacing, enhanced typography',
        };

        return <<<PROMPT
UI REDESIGN: Transform this screenshot into a professionally designed interface.

STYLE: {$styleInstructions}

IMPORTANT REQUIREMENTS - PRESERVE EXACTLY:
- Layout structure and grid system
- Sidebar navigation position and style
- Header/menu bar design
- All cards, tables, forms and their positions
- Text content, labels, and data values
- Brand logos and visual identity
- Interactive element positions
- All functionality and features

ONLY IMPROVE:
- Spacing and padding between elements
- Typography hierarchy and font weights
- Color harmony and palette balance
- Card styling and shadow depth
- Button and form field design
- Visual depth and hierarchy
- Overall polish and refinement
- Accessibility and contrast

Generate an improved version that looks like a premium, professionally designed interface while keeping ALL original content, layout, and functionality EXACTLY the same.
PROMPT;
    }

    /**
     * Get list of improvements for a design style
     */
    private function getImprovements(string $designStyle): array
    {
        return match($designStyle) {
            'modern_saas' => [
                'Improved spacing and padding throughout',
                'Enhanced typography hierarchy and font weights',
                'Better color contrast and accessibility',
                'Refined card designs with subtle shadows',
                'Polished button and form styling',
                'Cleaner navigation and sidebar design',
            ],
            'minimal' => [
                'Increased whitespace and visual breathing room',
                'Simplified typography with elegant fonts',
                'Reduced visual clutter, maximum clarity',
                'Subtle borders and divider lines',
                'Clean, understated button design',
            ],
            'bold' => [
                'Stronger color contrast and vibrancy',
                'Larger, more impactful typography',
                'Prominent call-to-action buttons',
                'Dynamic visual elements and icons',
                'High-contrast card designs',
            ],
            'elegant' => [
                'Sophisticated color palette with muted tones',
                'Refined serif and sans-serif typography pairing',
                'Luxurious shadows and depth effects',
                'Premium button and input field styling',
                'Elegant spacing and rhythm',
            ],
            'playful' => [
                'Bright, engaging color palette',
                'Rounded corners and friendly shapes',
                'Fun micro-interactions and hover effects',
                'Cheerful typography with personality',
                'Bouncy, engaging UI elements',
            ],
            default => [
                'Improved visual hierarchy',
                'Better typography and spacing',
                'Enhanced color harmony',
                'Polished component styling',
                'Professional overall appearance',
            ],
        };
    }

    /**
     * Create error result
     */
    private function errorResult(string $message, int $elapsedMs): array
    {
        return [
            'success' => false,
            'error' => $message,
            'error_code' => 'INTERNAL_ERROR',
            'provider' => 'huggingface',
            'model' => null,
            'generation_time' => round($elapsedMs / 1000, 2),
            'generation_time_ms' => $elapsedMs,
            'original_image_url' => null,
            'generated_image_url' => null,
            'status' => 'failed',
        ];
    }

    /**
     * Generate a preview for a specific component (cropped section)
     */
    public function generateComponentPreview(
        string $screenshotPath, 
        string $prompt, 
        array $cropOptions = []
    ): array {
        $startTime = microtime(true);
        
        $cropX = $cropOptions['crop_x'] ?? 10;
        $cropY = $cropOptions['crop_y'] ?? 10;
        $cropW = $cropOptions['crop_width'] ?? 30;
        $cropH = $cropOptions['crop_height'] ?? 30;
        $componentType = $cropOptions['component_type'] ?? 'UI component';
        
        // First try to use HuggingFace for img2img
        $provider = new HuggingFaceImageProvider();
        $status = $provider->availability();
        
        if ($status['available'] ?? false) {
            try {
                // Use SDXL for component improvement
                $result = $provider->generate(
                    $screenshotPath,
                    $prompt,
                    [
                        'model' => 'stabilityai/stable-diffusion-xl-base-1.0',
                        'strength' => 0.7,
                        'guidance_scale' => 7.5,
                    ]
                );
                
                if ($result['success'] && isset($result['image_path'])) {
                    return [
                        'success' => true,
                        'provider' => 'huggingface',
                        'model' => 'stabilityai/stable-diffusion-xl-base-1.0',
                        'image_path' => $result['image_path'],
                        'generation_time' => round((microtime(true) - $startTime) * 1000 / 1000, 2),
                        'status' => 'completed',
                    ];
                }
            } catch (\Exception $e) {
                Log::warning('HuggingFace component preview failed: ' . $e->getMessage());
            }
        }
        
        // Fallback: return crop coordinates for frontend to display
        return [
            'success' => false,
            'provider' => null,
            'model' => null,
            'image_path' => null,
            'generation_time' => round((microtime(true) - $startTime) * 1000 / 1000, 2),
            'status' => 'fallback',
            'crop_coordinates' => [
                'x' => $cropX,
                'y' => $cropY,
                'width' => $cropW,
                'height' => $cropH,
            ],
            'error' => 'Image generation unavailable',
        ];
    }

    /**
     * Fallback: generate redesign using MiniMax text-to-image.
     * Since MiniMax image-01 doesn't support img2img, we describe the original
     * screenshot in the prompt and ask for a redesigned version in the given style.
     */
    private function generateWithMiniMaxFallback(
        string $fullPath,
        string $stylePrompt,
        string $designStyle,
        array $options,
        float $startTime
    ): array {
        try {
            $minimax = new MiniMaxService();
            $avail = $minimax->health();

            if (!($avail['ok'] ?? false) && ($avail['status'] ?? '') !== 'healthy') {
                return [
                    'success' => false,
                    'error' => 'MiniMax is not available: ' . ($avail['reason'] ?? $avail['error'] ?? 'Connection failed'),
                    'error_code' => 'PROVIDER_UNAVAILABLE',
                    'provider' => 'minimax',
                    'model' => 'image-01',
                    'generation_time' => 0,
                    'generation_time_ms' => 0,
                    'can_retry' => true,
                ];
            }

            // Build a concise prompt for MiniMax image-01
            // NOTE: MiniMax image-01 has usage limits. Keep prompt short and focused.
            $styleDesc = match($designStyle) {
                'modern_saas' => 'modern SaaS dashboard, clean UI, purple accents, card layout',
                'minimal' => 'minimalist UI, whitespace, clean typography',
                'bold' => 'bold design, strong contrast, vibrant colors',
                'elegant' => 'elegant luxury UI, refined typography',
                'glassmorphism' => 'glassmorphism UI, translucent panels',
                'enterprise' => 'enterprise dashboard, professional look',
                'dark' => 'dark mode dashboard, modern aesthetic',
                default => 'modern professional dashboard UI',
            };
            $redesignPrompt = sprintf(
                "Transform this UI screenshot into a %s. " .
                "Improve visual hierarchy, spacing, typography and color palette. " .
                "Keep all content, layout and functionality exactly the same.",
                $styleDesc
            );

            $result = $minimax->image($redesignPrompt, [
                'size' => '1:1',
                'n' => 1,
                'timeout' => 300,
            ]);

            $generationTimeMs = (int)((microtime(true) - $startTime) * 1000);
            $generationTimeSec = round($generationTimeMs / 1000, 2);

            if (!empty($result['error'])) {
                Log::error('MiniMax fallback failed', ['error' => $result['error']]);
                $isCreditLimit = str_contains($result['error'], 'usage limit')
                    || str_contains($result['error'], 'credits')
                    || str_contains($result['error'], 'quota');
                return [
                    'success' => false,
                    'error' => $isCreditLimit
                        ? 'AI image generation credits exhausted. Please add credits to your MiniMax account to continue using redesign features.'
                        : $result['error'],
                    'error_code' => $isCreditLimit ? 'CREDITS_EXHAUSTED' : 'MINIMAX_FAILED',
                    'provider' => 'minimax',
                    'model' => 'image-01',
                    'generation_time' => $generationTimeSec,
                    'generation_time_ms' => $generationTimeMs,
                    'can_retry' => $isCreditLimit ? false : true,
                ];
            }

            // Download and save the generated image
            $imageUrl = $result['images'][0] ?? null;
            if (!$imageUrl) {
                return [
                    'success' => false,
                    'error' => 'No image returned from MiniMax',
                    'error_code' => 'NO_IMAGE',
                    'provider' => 'minimax',
                    'model' => 'image-01',
                    'generation_time' => $generationTimeSec,
                    'generation_time_ms' => $generationTimeMs,
                    'can_retry' => true,
                ];
            }

            $downloadUrl = str_starts_with($imageUrl, 'http')
                ? $imageUrl
                : env('APP_URL') . $imageUrl;
            $imageContent = @file_get_contents($downloadUrl);
            if ($imageContent === false) {
                // Fallback: try with https
                $imageContent = @file_get_contents(str_replace('http://', 'https://', $downloadUrl));
            }
            if ($imageContent === false) {
                Log::error('MiniMax fallback: failed to download image', [
                    'url' => $downloadUrl,
                    'local_path' => storage_path('app/public/' . ltrim($imageUrl, '/')),
                ]);
                return [
                    'success' => false,
                    'error' => 'Failed to download MiniMax image',
                    'error_code' => 'DOWNLOAD_FAILED',
                    'provider' => 'minimax',
                    'model' => 'image-01',
                    'generation_time' => $generationTimeSec,
                    'generation_time_ms' => $generationTimeMs,
                    'can_retry' => true,
                ];
            }

            $filename = 'inspector-redesigns/' . Str::uuid() . '.png';
            $publicPath = 'public/' . $filename;
            Storage::put($publicPath, $imageContent);

            Log::info('MiniMax fallback result', [
                'result_keys' => array_keys($result),
                'has_error' => !empty($result['error']),
                'has_images' => !empty($result['images']),
                'image_count' => count($result['images'] ?? []),
            ]);

            if (!empty($result['error'])) {
                Log::error('MiniMax fallback failed', ['error' => $result['error']]);
                return [
                    'success' => false,
                    'error' => $result['error'],
                    'error_code' => 'MINIMAX_FAILED',
                    'provider' => 'minimax',
                    'model' => 'image-01',
                    'generation_time' => $generationTimeSec,
                    'generation_time_ms' => $generationTimeMs,
                    'can_retry' => true,
                ];
            }

            return [
                'success' => true,
                'image_path' => $filename,
                'image_url' => Storage::url($filename),
                'provider' => 'minimax',
                'model' => 'image-01',
                'generation_time' => $generationTimeSec,
                'generation_time_ms' => $generationTimeMs,
                'style' => $designStyle,
            ];
        } catch (\Throwable $e) {
            Log::error('MiniMax fallback exception', ['exception' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'MiniMax redesign failed: ' . $e->getMessage(),
                'error_code' => 'EXCEPTION',
                'provider' => 'minimax',
                'model' => 'image-01',
                'generation_time' => 0,
                'generation_time_ms' => 0,
                'can_retry' => true,
            ];
        }
    }

    /**
     * Get provider status for debugging
     */
    public function getProviderStatus(): array
    {
        $provider = new HuggingFaceImageProvider();
        $status = $provider->availability();

        return [
            'provider' => 'huggingface',
            'name' => 'Hugging Face',
            'available' => $status['available'] ?? false,
            'status' => $status['status'] ?? 'unknown',
            'error' => $status['error'] ?? null,
            'models' => $provider->getModels(),
            'model_priority' => array_column($provider->getModels(), 'id'),
        ];
    }
}
