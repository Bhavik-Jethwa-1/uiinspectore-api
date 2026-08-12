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
        return <<<EOT
You are an expert senior UI/UX reviewer analyzing a web interface screenshot.

Persona: {$persona}
Page Goal: {$pageGoal}

Analyze the screenshot as if you are reviewing it from the perspective of: "{$persona}"
whose primary goal on this page is: "{$pageGoal}"

Provide a thorough analysis covering:
1. Visual hierarchy - Is the most important element clearly dominant?
2. Layout - Is spacing consistent and purposeful?
3. Typography - Is there clear type hierarchy?
4. Color usage - Does color guide attention effectively?
5. Contrast & Accessibility - Can all text be read easily?
6. Navigation clarity - Can users find what they need?
7. CTA hierarchy - Are calls-to-action clear and prominent?
8. Information architecture - Is content organized logically?
9. Consistency - Are design patterns applied uniformly?
10. User friction - Are there points of confusion or frustration?
11. Component density - Is there too much or too little on screen?
12. Content clarity - Is the purpose of the page immediately clear?

Be specific and actionable. Explain the PROBLEM, WHY IT MATTERS, and give a RECOMMENDED FIX.

Respond ONLY with valid JSON in this exact structure:
{
  "overallScore": 0-100,
  "scores": {
    "visualHierarchy": 0-100,
    "clarity": 0-100,
    "accessibility": 0-100,
    "consistency": 0-100,
    "layout": 0-100,
    "typography": 0-100,
    "ux": 0-100
  },
  "summary": "2-3 sentence overall assessment",
  "strengths": ["What works well 1", "What works well 2"],
  "issues": [
    {
      "title": "Issue title",
      "severity": "critical|high|medium|low",
      "category": "layout|typography|color|accessibility|ux|content|nav",
      "description": "What the issue is",
      "whyItMatters": "Why this matters for the user",
      "recommendation": "Specific fix recommendation",
      "x": 0-100 (percentage of image width, 0 = left),
      "y": 0-100 (percentage of image height, 0 = top),
      "width": 10-50 (percentage of image width),
      "height": 10-50 (percentage of image height)
    }
  ],
  "suggestions": [
    {
      "title": "Suggestion title",
      "priority": "critical|high|medium|low",
      "category": "ux|content|design|accessibility|nav",
      "problem": "Current state",
      "recommendation": "What to change",
      "expectedImpact": "How this improves the UX"
    }
  ]
}

CRITICAL RULES:
- Return ONLY valid JSON - no markdown, no explanation, no text outside the JSON
- All scores must be 0-100 integers
- severity must be exactly: critical, high, medium, or low
- priority must be exactly: critical, high, medium, or low
- For issues WITH bounding boxes: provide x,y,width,height as percentages (0-100)
- For issues without clear location: use x:50, y:50, width:20, height:20 as default
- Minimum 2 issues, maximum 8 issues
- Minimum 1 suggestion, maximum 6 suggestions
EOT;
    }

    private function callAI(string $prompt, string $imageBase64, string $mimeType): string
    {
        if ($this->provider === 'openai') {
            return $this->callOpenAI($prompt, $imageBase64, $mimeType);
        }

        if ($this->provider === 'anthropic') {
            return $this->callAnthropic($prompt, $imageBase64, $mimeType);
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

    private function parseAndValidateResponse(string $rawResponse): array
    {
        // Try to extract JSON from the response
        $json = $this->extractJson($rawResponse);

        if (!$json) {
            throw new \Exception('Failed to parse AI response as JSON');
        }

        // Validate structure
        $this->validateJsonStructure($json);

        return $json;
    }

    private function extractJson(string $response): ?array
    {
        // Try direct JSON decode first
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Try to extract JSON from markdown code block
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $response, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // Try to find JSON object in the text
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
            $decoded = json_decode(trim($matches[0]), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function validateJsonStructure(array $data): void
    {
        $requiredKeys = ['overallScore', 'scores', 'summary', 'strengths', 'issues', 'suggestions'];
        foreach ($requiredKeys as $key) {
            if (!isset($data[$key])) {
                throw new \Exception("Missing required key: {$key}");
            }
        }

        $scoreKeys = ['visualHierarchy', 'clarity', 'accessibility', 'consistency', 'layout', 'typography', 'ux'];
        foreach ($scoreKeys as $key) {
            if (!isset($data['scores'][$key]) || !is_int($data['scores'][$key])) {
                throw new \Exception("Invalid or missing score: {$key}");
            }
        }

        foreach ($data['issues'] as $issue) {
            if (empty($issue['title']) || empty($issue['severity']) || empty($issue['description'])) {
                throw new \Exception('Invalid issue structure');
            }
        }

        foreach ($data['suggestions'] as $suggestion) {
            if (empty($suggestion['title']) || empty($suggestion['priority']) || empty($suggestion['recommendation'])) {
                throw new \Exception('Invalid suggestion structure');
            }
        }
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
