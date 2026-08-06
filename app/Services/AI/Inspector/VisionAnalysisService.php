<?php

namespace App\Services\AI\Inspector;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VisionAnalysisService
{
    private const OPENROUTER_API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    // Free vision model on OpenRouter
    private const VISION_MODEL = 'nvidia/nemotron-nano-12b-v2-vl:free';

    // Retryable HTTP status codes
    private const RETRYABLE_CODES = [429, 500, 502, 503, 504];

    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 2000;

    private static function openRouterKey(): string
    {
        static $key = null;
        if ($key === null) {
            $key = env('OPENROUTER_API_KEY', '');
        }
        return $key;
    }

    /**
     * Analyze a screenshot and return comprehensive UI/UX analysis.
     *
     * @param string $imagePath - relative path in storage (e.g. "inspector-screenshots/uuid.png")
     * @param array $options - page_goal, persona
     * @return array
     */
    public function analyze(string $imagePath, array $options = []): array
    {
        $fullPath = storage_path('app/public/' . $imagePath);
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => 'Screenshot file not found'];
        }

        $pageGoal = $options['page_goal'] ?? 'General use';
        $persona = $options['persona'] ?? 'general';

        $personaContext = $this->getPersonaContext($persona);
        $prompt = $this->buildAnalysisPrompt($pageGoal, $personaContext);

        // Convert image to base64 data URI
        $dataUri = $this->compressImageForVision($fullPath);

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                ],
            ],
        ];

        return $this->callVisionApiWithRetry($messages);
    }

    /**
     * Call OpenRouter Vision API with retry logic for transient errors.
     */
    private function callVisionApiWithRetry(array $messages, int $attempt = 1): array
    {
        $key = self::openRouterKey();
        if (empty($key)) {
            return [
                'success' => false,
                'error' => 'OPENROUTER_API_KEY is not set in .env',
                'provider' => 'openrouter',
            ];
        }

        $payload = [
            'model' => self::VISION_MODEL,
            'messages' => $messages,
            'max_tokens' => 3000,
            'temperature' => 0.2,
        ];

        Log::info('VisionAnalysisService: calling OpenRouter', [
            'model' => self::VISION_MODEL,
            'attempt' => $attempt,
            'key_prefix' => substr($key, 0, 8),
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => 'https://uiinspectore.app',
                'X-Title' => 'UI Inspector',
            ])->timeout(120)->post(self::OPENROUTER_API_URL, $payload);

            $httpCode = $response->status();
            $body = $response->json();

            Log::info('VisionAnalysisService: OpenRouter response', [
                'http_code' => $httpCode,
                'attempt' => $attempt,
                'has_error' => isset($body['error']),
                'error_type' => $body['error']['type'] ?? null,
                'error_msg' => $body['error']['message'] ?? null,
            ]);

            // Retry on transient errors
            if (in_array($httpCode, self::RETRYABLE_CODES) && $attempt < self::MAX_RETRIES) {
                Log::warning("VisionAnalysisService: retryable HTTP {$httpCode}, attempt {$attempt}/" . self::MAX_RETRIES);
                usleep(self::RETRY_DELAY_MS * 1000);
                return $this->callVisionApiWithRetry($messages, $attempt + 1);
            }

            if ($httpCode === 401 || $httpCode === 403) {
                $msg = $body['error']['message'] ?? "Authentication failed (HTTP {$httpCode})";
                return [
                    'success' => false,
                    'error' => "OpenRouter authentication failed: {$msg}. Please check your API key at https://openrouter.ai/keys",
                    'provider' => 'openrouter',
                    'http_code' => $httpCode,
                ];
            }

            if ($httpCode === 429) {
                $msg = $body['error']['message'] ?? 'Rate limit exceeded';
                return [
                    'success' => false,
                    'error' => "OpenRouter rate limit reached: {$msg}. Please try again in a few minutes.",
                    'provider' => 'openrouter',
                    'http_code' => 429,
                    'can_retry' => true,
                ];
            }

            if ($httpCode !== 200) {
                $msg = $body['error']['message'] ?? "HTTP error {$httpCode}";
                return [
                    'success' => false,
                    'error' => "OpenRouter error: {$msg}",
                    'provider' => 'openrouter',
                    'http_code' => $httpCode,
                ];
            }

            // Success
            $content = $body['choices'][0]['message']['content'] ?? '';
            $analysis = $this->parseAnalysis($content);

            return [
                'success' => true,
                'analysis' => $analysis,
                'raw' => $content,
                'provider' => 'openrouter',
                'model' => $body['model'] ?? self::VISION_MODEL,
            ];
        } catch (\Throwable $e) {
            Log::error('VisionAnalysisService: exception', [
                'message' => $e->getMessage(),
                'attempt' => $attempt,
            ]);

            if ($attempt < self::MAX_RETRIES) {
                usleep(self::RETRY_DELAY_MS * 1000);
                return $this->callVisionApiWithRetry($messages, $attempt + 1);
            }

            return [
                'success' => false,
                'error' => 'Vision analysis request failed: ' . $e->getMessage(),
                'provider' => 'openrouter',
            ];
        }
    }

    /**
     * Compress an image file for vision API transmission.
     * Resizes to max 1024px width, converts to JPEG at 80% quality.
     */
    private function compressImageForVision(string $filePath): string
    {
        $fileSize = filesize($filePath);

        // Small files — skip compression, encode directly
        if ($fileSize < 20 * 1024) {
            $mime = mime_content_type($filePath);
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($filePath));
        }

        // 1024px — better quality for accurate UI analysis
        $maxDim = 1024;
        $img    = null;
        $ext    = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($ext === 'png') {
            $img = @imagecreatefrompng($filePath);
        } elseif (in_array($ext, ['jpg', 'jpeg'])) {
            $img = @imagecreatefromjpeg($filePath);
        } elseif ($ext === 'webp') {
            $img = @imagecreatefromwebp($filePath);
        } elseif ($ext === 'gif') {
            $img = @imagecreatefromgif($filePath);
        }

        if (!$img) {
            // Fallback: return as-is
            $mime = mime_content_type($filePath);
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($filePath));
        }

        $origW = imagesx($img);
        $origH = imagesy($img);

        // Only resize if larger than maxDim
        if ($origW > $maxDim || $origH > $maxDim) {
            $ratio = min($maxDim / $origW, $maxDim / $origH);
            $newW  = (int) round($origW * $ratio);
            $newH  = (int) round($origH * $ratio);
            $resized = imagecreatetruecolor($newW, $newH);
            imagecopy($resized, $img, 0, 0, 0, 0, $newW, $newH);
            if ($ext === 'png') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }
            imagedestroy($img);
            $img = $resized;
        }

        ob_start();
        imagejpeg($img, null, 80);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    /**
     * Analyze screenshot for component detection (layout, navigation, etc.)
     */
    public function detectComponents(string $imagePath): array
    {
        $fullPath = storage_path('app/public/' . $imagePath);
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => 'Screenshot file not found'];
        }

        $prompt = <<<'PROMPT'
