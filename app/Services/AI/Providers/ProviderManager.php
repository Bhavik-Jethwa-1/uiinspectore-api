<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Cache;

class ProviderManager
{
    private array $providers = [];
    private string $primaryName;
    private array $settings;

    // Singleton instance for reuse across requests
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->registerProvider(new OpenAIProvider());
        $this->registerProvider(new MiniMaxProvider());
        $this->registerProvider(new GeminiProvider());
        $this->settings = $this->loadSettings();
        $this->primaryName = $this->settings['primary'] ?? 'minimax';
    }

    public function registerProvider(AIProvider $provider): void
    {
        $this->providers[$provider->providerName()] = $provider;
    }

    public function getProvider(?string $name = null): ?AIProvider
    {
        $name = $name ?? $this->primaryName;
        return $this->providers[$name] ?? null;
    }

    /**
     * List all registered providers with their capabilities.
     *
     * @return array [
     *   slug => [
     *     'name'        => string,
     *     'label'      => string,
     *     'available'   => bool,
     *     'isPrimary'  => bool,
     *     'model'      => string,
     *     'capabilities' => string[],
     *   ],
     *   ...
     * ]
     */
    public function listProviders(): array
    {
        $result = [];
        foreach ($this->providers as $name => $provider) {
            $result[$name] = [
                'name'         => $name,
                'label'        => $provider->providerLabel(),
                'available'    => $provider->isAvailable(),
                'isPrimary'    => $name === $this->primaryName,
                'model'        => $this->getProviderModel($name),
                'capabilities' => $provider->capabilities(),
            ];
        }
        return $result;
    }

    public function getPrimary(): ?AIProvider
    {
        return $this->getProvider($this->primaryName);
    }

    public function primaryName(): string
    {
        return $this->primaryName;
    }

    public function setPrimary(string $name): void
    {
        if (!isset($this->providers[$name])) {
            throw new \InvalidArgumentException("Unknown provider: {$name}");
        }
        $this->primaryName = $name;
        $this->settings['primary'] = $name;
        $this->saveSettings();
    }

    public function getSettings(): array
    {
        return [
            'primary' => $this->primaryName,
            'openai'  => [
                'model'    => env('OPENAI_MODEL', 'gpt-4o'),
                'isActive' => $this->settings['openai']['isActive']
                              ?? ($this->providers['openai']->isAvailable()),
            ],
            'minimax' => [
                'isActive' => $this->settings['minimax']['isActive']
                              ?? ($this->providers['minimax']->isAvailable()),
            ],
        ];
    }

    public function updateSettings(array $settings): void
    {
        if (isset($settings['primary'])) {
            $this->setPrimary($settings['primary']);
        }
        if (isset($settings['openai'])) {
            $this->settings['openai'] = array_merge($this->settings['openai'] ?? [], $settings['openai']);
        }
        if (isset($settings['minimax'])) {
            $this->settings['minimax'] = array_merge($this->settings['minimax'] ?? [], $settings['minimax']);
        }
        $this->saveSettings();
    }

    // ─── Capability-specific dispatch ─────────────────────────────────────────

    /**
     * Send a chat message through the primary provider.
     *
     * @param array $messages
     * @param array $opts
     * @return array
     */
    public function chat(array $messages, array $opts = []): array
    {
        return $this->dispatch('chat', $messages, $opts);
    }

    /**
     * Streaming chat — yields SSE chunks.
     *
     * @yield array
     */
    public function streamChat(array $messages, array $opts = []): \Generator
    {
        // Respect requested provider from opts, fall back to primary
        $requestedProvider = $opts['provider'] ?? null;
        $provider = null;

        if ($requestedProvider) {
            $provider = $this->getProvider($requestedProvider);
        }

        if (!$provider || !$provider->isAvailable()) {
            // Try primary
            $provider = $this->getPrimary();
        }

        if (!$provider || !$provider->isAvailable()) {
            // Try any available provider
            foreach ($this->listProviders() as $name => $info) {
                if (!$info['available']) continue;
                $p = $this->getProvider($name);
                if ($p && $p->isAvailable()) { $provider = $p; break; }
            }
        }

        if (!$provider || !$provider->isAvailable()) {
            yield ['delta' => '', 'done' => true, 'error' => 'No AI provider available'];
            return;
        }

        if (method_exists($provider, 'streamChat')) {
            yield from $provider->streamChat($messages, $opts);
            return;
        }

        $result = $provider->chat($messages, $opts);
        if (!empty($result['error'])) {
            yield ['delta' => '', 'done' => true, 'error' => $result['error']];
            return;
        }

        $reply = $result['reply'] ?? '';
        foreach (mb_str_split($reply, 1) as $ch) {
            yield ['delta' => $ch, 'done' => false];
        }
        yield ['delta' => '', 'done' => true, 'reply' => $reply];
    }

    /**
     * Analyze an image using vision.
     *
     * @param string $imageUrl
     * @param string $prompt
     * @param array  $opts
     * @return array
     */
    public function vision(string $imageUrl, string $prompt, array $opts = []): array
    {
        return $this->dispatch('vision', $imageUrl, $opts, $prompt);
    }

    /**
     * Generate images.
     *
     * @param string $prompt
     * @param array  $opts
     * @return array
     */
    public function image(string $prompt, array $opts = []): array
    {
        return $this->dispatch('image', $prompt, $opts);
    }

    /**
     * Generate code.
     *
     * @param array $messages
     * @param array $opts
     * @return array
     */
    public function code(array $messages, array $opts = []): array
    {
        return $this->dispatch('code', $messages, $opts);
    }

    // ─── Private ───────────────────────────────────────────────────────────────

    private function dispatch(string $capability, ...$args): array
    {
        // Check if a specific provider was requested via opts (last arg)
        $opts = is_array(end($args)) ? end($args) : [];
        $requestedProvider = $opts['provider'] ?? null;

        $order = $this->resolveProviderOrder();

        // If a specific provider is requested, try it first
        if ($requestedProvider) {
            array_unshift($order, $requestedProvider);
            $order = array_unique($order);
        }

        if (empty($order)) {
            return ['error' => 'No AI providers available', 'status' => 503];
        }

        $lastResult = null;
        foreach ($order as $i => $providerName) {
            $provider = $this->getProvider($providerName);
            if (!$provider || !$provider->isAvailable()) {
                continue;
            }

            // Check if provider supports this capability
            if (!in_array($capability, $provider->capabilities(), true)) {
                continue;
            }

            try {
                $result = $this->callProvider($provider, $capability, $args);
            } catch (\Throwable $e) {
                Log::warning("ProviderManager: {$providerName} threw", ['capability' => $capability, 'error' => $e->getMessage()]);
                $lastResult = ['error' => $e->getMessage(), 'provider' => $providerName];
                if (!isset($order[$i + 1])) return $lastResult;
                continue;
            }

            if (empty($result['error'])) {
                $result['provider'] = $provider->providerName();
                return $result;
            }

            $lastResult = $result;
            if (!isset($order[$i + 1])) {
                return $lastResult;
            }
        }

        return $lastResult ?? ['error' => "No provider supports {$capability}", 'status' => 503];
    }

    private function resolveProviderOrder(): array
    {
        $all = $this->listProviders();
        $primary = $this->primaryName;

        $order = [];
        if (isset($all[$primary])) {
            $order[] = $primary;
        }
        foreach (array_keys($all) as $name) {
            if (!in_array($name, $order, true)) {
                $order[] = $name;
            }
        }
        return $order;
    }

    private function callProvider(AIProvider $provider, string $capability, array $args): array
    {
        try {
            return match ($capability) {
                'chat'   => $provider->chat($args[0] ?? [], $args[1] ?? []),
                'vision' => $provider->vision($args[0] ?? '', $args[2] ?? '', $args[1] ?? []),
                'image'  => $provider->image($args[0] ?? '', $args[1] ?? []),
                'code'   => $provider->code($args[0] ?? [], $args[1] ?? []),
                default  => ['error' => "Unknown capability: {$capability}", 'status' => 500],
            };
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), 'status' => 500];
        }
    }

    private function getProviderModel(string $name): string
    {
        return match ($name) {
            'openai'  => env('OPENAI_MODEL', 'gpt-4o'),
            'minimax' => 'MiniMax-M3',
            'gemini'  => env('GEMINI_MODEL', 'gemini-2.0-flash'),
            default   => 'unknown',
        };
    }

    private function loadSettings(): array
    {
        // Cache settings in memory for request lifetime
        static $cache = null;
        if ($cache !== null) return $cache;

        $path = base_path('storage/app/ai_settings.json');
        if (file_exists($path)) {
            $cache = json_decode(file_get_contents($path), true) ?? [];
        }
        $cache = $cache ?? [];
        return $cache;
    }

    private function saveSettings(): void
    {
        $dir = base_path('storage/app');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/ai_settings.json', json_encode($this->settings, JSON_PRETTY_PRINT));
    }
}
