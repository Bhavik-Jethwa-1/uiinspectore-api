<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * AIEngine — Unified AI orchestration layer.
 *
 * SINGLE PROVIDER ONLY: OpenClaw/MiniMax.
 * All AI features route through AIService -> ProviderManager -> Active Provider.
 *
 * The engine:
 * 1. Auto-detects request type from prompt / explicit type
 * 2. Routes to AIService, which dispatches to the configured provider
 * 3. Returns structured, consistent responses
 * 4. Handles errors gracefully with meaningful messages
 * 5. Logs every operation to laravel.log for debugging
 *
 * Usage:
 *   $engine = new AIEngine();
 *   $result = $engine->execute(['type' => 'chat', 'messages' => [...]]);
 *   $result = $engine->execute(['prompt' => 'Explain Flexbox']); // auto-detects
 */
class AIEngine
{
    // Supported request types
    public const TYPE_CHAT       = 'chat';
    public const TYPE_IMAGE      = 'image';
    public const TYPE_ANALYZE    = 'analyze';
    public const TYPE_CODE       = 'code';
    public const TYPE_RESEARCH   = 'research';
    public const TYPE_REDESIGN   = 'redesign';
    public const TYPE_COPYWRITE  = 'copywrite';
    public const TYPE_CONSULT    = 'consult';

    // Valid types list
    public const TYPES = [
        self::TYPE_CHAT,
        self::TYPE_IMAGE,
        self::TYPE_ANALYZE,
        self::TYPE_CODE,
        self::TYPE_RESEARCH,
        self::TYPE_REDESIGN,
        self::TYPE_COPYWRITE,
        self::TYPE_CONSULT,
    ];

    // Capability → model (for debug logging)

    private AIService $service;

    public function __construct(?int $userId = null, ?string $userToken = null)
    {
        $this->service = new AIService();
    }

    // ─── Main Entry Point ─────────────────────────────────────────────────

    /**
     * Execute an AI request with auto-detection.
     *
     * @param array $request {
     *   type?: string,          // chat|image|analyze|code|research|redesign|copywrite|consult
     *   prompt?: string,       // text prompt (used for auto-detection)
     *   messages?: array,      // chat messages [{role, content}]
     *   image_url?: string,    // image to analyze
     *   screenshot_url?: string,// alias for image_url
     *   model?: string,        // optional model override
     *   ...                    // type-specific options
     * }
     *
     * @return array Structured response (never throws)
     */
    public function execute(array $request): array
    {
        $requestId = uniqid('engine_', true);
        $startTime = microtime(true);

        // ── 1. Determine request type (explicit or auto-detected) ───────────
        $type = $request['type'] ?? $this->detectType($request);
        $type = $this->normalizeType($type);

        if (!in_array($type, self::TYPES, true)) {
            return $this->error("Unknown request type: '{$type}'", $requestId, $startTime);
        }

        $capability = $this->typeToCapability($type);
        $model      = $this->service->diagnostic()['chat_model'] ?? 'dynamic';

        // ── DEBUG LOG: incoming request ─────────────────────────────────────────
        Log::info('ENGINE_REQUEST', [
            'request_id' => $requestId,
            'type'       => $type,
            'provider'   => $this->service->primaryProviderName(),
            'model'      => $model,
            'endpoint'   => $this->endpointFor($capability),
            'request_summary' => $this->summarizeRequest($request),
        ]);

        // ── 2. Dispatch to handler ─────────────────────────────────────────
        try {
            $result = match ($type) {
                self::TYPE_CHAT      => $this->handleChat($request),
                self::TYPE_IMAGE     => $this->handleImage($request),
                self::TYPE_ANALYZE   => $this->handleAnalyze($request),
                self::TYPE_CODE      => $this->handleCode($request),
                self::TYPE_RESEARCH  => $this->handleResearch($request),
                self::TYPE_REDESIGN  => $this->handleRedesign($request),
                self::TYPE_COPYWRITE => $this->handleCopywrite($request),
                self::TYPE_CONSULT   => $this->handleConsult($request),
                default              => $this->error("Unhandled type: {$type}", $requestId, $startTime),
            };

            if (isset($result['error'])) {
                $result['request_id'] = $requestId;
                return $result;
            }

            return $this->success($result, $type, $requestId, $startTime);

        } catch (\Throwable $e) {
            return $this->exception($e, $requestId, $startTime);
        }
    }

