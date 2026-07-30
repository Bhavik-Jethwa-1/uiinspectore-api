<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\AIService;
use App\Services\AI\AIServiceLocator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Premium Auto Designer Controller
 *
 * AI pipeline:
 *   1. Analyze requirements   (AI reads user input → structured brief)
 *   2. Optimize prompt        (user prompt → professional design prompt)
 *   3. Plan UI               (design prompt → structured layout plan)
 *   4. Generate images       (plan → N photorealistic UI images)
 *   5. Analyze design        (each image → scores + suggestions)
 *   6. Generate code          (design → React/Next.js/Tailwind)
 *
 * Image generation ONLY uses configured AI providers.
 * NEVER uses Google Images, Unsplash, or any web search.
 */
class AutoDesignerController extends Controller
{
    private AIService $ai;

    public function __construct()
    {
        $this->ai = AIServiceLocator::service();
    }

    // ─── Pipeline Steps ─────────────────────────────────────────────────────

    /**
     * Step 1: Analyze user requirements
     * POST /api/auto-designer/analyze
     */
    public function analyze(Request $request)
    {
        $data = $request->validate([
            'projectName'        => 'nullable|string|max:120',
            'description'        => 'nullable|string|max:2000',
            'appType'            => 'nullable|string|max:80',
            'targetUsers'        => 'nullable|string|max:200',
            'industry'           => 'nullable|string|max:80',
            'brandPersonality'   => 'nullable|string|max:200',
            'primaryColor'       => 'nullable|string|max:30',
            'secondaryColor'     => 'nullable|string|max:30',
            'theme'              => 'nullable|string|in:light,dark,auto',
            'designStyle'        => 'nullable|string|max:60',
            'screens'            => 'nullable|integer|min:1|max:10',
            'responsive'          => 'nullable|array',
            'complexity'         => 'nullable|string|in:simple,professional,enterprise',
        ]);

        $brief = $this->buildBrief($data);
        $chat  = $this->ai;

        $prompt = "You are an expert UX/UI requirements analyst. Analyze the following project brief and return a structured JSON object with these fields:

{
  \"projectType\": \"type of app (e.g. SaaS Dashboard, E-commerce, CRM)\",
  \"coreFeatures\": [\"top 6-8 core features\"],
  \"userJourney\": \"primary user flow in 1-2 sentences\",
  \"keyScreens\": [\"screen1\", \"screen2\", ...],
  \"designRequirements\": {
    \"mood\": \"e.g. Professional, Trustworthy, Modern\",
    \"referenceBrands\": [\"Figma\", \"Linear\", \"Stripe\"],
    \"colorEmotion\": \"what emotions the color palette evokes\",
    \"typographySuggestion\": \"font pairing recommendation\",
    \"spacingStyle\": \"e.g. Spacious, Dense, Balanced\",
    \"iconographyStyle\": \"e.g. Outlined, Filled, Custom\"
  },
  \"accessibilityNeeds\": [\"WCAG considerations\"],
  \"mobileFirst\": true|false,
  \"aiPromptHints\": \"keywords that would help generate a stunning UI\"
}

Project brief:
$brief

Return ONLY valid JSON, no markdown, no explanation.";

