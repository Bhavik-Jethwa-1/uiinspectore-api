<?php

namespace App\Services\AI\Providers;

/**
 * Interface for AI provider adapters.
 *
 * Each provider implements the specific capabilities it supports.
 * Use capabilities() to discover what a provider can do.
 */
interface AIProvider
{
    // ─── Identity ────────────────────────────────────────────────────────────

    public function providerName(): string;
    public function providerLabel(): string;

    /**
     * Returns the capabilities this provider implements.
     * e.g. ['chat', 'vision', 'image', 'code']
     */
    public function capabilities(): array;

    public function isAvailable(): bool;

    // ─── Capabilities ─────────────────────────────────────────────────────────

    /**
     * Send a chat completion request.
     *
     * @param array $messages  [['role' => 'user'|'system'|'assistant', 'content' => string], ...]
     * @param array $opts      Optional: model, temperature, max_tokens, top_p, stop, system
     * @return array          Standardized: ['reply'=>string, 'error'=>string, 'model'=>string,
     *                          'usage'=>array, 'finish_reason'=>string, 'provider'=>string]
     */
    public function chat(array $messages, array $opts = []): array;

    /**
     * Streaming chat — yields SSE chunks as a Generator.
     *
     * @yield array  ['delta'=>string, 'done'=>bool, 'reply'=>string,
     *                'error'=>string, 'finish_reason'=>string]
     */
    public function streamChat(array $messages, array $opts = []): \Generator;

    /**
     * Analyze an image using vision.
     *
     * @param string $imageUrl  URL or local path of the image
     * @param string $prompt    Text prompt
     * @param array  $opts      Optional: model, max_tokens
     * @return array           ['reply'=>string, 'error'=>string, 'model'=>string, 'provider'=>string]
     */
    public function vision(string $imageUrl, string $prompt, array $opts = []): array;

    /**
     * Generate an image.
     *
     * @param string $prompt  Description of the image
     * @param array  $opts    Optional: size, n (count), model, style
     * @return array          ['images'=>[string], 'error'=>string, 'model'=>string,
     *                          'provider'=>string, 'size'=>string]
     */
    public function image(string $prompt, array $opts = []): array;

    /**
     * Generate code using a code-specialized model or prompt.
     *
     * @param array $messages  [['role' => ..., 'content' => ...], ...]
     *                          First message should be a system prompt defining the task.
     * @param array $opts      Optional: model, language ('react','nextjs','vue','tailwind',
     *                          'html','css','php','python','javascript','typescript'),
     *                          temperature, max_tokens
     * @return array           ['reply'=>string, 'error'=>string, 'model'=>string,
     *                          'language'=>string, 'provider'=>string]
     */
    public function code(array $messages, array $opts = []): array;
}
