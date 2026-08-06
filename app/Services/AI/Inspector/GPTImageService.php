<?php

namespace App\Services\AI\Inspector;

/**
 * GPTImageService — true image editing via OpenAI's GPT Image API.
 *
 * IMPORTANT: Requires a valid OpenAI API key (sk-... format), NOT a Google API key.
 * Use isAvailable() to check before attempting generation.
 */
class GPTImageService
{
    private const BASE_URL = 'https://api.openai.com/v1';
    private const VISION_MODEL = 'gpt-4o';
    private const IMAGE_MODEL = 'gpt-image-1';
    private string $apiKey;
    private string $groqApiKey;
    private string $groqVisionModel = 'llama-3.2-11b-vision-preview';

    public function __construct()
    {
        $this->apiKey = env('OPENAI_API_KEY', '');
        $this->groqApiKey = env('GROQ_API_KEY', '');
    }

    /**
     * Check if GPT Image is available with the current API key.
     * Returns an array with 'ok', 'reason', and 'key_format' fields.
     */
    public function availability(): array
    {
        if (empty($this->apiKey)) {
            return [
                'ok' => false,
                'reason' => 'No OpenAI API key set in .env (OPENAI_API_KEY)',
                'key_format' => 'missing',
                'hint' => 'Add your OpenAI API key to .env as OPENAI_API_KEY=sk-...',
            ];
        }

        if (!str_starts_with($this->apiKey, 'sk-')) {
            return [
                'ok' => false,
                'reason' => 'OPENAI_API_KEY does not appear to be a valid OpenAI key (should start with sk-)',
                'key_format' => 'invalid_format',
                'hint' => 'The key starts with "' . substr($this->apiKey, 0, 4) . '..." — this looks like a Google API key. Get a real OpenAI key at https://platform.openai.com/api-keys',
            ];
        }

        // Probe the actual image endpoint — /v1/models returns 200 even when billing is exhausted,
        // so we must test the image endpoint directly to know if generation will actually work.
        $probe = $this->probeImageEndpoint();
        if ($probe !== true) {
            return [
                'ok' => false,
                'reason' => $probe,
                'key_format' => 'billing_exhausted',
                'hint' => 'Add payment method at https://platform.openai.com/settings/billing — $5 free credit is exhausted.',
            ];
        }

        $fallback = !empty($this->groqApiKey) ? 'groq (free, unlimited — auto-fallback for Vision)' : null;

        return [
            'ok' => true,
            'reason' => 'GPT Image (gpt-image-1) is ready',
            'key_format' => 'valid',
            'fallback' => $fallback,
        ];
    }