Analyze this UI screenshot and detect all major components. Return a JSON object with:

{
  "layout": "dashboard|landing|form|table|login|register|profile|settings|ecommerce|blog|cms|other",
  "components": [
    {
      "type": "navbar|sidebar|header|footer|card|button|input|table|chart|form|banner|empty_state|modal|tabs|breadcrumb|pagination",
      "label": "what it is",
      "position": "top|bottom|left|right|center|full_width",
      "quality": "good|needs_improvement|critical",
      "issues": ["specific issue 1", "specific issue 2"]
    }
  ],
  "branding": {
    "has_logo": true,
    "logo_position": "left|center|right",
    "brand_colors": ["#hex1", "#hex2"],
    "brand_preserved": true
  },
  "navigation": {
    "type": "sidebar|navbar|tabs|breadcrumb|none",
    "items_count": 5,
    "labels_accurate": true
  },
  "summary": "2-3 sentence summary of what this UI does and its current state"
}

Be specific and detailed. Return ONLY the JSON object, no markdown.
PROMPT;

        $fullPath = $fullPath;
        $dataUri = $this->compressImageForVision($fullPath);

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                ],
            ],
        ];

        return $this->callVisionApiWithRetry($messages);
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    private function buildAnalysisPrompt(string $pageGoal, string $personaContext): string
    {
        return <<<PROMPT
You are an elite UI/UX review expert acting as a Senior UI Designer, UX Researcher, Accessibility Consultant, and Product Designer combined.

Page Goal: {$pageGoal}
Persona: {$personaContext}

Analyze this UI screenshot thoroughly and return a comprehensive JSON analysis:

{
  "scores": {
    "overall": 0-100,
    "visual_hierarchy": 0-100,
    "clarity": 0-100,
    "accessibility": 0-100,
    "consistency": 0-100
  },
  "summary": {
    "overall": "2-3 sentence overall assessment",
    "ui_issues": ["specific UI issue 1", "specific UI issue 2"],
    "ux_issues": ["specific UX issue 1"],
    "accessibility_issues": ["specific a11y issue 1"],
    "improvements": ["key improvement 1", "key improvement 2"]
  },
  "annotations": [
    {
      "number": 1,
      "type": "issue|improvement|praise",
      "severity": "critical|major|minor|info",
      "x": 0-100,
      "y": 0-100,
      "width": 10-50,
      "height": 5-20,
      "title": "Short descriptive title",
      "description": "Detailed explanation of what's wrong",
      "component_type": "navbar|card|button|input|table|form|footer|sidebar|header|banner|navigation",
      "suggested_fix": "Specific actionable fix",
      "expected_improvement": "What benefit user will get",
      "difficulty": "easy|medium|hard"
    }
  ],
  "suggestions": [
    {
      "category": "typography|color|spacing|content|accessibility|navigation|layout|iconography|contrast|responsive",
      "title": "Actionable suggestion title",
      "description": "Problem statement - what's wrong right now",
      "suggested_fix": "Implementation-ready fix - exactly what to do",
      "expected_improvement": "Specific UX improvement",
      "difficulty": "easy|medium|hard",
      "priority": "critical|high|medium|low"
    }
  ]
}

IMPORTANT RULES:
- Scores must be realistic (not inflated)
- Annotations: x/y are PERCENTAGE coordinates (0-100) from top-left corner
- Annotations: width/height are percentage sizes (5-50)
- NEVER write generic advice like "improve spacing" or "make it cleaner"
- Every suggestion must be implementation-ready with specific guidance
- Annotations should target the most impactful issues only (3-8 annotations)
- Suggestions should be categorized properly
- Return ONLY the JSON object, no markdown formatting
PROMPT;
    }

    private function getPersonaContext(string $persona): string
    {
        return match ($persona) {
            'first_time' => 'First-time user - needs onboarding, clear CTAs, obvious next steps, minimal cognitive load',
            'non_technical' => 'Non-technical user - avoid jargon, use plain language, intuitive navigation',
            'junior_dev' => 'Junior developer - clean code hints, standard patterns, good documentation labels',
            'devops' => 'DevOps engineer - technical clarity, efficiency, automation-friendly UI',
            'designer' => 'Product designer - design system consistency, visual hierarchy, pixel-perfect details',
            default => 'General user - balanced UX for most audiences',
        };
    }

    private function parseAnalysis(string $content): array
    {
        $json = $this->extractJson($content);

        // If extraction failed, try to salvage partial JSON
        if (!$json) {
            $partial = $this->salvagePartialJson($content);
            if ($partial) {
                $json = $partial;
            }
        }

        if (!$json) {
            // Last resort: use text-based extraction
            return $this->parseAnalysisFromText($content);
        }

        // Ensure required top-level keys exist
        return array_merge([
            'scores' => [
                'overall' => 50,
                'visual_hierarchy' => 50,
                'clarity' => 50,
                'accessibility' => 50,
                'consistency' => 50,
            ],
            'summary' => [
                'overall' => 'Analysis completed.',
                'ui_issues' => [],
                'ux_issues' => [],
                'accessibility_issues' => [],
                'improvements' => [],
            ],
            'annotations' => [],
            'suggestions' => [],
        ], $json);
    }

    /**
     * Extract JSON allowing for trailing garbage after valid JSON.
     */
    private function extractJson(string $content): ?array
    {
        // Direct parse attempt
        $json = json_decode($content, true);
        if ($json && is_array($json)) {
            return $json;
        }

        // Try markdown code blocks (with potential partial content after)
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*)/s', $content, $m)) {
            $candidate = $m[1];
            // Try to find the matching closing brace
            $depth = 0;
            for ($i = 0; $i < strlen($candidate); $i++) {
                if ($candidate[$i] === '{') $depth++;
                elseif ($candidate[$i] === '}') { $depth--; if ($depth === 0) { $candidate = substr($candidate, 0, $i + 1); break; } }
            }
            $json = json_decode($candidate, true);
            if (is_array($json)) return $json;
        }

        // Try outermost braces
        $start = strpos($content, '{');
        if ($start !== false) {
            // Try progressively shorter strings from end
            $end = strrpos($content, '}');
            if ($end !== false && $end > $start) {
                $candidate = substr($content, $start, $end - $start + 1);
                $json = json_decode($candidate, true);
                if (is_array($json)) return $json;

                // Try finding where valid JSON ends
                for ($i = $end; $i >= $start; $i--) {
                    $candidate = substr($content, $start, $i - $start + 1);
                    $json = json_decode($candidate, true);
                    if (is_array($json) && count($json) > 0) return $json;
                }
            }
        }

        return null;
    }

    /**
     * Try to salvage partial JSON by finding the last complete value before truncation.
     */
    private function salvagePartialJson(string $content): ?array
    {
        // Find opening brace
        $start = strpos($content, '{');
        if ($start === false) return null;

        // Try progressively shorter substrings to find valid JSON
        $end = strrpos($content, '}');
        if ($end === false || $end <= $start) return null;

        // Start from end and work backwards to find last complete parseable object
        for ($i = $end; $i >= $start; $i--) {
            $candidate = substr($content, $start, $i - $start + 1);
            $json = json_decode($candidate, true);
            if (is_array($json) && count($json) > 1) {
                return $json;
            }
        }

        return null;
    }

    /**
     * Fallback: extract meaningful data from raw text when JSON parsing fails.
     */
    private function parseAnalysisFromText(string $content): array
    {
        $scores = [
            'overall' => 50,
            'visual_hierarchy' => 50,
            'clarity' => 50,
            'accessibility' => 50,
            'consistency' => 50,
        ];

        // Try to find score patterns in text (e.g., "overall: 85" or "overall = 85")
        if (preg_match_all('/"(overall|visual_hierarchy|clarity|accessibility|consistency)"\s*:\s*(\d+)/i', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as [$key, $score]) {
                $key = strtolower($key);
                if (isset($scores[$key])) {
                    $scores[$key] = min(100, max(0, (int) $score));
                }
            }
        }

        // Extract summary as the longest sentence-like text
        $textContent = preg_replace('/[\[\]{}]/', ' ', $content);
        $textContent = preg_replace('/\s+/', ' ', $textContent);
        $summary = trim(substr($textContent, 0, 300)) ?: 'Analysis completed.';

        return [
            'scores' => $scores,
            'summary' => [
                'overall' => $summary,
                'ui_issues' => [],
                'ux_issues' => [],
                'accessibility_issues' => [],
                'improvements' => [],
            ],
            'annotations' => [],
            'suggestions' => [],
        ];
    }
}
