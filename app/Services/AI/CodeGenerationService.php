<?php

namespace App\Services\AI;

/**
 * CodeGenerationService — dedicated service for AI code generation.
 */
class CodeGenerationService
{
    private \App\Services\AI\Providers\ProviderManager $manager;

    public function __construct(?\App\Services\AI\Providers\ProviderManager $manager = null)
    {
        $this->manager = $manager ?? new \App\Services\AI\Providers\ProviderManager();
    }

    public function generate(array $messages, array $opts = []): array
    {
        $result = $this->manager->code($messages, $opts);

        if (!empty($result['error']) && ($result['status'] ?? 0) === 503) {
            $result['error'] = 'Code generation is unavailable for the selected provider. '
                . 'Please configure an AI provider with code generation support in Admin Settings.';
        }

        $result['capability'] = 'code';
        return $result;
    }

    public function react(array $messages, array $opts = []): array
    {
        return $this->generate($messages, array_merge($opts, [
            'language' => 'react',
            'system'  => 'You are an expert React developer. Output ONLY complete, working React component code using functional components and hooks. No explanations, no markdown fences unless asked. Use Tailwind CSS for styling.',
        ]));
    }

    public function nextjs(array $messages, array $opts = []): array
    {
        return $this->generate($messages, array_merge($opts, [
            'language' => 'nextjs',
            'system'  => 'You are an expert Next.js developer. Output ONLY complete, working Next.js page or component code. Use App Router (app/) conventions. No explanations, no markdown fences unless asked.',
        ]));
    }

    public function vue(array $messages, array $opts = []): array
    {
        return $this->generate($messages, array_merge($opts, [
            'language' => 'vue',
            'system'  => 'You are an expert Vue.js developer. Output ONLY complete, working Vue 3 component code using Composition API. No explanations, no markdown fences unless asked. Use Tailwind CSS for styling.',
        ]));
    }

    public function tailwind(array $messages, array $opts = []): array
    {
        return $this->generate($messages, array_merge($opts, [
            'language' => 'tailwind',
            'system'  => 'You are an expert Tailwind CSS developer. Output ONLY complete HTML with Tailwind utility classes. No explanations, no markdown fences unless asked.',
        ]));
    }

    public function html(array $messages, array $opts = []): array
    {
        return $this->generate($messages, array_merge($opts, [
            'language' => 'html',
            'system'  => 'You are an expert HTML/CSS developer. Output ONLY complete, semantic HTML with embedded CSS. No frameworks, no markdown fences unless asked. Make it responsive and accessible.',
        ]));
    }

    public function css(array $messages, array $opts = []): array
    {
        return $this->generate($messages, array_merge($opts, [
            'language' => 'css',
            'system'  => 'You are an expert CSS developer. Output ONLY complete CSS code. No markdown fences unless asked. Include responsive breakpoints.',
        ]));
    }

    public function php(array $messages, array $opts = []): array
    {
        return $this->generate($messages, array_merge($opts, [
            'language' => 'php',
            'system'  => 'You are an expert PHP developer. Output ONLY complete, working PHP code. No explanations, no markdown fences unless asked.',
        ]));
    }

    public function python(array $messages, array $opts = []): array
    {
        return $this->generate($messages, array_merge($opts, [
            'language' => 'python',
            'system'  => 'You are an expert Python developer. Output ONLY complete, working Python code. No explanations, no markdown fences unless asked.',
        ]));
    }

    public function javascript(array $messages, array $opts = []): array
    {
        return $this->generate($messages, array_merge($opts, [
            'language' => 'javascript',
            'system'  => 'You are an expert JavaScript developer. Output ONLY complete, working JavaScript code. No explanations, no markdown fences unless asked.',
        ]));
    }

    public function typescript(array $messages, array $opts = []): array
    {
        return $this->generate($messages, array_merge($opts, [
            'language' => 'typescript',
            'system'  => 'You are an expert TypeScript developer. Output ONLY complete, type-safe TypeScript code. No explanations, no markdown fences unless asked.',
        ]));
    }

    public function isAvailable(): bool
    {
        foreach ($this->manager->listProviders() as $info) {
            if ($info['available'] && in_array('code', $info['capabilities'] ?? [], true)) {
                return true;
            }
        }
        return false;
    }
}
