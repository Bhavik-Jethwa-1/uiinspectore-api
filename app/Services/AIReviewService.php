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

    public function __construct()
    {
        $this->provider = config('ai.provider', 'openai');
        $this->model = config('ai.openai.model', 'gpt-4o');
    }

    public function analyze(Review $review, Screenshot $screenshot): array
    {
        $persona = $this->formatPersona($review->persona);
        $pageGoal = $review->page_goal;

        // Read the screenshot file and encode as base64
        $imagePath = Storage::disk('local')->path($screenshot->path);
        $imageBase64 = base64_encode(file_get_contents($imagePath));
        $mimeType = $screenshot->mime_type;

        $prompt = $this->buildPrompt($persona, $pageGoal);

        $response = $this->callAI($prompt, $imageBase64, $mimeType);

        return $this->parseAndValidateResponse($response);
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
        $prompt = 'You are an expert senior UI/UX reviewer. Analyze this screenshot for a ' . $persona . '. '
            . 'Page goal: ' . $pageGoal . '. '
            . 'IMPORTANT: Respond with ONLY this exact JSON structure - no text outside it: ';
        $prompt .= '{"overallScore":75,"scores":{"visualHierarchy":75,"clarity":70,"accessibility":65,"consistency":70,"layout":72,"typography":68,"ux":70},';
        $prompt .= '"summary":"The UI is clean with clear navigation.","strengths":["Clean layout"],';
        $prompt .= '"issues":[{"title":"Small text","severity":"medium","description":"Text is hard to read","recommendation":"Increase font size","x":50,"y":50,"width":20,"height":20}],';
        $prompt .= '"suggestions":[{"title":"Improve contrast","priority":"medium","recommendation":"Use darker text"}]}';
        return $prompt;
    }

    private function callAI(string $prompt, string $imageBase64, string $mimeType): string
    {
        if ($this->provider === 'openai') {
            return $this->callOpenAI($prompt, $imageBase64, $mimeType);
        }

        if ($this->provider === 'anthropic') {
            return $this->callAnthropic($prompt, $imageBase64, $mimeType);
        }

        if ($this->provider === 'ollama') {
            return $this->callOllama($prompt, $imageBase64, $mimeType);
        }

        if ($this->provider === 'groq') {
            return $this->callGroq($prompt, $imageBase64, $mimeType);
        }

        if ($this->provider === 'gemini') {
            return $this->callGemini($prompt, $imageBase64, $mimeType);
        }

        if ($this->provider === 'cloudflare') {
            return $this->callCloudflare($prompt, $imageBase64, $mimeType);
        }

        if ($this->provider === 'xai') {
            return $this->callXAI($prompt, $imageBase64, $mimeType);
        }

        throw new \Exception("AI provider '{$this->provider}' not supported");
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
        $model = config('ai.anthropic.model', 'claude-3-5-sonnet-20241022');

        if (empty($apiKey)) {
            throw new \Exception('Anthropic API key not configured');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
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
        $model = config('ai.ollama.model', 'llava');

        $response = Http::timeout(180)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("{$baseUrl}/api/generate", [
                'model' => $model,
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
        $model = config('ai.groq.model', 'llama-3.2-11b-vision-preview');

        if (empty($apiKey)) {
            throw new \Exception('Groq API key not configured. Add AI_GROQ_API_KEY to your .env file.');
        }

        $dataUrl = "data:{$mimeType};base64,{$imageBase64}";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://api.groq.com/v1/chat/completions', [
            'model' => $model,
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
        $model = config('ai.gemini.model', 'gemini-1.5-flash');

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
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
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
        $model = config('ai.cloudflare.model', '@cf/meta/llama-3.2-11b-vision-instruct');

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
                "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$model}",
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
        $model = config('ai.xai.model', 'grok-2-vision-1212');

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
                'model' => $model,
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
        // Save scores
        $scores = $aiData['scores'];
        ReviewScore::create([
            'review_id' => $review->id,
            'visual_hierarchy' => $scores['visualHierarchy'] ?? null,
            'clarity' => $scores['clarity'] ?? null,
            'accessibility' => $scores['accessibility'] ?? null,
            'consistency' => $scores['consistency'] ?? null,
            'layout' => $scores['layout'] ?? null,
            'typography' => $scores['typography'] ?? null,
            'ux' => $scores['ux'] ?? null,
            'overall' => $aiData['overallScore'] ?? null,
            'summary' => $aiData['summary'] ?? null,
            'strengths' => $aiData['strengths'] ?? [],
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
}