        try {
            $result = $this->ai->chat([['role' => 'user', 'content' => $prompt]], ['max_tokens' => 1500]);
            $reply  = $result['reply'] ?? '';

            // Extract JSON from response
            $json = $this->extractJson($reply);

            return response()->json([
                'success'    => true,
                'brief'      => $json,
                'rawReply'   => $reply,
            ]);
        } catch (\Throwable $e) {
            Log::error('AD_ANALYZE_FAILED', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error'   => 'Analysis failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Step 2: Optimize prompt
     * POST /api/auto-designer/optimize-prompt
     */
    public function optimizePrompt(Request $request)
    {
        $data = $request->validate([
            'userPrompt'     => 'required|string|min:3|max:2000',
            'designStyle'    => 'nullable|string|max:60',
            'theme'          => 'nullable|string|in:light,dark,auto',
            'primaryColor'   => 'nullable|string|max:30',
            'appType'        => 'nullable|string|max:80',
        ]);

        $chat = $this->ai;

        $style       = $data['designStyle']   ?? 'Modern SaaS';
        $theme       = $data['theme']          ?? 'dark';
        $primary     = $data['primaryColor']   ?? '#7c5cff';
        $appType     = $data['appType']        ?? 'Web Application';
        $userPrompt  = $data['userPrompt'];

        $prompt = "You are an expert UI/UX prompt engineer. Transform a user's simple description into a detailed, professional AI image generation prompt for creating a stunning UI mockup.

IMPORTANT RULES:
- NEVER mention any real brand names (Apple, Google, etc.)
- Focus on design SYSTEM attributes (spacing, typography scale, color theory, layout patterns)
- Include specific technical adjectives (glassmorphism, micro-animations, etc.)
- Describe the UI from a 10,000-foot view down to card-level details
- End with quality keywords: ultra-detailed, 4K, award-winning, professional, premium

Theme: $theme
Style: $style
Primary Color: $primary
App Type: $appType
User Input: $userPrompt

Return ONLY the optimized prompt string (2-3 sentences, STRICTLY max 1000 characters total), no JSON, no explanation.";

        try {
            $result = $this->ai->chat([['role' => 'user', 'content' => $prompt]], ['max_tokens' => 400, 'temperature' => 0.8]);
            $optimized = trim($result['reply'] ?? '');

            return response()->json([
                'success'    => true,
                'original'   => $userPrompt,
                'optimized'  => $optimized,
            ]);
        } catch (\Throwable $e) {
            Log::error('AD_OPTIMIZE_FAILED', ['error' => $e->getMessage()]);
            // Fallback: return a sensible default prompt
            return response()->json([
                'success'   => true,
                'original'   => $userPrompt,
                'optimized'  => "A premium $style $appType with a $theme interface, " . strtolower($primary) . " accent colors, clean modern layout, elegant typography, glassmorphism cards, professional SaaS design, ultra-detailed, 4K, award-winning UI quality.",
            ]);
        }
    }

    /**
     * Step 3: Generate UI variations
     * POST /api/auto-designer/generate
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'optimizedPrompt' => 'required|string|min:5|max:1200',
            'variations'      => 'nullable|integer|min:1|max:4',
            'size'            => 'nullable|string|in:1024x1024,1792x1024,1024x1792,768x1024,1024x768',
            'style'           => 'nullable|string|max:60',
            'theme'           => 'nullable|string|in:light,dark',
            'seed'            => 'nullable|integer',
        ]);

        $variations = (int) ($data['variations'] ?? 1);
        $size       = $data['size']   ?? '1792x1024';
        $seed       = $data['seed']   ?? null;
        $imgProvider = $this->ai;

        $results = [];
        $errors  = [];

        for ($i = 0; $i < $variations; $i++) {
            $variationSuffix = $variations > 1
                ? " Variation " . ($i + 1) . " of $variations — unique layout composition, different card arrangements."
                : "";

            $fullPrompt = $data['optimizedPrompt'] . $variationSuffix;

            try {
                $imgResult = $this->ai->image($fullPrompt, [
                    'size'    => $size,
                    'n'       => 1,
                    'timeout' => 180,
                ]);

                if (!$imgResult['success']) {
                    $errors[] = $imgResult['error'] ?? 'Generation failed';
                    continue;
                }

                $results[] = [
                    'id'       => uniqid('design_', true),
                    'prompt'   => $fullPrompt,
                    'imageUrl' => $imgResult['images'][0] ?? '',
                    'model'    => $imgResult['model']  ?? 'image-01',
                    'size'     => $size,
                    'seed'     => $seed,
                    'index'    => $i + 1,
                    'generatedAt' => now()->toISOString(),
                ];
            } catch (\Throwable $e) {
                Log::error('AD_IMAGE_GEN_FAILED', ['variation' => $i, 'error' => $e->getMessage()]);
                $errors[] = "Variation " . ($i + 1) . ": " . $e->getMessage();
            }
        }

        return response()->json([
            'success'    => !empty($results),
            'designs'    => $results,
            'errors'     => $errors,
            'total'      => count($results),
            'requested'  => $variations,
        ], empty($results) ? 502 : 200);
    }

    /**
     * Step 4: Analyze a generated design
     * POST /api/auto-designer/analyze-design
     */
    public function analyzeDesign(Request $request)
    {
        $data = $request->validate([
            'imageUrl'   => 'required|string',
            'prompt'     => 'nullable|string|max:1200',
        ]);

        $imageUrl = $this->resolveImageUrl($data['imageUrl']);
        $prompt   = $data['prompt'] ?? 'Analyze this UI design in detail';
        $vision   = $this->ai;

        $analysisPrompt = "You are an expert UI/UX critic and accessibility auditor. Analyze this AI-generated UI design thoroughly and return a detailed JSON response:

{
  \"overallScore\": (0-100, overall excellence),
  \"scores\": {
    \"visualDesign\": (0-100),
    \"ux\": (0-100),
    \"accessibility\": (0-100),
    \"typography\": (0-100),
    \"spacing\": (0-100),
    \"hierarchy\": (0-100),
    \"responsiveness\": (0-100),
    \"colorHarmony\": (0-100),
    \"modernDesign\": (0-100)
  },
  \"strengths\": [\"strength 1\", \"strength 2\", ...],
  \"criticalIssues\": [\"issue 1 with specific location\", \"issue 2\", ...],
  \"improvementSuggestions\": [
    {
      \"area\": \"e.g. Typography\",
      \"suggestion\": \"specific actionable improvement\",
      \"priority\": \"high|medium|low\"
    }
  ],
  \"accessibilityNotes\": [\"WCAG issues found\"],
  \"moodAndTone\": \"professional/playful/etc\",
  \"targetAudienceFit\": \"who this design best serves\"
}

Be rigorous and specific. Identify actual problems with pixel-level locations (e.g. 'sidebar text is too small at 11px for WCAG AA').";

        try {
            $result = $this->ai->vision($imageUrl, $analysisPrompt, ['max_tokens' => 2000]);
            $reply  = $result['reply'] ?? '';
            $json   = $this->extractJson($reply);

            return response()->json([
                'success'  => true,
                'analysis' => $json,
                'rawReply' => $reply,
            ]);
        } catch (\Throwable $e) {
            Log::error('AD_ANALYZE_DESIGN_FAILED', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error'   => 'Analysis failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Step 5: Redesign — improve a design
     * POST /api/auto-designer/redesign
     */
    public function redesign(Request $request)
    {
        $data = $request->validate([
            'imageUrl'        => 'required|string',
            'improvementFocus' => 'nullable|string|max:300',
            'size'            => 'nullable|string',
            'theme'           => 'nullable|string|in:light,dark',
        ]);

        $vision    = $this->ai;
        $imgProv   = $this->ai;
        $focus     = $data['improvementFocus'] ?? 'Improve overall quality, spacing, typography, and modern design principles';
        $size      = $data['size'] ?? '1792x1024';
        $imageUrl  = $this->resolveImageUrl($data['imageUrl']);

        // Analyze the original to understand what to improve
        $analysisPrompt = "Briefly describe the key structural elements of this UI: layout type (sidebar, topbar, grid, etc.), color scheme, card style, and overall composition. Be concise — 2-3 sentences max.";

        try {
            $analysis = $this->ai->vision($imageUrl, $analysisPrompt, ['max_tokens' => 500]);
            $analysisText = $analysis['reply'] ?? '';

            $redesignPrompt = "You are a world-class UI designer. Redesign the following UI with these improvements: $focus.

Original design description: $analysisText

Create a completely new, improved version that:
- Addresses all the improvement areas
- Uses better spacing, hierarchy, and visual balance
- Maintains the same functional purpose but elevates the design quality
- Is original and creative — NOT a copy of the original

Generate an ultra-detailed prompt for AI image generation that would produce this improved design. Return ONLY the prompt string (max 400 chars), no explanation.";

            $chatResult = $this->ai->chat([["role" => "user", "content" => $redesignPrompt]], ["max_tokens" => 400]);
            $improvedPrompt = trim($chatResult['reply'] ?? '');

            // Generate the improved design
            $imgResult = $this->ai->image($improvedPrompt, [
                'size'    => $size,
                'n'       => 1,
                'timeout' => 180,
            ]);

            if (!$imgResult['success']) {
                return response()->json([
                    'success' => false,
                    'error'   => $imgResult['error'] ?? 'Redesign generation failed',
                ], 502);
            }

            return response()->json([
                'success'       => true,
                'originalUrl'   => $imageUrl,
                'improvedPrompt'=> $improvedPrompt,
                'improvedUrl'   => $imgResult['images'][0] ?? '',
                'model'         => $imgResult['model'] ?? 'image-01',
                'size'          => $size,
            ]);
        } catch (\Throwable $e) {
            Log::error('AD_REDESIGN_FAILED', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error'   => 'Redesign failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Step 6: Generate code from design
     * POST /api/auto-designer/generate-code
     */
    public function generateCode(Request $request)
    {
        $data = $request->validate([
            'imageUrl'    => 'nullable|string',
            'prompt'      => 'nullable|string|max:1200',
            'framework'   => 'required|string|in:react,nextjs,vue,html,tailwind,shadcn',
            'designNotes' => 'nullable|string|max:500',
        ]);

        $chat      = $this->ai;
        $imageUrl  = ($data['imageUrl'] ?? '') ? $this->resolveImageUrl($data['imageUrl']) : '';
        $framework = $data['framework'];
        $notes     = $data['designNotes'] ?? '';
        $textPrompt = $data['prompt'] ?? '';

        try {
            // Build proper message(s) — with image if URL provided
            if ($imageUrl) {
                // Vision: send image as actual content block + text prompt
                $codePrompt = $this->buildCodePrompt($framework, $textPrompt, $notes);
                $messages = [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text',     'text' => $codePrompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                    ],
                ]];
                $result = $chat($messages, ['max_tokens' => 4000, 'temperature' => 0.5]);
            } else {
                // Text-only
                $codePrompt = $this->buildCodePrompt($framework, $textPrompt, $notes);
                $result = $this->ai->chat([['role' => 'user', 'content' => $codePrompt]], ['max_tokens' => 4000, 'temperature' => 0.5]);
            }

            $code = $result['reply'] ?? '';

            // Extract code blocks
            $code = $this->extractCode($code, $framework);

            return response()->json([
                'success'   => true,
                'code'      => $code,
                'framework' => $framework,
                'lines'     => substr_count($code, "\n") + 1,
            ]);
        } catch (\Throwable $e) {
            Log::error('AD_CODE_GEN_FAILED', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error'   => 'Code generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─── Design History ─────────────────────────────────────────────────────

    /**
     * Save design to history
     * POST /api/auto-designer/history/save
     */
    public function saveToHistory(Request $request)
    {
        $data = $request->validate([
            'id'          => 'required|string',
            'prompt'      => 'nullable|string',
            'imageUrl'    => 'required|string',
            'style'       => 'nullable|string',
            'theme'       => 'nullable|string',
            'analysis'    => 'nullable|array',
            'isFavorite'  => 'nullable|boolean',
        ]);

        $userId  = $this->getUserId($request);
        $history = $this->getHistory($userId);

        $entry = array_merge($data, [
            'savedAt'  => now()->toISOString(),
            'userId'   => $userId,
        ]);

        // Replace if same id exists
        $history = array_filter($history, fn($e) => ($e['id'] ?? '') !== $data['id']);
        array_unshift($history, $entry);
        $history = array_slice($history, 0, 100); // keep last 100

        Storage::put("auto_designer/history_{$userId}.json", json_encode($history));

        return response()->json(['success' => true, 'total' => count($history)]);
    }

    /**
     * Load design history
     * GET /api/auto-designer/history
     */
    public function loadHistory(Request $request)
    {
        $userId   = $this->getUserId($request);
        $history  = $this->getHistory($userId);
        $favorites = array_filter($history, fn($e) => !empty($e['isFavorite']));

        return response()->json([
            'success'   => true,
            'history'   => $history,
            'favorites' => $favorites,
            'total'     => count($history),
        ]);
    }

    /**
     * Delete from history
     * DELETE /api/auto-designer/history/{id}
     */
    public function deleteFromHistory(Request $request, string $id)
    {
        $userId  = $this->getUserId($request);
        $history = $this->getHistory($userId);
        $history = array_filter($history, fn($e) => ($e['id'] ?? '') !== $id);
        Storage::put("auto_designer/history_{$userId}.json", json_encode(array_values($history)));

        return response()->json(['success' => true]);
    }

    /**
     * Toggle favorite
     * POST /api/auto-designer/history/{id}/favorite
     */
    public function toggleFavorite(Request $request, string $id)
    {
        $userId  = $this->getUserId($request);
        $history = $this->getHistory($userId);

        foreach ($history as &$entry) {
            if (($entry['id'] ?? '') === $id) {
                $entry['isFavorite'] = !($entry['isFavorite'] ?? false);
                break;
            }
        }

        Storage::put("auto_designer/history_{$userId}.json", json_encode($history));

        return response()->json(['success' => true]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function buildBrief(array $data): string
    {
        $parts = [];
        foreach ($data as $key => $val) {
            if ($val && !in_array($key, ['responsive'])) {
                $parts[] = "$key: $val";
            }
        }
        return implode("\n", $parts);
    }

    private function buildCodePrompt(string $framework, string $prompt, string $notes): string
    {
        $frameworkContext = match ($framework) {
            'react'    => "React 18 with hooks. Use functional components. Import from 'react'. Use inline styles or Tailwind CSS classes.",
            'nextjs'   => "Next.js 14 App Router. Use 'use client' for interactivity. Use Tailwind CSS. Include proper metadata exports.",
            'vue'      => "Vue 3 Composition API. <script setup>. Use Tailwind CSS classes.",
            'tailwind'  => "Pure HTML with Tailwind CSS CDN. Responsive classes. Modern utility-first approach.",
            'shadcn'    => "React with shadcn/ui components. Tailwind CSS. Lucide React icons. Radix UI primitives.",
            'html'     => "Clean semantic HTML5. CSS3 with CSS custom properties for theming.",
            default     => "React with Tailwind CSS.",
        };

        $desc = $prompt ? "The design should be: $prompt" : "Generate a modern, clean UI based on the provided image.";

        return "You are an expert frontend developer. Generate production-ready, clean $framework code for the following UI design.

Requirements:
- $frameworkContext
- Modern, clean code with proper structure
- $desc
- Additional notes: $notes
- Include both desktop and mobile responsive views
- Use realistic placeholder content (not lorem ipsum)
- Color palette: Use purple (#7c5cff) as primary accent, dark theme (#0d0d14, #15151f for backgrounds)
- Accessibility: proper ARIA labels, semantic HTML, focus states
- Output: ALL code in ONE code block with filename as first line comment (e.g. // App.jsx)

Return ONLY the complete code in a single fenced code block.";
    }

    private function extractJson(string $text): ?array
    {
        // Try to find JSON in markdown code blocks first
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) {
            $decoded = json_decode($m[1], true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }
        // Try raw JSON
        $decoded = json_decode(trim($text), true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        // Return raw text as fallback
        return ['raw' => $text];
    }

    /** Resolve a local storage path to an absolute URL for AI vision services */
    private function resolveImageUrl(string $url): string
    {
        if (str_starts_with($url, '/')) {
            // Prepend the app's base URL so AI vision services can access local files
            return rtrim(config('app.url'), '/') . $url;
        }
        return $url;
    }

    private function extractCode(string $text, string $framework): string
    {
        $ext = match ($framework) {
            'react', 'nextjs', 'vue'  => 'jsx',
            'shadcn'                   => 'jsx',
            'tailwind'                 => 'html',
            'html'                     => 'html',
            default                    => 'txt',
        };

        if (preg_match('/```(?:\w+)?\s*(.*?)\s*```/s', $text, $m)) {
            return trim($m[1]);
        }

        return trim($text);
    }

    private function getUserId(Request $request): string
    {
        // Use IP-based anonymous ID if not authenticated
        return $request->user()?->id
            ?? 'anon_' . md5($request->ip() ?? 'unknown');
    }

    private function getHistory(string $userId): array
    {
        $path = "auto_designer/history_{$userId}.json";
        if (!Storage::exists($path)) return [];

        $content = Storage::get($path);
        return json_decode($content, true) ?? [];
    }
}
