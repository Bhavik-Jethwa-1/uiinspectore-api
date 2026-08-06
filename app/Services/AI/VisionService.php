<?php

namespace App\Services\AI;

use App\Services\AI\Inspector\GPTImageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Multi-modal vision service — wraps GPT Vision and MiniMax VL.
 *
 * Tries GPT Vision first; on any failure (billing, network, parse),
 * automatically falls back to MiniMax VL (free via OpenClaw gateway).
 *
 * Never throws. Always returns a structured array.
 */
class VisionService
{
    public function __construct(
        private GPTImageService $gptImage,
        private MiniMaxService $miniMax,
    ) {}

    /**
     * Check if any vision provider is available.
     */
    public function availability(): array
    {
        // GPT Vision
        try {
            $gpt = $this->gptImage->availability();
            if ($gpt['ok']) {
                return ['ok' => true, 'provider' => 'openai', 'model' => 'gpt-4o'];
            }
        } catch (\Throwable $e) {
            Log::warning('VisionService: GPT availability check threw', [
                'error' => $e->getMessage(),
            ]);
        }

        // MiniMax VL (free fallback) — if health check times out, still report as available
        // since the gateway may just be waking up. The actual vision call will use a longer timeout.
        try {
            $mm = $this->miniMax->availability();
            if ($mm['ok']) {
                return ['ok' => true, 'provider' => 'minimax', 'model' => 'MiniMax-VL-01'];
            }
            // If unhealthy due to timeout, treat as available — gateway is probably waking up
            $reason = $mm['reason'] ?? '';
            if (str_contains(strtolower($reason), 'timeout') || str_contains(strtolower($reason), 'unreachable')) {
                Log::info('VisionService: MiniMax health check timed out — will attempt vision call anyway');
                return ['ok' => true, 'provider' => 'minimax', 'model' => 'MiniMax-VL-01', 'tentative' => true];
            }
            return [
                'ok' => false,
                'provider' => null,
                'model' => null,
                'reason' => $mm['reason'] ?? 'MiniMax gateway unavailable',
            ];
        } catch (\Throwable $e) {
            Log::warning('VisionService: MiniMax availability check threw', [
                'error' => $e->getMessage(),
            ]);
            return [
                'ok' => false,
                'provider' => null,
                'model' => null,
                'reason' => 'Both GPT Vision and MiniMax unavailable',
            ];
        }
    }

    /**
     * Analyze a screenshot and return structured UI data.
     *
     * @param string $screenshotPath  Relative path in storage/app/public/
     * @return array{success: bool, analysis?: array, error?: string, provider?: string}
     */
    public function analyzeScreenshot(string $screenshotPath): array
    {
        $fullPath = storage_path("app/public/{$screenshotPath}");
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => "Screenshot not found: {$screenshotPath}"];
        }

        $imageUrl = url(Storage::url($screenshotPath));

        $prompt = <<<'PROMPT'
You are an expert UI/UX designer analyzing a web application screenshot.

Analyze the screenshot and return a detailed JSON object describing ALL UI components you can see:

{
  "overall_score": 0-100,
  "summary": "brief description of what the app is",
  "layout": ["header with logo and nav", "left sidebar with navigation items", "main content area with cards"],
  "components": {
    "header": { "present": true, "height": "px", "items": ["logo", "nav items", "user menu"] },
    "sidebar": { "present": true, "width": "px", "items": ["nav items"] },
    "cards": { "count": N, "types": ["metric card", "chart card", "list card"] },
    "buttons": { "count": N, "types": ["primary CTA", "secondary", "icon button"] },
    "tables": { "count": N, "columns": N, "features": ["sorting", "pagination"] },
    "forms": { "count": N, "inputs": N },
    "typography": { "font_family": "sans|serif|mono", "size_scale": "consistent|varied" },
    "colors": { "primary": "#hex", "secondary": "#hex", "background": "#hex", "text": "#hex" },
    "spacing": { "consistent": true, "density": "compact|normal|spacious" },
    "shadows": { "present": true, "intensity": "none|light|medium|heavy" },
    "icons": { "present": true, "style": "outline|solid|mixed" }
  },
  "must_preserve": ["layout structure", "sidebar navigation", "all component positions"],
  "issues": ["improve spacing", "upgrade typography", "add shadows"]
}

