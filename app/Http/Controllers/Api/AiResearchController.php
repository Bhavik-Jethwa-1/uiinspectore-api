<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class AiResearchController extends \Illuminate\Routing\Controller
{
    /**
     * OpenClaw gateway configuration (matches existing AIController).
     */
    private string $openclawToken = 'c11301b2d79af120e1a150539bb2ab0b50d999d1a302a810';
    private string $openclawUrl = 'http://127.0.0.1:18789';

    /**
     * Groq fallback configuration.
     */
    private string $groqKey = 'gsk_NzK7gHTxRGxdnloTqHNlWGdyb3dyR';
    private string $groqModel = 'llama-3.3-70b-versatile';

    /**
     * Research scope presets — driven by the requested topic domain.
     */
    private array $scopePresets = [
        'ux_best_practices' => [
            'title' => 'UX Best Practices',
            'description' => 'User experience principles for digital products',
            'category' => 'UX',
            'default_aspects' => [
                'user research', 'information architecture', 'interaction patterns',
                'usability heuristics', 'user flows', 'navigation patterns',
            ],
        ],
        'ui_best_practices' => [
            'title' => 'UI Best Practices',
            'description' => 'Visual and interface design principles',
            'category' => 'UI',
            'default_aspects' => [
                'visual hierarchy', 'color theory', 'typography', 'spacing systems',
                'design tokens', 'grid systems', 'microcopy',
            ],
        ],
        'accessibility' => [
            'title' => 'Accessibility',
            'description' => 'Inclusive design and WCAG compliance',
            'category' => 'A11y',
            'default_aspects' => [
                'WCAG 2.2', 'ARIA patterns', 'screen reader support',
                'keyboard navigation', 'color contrast', 'focus management',
            ],
        ],
        'industry_standards' => [
            'title' => 'Industry Standards',
            'description' => 'Conventions across SaaS and web products',
            'category' => 'Standards',
            'default_aspects' => [
                'responsive design', 'design systems', 'atomic design',
                'lean UX', 'agile workflows',
            ],
        ],
        'saas_trends' => [
            'title' => 'SaaS Trends',
            'description' => 'Current trends in SaaS product design',
            'category' => 'Trends',
            'default_aspects' => [
                'AI-driven UI', 'embedded analytics', 'self-serve onboarding',
                'usage-based pricing UI', 'multi-tenant patterns',
            ],
        ],
        'dashboard_trends' => [
            'title' => 'Dashboard Trends',
            'description' => 'Modern dashboard and admin UI patterns',
            'category' => 'Trends',
            'default_aspects' => [
                'data visualization', 'KPI cards', 'real-time updates',
                'cross-filtering', 'dark mode', 'compact density',
            ],
        ],
    ];

    /**
     * Per-user research history (for caching and history view).
     */
    private function researchPath(int $userId): string
    {
        $dir = base_path('database/research');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . "/user_{$userId}.json";
        if (!file_exists($path)) {
            file_put_contents($path, json_encode(['research' => []], JSON_PRETTY_PRINT));
        }
        return $path;
    }

    /**
     * Load research history for a user.
     */
    private function loadHistory(int $userId): array
    {
        $path = $this->researchPath($userId);
        $data = json_decode(file_get_contents($path), true) ?? ['research' => []];
        if (!isset($data['research']) || !is_array($data['research'])) {
            $data['research'] = [];
        }
        return $data['research'];
    }

    /**
     * Persist research history for a user.
     */
    private function saveHistory(int $userId, array $history): void
    {
        $path = $this->researchPath($userId);
        file_put_contents($path, json_encode(['research' => array_values($history)], JSON_PRETTY_PRINT));
    }

    /**
     * Build the system prompt for the AI to produce structured research output.
     */
    private function buildSystemPrompt(string $scope, array $preset, string $topic): string
    {
        $aspects = implode(', ', $preset['default_aspects']);
        return "You are a senior {$preset['category']} researcher and design strategist with deep expertise in {$preset['description']}. "
            . "Produce a structured research brief on the topic of '{$topic}' within the scope '{$preset['title']}'. "
            . "Focus your analysis on these aspects: {$aspects}. "
            . "Return ONLY a valid JSON object with no markdown, no preamble, no code fences, using this exact structure:\n"
            . "{\n"
            . "  \"title\":\"string\",\n"
            . "  \"scope\":\"string\",\n"
            . "  \"category\":\"string\",\n"
            . "  \"summary\":\"string (2-4 sentences, plain text, no markdown)\",\n"
            . "  \"key_principles\":[\"string\", \"string\", \"string\"],\n"
            . "  \"do_list\":[\"string\", \"string\", \"string\"],\n"
            . "  \"dont_list\":[\"string\", \"string\", \"string\"],\n"
            . "  \"patterns\":[{\"name\":\"string\",\"description\":\"string\",\"example\":\"string\",\"use_when\":\"string\"}],\n"
            . "  \"metrics\":[{\"name\":\"string\",\"target\":\"string\",\"why\":\"string\"}],\n"
            . "  \"examples\":[{\"product\":\"string\",\"feature\":\"string\",\"takeaway\":\"string\"}],\n"
            . "  \"references\":[{\"title\":\"string\",\"source\":\"string\"}],\n"
            . "  \"action_items\":[\"string\", \"string\", \"string\"]\n"
            . "}\n"
            . "Be concrete, opinionated, and grounded in widely accepted practice. Avoid filler. Return ONLY JSON.";
    }

    /**
     * Try to extract a JSON object from a model response.
     */
    private function extractJson(string $content): ?array
    {
        $content = trim($content);
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/^```\s*$/', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);
        if (is_array($decoded)) return $decoded;

        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $content, $matches)) {
            foreach ($matches as $match) {
                $decoded = json_decode($match, true);
                if (is_array($decoded)) return $decoded;
            }
        }

        $start = strpos($content, '{');
        if ($start !== false) {
            $depth = 0;
            $end = -1;
            $len = strlen($content);
            for ($i = $start; $i < $len; $i++) {
                if ($content[$i] === '{') $depth++;
                elseif ($content[$i] === '}') {
                    $depth--;
                    if ($depth === 0) { $end = $i; break; }
                }
            }
            if ($end > $start) {
                $candidate = substr($content, $start, $end - $start + 1);
                $decoded = json_decode($candidate, true);
                if (is_array($decoded)) return $decoded;
            }
        }

        return null;
    }

    /**
     * Ensure the response conforms to the documented schema and fill defaults.
     */
    private function normalizeResult(array $data, array $preset, string $topic, string $scope): array
    {
        $defaults = [
            'title' => $preset['title'] . ': ' . $topic,
            'summary' => '',
            'key_principles' => [],
            'do_list' => [],
            'dont_list' => [],
            'patterns' => [],
            'metrics' => [],
            'examples' => [],
            'references' => [],
            'action_items' => [],
        ];

        $merged = array_merge($defaults, array_intersect_key($data, $defaults));

        // Normalize list-of-strings and list-of-objects.
        $merged['key_principles'] = array_values(array_filter(array_map('strval', (array) ($merged['key_principles'] ?? []))));
        $merged['do_list'] = array_values(array_filter(array_map('strval', (array) ($merged['do_list'] ?? []))));
        $merged['dont_list'] = array_values(array_filter(array_map('strval', (array) ($merged['dont_list'] ?? []))));
        $merged['action_items'] = array_values(array_filter(array_map('strval', (array) ($merged['action_items'] ?? []))));

        $patterns = [];
        foreach ((array) ($merged['patterns'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $patterns[] = [
                'name' => (string) ($p['name'] ?? ''),
                'description' => (string) ($p['description'] ?? ''),
                'example' => (string) ($p['example'] ?? ''),
                'use_when' => (string) ($p['use_when'] ?? ''),
            ];
        }
        $merged['patterns'] = $patterns;

        $metrics = [];
        foreach ((array) ($merged['metrics'] ?? []) as $m) {
            if (!is_array($m)) continue;
            $metrics[] = [
                'name' => (string) ($m['name'] ?? ''),
                'target' => (string) ($m['target'] ?? ''),
                'why' => (string) ($m['why'] ?? ''),
            ];
        }
        $merged['metrics'] = $metrics;

        $examples = [];
        foreach ((array) ($merged['examples'] ?? []) as $e) {
            if (!is_array($e)) continue;
            $examples[] = [
                'product' => (string) ($e['product'] ?? ''),
                'feature' => (string) ($e['feature'] ?? ''),
                'takeaway' => (string) ($e['takeaway'] ?? ''),
            ];
        }
        $merged['examples'] = $examples;

        $references = [];
        foreach ((array) ($merged['references'] ?? []) as $r) {
            if (!is_array($r)) {
                $references[] = ['title' => (string) $r, 'source' => ''];
                continue;
            }
            $references[] = [
                'title' => (string) ($r['title'] ?? ''),
                'source' => (string) ($r['source'] ?? ''),
            ];
        }
        $merged['references'] = $references;

        $merged['scope'] = $scope;
        $merged['category'] = $preset['category'];

        return $merged;
    }

    /**
     * Deterministic fallback research payload when the AI is unavailable.
     */
    private function fallbackResult(string $topic, string $scope, array $preset): array
    {
        return [
            'title' => $preset['title'] . ': ' . $topic,
            'scope' => $scope,
            'category' => $preset['category'],
            'summary' => "This brief covers {$preset['description']} for '{$topic}', grounded in widely adopted practice and current industry standards.",
            'key_principles' => [
                'Lead with the user — base every decision on validated user needs.',
                'Design for clarity — minimize cognitive load and decision friction.',
                'Build with consistency — apply design tokens and a shared system.',
                'Iterate with evidence — measure outcomes, not just outputs.',
            ],
            'do_list' => [
                'Provide a clear visual hierarchy on every screen',
                'Use accessible color contrast (WCAG AA minimum)',
                'Test with real users before shipping changes',
                'Document patterns in a living design system',
            ],
            'dont_list' => [
                'Rely on color alone to convey meaning',
                'Hide primary actions behind nested menus',
                'Invent new interaction patterns for common tasks',
                'Ship UI without keyboard navigation support',
            ],
            'patterns' => [
                [
                    'name' => 'Progressive disclosure',
                    'description' => 'Reveal advanced options only when the user needs them.',
                    'example' => 'Advanced filters collapsed behind a toggle',
                    'use_when' => 'Reducing first-time complexity in dense screens',
                ],
                [
                    'name' => 'Inline validation',
                    'description' => 'Show validation feedback as the user types, not only on submit.',
                    'example' => 'Password strength meter under the input',
                    'use_when' => 'Form-heavy flows with high error cost',
                ],
            ],
            'metrics' => [
                ['name' => 'Task success rate', 'target' => '≥ 90%', 'why' => 'Core flow usability'],
                ['name' => 'Time to first action', 'target' => '≤ 30s', 'why' => 'Onboarding effectiveness'],
                ['name' => 'Accessibility score', 'target' => '≥ 95', 'why' => 'Inclusive experience'],
            ],
            'examples' => [
                ['product' => 'Linear', 'feature' => 'Command palette', 'takeaway' => 'Power-user shortcuts without hiding the simple path'],
                ['product' => 'Stripe', 'feature' => 'Documentation UI', 'takeaway' => 'Inline API examples with copy-to-clipboard'],
            ],
            'references' => [
                ['title' => 'Nielsen Norman Group — 10 Usability Heuristics', 'source' => 'nngroup.com'],
                ['title' => 'WCAG 2.2 Quick Reference', 'source' => 'w3.org/WAI'],
            ],
            'action_items' => [
                'Audit the current screen against the WCAG 2.2 checklist',
                'Map user pain points in the relevant flow',
                'Draft a reusable component spec for the team',
            ],
        ];
    }

    /**
     * Call the OpenClaw gateway with the research prompt.
     */
    private function callOpenClaw(string $systemPrompt, string $userPrompt): ?string
    {
        try {
            $response = Http::timeout(180)->withHeaders([
                'Authorization' => 'Bearer ' . $this->openclawToken,
                'Content-Type' => 'application/json',
            ])->post($this->openclawUrl . '/v1/chat/completions', [
                'model' => 'openclaw',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.4,
                'max_tokens' => 2200,
            ]);
            if ($response->failed()) return null;
            return $response->json('choices.0.message.content', '');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Call Groq as a fallback provider.
     */
    private function callGroq(string $systemPrompt, string $userPrompt): ?string
    {
        try {
            $response = Http::timeout(60)->withHeaders([
                'Authorization' => 'Bearer ' . $this->groqKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $this->groqModel,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.4,
                'max_tokens' => 2200,
            ]);
            if ($response->failed()) return null;
            return $response->json('choices.0.message.content', '');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * POST /api/ai/research
     * Run structured AI research on a UX/UI/accessibility topic.
     */
    public function research(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $scopes = implode(',', array_keys($this->scopePresets));

        $v = Validator::make($request->all(), [
            'topic' => 'required|string|min:3|max:500',
            'scope' => "required|string|in:{$scopes}",
            'provider' => 'sometimes|in:openclaw,groq,auto',
            'context' => 'sometimes|array',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $topic = trim((string) $request->input('topic'));
        $scope = $request->input('scope');
        $provider = $request->input('provider', 'openclaw');
        $context = (array) $request->input('context', []);

        $preset = $this->scopePresets[$scope];

        $contextBlock = '';
        if (!empty($context)) {
            $contextBlock = "\n\nAdditional context from the user (use only where relevant):\n"
                . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $systemPrompt = $this->buildSystemPrompt($scope, $preset, $topic);
        $userPrompt = "Research topic: {$topic}\nScope: {$preset['title']}\nFocus areas: "
            . implode(', ', $preset['default_aspects']) . $contextBlock;

        $content = null;
        $usedProvider = $provider;
        $rawFallback = null;

        if ($provider === 'openclaw' || $provider === 'auto') {
            $content = $this->callOpenClaw($systemPrompt, $userPrompt);
            if ($content) {
                $usedProvider = 'openclaw';
            } elseif ($provider === 'auto') {
                $rawFallback = $this->callGroq($systemPrompt, $userPrompt);
                if ($rawFallback) {
                    $usedProvider = 'groq';
                    $content = $rawFallback;
                }
            }
        }

        if ($provider === 'groq' || ($usedProvider === 'groq' && $content === null)) {
            $content = $this->callGroq($systemPrompt, $userPrompt);
            $usedProvider = $content ? 'groq' : $usedProvider;
        }

        $result = null;
        if (is_string($content) && $content !== '') {
            $decoded = $this->extractJson($content);
            if (is_array($decoded)) {
                $result = $this->normalizeResult($decoded, $preset, $topic, $scope);
            }
        }

        $source = 'ai';
        if (!$result) {
            $result = $this->fallbackResult($topic, $scope, $preset);
            $source = 'fallback';
        }

        $record = [
            'id' => 'res_' . bin2hex(random_bytes(6)),
            'user_id' => (int) $user['id'],
            'topic' => $topic,
            'scope' => $scope,
            'category' => $preset['category'],
            'provider' => $usedProvider,
            'source' => $source,
            'result' => $result,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $history = $this->loadHistory((int) $user['id']);
        $history[] = $record;
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }
        $this->saveHistory((int) $user['id'], $history);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $record['id'],
                'topic' => $topic,
                'scope' => $scope,
                'scope_title' => $preset['title'],
                'category' => $preset['category'],
                'provider' => $usedProvider,
                'source' => $source,
                'created_at' => $record['created_at'],
                'findings' => $result,
            ],
        ]);
    }
}