    // ─── Streaming ─────────────────────────────────────────────────────────

    /**
     * Stream a chat response. Returns a Generator.
     * Yields: ['delta' => string, 'done' => bool, 'error' => ?string]
     */
    public function streamChat(array $messages, array $opts = []): \Generator
    {
        $requestId = uniqid('stream_', true);

        Log::info('ENGINE_STREAM_REQUEST', [
            'request_id'   => $requestId,
            'type'         => 'chat',
            'provider'     => $this->service->primaryProviderName(),
            'model'        => $opts['model'] ?? $this->service->diagnostic()['chat_model'] ?? 'dynamic',
            'endpoint'     => $this->endpointFor('chat'),
            'msg_count'    => count($messages),
        ]);

        try {
            // Pass the model through if provided — provider will dispatch
            // vision requests automatically when image content is present.
            yield from $this->service->streamChat($messages, $opts);
        } catch (\Throwable $e) {
            Log::error('ENGINE_STREAM_ERROR', [
                'request_id' => $requestId,
                'provider'   => $this->service->primaryProviderName(),
                'error'      => $e->getMessage(),
            ]);
            yield ['delta' => '', 'done' => true, 'error' => $e->getMessage()];
        }
    }

    // ─── Capabilities ──────────────────────────────────────────────────────

    /**
     * Get the capabilities of all registered AI providers.
     */
    public function getCapabilities(): array
    {
        return [
            'chat'      => true,
            'image'     => true,
            'analyze'   => true,
            'code'      => true,
            'research'  => true,
            'streaming' => true,
            'vision'    => true,
        ];
    }

    // ─── Health ────────────────────────────────────────────────────────────