    /**
     * Probe the image edits endpoint with a tiny 1x1 PNG to check quota.
     * Returns true if the endpoint is reachable (even if image is invalid),
     * or a reason string if there's a billing/quota issue.
     */
    private function probeImageEndpoint(): bool|string
    {
        // Tiny valid 1x1 transparent PNG (26 bytes)
        $pixelPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+P+/HgAFhAJ/wlseKgAAAABJRU5ErkJggg==');

        $tempFile = tempnam(sys_get_temp_dir(), 'gpt_probe_');
        file_put_contents($tempFile, $pixelPng);

        $ch = curl_init(self::BASE_URL . '/images/edits');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => [
                'image'   => new \CURLFile($tempFile, 'image/png', 'pixel.png'),
                'prompt'  => 'test',
                'model'   => 'gpt-image-1',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        unlink($tempFile);

        if ($httpCode === 200) {
            return true; // endpoint works
        }

        $data = json_decode($resp, true);
        $code = $data['error']['code'] ?? '';
        $msg  = $data['error']['message'] ?? '';

        if ($code === 'billing_hard_limit_reached' || str_contains($msg, 'hard limit')) {
            return 'OpenAI billing hard limit reached — add payment method at https://platform.openai.com/settings/billing to continue.';
        }
        if ($code === 'insufficient_quota' || str_contains($msg, 'quota')) {
            return 'OpenAI API quota exhausted — add billing or use a different key.';
        }
        if ($code === 'billing_not_active' || str_contains($msg, 'billing')) {
            return 'OpenAI billing not active — add payment method at https://platform.openai.com/settings/billing.';
        }

        // For other errors (400 invalid request etc.), the endpoint is reachable
        if ($httpCode >= 400 && $httpCode < 500) {
            return true; // key is valid, endpoint works — the error is from invalid input
        }

        return "Image API returned HTTP $httpCode: $msg";
    }

    public function isAvailable(): bool
    {
        return $this->availability()['ok'] === true;
    }

    /**
     * Analyze a screenshot using GPT Vision.
     * Falls back to Groq (free) if OpenAI quota is exceeded.
     */
    public function analyzeScreenshot(string $imagePath): array
    {
        $fullPath = storage_path('app/public/' . ltrim($imagePath, '/'));
        if (!file_exists($fullPath)) {
            return ['error' => 'Screenshot file not found: ' . $fullPath];
        }

        $imageData = file_get_contents($fullPath);
        if (!$imageData) {
            return ['error' => 'Could not read screenshot file'];
        }

        $base64 = base64_encode($imageData);
        $mime = mime_content_type($fullPath) ?: 'image/png';

        $prompt = <<<'PROMPT'
You are an expert UI/UX designer analyzing a web application screenshot.

Analyze this UI and return JSON with these exact keys:
{
  "layout": "string — describe overall layout (header, sidebar, main content, grid structure)",
  "components": ["list of all visible UI components — nav items, cards, tables, forms, buttons, icons, badges"],
  "issues": ["specific visual design problems — contrast, spacing, alignment, typography hierarchy, dated styling"],
  "typography_notes": "font sizes, weights, hierarchy, readability notes",
  "color_notes": "color palette, contrast issues, accessibility problems",
  "accessibility_notes": "obvious accessibility problems — small text, low contrast, missing labels",
  "must_preserve": ["EXACT list of what must NOT change: sidebar position, header content, logo location, card/table positions, button positions, ALL text labels and content, any data/numbers"],
  "improvement_focus": ["specific visual improvements to apply: typography, colors, shadows, spacing, polish"]
}

Be specific and thorough — your analysis guides a precise image editing operation.
PROMPT;

        // Try OpenAI first
        $result = $this->analyzeWithOpenAI($base64, $mime, $prompt);
        if ($result !== null) {
            return $result;
        }

        // Quota exceeded — try Groq as free fallback
        if (!empty($this->groqApiKey)) {
            $result = $this->analyzeWithGroq($base64, $mime, $prompt);
            if ($result !== null) {
                return $result;
            }
        }

        // Both failed
        return [
            'error' => 'Vision analysis failed on both OpenAI and Groq. OpenAI quota may be exhausted. Add billing at https://platform.openai.com or use the free Groq fallback.',
            'error_code' => 'QUOTA_EXHAUSTED',
        ];
    }

    private function analyzeWithOpenAI(string $base64, string $mime, string $prompt): ?array
    {
        $body = [
            'model' => self::VISION_MODEL,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$base64}"]],
                    ],
                ],
            ],
            'max_tokens' => 2000,
            'temperature' => 0.3,
        ];

        $ch = curl_init(self::BASE_URL . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['error' => 'OpenAI Vision connection error: ' . $curlErr];
        }

        if ($httpCode === 429 || $httpCode === 403) {
            // Quota exceeded or forbidden — return null to trigger fallback
            return null;
        }

        if ($httpCode !== 200) {
            $body = json_decode($resp, true);
            $msg = $body['error']['message'] ?? "HTTP $httpCode";
            // Check for quota error
            if (stripos($msg, 'quota') !== false || stripos($msg, 'exceeded') !== false) {
                return null; // trigger fallback
            }
            return ['error' => "OpenAI Vision error: $msg", 'status' => $httpCode];
        }

        $data = json_decode($resp, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        $analysis = $this->parseJsonResponse($content);
        $analysis['raw'] = $content;
        $analysis['model_used'] = $data['model'] ?? self::VISION_MODEL;
        $analysis['provider'] = 'openai';

        return $analysis;
    }

    private function analyzeWithGroq(string $base64, string $mime, string $prompt): ?array
    {
        // Groq keys start with 'sk-' (same as OpenAI), not 'gsk_'
        if (empty($this->groqApiKey) || !str_starts_with($this->groqApiKey, 'sk-')) {
            return [
                'error' => 'Groq Vision fallback skipped: GROQ_API_KEY is not set or is not a valid Groq API key (Groq keys start with sk-). Add a valid Groq key to .env to enable free Vision fallback.',
                'provider' => 'groq',
            ];
        }

        $body = [
            'model' => $this->groqVisionModel,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$base64}"]],
                    ],
                ],
            ],
            'max_tokens' => 2000,
            'temperature' => 0.3,
        ];

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->groqApiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['error' => 'Groq Vision connection error: ' . $curlErr];
        }

        if ($httpCode !== 200) {
            $body = json_decode($resp, true);
            $msg = $body['error']['message'] ?? "HTTP $httpCode";
            return ['error' => "Groq Vision error: $msg", 'status' => $httpCode, 'provider' => 'groq'];
        }

        $data = json_decode($resp, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        $analysis = $this->parseJsonResponse($content);
        $analysis['raw'] = $content;
        $analysis['model_used'] = $data['model'] ?? $this->groqVisionModel;
        $analysis['provider'] = 'groq';

        return $analysis;
    }

    /**
     * Generate an improved version of the screenshot using GPT Image editing.
     * The original image is always used as the reference — layout is preserved.
     *
     * @param string $imagePath Path to the original screenshot (in storage/app/public/)
     * @param array $analysis  GPT Vision analysis results
     * @param string $designStyle e.g. 'modern_saas', 'minimal', 'glassmorphism'
     * @return array ['success' => bool, 'image_data' => string (binary), 'improvements' => array]
     */
    public function editImage(string $imagePath, array $analysis, string $designStyle = 'modern_saas'): array
    {
        $fullPath = storage_path('app/public/' . ltrim($imagePath, '/'));
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => 'Screenshot file not found'];
        }

        $imageData = file_get_contents($fullPath);
        if (!$imageData) {
            return ['success' => false, 'error' => 'Could not read screenshot file'];
        }

        $base64 = rtrim(strtr(base64_encode($imageData), '+/', '-_'), '=');
        $mime = mime_content_type($fullPath) ?: 'image/png';

        $prompt = $this->buildEditPrompt($analysis, $designStyle);

        // GPT Image edit request
        $ch = curl_init(self::BASE_URL . '/images/edits');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => self::IMAGE_MODEL,
                'image' => "data:{$mime};base64,{$base64}",
                'prompt' => $prompt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['success' => false, 'error' => 'GPT Image connection error: ' . $curlErr];
        }

        if ($httpCode !== 200) {
            $body = json_decode($resp, true);
            $msg = $body['error']['message'] ?? $body['error']['type'] ?? "HTTP $httpCode";
            $code = $body['error']['code'] ?? null;

            // Specific error handling
            if ($code === 'invalid_api_key' || str_contains($msg, 'Incorrect API key')) {
                return [
                    'success' => false,
                    'error' => 'OpenAI API key is invalid or rejected',
                    'error_code' => 'INVALID_KEY',
                    'hint' => 'Your OpenAI API key appears to be invalid. Please check it at https://platform.openai.com/api-keys',
                ];
            }

            return [
                'success' => false,
                'error' => "GPT Image error: $msg",
                'status' => $httpCode,
                'error_code' => $code,
            ];
        }

        $data = json_decode($resp, true);
        $imageUrl = $data['data'][0]['url'] ?? null;
        $revisedPrompt = $data['data'][0]['revised_prompt'] ?? null;

        if (!$imageUrl) {
            return ['success' => false, 'error' => 'No image URL returned by GPT Image'];
        }

        // Download the generated image
        $imgCh = curl_init($imageUrl);
        $imgFh = fopen('php://temp', 'w+');
        curl_setopt_array($imgCh, [
            CURLOPT_FILE => $imgFh,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_exec($imgCh);
        curl_close($imgCh);
        rewind($imgFh);
        $resultImageData = stream_get_contents($imgFh);
        fclose($imgFh);

        if (!$resultImageData || strlen($resultImageData) < 1024) {
            return ['success' => false, 'error' => 'Generated image is empty or too small'];
        }

        return [
            'success' => true,
            'image_data' => $resultImageData,
            'revised_prompt' => $revisedPrompt,
            'model' => self::MODEL,
            'improvements' => $this->detectImprovements($analysis, $designStyle),
        ];
    }

    /**
     * Build the edit prompt from vision analysis + design style.
     */
    private function buildEditPrompt(array $analysis, string $designStyle): string
    {
        $mustPreserve = is_array($analysis['must_preserve'] ?? null)
            ? implode(', ', $analysis['must_preserve'])
            : 'layout, navigation, sidebar, header, all component positions, all text content';

        $styleGuidance = match ($designStyle) {
            'modern_saas' => <<<'STYLE'
Apply a MODERN SAAS DESIGN treatment:
- Clean, spacious layout with generous padding and margins
- Refined typography: clear hierarchy with 2-3 font weights, readable sizes
- Subtle shadows (0 2px 8px rgba(0,0,0,0.08) to 0 8px 32px rgba(0,0,0,0.12))
- Rounded corners (8px-16px radius) on cards and buttons
- Color palette: professional blues and purples with neutral grays; improve contrast
- Clean borders (1px solid rgba(0,0,0,0.06)) instead of heavy dividers
- Improve button and card visual hierarchy
STYLE,
            'minimal' => <<<'STYLE'
Apply a MINIMAL DESIGN treatment:
- Maximum whitespace — open up spacing throughout
- Ultra-clean sans-serif typography with refined weights
- Subtle 1px borders instead of shadows
- Light neutral palette: pure whites, warm grays, near-black text
- Understated elegance: restrained but intentional
- Generous padding on all interactive elements
STYLE,
            'glassmorphism' => <<<'STYLE'
Apply a GLASSMORPHISM treatment:
- Frosted glass panels with backdrop-blur effects
- Translucent white/gray card backgrounds (rgba(255,255,255,0.1-0.2))
- Subtle white border (1px solid rgba(255,255,255,0.2))
- Vibrant gradient accent colors (purple-to-blue or pink-to-orange)
- Floating card effect with layered blur shadows
- Modern and futuristic aesthetic
STYLE,
            'enterprise' => <<<'STYLE'
Apply an ENTERPRISE DESIGN treatment:
- Structured, data-dense but organized layout
- Strong grid alignment throughout
- Professional business aesthetic with clean lines
- Subdued color palette with clear accent colors
- Clear visual hierarchy for complex information
- Structured tables, forms, and data display
STYLE,
            'dark' => <<<'STYLE'
Apply a PREMIUM DARK THEME treatment:
- Deep charcoal and near-black backgrounds (#0f0f14, #1a1a24)
- Elevated surfaces with subtle lighter grays (#252530, #2a2a38)
- Vibrant accent highlights (electric blue, teal, or purple)
- Reduced eye strain with careful contrast ratios
- Subtle glow effects on interactive elements
- Modern dark aesthetic with depth
STYLE,
            default => 'Improve the overall visual design: better typography, spacing, colors, and polish.',
        };

        $issues = is_array($analysis['issues'] ?? null)
            ? implode('; ', $analysis['issues'])
            : 'general visual design improvements';

        return <<<PROMPT
CRITICAL EDITING INSTRUCTIONS — THIS IS AN IMAGE EDIT, NOT A NEW IMAGE GENERATION:

1. PRESERVE EXACTLY — Do NOT move, add, or remove ANY UI elements. Keep ALL of the following exactly where they are:
   - Sidebar/navigation structure and all items
   - Header with logo, search, and actions
   - All cards, tables, forms, buttons, icons
   - All text labels, numbers, content
   - All component positions, sizes, and proportions
   - Branding and logo

2. IMPROVE ONLY — Apply these visual enhancements while keeping the layout identical:

{$styleGuidance}

3. DESIGN ISSUES TO ADDRESS:
{$issues}

4. MUST PRESERVE:
{$mustPreserve}

Output: The SAME dashboard/UI as the original, but with improved visual design applied.
PROMPT;
    }

    /**
     * Detect what was improved based on the vision analysis.
     */
    private function detectImprovements(array $analysis, string $designStyle): array
    {
        $improved = [];
        $preserved = [];

        // Always preserved
        $preserved[] = 'Layout — all component positions preserved exactly';
        $preserved[] = 'Navigation — sidebar and header at original positions';
        $preserved[] = 'Content — all text, labels, and data maintained';
        $preserved[] = 'Branding — logo and marks at original positions';

        // Style-specific improvements
        $improved[] = match ($designStyle) {
            'modern_saas' => 'Modern SaaS styling — refined shadows, rounded corners, clean card hierarchy',
            'minimal' => 'Minimal design — maximum whitespace, ultra-clean typography',
            'glassmorphism' => 'Glassmorphism — frosted glass panels with backdrop blur effects',
            'enterprise' => 'Enterprise polish — structured grid alignment, professional aesthetic',
            'dark' => 'Premium dark theme — deep charcoal tones with vibrant accent highlights',
            default => 'Visual design — improved typography, spacing, and color harmony',
        };

        $improved[] = 'Typography — refined font weights, sizes, and hierarchy';
        $improved[] = 'Color palette — improved contrast ratios and accessibility';
        $improved[] = 'Spacing — optimized padding and margins throughout';
        $improved[] = 'Cards and buttons — refined shadows, borders, and visual depth';

        return [
            'improved' => $improved,
            'regressed' => [],
            'unchanged' => $preserved,
        ];
    }

    /**
     * Try to parse JSON from a response that may have markdown fences.
     */
    private function parseJsonResponse(string $content): array
    {
        // Strip markdown fences
        $content = trim($content);
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/^```\s*$/', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Fallback: return raw content in a structured wrapper
        return [
            'layout' => '[Could not parse GPT Vision response]',
            'components' => [],
            'issues' => [],
            'typography_notes' => '',
            'color_notes' => '',
            'accessibility_notes' => '',
            'must_preserve' => [],
            'improvement_focus' => [],
            'raw' => $content,
            'parse_error' => json_last_error_msg(),
        ];
    }
}
