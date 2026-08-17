<?php

namespace App\Services;

use App\Exceptions\AIResponseException;
use App\Models\Review;
use App\Models\ReviewAnnotation;
use App\Models\ReviewIssue;
use App\Models\ReviewScore;
use App\Models\ReviewSuggestion;
use App\Models\Screenshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AIReviewService
{
    private string $provider;
    private string $model;

    /** @var array Default fallback chain for vision-capable providers */
    private const FALLBACK_CHAIN = [
        // gemini-3.5-flash: primary working provider with vision + large free tier
        ['provider' => 'gemini',  'retries' => 1],
        // Groq has NO vision models (all decommissioned) — skip by default
        // ['provider' => 'groq', 'retries' => 1],
        // xAI has no credits on this account — skip by default
        // ['provider' => 'xai', 'retries' => 1],
        // OpenAI: available but no credits (keep commented unless credits added)
        // ['provider' => 'openai', 'retries' => 1],
    ];

    /**
     * Override the fallback chain via AI_FALLBACK_PROVIDERS env var.
     * Format: comma-separated provider names, e.g. "gemini,openai,xai"
     * Providers without valid API keys or vision support will be skipped at runtime.
     */

    public function __construct()
    {
        $this->provider = config('ai.provider', 'gemini');
        $this->model    = $this->getModelForProvider($this->provider);
    }

    /**
     * Get the configured model name for a given provider.
     */
    private function getModelForProvider(string $provider): string
    {
        return match ($provider) {
            'openai'    => config('ai.openai.model',    'gpt-4o'),
            'anthropic' => config('ai.anthropic.model', 'claude-3-5-sonnet-20241022'),
            'ollama'    => config('ai.ollama.model',    'llava'),
            'cloudflare'=> config('ai.cloudflare.model','@cf/meta/llama-3.2-11b-vision-instruct'),
            'groq'      => config('ai.groq.model',      'llama-3.2-11b-vision-preview'),
            'gemini'    => config('ai.gemini.model',    'gemini-2.0-flash'),
            'xai'       => config('ai.xai.model',       'grok-2-vision-1212'),
            default     => 'gpt-4o',
        };
    }

    public function analyze(Review $review, Screenshot $screenshot): array
    {
        $persona  = $this->formatPersona($review->persona);
        $pageGoal = $review->page_goal;

        // Read the screenshot file and encode as base64
        $imagePath  = Storage::disk('local')->path($screenshot->path);
        $imageBase64 = base64_encode(file_get_contents($imagePath));
        $mimeType   = $screenshot->mime_type;

        $prompt = $this->buildPrompt($persona, $pageGoal);

        // Try primary provider first, then fall back through the chain
        $response = $this->callAIWithFallback($prompt, $imageBase64, $mimeType);

        return $this->parseAndValidateResponse($response);
    }

    /**
     * Attempt AI call with automatic fallback across configured providers.
     * Tries primary provider, then each fallback provider with its retry count.
     */
    private function callAIWithFallback(string $prompt, string $imageBase64, string $mimeType): string
    {
        $attempted = [];

        // Build ordered chain: primary first, then configured fallbacks
        $chain = $this->buildFallbackChain();

        foreach ($chain as $attempt) {
            $provider = $attempt['provider'];
            $retries  = $attempt['retries'];
            $model    = $this->getModelForProvider($provider);

            for ($i = 0; $i <= $retries; $i++) {
                try {
                    $response = $this->callAI($prompt, $imageBase64, $mimeType, $provider, $model);
                    Log::info("AI call succeeded", ['provider' => $provider, 'model' => $model]);
                    return $response;
                } catch (AIResponseException $e) {
                    // Auth errors are fatal — never retry or fallback
                    if ($e->isAuthError()) {
                        Log::error("AI auth error (not retrying or falling back)", [
                            'provider' => $provider,
                            'status'   => $e->statusCode,
                        ]);
                        // Re-throw auth errors immediately so user sees the error
                        throw $e;
                    }

                    $attemptKey = "{$provider}:{$i}";
                    $already = in_array($attemptKey, $attempted);
                    $attempted[] = $attemptKey;

                    Log::warning("AI call attempt failed, " . ($i < $retries ? "retrying" : "falling back"), [
                        'provider'  => $provider,
                        'model'    => $model,
                        'attempt'  => $i + 1,
                        'max'      => $retries + 1,
                        'status'   => $e->statusCode,
                        'message'  => $e->getMessage(),
                    ]);
                } catch (\Exception $e) {
                    $attemptKey = "{$provider}:{$i}";
                    $attempted[] = $attemptKey;
                    Log::warning("AI call threw exception, " . ($i < $retries ? "retrying" : "falling back"), [
                        'provider' => $provider,
                        'model'   => $model,
                        'attempt' => $i + 1,
                        'max'     => $retries + 1,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        throw new \Exception("All AI providers failed after retries. Tried: " . implode(', ', array_unique($attempted)));
    }

    /**
     * Build the ordered fallback chain: primary provider first, then configured fallbacks.
     * Providers without valid API keys or vision support are silently skipped.
     */
    private function buildFallbackChain(): array
    {
        $primary = ['provider' => $this->provider, 'retries' => 0];
        $chain   = [$primary];

        // Allow override via AI_FALLBACK_PROVIDERS env var (comma-separated list)
        $fbEnv = env('AI_FALLBACK_PROVIDERS', '');
        if (!empty($fbEnv)) {
            foreach (array_filter(array_map('trim', explode(',', $fbEnv))) as $fbProvider) {
                if ($fbProvider !== $this->provider) {
                    $chain[] = ['provider' => $fbProvider, 'retries' => 1];
                }
            }
        } else {
            // Use built-in default chain (excludes known-broken providers)
            foreach (self::FALLBACK_CHAIN as $fb) {
                if ($fb['provider'] !== $this->provider) {
                    $chain[] = $fb;
                }
            }
        }

        return $chain;
    }

    private function formatPersona(string $persona): string
    {
        return match ($persona) {
            'first_time' => 'First-time user',
            'non_technical' => 'Non-technical user',
            'junior_developer' => 'Junior developer',
            'developer' => 'Experienced developer',
            'devops' => 'DevOps engineer',
            'designer' => 'Product designer',
            'manager' => 'Product manager',
            'custom' => 'Custom user',
            default => 'General user',
        };
    }

    private function buildPrompt(string $persona, string $pageGoal): string
    {
        return <<<'PROMPT'
You are an expert senior UI/UX designer and accessibility specialist.
Analyze the uploaded screenshot from a FIRST-TIME USER perspective.

PAGE GOAL: Describe what the user should be able to do on this page.

CRITICAL RULES:
- Analyze ONLY what is visually visible in this screenshot
- Do NOT make any claims about backend performance, API speed, database efficiency, code quality, or implementation details
- Do NOT say "the API is slow" or "the database query is inefficient" — you cannot observe these
- Do NOT reference frameworks, programming languages, libraries, or technology choices visible in the UI
- If you cannot determine something from the screenshot alone, say so in the description
- All coordinates must accurately identify the problem area in the screenshot — not generic areas

VISUAL ELEMENTS TO ANALYZE:
1. Visual Hierarchy — What stands out first? Is the most important element clearly visible?
2. Layout — Is information arranged logically? Is there good use of space?
3. Spacing — Are elements properly spaced? Too cramped or too sparse?
4. Alignment — Are elements properly aligned? Any obvious misalignments?
5. Typography — Is text readable? Good font hierarchy? Appropriate sizes?
6. Font Hierarchy — Can you distinguish headings from body text at a glance?
7. Color Usage — Are colors used purposefully? Is there good contrast?
8. Contrast — Can all text and UI elements be clearly distinguished?
9. Buttons — Are CTAs clearly identifiable? Do they look clickable?
10. Forms — Are form fields clearly labeled? Is it obvious what to enter?
11. Navigation — Is it clear how to navigate? Are navigation items obvious?
12. Cards — Are cards well-defined? Is content properly contained?
13. Images — Are images clear and appropriately sized?
14. Icons — Are icons recognizable? Consistent in style and size?
15. Whitespace — Is there enough breathing room? Too cluttered?
16. Consistency — Are similar elements styled consistently?
17. Visual Clarity — Is the overall interface easy to understand at a glance?
18. Readability — Can all text be read comfortably?
19. Accessibility — Can users with disabilities use this interface? (color-blind safe, sufficient contrast)
20. Responsive Concerns — Does the layout adapt appropriately to different screen sizes?

RESPOND WITH ONLY A VALID JSON OBJECT — no markdown, no code blocks, no text outside it.

JSON SCHEMA:
{
  "overallScore": 0-100,
  "summary": "2-3 sentence summary of the overall UI quality",
  "strengths": ["specific strength 1", "specific strength 2"],
  "scores": {
    "visualHierarchy": 0-100,
    "clarity": 0-100,
    "accessibility": 0-100,
    "consistency": 0-100,
    "layout": 0-100,
    "typography": 0-100,
    "ux": 0-100
  },
  "issues": [
    {
      "title": "Specific issue title",
      "severity": "critical|high|medium|low",
      "description": "What specifically is wrong with this element",
      "whyItMatters": "Why this problem matters for users",
      "recommendation": "Specific actionable fix",
      "category": "visualHierarchy|layout|typography|color|accessibility|consistency|content|navigation",
      "x": 0-100,
      "y": 0-100,
      "width": 0-100,
      "height": 0-100
    }
  ],
  "suggestions": [
    {
      "title": "Suggestion title",
      "priority": "critical|high|medium|low",
      "category": "visualHierarchy|layout|typography|color|accessibility|consistency|content|navigation",
      "problem": "What specific problem does this suggestion solve",
      "recommendation": "How to implement this suggestion",
      "expectedImpact": "Expected UX improvement"
    }
  ]
}

COORDINATE RULES (important):
- x, y, width, height are PERCENTAGES (0-100) of the screenshot dimensions
- Coordinates must identify the EXACT region of the problem, not a generic area
- For small elements (single button, icon): width/height should be 5-15% at most
- For area problems (poor spacing, clutter): use the full affected area
- If an issue spans the entire screen (e.g., poor contrast everywhere): use x:0, y:0, width:100, height:100
- If an issue has no specific location (e.g., font is small everywhere): provide a representative region
- NEVER use coordinates like 0, 0, 100, 100 for a small localized issue
- Be PRECISE — wrong coordinates make annotation pins appear in the wrong place

SEVERITY DEFINITIONS:
- critical: Issue prevents task completion or makes interface unusable
- high: Significant usability problem that seriously impacts user experience
- medium: Usability problem that should be fixed but is not critical
- low: Minor polish or aesthetic improvement

CATEGORY DEFINITIONS:
- visualHierarchy: What stands out vs what should stand out
- layout: Overall arrangement and composition of elements
- typography: Text readability, font sizes, font weights, line height
- color: Color usage, palette consistency, color harmony
- accessibility: Contrast, focus states, screen reader support
- consistency: Inconsistent styling across similar elements
- content: Text content quality, labels, placeholder text
- navigation: How users find and reach different areas

REQUIREMENTS:
- overallScore must be 0-100 (integer)
- All scores must be 0-100 (integers)
- issues and suggestions can be empty arrays if nothing found
- Every issue MUST have: title, severity, description, whyItMatters, recommendation, category, and coordinates
- Every suggestion MUST have: title, priority, category, problem, recommendation, expectedImpact
- Be specific and detailed — vague issues and suggestions are not helpful
- Respond with ONLY the JSON object, nothing else
  - If an issue has no specific location (e.g., font is small everywhere): provide a representative region — do NOT return null or omitted coordinates
PROMPT;
    }

    /**
     * Dispatch to the correct provider-specific method.
     * Provider and model are passed explicitly so the fallback chain can override them.
     */
    private function callAI(string $prompt, string $imageBase64, string $mimeType, string $provider, string $model): string
    {
        // Temporarily set instance vars so individual methods still work if they read them
        $previousProvider = $this->provider;
        $previousModel    = $this->model;
        $this->provider   = $provider;
        $this->model      = $model;

        try {
            return match ($provider) {
                'openai'    => $this->callOpenAI($prompt, $imageBase64, $mimeType),
                'anthropic' => $this->callAnthropic($prompt, $imageBase64, $mimeType),
                'ollama'    => $this->callOllama($prompt, $imageBase64, $mimeType),
                'groq'      => $this->callGroq($prompt, $imageBase64, $mimeType),
                'gemini'    => $this->callGemini($prompt, $imageBase64, $mimeType),
                'cloudflare'=> $this->callCloudflare($prompt, $imageBase64, $mimeType),
                'xai'       => $this->callXAI($prompt, $imageBase64, $mimeType),
                default     => throw new \Exception("AI provider '{$provider}' not supported"),
            };
        } finally {
            $this->provider = $previousProvider;
            $this->model    = $previousModel;
        }
    }

    private function getOpenAIKey(): string
    {
        // Try settings file first (admin-configured)
        $settingsPath = base_path('.ai_settings.json');
        if (file_exists($settingsPath)) {
            $settings = json_decode(file_get_contents($settingsPath), true);
            if (!empty($settings['openai_key'])) {
                return $settings['openai_key'];
            }
        }
        // Fall back to environment/config
        return config('ai.openai.api_key', '');
    }

    private function callOpenAI(string $prompt, string $imageBase64, string $mimeType): string
    {
        $apiKey = $this->getOpenAIKey();

        if (empty($apiKey)) {
            throw new \Exception('OpenAI API key not configured');
        }

        $dataUrl = "data:{$mimeType};base64,{$imageBase64}";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'high']],
                    ],
                ],
            ],
            'max_tokens' => 4000,
        ]);

        if ($response->failed()) {
            $status = $response->status();
            $body = $response->body();
            Log::error('OpenAI API error', ['status' => $status, 'body' => $body]);

            if ($status === 429) {
                throw new AIResponseException(429, $body);
            }
            if (in_array($status, [401, 403])) {
                throw new AIResponseException($status, $body);
            }

            throw new AIResponseException($status, $body);
        }

        $body = $response->json();

        return $body['choices'][0]['message']['content'] ?? '';
    }

    private function callAnthropic(string $prompt, string $imageBase64, string $mimeType): string
    {
        $apiKey = config('ai.anthropic.api_key');

        if (empty($apiKey)) {
            throw new \Exception('Anthropic API key not configured');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model,
            'max_tokens' => 4000,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mimeType,
                                'data' => $imageBase64,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            $status = $response->status();
            $body = $response->body();
            Log::error('Anthropic API error', ['status' => $status, 'body' => $body]);

            if ($status === 429) {
                throw new AIResponseException(429, $body);
            }
            if (in_array($status, [401, 403])) {
                throw new AIResponseException($status, $body);
            }

            throw new AIResponseException($status, $body);
        }

        $body = $response->json();

        return $body['content'][0]['text'] ?? '';
    }

    private function callOllama(string $prompt, string $imageBase64, string $mimeType): string
    {
        $baseUrl = config('ai.ollama.base_url', 'http://127.0.0.1:11434');

        $response = Http::timeout(180)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("{$baseUrl}/api/generate", [
                'model' => $this->model,
                'prompt' => $prompt,
                'images' => [$imageBase64],
                'stream' => false,
            ]);

        if ($response->failed()) {
            $status = $response->status();
            $body = $response->body();
            Log::error('Ollama API error', ['status' => $status, 'body' => $body]);
            throw new AIResponseException($status, $body);
        }

        $body = $response->json();
        return $body['response'] ?? '';
    }

    private function callGroq(string $prompt, string $imageBase64, string $mimeType): string
    {
        $apiKey = config('ai.groq.api_key');

        if (empty($apiKey)) {
            throw new \Exception('Groq API key not configured. Add AI_GROQ_API_KEY to your .env file.');
        }

        $dataUrl = "data:{$mimeType};base64,{$imageBase64}";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://api.groq.com/v1/chat/completions', [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ],
                ],
            ],
            'max_tokens' => 4000,
        ]);

        if ($response->failed()) {
            $status = $response->status();
            $body = $response->body();
            Log::error('Groq API error', ['status' => $status, 'body' => $body]);

            if ($status === 429) {
                throw new AIResponseException(429, $body);
            }
            if (in_array($status, [401, 403])) {
                throw new AIResponseException($status, $body);
            }

            throw new AIResponseException($status, $body);
        }

        $body = $response->json();

        return $body['choices'][0]['message']['content'] ?? '';
    }

    private function callGemini(string $prompt, string $imageBase64, string $mimeType): string
    {
        $apiKey = config('ai.gemini.api_key');

        if (empty($apiKey)) {
            throw new \Exception('Gemini API key not configured. Add AI_GEMINI_API_KEY to your .env file.');
        }

        $mimeToGemini = [
            'image/png' => 'image/png',
            'image/jpeg' => 'image/jpeg',
            'image/webp' => 'image/webp',
        ];
        $geminiMime = $mimeToGemini[$mimeType] ?? 'image/png';

        $response = Http::timeout(120)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$apiKey}",
                [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                                [
                                    'inline_data' => [
                                        'mime_type' => $geminiMime,
                                        'data' => $imageBase64,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 4096,
                        'temperature' => 0.3,
                    ],
                ]
            );

        if ($response->failed()) {
            $status = $response->status();
            $body = $response->body();
            Log::error('Gemini API error', ['status' => $status, 'body' => $body]);

            if ($status === 429) {
                throw new AIResponseException(429, $body);
            }
            if (in_array($status, [401, 403])) {
                throw new AIResponseException($status, $body);
            }

            throw new AIResponseException($status, $body);
        }

        $body = $response->json();
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($text)) {
            $promptFeedback = $body['promptFeedback'] ?? null;
            if ($promptFeedback) {
                Log::warning('Gemini empty response', ['promptFeedback' => $promptFeedback]);
            }
        }

        return $text;
    }

    private function callCloudflare(string $prompt, string $imageBase64, string $mimeType): string
    {
        $accountId = config('ai.cloudflare.account_id');
        $apiToken = config('ai.cloudflare.api_token');

        if (empty($accountId) || empty($apiToken)) {
            throw new \Exception('Cloudflare AI not configured. Set AI_CF_ACCOUNT_ID and AI_CF_API_TOKEN in .env');
        }

        $mimeToFormat = [
            'image/png' => 'png',
            'image/jpeg' => 'jpeg',
            'image/webp' => 'webp',
        ];
        $format = $mimeToFormat[$mimeType] ?? 'png';

        $response = Http::timeout(180)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiToken,
                'Content-Type' => 'application/json',
            ])
            ->post(
                "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$this->model}",
                [
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => $prompt],
                                [
                                    'type' => 'image_url',
                                    'image_url' => [
                                        'url' => "data:{$mimeType};base64,{$imageBase64}",
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]
            );

        if ($response->failed()) {
            $status = $response->status();
            $body = $response->body();
            Log::error('Cloudflare AI error', ['status' => $status, 'body' => $body]);

            if ($status === 429) {
                throw new AIResponseException(429, $body);
            }
            if (in_array($status, [401, 403])) {
                throw new AIResponseException($status, $body);
            }

            throw new AIResponseException($status, $body);
        }

        $body = $response->json();
        return $body['result']['response'] ?? '';
    }

    private function callXAI(string $prompt, string $imageBase64, string $mimeType): string
    {
        $apiKey = config('ai.xai.api_key');

        if (empty($apiKey)) {
            throw new \Exception('xAI API key not configured. Add XAI_API_KEY to your .env file.');
        }

        $dataUrl = "data:{$mimeType};base64,{$imageBase64}";

        $response = Http::timeout(120)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.x.ai/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            [
                                'type' => 'image_url',
                                'image_url' => ['url' => $dataUrl],
                            ],
                        ],
                    ],
                ],
                'max_tokens' => 4096,
            ]);

        if ($response->failed()) {
            $status = $response->status();
            $body = $response->body();
            Log::error('xAI API error', ['status' => $status, 'body' => $body]);

            if ($status === 429) {
                throw new AIResponseException(429, $body);
            }
            if (in_array($status, [401, 403])) {
                throw new AIResponseException($status, $body);
            }

            throw new AIResponseException($status, $body);
        }

        $body = $response->json();
        $content = $body['choices'][0]['message']['content'] ?? '';

        return $content;
    }

    private function parseAndValidateResponse(string $rawResponse): array
    {
        // Try to extract JSON from the response
        $json = $this->extractJson($rawResponse);

        if (!$json) {
            throw new \Exception('Failed to parse AI response as JSON');
        }

        // Validate and normalize structure (returns normalized data)
        $json = $this->validateJsonStructure($json);

        return $json;
    }

    private function extractJson(string $response): ?array
    {
        // Try direct JSON decode first
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Extract JSON from markdown code block
        // Approach: find ```json, then extract content until the LAST occurrence of ```
        // This handles cases where the JSON contains ``` inside string values
        $jsonStart = strpos($response, '```json');
        if ($jsonStart !== false) {
            $after = substr($response, $jsonStart + 7);
            // Strip leading whitespace/newlines after ```json
            $after = ltrim($after, "\n ");
            // Find the LAST ``` in the remaining text
            $lastTick = strrpos($after, '```');
            if ($lastTick !== false) {
                $json = substr($after, 0, $lastTick);
                // Fix common JSON-in-HTML issues: unescaped newlines in string values
                $json = $this->fixJsonNewlines($json);
                $decoded = json_decode($json, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
                // Try with trimmed version
                $decoded = json_decode(trim($json), true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        // Fallback: try to find first { and last } in the response
        $start = strpos($response, '{');
        if ($start !== false) {
            $end = strrpos($response, '}');
            if ($end !== false && $end > $start) {
                $json = substr($response, $start, $end - $start + 1);
                $json = $this->fixJsonNewlines($json);
                $decoded = json_decode($json, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    private function fixJsonNewlines(string $json): string
    {
        // Gemini may return raw newlines inside JSON string values
        // e.g., "summary": "Line 1\nLine 2" instead of properly escaped
        // Strategy: process line by line — within string values, escape raw newlines
        $result = '';
        $inString = false;
        $i = 0;
        $len = strlen($json);
        while ($i < $len) {
            $ch = $json[$i];
            if ($ch === '"' && ($i === 0 || $json[$i-1] !== '\\')) {
                $inString = !$inString;
                $result .= $ch;
            } elseif ($ch === "\n" && $inString) {
                // Raw newline inside string — escape it
                $result .= '\\n';
            } elseif ($ch === "\r" && $inString) {
                // Also escape carriage returns
                $result .= '\\r';
            } else {
                $result .= $ch;
            }
            $i++;
        }
        return $result;
    }

    private function normalizeAIResponse(array $data): array
    {
        // Handle nested analysis wrappers (various AI response styles)
        // e.g., {ui_analysis: {...}}, {analysis: {...}}, {screenshot_analysis: {...}}, {data: {...}}
        $wrapperKeys = ['ui_analysis', 'analysis', 'screenshot_analysis', 'data', 'result', 'response', 'review', 'output'];
        foreach ($wrapperKeys as $wrapper) {
            if (isset($data[$wrapper]) && is_array($data[$wrapper])) {
                $inner = $data[$wrapper];
                // Only unwrap if inner has the data we need
                if (isset($inner['overallScore']) || isset($inner['overall_score']) || isset($inner['scores']) || isset($inner['issues'])) {
                    $data = array_merge($data, $inner);
                    unset($data[$wrapper]);
                    break;
                }
            }
        }

        // Normalize issue fields — AI may return 'issue_id' instead of 'title', etc.
        $issues = [];
        foreach ($data['issues'] ?? [] as $issue) {
            if (!is_array($issue)) continue;
            $normalized = [
                'title' => $issue['title'] ?? $issue['issue'] ?? $issue['name'] ?? 'Unnamed issue',
                'severity' => $issue['severity'] ?? $issue['priority'] ?? $issue['impact'] ?? 'medium',
                'description' => $issue['description'] ?? $issue['problem'] ?? $issue['text'] ?? '',
                'category' => $issue['category'] ?? 'ux',
                'whyItMatters' => $issue['whyItMatters'] ?? $issue['why_it_matters'] ?? $issue['reason'] ?? '',
                'recommendation' => $issue['recommendation'] ?? $issue['fix'] ?? $issue['suggestion'] ?? '',
            ];
            // Normalize severity to our enum
            $sev = strtolower($normalized['severity']);
            if (in_array($sev, ['critical', 'high', 'error'])) $normalized['severity'] = 'critical';
            elseif (in_array($sev, ['warning', 'medium', 'major'])) $normalized['severity'] = 'high';
            elseif (in_array($sev, ['info', 'low', 'minor', 'suggestion'])) $normalized['severity'] = 'low';
            else $normalized['severity'] = 'medium';
            $issues[] = $normalized;
        }
        $data['issues'] = $issues;

        // Normalize suggestions
        $suggestions = [];
        foreach ($data['suggestions'] ?? [] as $s) {
            if (!is_array($s)) continue;
            $normalized = [
                'title' => $s['title'] ?? $s['name'] ?? $s['suggestion'] ?? 'Unnamed suggestion',
                'priority' => $s['priority'] ?? $s['severity'] ?? $s['impact'] ?? 'medium',
                'category' => $s['category'] ?? 'ux',
                'problem' => $s['problem'] ?? $s['description'] ?? '',
                'recommendation' => $s['recommendation'] ?? $s['fix'] ?? '',
                'expectedImpact' => $s['expectedImpact'] ?? $s['expected_impact'] ?? $s['impact'] ?? '',
            ];
            $pri = strtolower($normalized['priority']);
            if (in_array($pri, ['critical', 'high', 'major', 'error'])) $normalized['priority'] = 'critical';
            elseif (in_array($pri, ['warning', 'medium', 'moderate'])) $normalized['priority'] = 'high';
            elseif (in_array($pri, ['info', 'low', 'minor'])) $normalized['priority'] = 'low';
            else $normalized['priority'] = 'medium';
            $suggestions[] = $normalized;
        }
        $data['suggestions'] = $suggestions;

        // Normalize scores — flatten if nested under 'scores' key already handled above
        // Handle scores nested under 'scores.scores' or similar
        if (isset($data['scores']['scores']) && is_array($data['scores']['scores'])) {
            $data['scores'] = $data['scores']['scores'];
        }

        // Normalize score keys to camelCase canonical names
        $scoreKeyMap = [
            'visual_hierarchy' => 'visualHierarchy',
            'visual-hierarchy' => 'visualHierarchy',
            'visualhierarchy' => 'visualHierarchy',
            'clarity_score' => 'clarity',
            'clarity-score' => 'clarity',
            'accessibility_score' => 'accessibility',
            'accessibility-score' => 'accessibility',
            'accessibility' => 'accessibility',
            'consistency_score' => 'consistency',
            'consistency' => 'consistency',
            'layout_score' => 'layout',
            'layout' => 'layout',
            'typography_score' => 'typography',
            'typography' => 'typography',
            'ux_score' => 'ux',
            'ux' => 'ux',
            'usability' => 'ux',
            'readability' => 'clarity',
            'information_density' => 'layout',
            'aesthetics' => 'clarity',
            'design' => 'ux',
            'content' => 'clarity',
            'feedbackanderrorhandling' => 'ux',
            'feedback' => 'ux',
        ];
        $normalizedScores = [];
        foreach ($data['scores'] ?? [] as $key => $value) {
            $normalizedKey = $scoreKeyMap[strtolower($key)] ?? $key;
            $normalizedScores[$normalizedKey] = is_numeric($value) ? (int) $value : $value;
        }
        $data['scores'] = $normalizedScores;

        // Normalize overallScore from various possible keys
        $overallKeys = ['overallScore', 'overall_score', 'overall', 'totalScore', 'total_score', 'score', 'rating'];
        foreach ($overallKeys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $data['overallScore'] = (int) $data[$key];
                break;
            }
        }

        // Ensure summary exists
        $summaryKeys = ['summary', 'overview', 'conclusion', 'verdict', 'result'];
        foreach ($summaryKeys as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data['summary'] = $data[$key];
                break;
            }
        }
        if (!isset($data['summary'])) $data['summary'] = '';

        return $data;
    }

    private function validateJsonStructure(array $data): array
    {
        // Normalize AI response first
        $data = $this->normalizeAIResponse($data);

        // Check required top-level keys
        if (!isset($data['overallScore']) || !is_numeric($data['overallScore'])) {
            $available = array_keys($data);
            throw new \Exception("AI response missing overallScore. Got keys: " . implode(', ', $available));
        }

        // Scores — must be an array with at least one numeric value
        if (!is_array($data['scores']) || empty($data['scores'])) {
            throw new \Exception('Scores must be a non-empty object');
        }
        foreach ($data['scores'] as $key => $value) {
            if (!is_numeric($value)) {
                throw new \Exception("Score '{$key}' must be numeric, got: " . gettype($value));
            }
        }

        // Issues and suggestions must be arrays (even if empty)
        if (!is_array($data['issues'])) {
            throw new \Exception('Issues must be an array');
        }
        if (!is_array($data['suggestions'])) {
            throw new \Exception('Suggestions must be an array');
        }

        // Validate each issue has required fields and valid coordinates
        $validCategories = ['visualHierarchy', 'layout', 'typography', 'color', 'accessibility', 'consistency', 'content', 'navigation'];
        foreach ($data['issues'] as $i => $issue) {
            if (!is_array($issue)) {
                throw new \Exception("Issue at index {$i} must be an object");
            }
            if (empty(($issue['title'] ?? ''))) {
                throw new \Exception("Issue at index {$i} is missing a title");
            }
            if (empty(($issue['description'] ?? ''))) {
                throw new \Exception("Issue at index {$i} ('{$issue['title']}') is missing a description");
            }
            if (empty(($issue['whyItMatters'] ?? ''))) {
                throw new \Exception("Issue at index {$i} ('{$issue['title']}') is missing whyItMatters");
            }
            if (empty(($issue['recommendation'] ?? ''))) {
                throw new \Exception("Issue at index {$i} ('{$issue['title']}') is missing a recommendation");
            }
            $category = $issue['category'] ?? '';
            if (!in_array($category, $validCategories)) {
                throw new \Exception("Issue at index {$i} ('{$issue['title']}') has invalid category '{$category}'. Must be one of: " . implode(', ', $validCategories));
            }
            // Validate coordinates — null is acceptable (will use defaults), but non-null must be numeric and 0-100
            foreach (['x', 'y', 'width', 'height'] as $coord) {
                $val = $issue[$coord] ?? null;
                if ($val !== null && !is_numeric($val)) {
                    throw new \Exception("Issue at index {$i} ('{$issue['title']}') has non-numeric coordinate {$coord}: " . gettype($val));
                }
                if ($val !== null && (floatval($val) < 0 || floatval($val) > 100)) {
                    throw new \Exception("Issue at index {$i} ('{$issue['title']}') has {$coord}={$val} — must be between 0 and 100");
                }
                if ($val < 0 || $val > 100) {
                    throw new \Exception("Issue at index {$i} ('{$issue['title']}') has {$coord}={$val} — must be between 0 and 100");
                }
            }
        }

        // Validate each suggestion has required fields
        foreach ($data['suggestions'] as $i => $suggestion) {
            if (!is_array($suggestion)) {
                throw new \Exception("Suggestion at index {$i} must be an object");
            }
            if (empty(($suggestion['title'] ?? ''))) {
                throw new \Exception("Suggestion at index {$i} is missing a title");
            }
            if (empty(($suggestion['problem'] ?? ''))) {
                throw new \Exception("Suggestion at index {$i} ('{$suggestion['title']}') is missing problem");
            }
            if (empty(($suggestion['recommendation'] ?? ''))) {
                throw new \Exception("Suggestion at index {$i} ('{$suggestion['title']}') is missing recommendation");
            }
            if (empty(($suggestion['expectedImpact'] ?? ''))) {
                throw new \Exception("Suggestion at index {$i} ('{$suggestion['title']}') is missing expectedImpact");
            }
            $category = $suggestion['category'] ?? '';
            if (!in_array($category, $validCategories)) {
                throw new \Exception("Suggestion at index {$i} ('{$suggestion['title']}') has invalid category '{$category}'. Must be one of: " . implode(', ', $validCategories));
            }
        }

        // Annotations — optional but if present must be an array of objects with coordinates
        if (isset($data['annotations']) && is_array($data['annotations'])) {
            foreach ($data['annotations'] ?? [] as $i => $ann) {
                if (!is_array($ann)) {
                    throw new \Exception("Annotation at index {$i} must be an object");
                }
            }
        }

        return $data;
    }

    public function saveReviewResults(Review $review, array $aiData): void
    {
        // Clean up any existing review data before saving new results
        // This ensures retry works properly without duplicate records
        $this->cleanupReviewResults($review->id);

        // Parse and normalize scores — AI may return them as ints or floats
        $scores = $aiData['scores'] ?? [];
        foreach ($scores as $k => $v) {
            $scores[$k] = is_numeric($v) ? (int) $v : null;
        }

        // Ensure each score category has a value (default to 0 if missing)
        $scoreFields = ['visualHierarchy', 'clarity', 'accessibility', 'consistency', 'layout', 'typography', 'ux'];
        foreach ($scoreFields as $field) {
            if (!isset($scores[$field]) || $scores[$field] === null) {
                $scores[$field] = 0;
            }
        }

        ReviewScore::create([
            'review_id'         => $review->id,
            'visual_hierarchy'  => $scores['visualHierarchy'],
            'clarity'           => $scores['clarity'],
            'accessibility'     => $scores['accessibility'],
            'consistency'       => $scores['consistency'],
            'layout'            => $scores['layout'],
            'typography'        => $scores['typography'],
            'ux'                => $scores['ux'],
            'overall'           => isset($aiData['overallScore']) && is_numeric($aiData['overallScore']) ? (int) $aiData['overallScore'] : 0,
            'summary'           => $aiData['summary'] ?? '',
            'strengths'         => $aiData['strengths'] ?? [],
        ]);

        // Save issues and annotations
        foreach ($aiData['issues'] ?? [] as $issueData) {
            $issue = ReviewIssue::create([
                'review_id' => $review->id,
                'title' => $issueData['title'],
                'severity' => $issueData['severity'],
                'category' => $issueData['category'] ?? 'ux',
                'description' => $issueData['description'],
                'why_it_matters' => $issueData['whyItMatters'] ?? '',
                'recommendation' => $issueData['recommendation'],
            ]);

            ReviewAnnotation::create([
                'review_id' => $review->id,
                'review_issue_id' => $issue->id,
                'x' => $issueData['x'] ?? 50,
                'y' => $issueData['y'] ?? 50,
                'width' => $issueData['width'] ?? 20,
                'height' => $issueData['height'] ?? 20,
            ]);
        }

        // Save suggestions
        foreach ($aiData['suggestions'] ?? [] as $suggestionData) {
            ReviewSuggestion::create([
                'review_id' => $review->id,
                'title' => $suggestionData['title'],
                'priority' => $suggestionData['priority'],
                'category' => $suggestionData['category'] ?? 'ux',
                'problem' => $suggestionData['problem'] ?? '',
                'recommendation' => $suggestionData['recommendation'],
                'expected_impact' => $suggestionData['expectedImpact'] ?? '',
            ]);
        }

        // Update review with AI response
        $review->update([
            'status' => 'completed',
            'ai_response' => json_encode($aiData),
        ]);
    }

    /**
     * Clean up existing review results to allow retry.
     * Deletes scores, issues, annotations, and suggestions for the given review.
     */
    public function cleanupReviewResults(int $reviewId): void
    {
        // Delete annotations first (foreign key constraint)
        ReviewAnnotation::where('review_id', $reviewId)->delete();

        // Delete issues (cascades from reviews table but explicit is clearer)
        ReviewIssue::where('review_id', $reviewId)->delete();

        // Delete suggestions
        ReviewSuggestion::where('review_id', $reviewId)->delete();

        // Delete scores
        ReviewScore::where('review_id', $reviewId)->delete();
    }
}