    public function health(): array
    {
        $result = $this->service->health();
        $diag   = $this->service->diagnostic();

        return [
            'healthy'  => ($result['status'] ?? 'unhealthy') === 'healthy',
            'provider' => $this->service->primaryProviderName(),
            'checks'   => [
                'chat'   => [
                    'status'   => $result['gateway']['status'] ?? 'unknown',
                    'model'    => $diag['chat_model'] ?? 'dynamic',
                    'endpoint' => $diag['chat_endpoint'] ?? null,
                ],
                'image'   => [
                    'status' => $result['image']['status'] ?? 'unknown',
                    'model'  => $diag['image_model'] ?? 'dynamic',
                ],
                'vision'  => [
                    'status' => $result['vision']['status'] ?? 'unknown',
                    'model'  => $diag['vision_model'] ?? 'dynamic',
                ],
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRIVATE: Type Detection
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Auto-detect request type from prompt / request content.
     */
    private function detectType(array $request): string
    {
        $prompt = strtolower($request['prompt'] ?? '');
        $last   = $request['messages'] ?? [];
        $last   = end($last);
        $text   = $prompt . ' ' . strtolower(is_array($last['content'] ?? null) ? '' : ($last['content'] ?? ''));

        // Explicit image attached
        if (!empty($request['image_url']) || !empty($request['screenshot_url'])) {
            // If it's a redesign request
            if (str_contains($text, 'redesign') || str_contains($text, 're-design')) {
                return self::TYPE_REDESIGN;
            }
            // If it's asking to generate/create something based on image
            if (str_contains($text, 'generate') && (str_contains($text, 'ui') || str_contains($text, 'page') || str_contains($text, 'design'))) {
                return self::TYPE_IMAGE;
            }
            // Default: analyze
            return self::TYPE_ANALYZE;
        }

        // Keyword-based detection from prompt
        $patterns = [
            self::TYPE_CODE => [
                'generate code', 'write code', 'create a', 'make a', 'build a',
                'react component', 'vue component', 'html page', 'css', 'javascript',
                'typescript', 'tailwind', 'laravel', 'php', 'function', 'class',
                'implement', 'component', 'login page', 'dashboard ui',
            ],
            self::TYPE_IMAGE => [
                'generate image', 'create image', 'draw', 'illustrate',
                'generate a', 'create a', 'design a', 'mockup', 'ui design',
                'landing page design', 'website design', 'mobile app ui',
                'dashboard design', 'saas design',
            ],
            self::TYPE_REDESIGN => [
                'redesign', 're-design', 'improve', 'refresh', 'modernize',
                'make it better', 'update the', 'new look',
            ],
            self::TYPE_RESEARCH => [
                'research', 'trends', 'best practices', 'compare', 'analysis of',
                'study', 'benchmark', 'industry',
            ],
            self::TYPE_COPYWRITE => [
                'copywriting', 'write copy', 'marketing', 'headline', 'cta',
                'button text', 'tagline', 'product description', 'ad copy',
            ],
            self::TYPE_ANALYZE => [
                'analyze', 'review', 'audit', 'critique', 'assess',
                'check this', 'look at this', 'review this', 'usability',
                'accessibility', 'ux review', 'ui review',
            ],
            self::TYPE_CONSULT => [
                'consult', 'advice', 'recommend', 'should i', 'what do you think',
                'help me decide', 'guidance', 'suggestion for',
            ],
        ];

        foreach ($patterns as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $type;
                }
            }
        }

        // Default: chat
        return self::TYPE_CHAT;
    }

    private function normalizeType(?string $type): string
    {
        if (!$type) return self::TYPE_CHAT;

        return match (strtolower(trim($type))) {
            'chat', 'message', 'ask', 'talk'             => self::TYPE_CHAT,
            'image', 'generate-image', 'text-to-image'  => self::TYPE_IMAGE,
            'analyze', 'analysis', 'audit', 'review'    => self::TYPE_ANALYZE,
            'code', 'codegen', 'generate-code', 'write-code' => self::TYPE_CODE,
            'research', 'study', 'benchmark'            => self::TYPE_RESEARCH,
            'redesign', 're-design', 'regenerate'       => self::TYPE_REDESIGN,
            'copywrite', 'copy', 'marketing'            => self::TYPE_COPYWRITE,
            'consult', 'advice', 'help'                 => self::TYPE_CONSULT,
            default                                       => self::TYPE_CHAT,
        };
    }

    private function typeToCapability(string $type): string
    {
        return match ($type) {
            self::TYPE_IMAGE     => 'image',
            self::TYPE_ANALYZE,
            self::TYPE_REDESIGN  => 'vision',
            default              => 'chat',
        };
    }

    private function endpointFor(string $capability): string
    {
        return $this->service->diagnostic()['chat_endpoint'] ?? 'dynamic';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRIVATE: Request Handlers (all route through AIService)
    // ═══════════════════════════════════════════════════════════════════════

    private function handleChat(array $request): array
    {
        $messages = $request['messages'] ?? [];
        if (empty($messages)) {
            $prompt = $request['prompt'] ?? '';
            if (!$prompt) {
                return ['error' => 'No prompt or messages provided'];
            }
            $messages = [['role' => 'user', 'content' => $prompt]];
        }

        // Conversational ChatGPT-style system persona
        $systemPrompt = "You are a friendly, knowledgeable AI assistant. You're helpful, conversational, and genuinely enjoy helping users solve problems. Reply in a natural, conversational tone — like you're chatting with a friend who happens to be an expert. Be direct and practical, not stiff or overly formal. When providing code or technical info, be clear and concise. When giving opinions or suggestions, be confident but not arrogant. Use casual connective phrases naturally — 'Sure thing!', 'Here's what I'd do...', 'No problem!', etc. Don't pad your answers or state the obvious. Just get to the point and be genuinely useful.";

        $opts = [
            'model'       => $request['model'] ?? null,
            'max_tokens'  => $request['max_tokens'] ?? 2000,
            'temperature' => $request['temperature'] ?? 0.7,
            'top_p'       => $request['top_p'] ?? null,
            'system'      => $systemPrompt,
        ];

        $result = $this->service->chat($messages, $opts);

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return [
            'type'    => self::TYPE_CHAT,
            'reply'   => $result['reply'] ?? '',
            'model'   => $result['model'] ?? 'minimax',
            'usage'   => $result['usage'] ?? [],
            'finish_reason' => $result['finish_reason'] ?? null,
        ];
    }

    private function handleImage(array $request): array
    {
        $prompt = $request['prompt'] ?? '';
        if (!$prompt) {
            return ['error' => 'Prompt is required for image generation'];
        }

        // Image generation goes through AIService with provider fallback.
        $result = $this->service->image($prompt, [
            'size'    => $request['size'] ?? '1024x1024',
            'n'       => min((int)($request['n'] ?? 1), 4),
            'timeout' => (int)($request['timeout'] ?? 120),
        ]);

        if (isset($result['error'])) {
            return [
                'error'  => $result['error'],
                'status' => $result['status'] ?? 500,
            ];
        }

        if (empty($result['images'])) {
            return ['error' => 'MiniMax returned no images'];
        }

        return [
            'type'   => self::TYPE_IMAGE,
            'images' => $result['images'],
            'model'  => $result['model'] ?? $this->service->diagnostic()['image_model'] ?? 'dynamic',
            'prompt' => $result['prompt'] ?? $prompt,
            'size'   => $result['size'] ?? $request['size'] ?? '1:1',
        ];
    }

    private function handleAnalyze(array $request): array
    {
        $imageUrl = $request['image_url'] ?? $request['screenshot_url'] ?? '';
        $prompt   = $request['prompt'] ?? '';
        $context  = $request['project_context'] ?? $request['context'] ?? '';

        if (!$imageUrl && !$prompt) {
            return ['error' => 'Either an image URL or a prompt is required'];
        }

        // Use AIService.vision() — dispatches to configured provider
        $analysisPrompt = $this->buildAnalysisPrompt($prompt, $context, (bool)$imageUrl);

        if ($imageUrl) {
            $result = $this->service->vision($imageUrl, $analysisPrompt, [
                'max_tokens'  => $request['max_tokens'] ?? 2000,
                'temperature' => $request['temperature'] ?? 0.3,
            ]);
        } else {
            $result = $this->service->chat(
                [['role' => 'user', 'content' => $analysisPrompt]],
                [
                    'max_tokens'  => $request['max_tokens'] ?? 2000,
                    'temperature' => $request['temperature'] ?? 0.3,
                ]
            );
        }

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return [
            'type'  => self::TYPE_ANALYZE,
            'reply' => $result['reply'] ?? '',
            'model' => $result['model'] ?? 'minimax',
            'usage' => $result['usage'] ?? [],
        ];
    }

    private function buildAnalysisPrompt(string $userPrompt, string $context, bool $hasImage): string
    {
        $base = $userPrompt ?: 'Analyze this UI design for me.';

        $prompt = "You are a sharp, approachable UI/UX expert. Think of yourself as a trusted creative partner giving honest, useful feedback — not writing a formal audit report.\n\n";

        if ($userPrompt) {
            $prompt .= "The user wants you to focus on: {$userPrompt}\n\n";
        }

        if ($context) {
            $prompt .= "Context: {$context}\n\n";
        }

        $prompt .= "Give your thoughts naturally, like you're chatting through what works and what could be better. Cover layout, typography, colors, spacing, accessibility, and any UX friction you spot. Be specific and actionable — don't just say 'improve spacing', explain why and how. If something is genuinely great, say so! \n";

        if ($hasImage) {
            $prompt .= "\n[The image is attached for you to analyze]";
        }

        return $prompt;
    }

    private function handleCode(array $request): array
    {
        $prompt = $request['prompt'] ?? '';
        if (!$prompt) {
            return ['error' => 'Prompt is required for code generation'];
        }

        $framework = $request['framework'] ?? '';
        $codePrompt = "Hey! I need some {$framework} code for: {$prompt}\n\n";
        $codePrompt .= "Can you generate clean, modern, production-ready code? Here's what I'm looking for:\n";
        $codePrompt .= "- {$framework} best practices — make it something I'd actually ship\n";
        $codePrompt .= "- All necessary imports included\n";

        if ($framework === 'react' || $framework === 'nextjs') {
            $codePrompt .= "- Functional components with hooks where it makes sense\n";
            $codePrompt .= "- Tailwind CSS for styling (keep it clean and readable)\n";
        }

        $codePrompt .= "\nKeep it practical, readable, and something a fellow developer would be happy to work with. No placeholder comments or TODO's unless specifically asked for.\n";

        $result = $this->service->chat(
            [['role' => 'user', 'content' => $codePrompt]],
            [
                'max_tokens'  => $request['max_tokens'] ?? 3000,
                'temperature' => $request['temperature'] ?? 0.2,
            ]
        );

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return [
            'type'     => self::TYPE_CODE,
            'reply'    => $result['reply'] ?? '',
            'model'    => $result['model'] ?? 'minimax',
            'usage'    => $result['usage'] ?? [],
            'language' => $framework,
        ];
    }

    private function handleResearch(array $request): array
    {
        $topic = $request['topic'] ?? $request['prompt'] ?? '';
        if (!$topic) {
            return ['error' => 'Topic is required for research'];
        }

        $niche = $request['niche'] ?? '';
        $prompt = "Research brief: {$topic}\n";
        if ($niche) {
            $prompt .= "Context: {$niche}\n";
        }
        $prompt .= "\nProvide a structured response covering:\n";
        $prompt .= "1. Current trends (3-5 bullet points, most important first)\n";
        $prompt .= "2. What's working well (specific examples)\n";
        $prompt .= "3. Competitive landscape (who's leading and why)\n";
        $prompt .= "4. Practical recommendations (numbered list, most impactful first)\n";
        $prompt .= "Be direct and concise. No preamble or 'Based on my knowledge' phrases.\n";

        $result = $this->service->chat(
            [['role' => 'user', 'content' => $prompt]],
            [
                'max_tokens'  => $request['max_tokens'] ?? 2000,
                'temperature' => $request['temperature'] ?? 0.5,
            ]
        );

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return [
            'type'    => self::TYPE_RESEARCH,
            'reply'   => $result['reply'] ?? '',
            'topic'   => $topic,
            'model'   => $result['model'] ?? 'minimax',
            'usage'   => $result['usage'] ?? [],
        ];
    }

    private function handleRedesign(array $request): array
    {
        $imageUrl = $request['image_url'] ?? $request['screenshot_url'] ?? '';
        $style    = $request['style'] ?? 'modern-saas';
        $context  = $request['project_context'] ?? '';
        $prompt   = $request['prompt'] ?? '';

        // Build redesign prompt
        $redesignPrompt = "Redesign this UI with a {$style} style.\n";
        if ($prompt) {
            $redesignPrompt .= "Requirements: {$prompt}\n";
        }
        if ($context) {
            $redesignPrompt .= "Context: {$context}\n";
        }
        $redesignPrompt .= "Focus on: Modern aesthetics, better UX, improved visual hierarchy.\n";

        if (!$imageUrl) {
            // Text-to-image redesign — image() is the only image-generation path
            $imagePrompt = $this->buildRedesignImagePrompt($style, $prompt, $context);
            return $this->handleImage([
                'prompt' => $imagePrompt,
                'size'   => $request['size'] ?? '1024x1024',
                'model'  => $request['model'] ?? $this->service->diagnostic()['image_model'] ?? 'dynamic',
            ]);
        }

        // Image-to-image redesign — use vision to analyze + image() to generate redesign
        $visionResult = $this->service->vision($imageUrl, $redesignPrompt, [
            'max_tokens'  => 2000,
            'temperature' => 0.5,
        ]);

        // Always generate a redesign image (text-to-image uses style; image-to-image uses analyzed context)
        $imagePrompt = $this->buildRedesignImagePrompt($style, $prompt, $context);
        $imageResult = $this->handleImage([
            'prompt' => $imagePrompt,
            'size'   => $request['size'] ?? '1024x1024',
            'model'  => $request['model'] ?? $this->service->diagnostic()['image_model'] ?? 'dynamic',
        ]);

        // If image generation failed, return vision text + error
        if (isset($imageResult['error']) && empty($imageResult['images'])) {
            return [
                'type'  => self::TYPE_REDESIGN,
                'reply' => $visionResult['reply'] ?? '',
                'model' => $visionResult['model'] ?? 'minimax',
                'error' => $imageResult['error'] ?? 'Image generation failed',
                'usage' => array_merge($visionResult['usage'] ?? [], $imageResult['usage'] ?? []),
            ];
        }

        return [
            'type'     => self::TYPE_REDESIGN,
            'reply'    => $visionResult['reply'] ?? '',
            'model'    => $visionResult['model'] ?? 'minimax',
            'images'   => $imageResult['images'] ?? [],
            'image_model' => $imageResult['model'] ?? $this->service->diagnostic()['image_model'] ?? 'dynamic',
            'usage'    => array_merge($visionResult['usage'] ?? [], $imageResult['usage'] ?? []),
        ];
    }

    private function buildRedesignImagePrompt(string $style, string $requirements, string $context): string
    {
        $styleDescriptions = [
            'modern-saas'    => 'Modern SaaS dashboard with clean whites, purple accent #7c5cff, card-based layout, subtle shadows, professional typography, high-fidelity UI mockup, 4K render',
            'minimal'        => 'Minimalist UI design with ample whitespace, clean sans-serif typography, monochromatic palette, subtle micro-interactions, premium feel, 4K render',
            'enterprise'     => 'Enterprise B2B SaaS interface with professional blue tones, data-dense layouts, clear hierarchy, reliable and trustworthy aesthetic, 4K render',
            'apple'          => 'Apple-inspired design with SF-style typography, generous spacing, frosted glass effects, smooth animations, premium iOS/macOS aesthetic, 4K render',
            'material'       => 'Material Design 3 UI with elevated surfaces, rounded corners, vibrant accent colors, cohesive color system, modern Google aesthetic, 4K render',
            'dark'           => 'Dark mode SaaS dashboard with deep navy/black backgrounds, vibrant accent colors, glassmorphism effects, modern tech aesthetic, 4K render',
            'glassmorphism'  => 'Glassmorphism UI with frosted glass cards, gradient backgrounds, blur effects, translucent overlays, modern and sleek aesthetic, 4K render',
        ];

        $styleDesc = $styleDescriptions[$style] ?? $styleDescriptions['modern-saas'];
        $req = $requirements ? " Additional requirements: {$requirements}" : '';
        $ctx = $context ? " Context: {$context}" : '';

        return "{$styleDesc}{$req}{$ctx}, 4K ultra-detailed UI mockup";
    }

    private function handleCopywrite(array $request): array
    {
        $prompt  = $request['prompt'] ?? '';
        $type    = $request['type'] ?? 'landing-page';
        $tone    = $request['tone'] ?? 'modern';
        $context = $request['product_context'] ?? '';

        if (!$prompt && !$context) {
            return ['error' => 'Prompt or product context is required'];
        }

        $product = $prompt ?: $context;

        $copyPrompt = "Write {$tone} {$type} copy for this product. Output ONLY the copy — no intros, no suggestions, no 'here's what I wrote', no advice about building or coding. Pure copy only.

";
        $copyPrompt .= "Product: {$product}

";
        $copyPrompt .= "Format your output as:
";
        $copyPrompt .= "HEADLINE: [compelling one-liner that stops scroll]
";
        $copyPrompt .= "SUBHEADLINE: [one line expanding the value proposition]
";
        $copyPrompt .= "BODY: [2-3 short paragraphs. Conversational, specific, human. No generic marketing buzzwords.]
";
        $copyPrompt .= "CTA: [3-5 words, action-driven button text]
";
        $copyPrompt .= "FAQ:
- [Q and A about the product]
- [Q and A about the product]
- [Q and A about the product]
";

        $result = $this->service->chat(
            [['role' => 'user', 'content' => $copyPrompt]],
            [
                'max_tokens'  => $request['max_tokens'] ?? 2000,
                'temperature' => $request['temperature'] ?? 0.6,
            ]
        );

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return [
            'type'  => self::TYPE_COPYWRITE,
            'reply' => $result['reply'] ?? '',
            'model' => $result['model'] ?? 'minimax',
            'usage' => $result['usage'] ?? [],
        ];
    }

    private function handleConsult(array $request): array
    {
        $question = $request['question'] ?? $request['prompt'] ?? '';
        $context  = $request['context'] ?? '';

        if (!$question) {
            return ['error' => 'Question is required'];
        }

        $consultPrompt = "UX/UI consultation question: {$question}\n";
        if ($context) {
            $consultPrompt .= "Context: {$context}\n";
        }
        $consultPrompt .= "\nGive specific, actionable recommendations. Structure:\n";
        $consultPrompt .= "1. My assessment (2-3 sentences max)\n";
        $consultPrompt .= "2. Top recommendations (numbered, most important first)\n";
        $consultPrompt .= "3. Tradeoffs to consider (if any)\n";
        $consultPrompt .= "Be direct. No hedged answers like 'it depends' without explaining what it depends on.\n";

        $result = $this->service->chat(
            [['role' => 'user', 'content' => $consultPrompt]],
            [
                'max_tokens'  => $request['max_tokens'] ?? 2000,
                'temperature' => $request['temperature'] ?? 0.5,
            ]
        );

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return [
            'type'  => self::TYPE_CONSULT,
            'reply' => $result['reply'] ?? '',
            'model' => $result['model'] ?? 'minimax',
            'usage' => $result['usage'] ?? [],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRIVATE: Response Formatting
    // ═══════════════════════════════════════════════════════════════════════

    private function success(array $result, string $type, string $requestId, float $startTime): array
    {
        $duration = round((microtime(true) - $startTime) * 1000, 1);

        Log::info('ENGINE_OK', [
            'request_id'  => $requestId,
            'type'        => $type,
            'provider'    => $this->service->primaryProviderName(),
            'duration_ms' => $duration,
        ]);

        return array_merge([
            'success'    => true,
            'type'       => $type,
            'provider'   => $this->service->primaryProviderName(),
            'request_id' => $requestId,
            'duration_ms'=> $duration,
        ], $result);
    }

    private function error(string $message, string $requestId, float $startTime): array
    {
        $duration = round((microtime(true) - $startTime) * 1000, 1);

        Log::error('ENGINE_ERROR', [
            'request_id'  => $requestId,
            'message'     => $message,
            'provider'    => $this->service->primaryProviderName(),
            'duration_ms' => $duration,
        ]);

        return [
            'success'    => false,
            'error'      => $message,
            'provider'   => $this->service->primaryProviderName(),
            'request_id' => $requestId,
            'duration_ms'=> $duration,
        ];
    }

    private function exception(\Throwable $e, string $requestId, float $startTime): array
    {
        $duration = round((microtime(true) - $startTime) * 1000, 1);

        Log::error('ENGINE_EXCEPTION', [
            'request_id'  => $requestId,
            'exception'   => get_class($e),
            'message'     => $e->getMessage(),
            'file'        => $e->getFile(),
            'line'        => $e->getLine(),
            'trace'       => collect($e->getTrace())->take(3)->map(fn($t) => $t['file'] . ':' . $t['line'] . ' ' . ($t['function'] ?? '?'))->toArray(),
            'provider'    => $this->service->primaryProviderName(),
            'duration_ms' => $duration,
        ]);

        return [
            'success'    => false,
            'error'      => 'An internal error occurred: ' . $e->getMessage(),
            'provider'   => $this->service->primaryProviderName(),
            'request_id' => $requestId,
            'duration_ms'=> $duration,
        ];
    }

    /**
     * Compact summary of an incoming request for debug logging.
     */
    private function summarizeRequest(array $request): array
    {
        $summary = [
            'type' => $request['type'] ?? null,
            'prompt_preview' => isset($request['prompt']) ? mb_substr((string)$request['prompt'], 0, 120) : null,
            'has_image' => !empty($request['image_url']) || !empty($request['screenshot_url']),
            'image_url_preview' => isset($request['image_url']) ? mb_substr((string)$request['image_url'], 0, 80) : null,
            'msg_count' => isset($request['messages']) && is_array($request['messages']) ? count($request['messages']) : 0,
            'model' => $request['model'] ?? null,
        ];
        return $summary;
    }
}
