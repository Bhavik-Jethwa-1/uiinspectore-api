<?php

namespace App\Services\AI;

/**
 * VisionService — dedicated service for all image analysis tasks.
 */
class VisionService
{
    private \App\Services\AI\Providers\ProviderManager $manager;

    public function __construct(?\App\Services\AI\Providers\ProviderManager $manager = null)
    {
        $this->manager = $manager ?? new \App\Services\AI\Providers\ProviderManager();
    }

    public function analyze(string $imageUrl, string $prompt, array $opts = []): array
    {
        $result = $this->manager->vision($imageUrl, $prompt, $opts);
        $result['capability'] = 'vision';
        return $result;
    }

    public function screenshotReview(string $imageUrl, array $opts = []): array
    {
        $prompt = $opts['prompt'] ?? 'Analyze this UI screenshot in detail. Identify usability issues, accessibility problems, layout issues, and suggest specific improvements.';
        return $this->analyze($imageUrl, $prompt, $opts);
    }

    public function uiAnalysis(string $imageUrl, array $opts = []): array
    {
        return $this->analyze($imageUrl, $opts['prompt'] ?? 'Analyze the UI design: layout, hierarchy, consistency, visual appeal.', $opts);
    }

    public function accessibilityReview(string $imageUrl, array $opts = []): array
    {
        return $this->analyze($imageUrl, $opts['prompt'] ?? 'Review this UI for accessibility issues: color contrast, text size, keyboard navigation, ARIA labels, WCAG compliance.', $opts);
    }

    public function uxAnalysis(string $imageUrl, array $opts = []): array
    {
        return $this->analyze($imageUrl, $opts['prompt'] ?? 'Analyze the UX: user flow, clarity, ease of use, pain points, improvement opportunities.', $opts);
    }

    public function typographyAnalysis(string $imageUrl, array $opts = []): array
    {
        return $this->analyze($imageUrl, $opts['prompt'] ?? 'Analyze the typography: font choices, sizes, weights, line height, readability.', $opts);
    }

    public function colorAnalysis(string $imageUrl, array $opts = []): array
    {
        return $this->analyze($imageUrl, $opts['prompt'] ?? 'Analyze the color palette: harmony, contrast, brand consistency, accessibility.', $opts);
    }

    public function isAvailable(): bool
    {
        foreach ($this->manager->listProviders() as $info) {
            if ($info['available'] && in_array('vision', $info['capabilities'] ?? [], true)) {
                return true;
            }
        }
        return false;
    }
}
