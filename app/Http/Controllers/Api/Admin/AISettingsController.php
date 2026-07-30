<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AI\AIService;
use App\Services\AI\Providers\ProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Admin AISettingsController — manage AI providers centrally.
 *
 * Routes (all require api.auth + admin):
 *   GET    /api/admin/ai/settings        → current settings (no secrets)
 *   PUT    /api/admin/ai/settings        → update settings
 *   POST   /api/admin/ai/test-connection → test an OpenAI key
 *   GET    /api/admin/ai/providers       → list providers + status
 *
 * Settings file: storage/app/ai_settings.json
 *   {
 *     "primary": "minimax",
 *     "openai":  { "model": "gpt-4o", "isActive": false },
 *     "minimax": { "isActive": true }
 *   }
 *
 * IMPORTANT: API keys are NEVER written to ai_settings.json and NEVER
 * returned in API responses. They live in .env only. The frontend can
 * SET them via .env-update through the dedicated endpoint below.
 */
class AISettingsController extends Controller
{
    private ProviderManager $manager;

    public function __construct()
    {
        $this->manager = new ProviderManager();
    }

    // ─── GET /api/admin/ai/settings ─────────────────────────────────────────

    public function show(Request $request): JsonResponse
    {
        $settings = $this->manager->getSettings();

        return response()->json([
            'success'     => true,
            'settings'    => $settings,
            'has_openai'  => $this->hasOpenAIKey(),
            'has_minimax' => $this->hasMiniMaxKey(),
            'has_gemini'  => $this->hasGeminiKey(),
            'any_available' => (new AIService())->anyAvailable(),
        ]);
    }

    // ─── PUT /api/admin/ai/settings ─────────────────────────────────────────

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'primary'                  => 'nullable|string|in:openai,minimax,gemini',
            'openai.model'             => 'nullable|string|max:120',
            'openai.isActive'          => 'nullable|boolean',
            'openai.apiKey'            => 'nullable|string|max:300',
            'minimax.isActive'         => 'nullable|boolean',
            'gemini.model'             => 'nullable|string|max:120',
            'gemini.isActive'          => 'nullable|boolean',
            'gemini.apiKey'            => 'nullable|string|max:300',
        ]);

        // API key handling — NEVER persist in ai_settings.json
        if (!empty($data['openai']['apiKey'])) {
            $written = $this->updateEnvFile('OPENAI_API_KEY', $data['openai']['apiKey']);
            if (!$written) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Failed to persist OpenAI API key to .env (file may be read-only)',
                ], 500);
            }
            $this->reloadEnvKey('OPENAI_API_KEY', $data['openai']['apiKey']);
            unset($data['openai']['apiKey']);
        }
        if (!empty($data['gemini']['apiKey'])) {
            $written = $this->updateEnvFile('GEMINI_API_KEY', $data['gemini']['apiKey']);
            if (!$written) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Failed to persist Gemini API key to .env (file may be read-only)',
                ], 500);
            }
            $this->reloadEnvKey('GEMINI_API_KEY', $data['gemini']['apiKey']);
            unset($data['gemini']['apiKey']);
        }

        // Sanitize: strip any other key fields before persisting
        unset($data['openai']['apiKey']);
        unset($data['gemini']['apiKey']);

        $this->manager->updateSettings($data);

        return response()->json([
            'success'  => true,
            'settings' => $this->manager->getSettings(),
            'message'  => 'AI settings updated successfully',
        ]);
    }

    // ─── POST /api/admin/ai/test-connection ─────────────────────────────────

    public function testConnection(Request $request): JsonResponse
    {
        $request->validate([
            'apiKey' => 'required|string|max:300',
            'model'  => 'nullable|string|max:120',
        ]);

        $apiKey = $request->input('apiKey');
        $model  = $request->input('model') ?: env('OPENAI_MODEL', 'gpt-4o');

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'       => $model,
                    'messages'    => [
                        ['role' => 'system', 'content' => 'You are a connectivity test.'],
                        ['role' => 'user',   'content' => 'Reply with the single word: pong'],
                    ],
                    'max_tokens'  => 10,
                    'temperature' => 0,
                ]);

            if (!$response->successful()) {
                $err = $response->json();
                $msg = $err['error']['message'] ?? "HTTP {$response->status()}";
                return response()->json([
                    'success' => false,
                    'error'   => $msg,
                    'status'  => $response->status(),
                ], $response->status() === 401 ? 401 : 400);
            }

            $data = $response->json();
            return response()->json([
                'success' => true,
                'model'   => $data['model'] ?? $model,
                'message' => 'Connection successful',
            ]);
        } catch (\Throwable $e) {
            Log::error('OPENAI_TEST_CONNECTION_FAILED', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'error'   => 'Connection failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─── GET /api/admin/ai/providers ────────────────────────────────────────

    public function providers(Request $request): JsonResponse
    {
        $providers = $this->manager->listProviders();
        $primary   = $this->manager->getPrimary();

        $out = [];
        foreach ($providers as $name => $info) {
            $out[$name] = [
                'name'       => $name,
                'label'      => $info['label'],
                'slug'       => $name,
                'available'  => $info['available'],
                'isPrimary'  => $info['isPrimary'],
                'model'      => $info['model'],
                'isActive'   => $this->isProviderActive($name),
            ];
        }

        return response()->json([
            'success'  => true,
            'providers'=> $out,
            'primary'  => $primary ? $primary->providerName() : null,
            'has_openai'  => $this->hasOpenAIKey(),
            'has_minimax' => $this->hasMiniMaxKey(),
            'has_gemini'  => $this->hasGeminiKey(),
        ]);
    }

    // ───────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ───────────────────────────────────────────────────────────────────────

    private function hasOpenAIKey(): bool
    {
        $key = (string) env('OPENAI_API_KEY', '');
        return $key !== '' && trim($key) !== '';
    }

    private function hasMiniMaxKey(): bool
    {
        $key = (string) env('MINIMAX_API_KEY', '');
        return $key !== '' && trim($key) !== '';
    }

    private function hasGeminiKey(): bool
    {
        $key = (string) env('GEMINI_API_KEY', '');
        return $key !== '' && trim($key) !== '';
    }

    private function isProviderActive(string $name): bool
    {
        $settings = $this->manager->getSettings();
        return (bool) ($settings[$name]['isActive'] ?? false);
    }

    /**
     * Update a single key in the .env file.
     *
     * IMPORTANT: This is best-effort. Returns false if the file is not writable.
     */
    private function updateEnvFile(string $key, string $value): bool
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath) || !is_writable($envPath)) {
            return false;
        }

        $content = file_get_contents($envPath);
        $escaped = $this->escapeEnvValue($value);

        $pattern = "/^{$key}=.*$/m";

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, "{$key}={$escaped}", $content);
        } else {
            // Append at end
            $content = rtrim($content) . "\n{$key}={$escaped}\n";
        }

        return file_put_contents($envPath, $content) !== false;
    }

    private function escapeEnvValue(string $value): string
    {
        // Quote if value contains spaces or special chars
        if (preg_match('/\s|"|\'|#/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }
        return $value;
    }

    /**
     * Update the in-memory $_ENV / env() so the current request sees the
     * new key without requiring a php artisan config:cache.
     */
    private function reloadEnvKey(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }
}