IMPORTANT: Return ONLY valid JSON. No markdown, no code fences, no explanation.
PROMPT;

        // ── Try GPT Vision ────────────────────────────────────────────────
        try {
            $gptAvail = $this->gptImage->availability();
            if ($gptAvail['ok']) {
                $result = $this->gptImage->analyzeScreenshot($screenshotPath);
                if ($result['success'] ?? false) {
                    return [
                        'success' => true,
                        'analysis' => $result['analysis'] ?? $result,
                        'provider' => 'openai',
                    ];
                }
                // GPT failed with an error — check if it's billing-related
                $error = $result['error'] ?? '';
                if ($this->isBillingError($error)) {
                    Log::info('VisionService: GPT Vision billing error, falling back to MiniMax', [
                        'error' => $error,
                    ]);
                    return $this->analyzeWithMiniMax($imageUrl, $prompt);
                }
                // Non-billing error from GPT — try MiniMax anyway
                if (!empty($error)) {
                    Log::warning('VisionService: GPT Vision error, trying MiniMax', [
                        'error' => $error,
                    ]);
                    $mmResult = $this->analyzeWithMiniMax($imageUrl, $prompt);
                    if ($mmResult['success']) {
                        return $mmResult;
                    }
                    // Both failed — return GPT error (it was primary)
                    return ['success' => false, 'error' => $error, 'provider' => 'openai'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('VisionService: GPT Vision threw exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        // ── Fall back to MiniMax VL ───────────────────────────────────────
        return $this->analyzeWithMiniMax($imageUrl, $prompt);
    }

    private function analyzeWithMiniMax(string $imageUrl, string $prompt): array
    {
        try {
            $mmAvail = $this->miniMax->availability();
            if (!$mmAvail['ok']) {
                return [
                    'success' => false,
                    'error' => 'MiniMax unavailable: ' . ($mmAvail['reason'] ?? 'gateway unreachable'),
                    'provider' => 'minimax',
                ];
            }

            $result = $this->miniMax->vision($imageUrl, $prompt, [
                'max_tokens' => 4096,
                'temperature' => 0.1,
                'timeout' => 180, // allow 180s for large image processing via OpenRouter
            ]);

            if (isset($result['error'])) {
                return [
                    'success' => false,
                    'error' => 'MiniMax: ' . ($result['error'] ?? 'unknown error'),
                    'provider' => 'minimax',
                ];
            }

            // MiniMaxService.chat() returns 'reply' key, not 'choices'
            $content = trim($result['reply'] ?? ($result['choices'][0]['message']['content'] ?? ''));

            // Strip markdown code fences
            $content = preg_replace('/^```json\s*/', '', $content);
            $content = preg_replace('/^```\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);

            $analysis = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($analysis)) {
                return [
                    'success' => true,
                    'analysis' => $analysis,
                    'provider' => 'minimax',
                ];
            }

            // Non-JSON response — still useful as raw text
            return [
                'success' => true,
                'analysis' => [
                    'summary' => $content,
                    'raw_response' => true,
                    'components' => [],
                    'layout' => [],
                    'issues' => [],
                ],
                'provider' => 'minimax',
            ];

        } catch (\Throwable $e) {
            Log::error('VisionService: MiniMax threw exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return [
                'success' => false,
                'error' => 'MiniMax exception: ' . $e->getMessage(),
                'provider' => 'minimax',
            ];
        }
    }

    private function isBillingError(string $error): bool
    {
        $lower = strtolower($error);
        return str_contains($lower, 'billing')
            || str_contains($lower, 'limit')
            || str_contains($lower, 'quota')
            || str_contains($lower, 'payment')
            || str_contains($lower, 'hard limit')
            || str_contains($lower, 'exceeded')
            || str_contains($lower, 'insufficient');
    }
}
