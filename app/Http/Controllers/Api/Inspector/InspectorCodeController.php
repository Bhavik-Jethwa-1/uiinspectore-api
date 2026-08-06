<?php

namespace App\Http\Controllers\Api\Inspector;

use App\Http\Controllers\Controller;
use App\Models\Inspector\UiGeneratedCode;
use App\Models\Inspector\UiRedesign;
use App\Services\AI\Inspector\CodeGenerationService;
use App\Services\AI\VisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * InspectorCodeController — React + Tailwind code generation for UI redesigns.
 *
 * All methods return HTTP 200 with structured JSON.
 * HTTP 500/503 is NEVER returned for provider failures.
 */
class InspectorCodeController extends Controller
{
    public function __construct(
        private CodeGenerationService $codeService,
        private VisionService $visionService,
    ) {}

    // ─── Read ────────────────────────────────────────────────────────────────

    /**
     * GET /api/inspector/codes/{redesignId}
     */
    public function show($redesignId): JsonResponse
    {
        try {
            $redesign = UiRedesign::with('generatedCodes')->find((int) $redesignId);

            if (!$redesign) {
                return $this->json(404, false, 'Redesign not found');
            }

            $code = $redesign->generatedCodes()
                ->where('framework', 'react')
                ->orderByDesc('id')
                ->first();

            if (!$code) {
                return $this->json(404, false, 'No code generated yet for this redesign', [
                    'redesign_id' => (int) $redesignId,
                ]);
            }

            return $this->json(200, true, 'Code retrieved', [
                'code' => $this->formatCode($code),
                'redesign' => $this->formatRedesign($redesign),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e, 'show');
        }
    }

    // ─── Generate ───────────────────────────────────────────────────────────

    /**
     * POST /api/inspector/codes/{redesignId}/generate
     * Generate React + Tailwind code for a redesign.
     *
     * Returns HTTP 200 always. Success/failure is in the JSON body.
     */
    public function generate($redesignId, Request $request): JsonResponse
    {
        try {
            // ── Validate redesign ──────────────────────────────────────────
            $redesign = UiRedesign::with('screenshot')->find((int) $redesignId);

            if (!$redesign) {
                return $this->json(404, false, 'Redesign not found');
            }

            $designStyle = $request->input('design_style', $redesign->design_style ?? 'modern_saas');

            // ── Check provider availability (never 503 — just report in body) ──
            $providerCheck = $this->codeService->availability();
            if (!$providerCheck['ok']) {
                $this->log('warning', 'No code provider available', [
                    'redesign_id' => (int) $redesignId,
                    'reason' => $providerCheck['error'] ?? 'unknown',
                ]);

                return $this->json(200, false, 'No working AI provider is configured.', [
                    'status' => 'provider_unavailable',
                    'error_code' => 'CODE_SERVICE_UNAVAILABLE',
                    'provider_check' => $providerCheck,
                    'redesign_id' => (int) $redesignId,
                ]);
            }

            // ── Reserve code record ────────────────────────────────────────
            $code = UiGeneratedCode::updateOrCreate(
                ['ui_redesign_id' => $redesign->id, 'framework' => 'react'],
                ['status' => 'generating']
            );

            // ── Vision analysis ────────────────────────────────────────────
            $visionAnalysis = $redesign->vision_analysis;
            $screenshotPath = $redesign->original_image_path ?? $redesign->screenshot?->file_path;

            if (!$visionAnalysis) {
                $visionAvail = $this->visionService->availability();
                if (!$visionAvail['ok']) {
                    $code->update(['status' => 'failed']);

                    $this->log('warning', 'Vision service unavailable', [
                        'redesign_id' => (int) $redesignId,
                        'vision_check' => $visionAvail,
                    ]);

                    return $this->json(200, false, 'Vision analysis unavailable: no provider is configured.', [
                        'status' => 'provider_unavailable',
                        'error_code' => 'VISION_SERVICE_UNAVAILABLE',
                        'provider_check' => $visionAvail,
                        'redesign_id' => (int) $redesignId,
                        'code_id' => $code->id,
                    ]);
                }

                if (!$screenshotPath) {
                    $code->update(['status' => 'failed']);

                    return $this->json(200, false, 'No screenshot available for vision analysis.', [
                        'error_code' => 'NO_SCREENSHOT',
                        'redesign_id' => (int) $redesignId,
                        'code_id' => $code->id,
                    ]);
                }

                // Run vision analysis — VisionService auto-selects provider
                $analysis = $this->visionService->analyzeScreenshot($screenshotPath);
                if (!$analysis['success']) {
                    $code->update(['status' => 'failed']);

                    $this->log('error', 'Vision analysis failed', [
                        'redesign_id' => (int) $redesignId,
                        'screenshot' => $screenshotPath,
                        'provider' => $analysis['provider'] ?? 'unknown',
                        'error' => $analysis['error'] ?? 'unknown',
                    ]);

                    return $this->json(200, false, 'Vision analysis failed: ' . ($analysis['error'] ?? 'unknown'), [
                        'error_code' => 'VISION_FAILED',
                        'provider' => $analysis['provider'] ?? 'unknown',
                        'redesign_id' => (int) $redesignId,
                        'code_id' => $code->id,
                    ]);
                }

                $visionAnalysis = $analysis['analysis'];
                $redesign->update(['vision_analysis' => $visionAnalysis]);
            }

            // ── Generate code ──────────────────────────────────────────────
            $startTime = microtime(true);

            $result = $this->codeService->generateCode($visionAnalysis, $designStyle, $screenshotPath);

            $generationTime = (int) ((microtime(true) - $startTime) * 1000);

            if (!$result['success']) {
                $code->update([
                    'status' => 'failed',
                    'summary' => $result['error'] ?? 'Code generation failed',
                ]);

                $this->log('error', 'Code generation failed', [
                    'redesign_id' => (int) $redesignId,
                    'design_style' => $designStyle,
                    'provider' => $result['provider'] ?? 'unknown',
                    'model' => $result['model'] ?? 'unknown',
                    'error' => $result['error'] ?? 'unknown',
                    'error_code' => $result['error_code'] ?? 'GENERATION_FAILED',
                    'generation_time_ms' => $generationTime,
                ]);

                return $this->json(200, false, $result['error'] ?? 'Code generation failed', [
                    'error_code' => $result['error_code'] ?? 'GENERATION_FAILED',
                    'provider' => $result['provider'] ?? null,
                    'model' => $result['model'] ?? null,
                    'redesign_id' => (int) $redesignId,
                    'code_id' => $code->id,
                    'generation_time_ms' => $generationTime,
                ]);
            }

            // ── Success ───────────────────────────────────────────────────
            $code->update([
                'status' => 'completed',
                'generated_code' => $result['code'],
                'supporting_code' => $result['supporting_code'] ?? null,
                'summary' => $result['summary'] ?? 'React component generated.',
                'generation_time_ms' => $generationTime,
                'model' => $result['model'] ?? $providerCheck['model'] ?? 'unknown',
                'provider' => $result['provider'] ?? 'openai',
            ]);

            $redesign->update(['status' => 'completed']);

            $this->log('info', 'Code generated successfully', [
                'redesign_id' => (int) $redesignId,
                'code_id' => $code->id,
                'provider' => $result['provider'] ?? 'openai',
                'model' => $result['model'] ?? 'unknown',
                'generation_time_ms' => $generationTime,
                'code_length' => strlen($result['code'] ?? ''),
            ]);

            return $this->json(200, true, 'Code generated successfully', [
                'code' => $this->formatCode($code),
                'redesign' => $this->formatRedesign($redesign),
            ]);

        } catch (\TypeError $e) {
            // e.g. passing string where int expected — should never happen but catch it
            $this->log('error', 'TypeError in generate', [
                'redesign_id' => $redesignId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json(200, false, 'Invalid request parameters.', [
                'error_code' => 'INVALID_PARAMETER',
                'exception' => $e->getMessage(),
            ]);

        } catch (\Throwable $e) {
            return $this->error($e, 'generate', ['redesign_id' => $redesignId]);
        }
    }

    // ─── Download ──────────────────────────────────────────────────────────

    /**
     * GET /api/inspector/codes/{redesignId}/download
     */
    public function download($redesignId): JsonResponse
    {
        try {
            $redesign = UiRedesign::with('generatedCodes')->find((int) $redesignId);

            if (!$redesign) {
                return $this->json(404, false, 'Redesign not found');
            }

            $code = $redesign->generatedCodes()
                ->where('framework', 'react')
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->first();

            if (!$code || !$code->generated_code) {
                return $this->json(404, false, 'No completed code found. Generate code first.');
            }

            $zipName = 'ui-redesign-' . $redesign->id . '-' . Str::slug($redesign->design_style) . '.zip';
            $tempPath = sys_get_temp_dir() . '/' . $zipName;

            $zip = new ZipArchive();
            if ($zip->open($tempPath, ZipArchive::CREATE) !== true) {
                return $this->json(500, false, 'Could not create ZIP file.');
            }

            $zip->addFromString('package.json', json_encode([
                'name' => 'ui-redesign-' . Str::slug($redesign->design_style),
                'private' => true, 'version' => '0.0.0', 'type' => 'module',
                'scripts' => ['dev' => 'vite', 'build' => 'vite build', 'preview' => 'vite preview'],
                'dependencies' => ['react' => '^18.3.1', 'react-dom' => '^18.3.1'],
                'devDependencies' => [
                    '@vitejs/plugin-react' => '^4.3.4',
                    'autoprefixer' => '^10.4.20', 'postcss' => '^8.5.3',
                    'tailwindcss' => '^3.4.17', 'vite' => '^6.3.5',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $zip->addFromString('vite.config.js', <<<'JS'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
export default defineConfig({ plugins: [react()], server: { port: 3000 } })
JS);

            $zip->addFromString('index.html', <<<'HTML'
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/><title>AI UI Redesign</title></head>
<body><div id="root"></div><script type="module" src="/src/main.jsx"></script></body></html>
HTML);

            $zip->addFromString('src/main.jsx', <<<JS
import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'
import './index.css'
ReactDOM.createRoot(document.getElementById('root')).render(<React.StrictMode><App /></React.StrictMode>)
JS);

            $zip->addFromString('src/App.jsx', $code->generated_code);
            $zip->addFromString('src/index.css', "@tailwind base;\n@tailwind components;\n@tailwind utilities;\n");

            if ($code->supporting_code) {
                $zip->addFromString('src/components/AdditionalComponents.jsx', $code->supporting_code);
            }

            $zip->close();
            $zipContent = file_get_contents($tempPath);
            unlink($tempPath);

            return response($zipContent, 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . $zipName . '"',
                'Content-Length' => strlen($zipContent),
            ]);

        } catch (\Throwable $e) {
            return $this->error($e, 'download', ['redesign_id' => $redesignId]);
        }
    }

    // ─── Provider Status ────────────────────────────────────────────────────

    /**
     * GET /api/inspector/codes/providers
     */
    public function providerStatus(): JsonResponse
    {
        try {
            $avail = $this->codeService->availability();

            return $this->json(200, true, 'Provider status retrieved', [
                'available' => $avail['ok'],
                'provider' => $avail,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e, 'providerStatus');
        }
    }

    // ─── Private Helpers ───────────────────────────────────────────────────

    /**
     * Return a consistent JSON response.
     *
     * @param int $httpStatus  Always 200 for business errors; use 404 for not-found.
     * @param bool $success
     * @param string $message
     * @param array $extra
     * @return JsonResponse
     */
    private function json(int $httpStatus, bool $success, string $message, array $extra = []): JsonResponse
    {
        $body = array_merge([
            'success' => $success,
            'message' => $message,
        ], $extra);

        // If success is false and this would be a 2xx, downgrade to 400 unless it's a 404
        if (!$success && $httpStatus >= 200 && $httpStatus < 300 && $httpStatus !== 404) {
            // We NEVER return 5xx for provider failures — but for business logic errors use 400
            if ($httpStatus >= 500) {
                $httpStatus = 400;
            }
        }

        return response()->json($body, $httpStatus);
    }

    /**
     * Handle an unexpected exception — log it and return a safe JSON error.
     * Never returns HTTP 500/503 to the client.
     */
    private function error(\Throwable $e, string $method, array $context = []): JsonResponse
    {
        $errorId = Str::random(12);

        Log::error("InspectorCodeController::{$method} unhandled exception [{$errorId}]", [
            'error' => $e->getMessage(),
            'class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect($e->getTrace())->take(10)->map(fn($f) => [
                'file' => $f['file'] ?? null,
                'line' => $f['line'] ?? null,
                'function' => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''),
            ])->toArray(),
            'context' => $context,
        ]);

        // If it's an API/auth exception, re-throw appropriate status
        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
                'error_id' => $errorId,
            ], 401);
        }

        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
                'error_id' => $errorId,
            ], 404);
        }

        return response()->json([
            'success' => false,
            'message' => 'An unexpected error occurred. Please try again.',
            'error_id' => $errorId,
        ], 200); // Always 200 — never expose 500 to frontend
    }

    /**
     * Structured log helper for code generation events.
     */
    private function log(string $level, string $message, array $context = []): void
    {
        Log::$level("InspectorCodeController: {$message}", array_merge([
            'service' => 'inspector_code',
            'controller' => 'InspectorCodeController',
        ], $context));
    }

    private function formatCode($code): array
    {
        return [
            'id' => $code->id,
            'framework' => $code->framework,
            'status' => $code->status,
            'generated_code' => $code->generated_code,
            'supporting_code' => $code->supporting_code,
            'summary' => $code->summary,
            'generation_time_ms' => $code->generation_time_ms,
            'model' => $code->model,
            'provider' => $code->provider,
            'created_at' => $code->created_at?->toIso8601String(),
        ];
    }

    private function formatRedesign($redesign): array
    {
        return [
            'id' => $redesign->id,
            'design_style' => $redesign->design_style,
            'status' => $redesign->status,
            'vision_analysis' => $redesign->vision_analysis,
        ];
    }
}
