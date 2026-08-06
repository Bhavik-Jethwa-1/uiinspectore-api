<?php

namespace App\Services\AI\Inspector;

use App\Services\AI\MiniMaxService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class VisionAnalysisService
{
    private MiniMaxService $miniMax;

    public function __construct(MiniMaxService $miniMax)
    {
        $this->miniMax = $miniMax;
    }

    private const OPENROUTER_API_URL = 'https://openrouter.ai/api/v1/chat/completions';
    
    // Free vision model on OpenRouter
    private const VISION_MODEL = 'nvidia/nemotron-nano-12b-v2-vl:free';
    private const OPENROUTER_KEY_OK = 'sk-or-v2-'; // keys that look valid (not placeholder)

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

        try {
            // Convert image to base64
            $imageData = base64_encode(file_get_contents($fullPath));
            $mimeType = mime_content_type($fullPath);
            $dataUri = "data:{$mimeType};base64,{$imageData}";

            // Call OpenRouter vision API directly
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . self::openRouterKey(),
                'Content-Type' => 'application/json',
            ])->timeout(120)->post(self::OPENROUTER_API_URL, [
                'model' => self::VISION_MODEL,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                        ],
                    ],
                ],
                'max_tokens' => 4000,
                'temperature' => 0.2,
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                $httpCode = $response->status();
                $errorMsg = $error['error']['message'] ?? 'Unknown error';
                Log::warning('OpenRouter vision API error, falling back to MiniMax VL', [
                    'http_code' => $httpCode,
                    'error' => $errorMsg,
                ]);
                // Fall through to MiniMax VL fallback
                return $this->analyzeWithMiniMax($imagePath, $prompt);
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            $analysis = $this->parseAnalysis($content);

            return [
                'success' => true,
                'analysis' => $analysis,
                'raw' => $content,
            ];
        } catch (\Throwable $e) {
            Log::warning('Vision analysis exception, falling back to MiniMax VL', ['message' => $e->getMessage()]);
            return $this->analyzeWithMiniMax($imagePath, $prompt);
        }
    }

    /**
     * Fallback: analyze using MiniMax VL via OpenClaw gateway.
     */
    private function analyzeWithMiniMax(string $imagePath, string $prompt): array
    {
        try {
            $imageUrl = 'storage/' . ltrim($imagePath, '/');
            $result = $this->miniMax->vision($imageUrl, $prompt);

            if (!($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'MiniMax VL analysis failed',
                ];
            }

            $content = $result['choices'][0]['message']['content'] ?? '';
            $analysis = $this->parseAnalysis($content);

            return [
                'success' => true,
                'analysis' => $analysis,
                'raw' => $content,
                'provider' => 'minimax',
            ];
        } catch (\Throwable $e) {
            Log::error('MiniMax VL fallback also failed', ['message' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Vision analysis failed: ' . $e->getMessage(),
            ];
        }
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

        try {
            $imageData = base64_encode(file_get_contents($fullPath));
            $mimeType = mime_content_type($fullPath);
            $dataUri = "data:{$mimeType};base64,{$imageData}";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . self::openRouterKey(),
                'Content-Type' => 'application/json',
            ])->timeout(120)->post(self::OPENROUTER_API_URL, [
                'model' => self::VISION_MODEL,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                        ],
                    ],
                ],
                'max_tokens' => 3000,
                'temperature' => 0.2,
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                return [
                    'success' => false,
                    'error' => 'Component detection API error: ' . ($error['error']['message'] ?? 'Unknown'),
                ];
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            $json = $this->extractJson($content);

            return [
                'success' => true,
                'components' => $json,
                'raw' => $content,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Component detection failed: ' . $e->getMessage(),
            ];
        }
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
      "description": "Problem statement — what's wrong right now",
      "suggested_fix": "Implementation-ready fix — exactly what to do",
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
            'first_time' => 'First-time user — needs onboarding, clear CTAs, obvious next steps, minimal cognitive load',
            'non_technical' => 'Non-technical user — avoid jargon, use plain language, intuitive navigation',
            'junior_dev' => 'Junior developer — clean code hints, standard patterns, good documentation labels',
            'devops' => 'DevOps engineer — technical clarity, efficiency, automation-friendly UI',
            'designer' => 'Product designer — design system consistency, visual hierarchy, pixel-perfect details',
            default => 'General user — balanced UX for most audiences',
        };
    }

    private function parseAnalysis(string $content): array
    {
        $json = $this->extractJson($content);
        if (!$json) {
            return [
                'scores' => ['overall' => 50, 'visual_hierarchy' => 50, 'clarity' => 50, 'accessibility' => 50, 'consistency' => 50],
                'summary' => ['overall' => substr($content, 0, 300), 'ui_issues' => [], 'ux_issues' => [], 'accessibility_issues' => [], 'improvements' => []],
                'annotations' => [],
                'suggestions' => [],
            ];
        }
        return $json;
    }

    private function extractJson(string $content): ?array
    {
        $json = json_decode($content, true);
        if ($json && is_array($json)) {
            return $json;
        }

        // Try markdown code blocks
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $m)) {
            $json = json_decode($m[1], true);
            if (is_array($json)) {
                return $json;
            }
        }

        // Try outermost braces
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $json = json_decode(substr($content, $start, $end - $start + 1), true);
            if (is_array($json)) {
                return $json;
            }
        }

        return null;
    }
}
