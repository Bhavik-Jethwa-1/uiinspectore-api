<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Free image enhancement service using Python/Pillow.
 * Applies realistic UI polish: contrast, sharpness, color balance.
 * No GPU, no API keys, no billing required.
 */
class ImageEnhancementService
{
    protected string $storagePath;

    public function __construct()
    {
        $this->storagePath = storage_path('app/public/inspector-redesigns/');
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * Enhance a screenshot with UI polish (full enhancement).
     * This is the legacy method - prefer using AI generation + optimize().
     */
    public function enhance(string $sourcePath, string $style = 'modern_saas'): array
    {
        return $this->optimize($sourcePath, $style);
    }

    /**
     * Optimize an AI-generated image: compress, improve quality, apply final polish.
     * Used as post-processing after AI image generation.
     * Returns the path to the optimized image.
     */
    public function optimize(string $sourcePath, string $style = 'modern_saas'): array
    {
        $startTime = microtime(true);

        // sourcePath may be relative or absolute
        $absolutePath = str_starts_with($sourcePath, '/')
            ? $sourcePath
            : storage_path('app/public/' . $sourcePath);

        if (!file_exists($absolutePath)) {
            return ['success' => false, 'error' => 'Source image not found'];
        }

        $filename = 'optimized_' . time() . '_' . uniqid() . '.png';
        $outputPath = $this->storagePath . $filename;

        // Subtle optimization presets - much lighter than full enhancement
        $presets = $this->getOptimizePreset($style);

        // Build Python optimization script
        $script = $this->buildOptimizationScript($absolutePath, $outputPath, $presets);

        $tmpScript = '/tmp/optimize_' . uniqid() . '.py';
        file_put_contents($tmpScript, $script);

        $output = [];
        $returnCode = 0;
        exec("python3 " . escapeshellarg($tmpScript) . " 2>&1", $output, $returnCode);
        @unlink($tmpScript);

        if ($returnCode !== 0) {
            Log::error('ImageEnhancementService optimize failed', ['output' => $output, 'code' => $returnCode]);
            // Don't fail completely - just return the original path
            return [
                'success' => true,
                'image_path' => str_replace(storage_path('app/public/'), '', $absolutePath),
                'generation_time_ms' => 0,
                'provider' => 'pillow',
                'style' => $style,
                'note' => 'Optimization skipped - using original',
            ];
        }

        if (!file_exists($outputPath)) {
            return ['success' => false, 'error' => 'Optimized image was not created'];
        }

        $elapsed = (int)((microtime(true) - $startTime) * 1000);

        return [
            'success' => true,
            'image_path' => 'inspector-redesigns/' . $filename,
            'generation_time_ms' => $elapsed,
            'provider' => 'pillow',
            'style' => $style,
        ];
    }

    /**
     * Get optimization preset for post-processing.
     * Lighter touch than full enhancement - just quality polish.
     */
    protected function getOptimizePreset(string $style): array
    {
        $presets = [
            'modern_saas' => [
                'contrast' => 1.05,
                'brightness' => 1.02,
                'sharpness' => 1.15,
                'saturation' => 1.02,
            ],
            'minimal' => [
                'contrast' => 1.08,
                'brightness' => 1.0,
                'sharpness' => 1.1,
                'saturation' => 0.98,
            ],
            'bold' => [
                'contrast' => 1.12,
                'brightness' => 1.05,
                'sharpness' => 1.2,
                'saturation' => 1.1,
            ],
            'elegant' => [
                'contrast' => 1.1,
                'brightness' => 1.0,
                'sharpness' => 1.15,
                'saturation' => 0.95,
            ],
            'playful' => [
                'contrast' => 1.08,
                'brightness' => 1.03,
                'sharpness' => 1.15,
                'saturation' => 1.08,
            ],
        ];

        return $presets[$style] ?? $presets['modern_saas'];
    }

    /**
     * Build Python script for image optimization (post-processing).
     */
    protected function buildOptimizationScript(string $source, string $output, array $preset): string
    {
        $jsonPreset = json_encode($preset);
        return <<<PYTHON
import sys
try:
    from PIL import Image, ImageEnhance, ImageFilter
except ImportError:
    print("PIL not available")
    sys.exit(1)

try:
    img = Image.open("{$source}").convert("RGB")

    # 1. Slight brightness adjustment
    if {$preset['brightness']} != 1.0:
        enhancer = ImageEnhance.Brightness(img)
        img = enhancer.enhance({$preset['brightness']})

    # 2. Contrast enhancement
    if {$preset['contrast']} != 1.0:
        enhancer = ImageEnhance.Contrast(img)
        img = enhancer.enhance({$preset['contrast']})

    # 3. Subtle sharpening
    if {$preset['sharpness']} > 1.0:
        enhancer = ImageEnhance.Sharpness(img)
        img = enhancer.enhance({$preset['sharpness']})

    # 4. Color/saturation fine-tuning
    if {$preset['saturation']} != 1.0:
        enhancer = ImageEnhance.Color(img)
        img = enhancer.enhance({$preset['saturation']})

    # Save with high quality
    img.save("{$output}", "PNG", quality=95)
    print("OK")

except Exception as e:
    print(f"Error: {e}")
    sys.exit(1)
PYTHON;
    }

    /**
     * Get enhancement preset for a style.
     */
    protected function getStylePreset(string $style): array
    {
        $presets = [
            'modern_saas' => [
                'contrast' => 1.15,
                'brightness' => 1.08,
                'sharpness' => 1.4,
                'color' => 1.1,
                'saturation' => 1.05,
                'warmth' => 0,
                'vignette' => 0.03,
            ],
            'minimal' => [
                'contrast' => 1.2,
                'brightness' => 1.05,
                'sharpness' => 1.5,
                'color' => 0.95,
                'saturation' => 0.9,
                'warmth' => -5,
                'vignette' => 0.02,
            ],
            'glassmorphism' => [
                'contrast' => 1.1,
                'brightness' => 1.12,
                'sharpness' => 1.3,
                'color' => 1.15,
                'saturation' => 1.1,
                'warmth' => 5,
                'vignette' => 0.04,
            ],
            'enterprise' => [
                'contrast' => 1.25,
                'brightness' => 1.02,
                'sharpness' => 1.6,
                'color' => 1.0,
                'saturation' => 0.95,
                'warmth' => -3,
                'vignette' => 0.0,
            ],
            'dark' => [
                'contrast' => 1.3,
                'brightness' => 0.9,
                'sharpness' => 1.5,
                'color' => 1.1,
                'saturation' => 1.15,
                'warmth' => -10,
                'vignette' => 0.06,
            ],
        ];

        return $presets[$style] ?? $presets['modern_saas'];
    }

    /**
     * Build Python script for image enhancement.
     */
    protected function buildEnhancementScript(string $source, string $output, array $preset): string
    {
        $jsonPreset = json_encode($preset);
        return <<<PYTHON
import sys
try:
    from PIL import Image, ImageEnhance, ImageFilter, ImageColor
except ImportError:
    print("PIL not available")
    sys.exit(1)

try:
    img = Image.open("{$source}").convert("RGB")

    # Convert to RGBA for vignette support
    img = img.convert("RGBA")

    # 1. Brightness
    if {$preset['brightness']} != 1.0:
        enhancer = ImageEnhance.Brightness(img)
        img = enhancer.enhance({$preset['brightness']})

    # 2. Contrast
    if {$preset['contrast']} != 1.0:
        enhancer = ImageEnhance.Contrast(img)
        img = enhancer.enhance({$preset['contrast']})

    # 3. Color / Saturation
    if {$preset['saturation']} != 1.0:
        enhancer = ImageEnhance.Color(img)
        img = enhancer.enhance({$preset['saturation']})

    # 4. Sharpness
    if {$preset['sharpness']} > 1.0:
        # Apply unsharp mask for sharpness
        for _ in range(int({$preset['sharpness']})):
            img = img.filter(ImageFilter.SHARPEN)

    # 5. Vignette effect
    if {$preset['vignette']} > 0:
        width, height = img.size
        vignette = Image.new('RGBA', (width, height), (0, 0, 0, 0))
        from PIL import ImageDraw
        draw = ImageDraw.Draw(vignette)

        # Create radial gradient for vignette
        cx, cy = width / 2, height / 2
        max_dist = ((cx ** 2 + cy ** 2) ** 0.5)
        strength = int({$preset['vignette']} * 255)

        for y in range(0, height, 2):
            for x in range(0, width, 2):
                dist = ((x - cx) ** 2 + (y - cy) ** 2) ** 0.5
                factor = min(1.0, dist / (max_dist * 0.7))
                alpha = int(strength * factor)
                vignette.putpixel((x, y), (0, 0, 0, alpha))
                if x + 1 < width:
                    vignette.putpixel((x + 1, y), (0, 0, 0, alpha))
                if y + 1 < height:
                    vignette.putpixel((x, y + 1), (0, 0, 0, alpha))
                if x + 1 < width and y + 1 < height:
                    vignette.putpixel((x + 1, y + 1), (0, 0, 0, alpha))

        img = Image.alpha_composite(img, vignette)

    # Convert back to RGB for saving
    img = img.convert("RGB")

    # Save with high quality
    img.save("{$output}", "PNG", quality=95)
    print("OK")

except Exception as e:
    print(f"Error: {e}")
    sys.exit(1)
PYTHON;
    }
}
