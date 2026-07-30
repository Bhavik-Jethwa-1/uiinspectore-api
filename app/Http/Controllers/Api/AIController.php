<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\AIEngine;
use App\Services\AI\AIServiceLocator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Unified AI Controller — single entry point for ALL AI features.
 * Routes through AIEngine → AIService → ProviderManager → Active Provider.
 * Uses singleton AIEngine for reduced instantiation overhead.
 */
class AIController extends Controller
{
    private AIEngine $ai;

    public function __construct()
    {
        $this->ai = AIServiceLocator::engine();
    }

    // ─── POST /api/ai/chat ─────────────────────────────────────────────────
    public function chat(Request $request)
    {
        $messages = $request->input('messages', []);
        if (empty($messages)) {
            return response()->json(['error' => 'No messages provided'], 400);
        }

        $result = $this->ai->chat($messages, [
            'max_tokens'  => $request->input('max_tokens', 2000),
            'temperature' => $request->input('temperature', 0.7),
        ]);

        if (isset($result['error'])) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json($result);
    }

    // ─── POST /api/ai/stream ───────────────────────────────────────────────
    public function stream(Request $request)
    {
        $messages = $request->input('messages', []);
        if (empty($messages)) {
            return response()->json(['error' => 'No messages provided'], 400);
        }

        return response()->stream(function () use ($messages, $request) {
            $stream = $this->ai->streamChat($messages, [
                'max_tokens'  => $request->input('max_tokens', 2000),
                'temperature' => $request->input('temperature', 0.7),
            ]);

            $full = '';
            foreach ($stream as $chunk) {
                if (isset($chunk['error'])) {
                    echo "data: " . json_encode(['error' => $chunk['error']]) . "\n\n";
                    flush();
                    break;
                }
                if (($chunk['delta'] ?? '') !== '') {
                    $full .= $chunk['delta'];
                    echo "data: " . json_encode([
                        'delta' => $chunk['delta'],
                        'done'  => false,
                    ]) . "\n\n";
                    flush();
                }
                if ($chunk['done'] ?? false) {
                    echo "data: " . json_encode([
                        'delta' => '',
                        'done'  => true,
                        'reply' => $chunk['reply'] ?? $full,
                    ]) . "\n\n";
                    flush();
                    break;
                }
            }
            echo "data: [DONE]\n\n";
            flush();
        }, 200, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection'    => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // ─── POST /api/ai/analyze ─────────────────────────────────────────────
    public function analyze(Request $request)
    {
        $screenshotUrl = $request->input('screenshot_url', '');
        $projectContext = $request->input('project_context', '');

        $result = $this->ai->execute(['type' => 'analyze', 'screenshot_url' => $screenshotUrl ?: null, 'project_context' => $projectContext]);
        $parsed = $this->parseJsonResponse($result);

        return response()->json(array_merge($result, ['parsed' => $parsed]));
    }

    // ─── POST /api/ai/detect ───────────────────────────────────────────────
    public function detect(Request $request)
    {
        $screenshotUrl = $request->input('screenshot_url', '');
        $projectContext = $request->input('project_context', '');

        $result = $this->ai->execute(['type' => 'detect', 'screenshot_url' => $screenshotUrl ?: null, 'project_context' => $projectContext]);
        $parsed = $this->parseJsonResponse($result);

        return response()->json(array_merge($result, ['parsed' => $parsed]));
    }

    // ─── POST /api/ai/suggestions ─────────────────────────────────────────
    public function suggestions(Request $request)
    {
        $projectContext = $request->input('project_context', '');
        $categories = $request->input('categories', []);

        $result = $this->ai->execute(['type' => 'suggestions', 'project_context' => $projectContext, 'categories' => $categories]);
        $parsed = $this->parseJsonResponse($result);

        return response()->json(array_merge($result, ['parsed' => $parsed]));
    }

    // ─── POST /api/ai/redesign ────────────────────────────────────────────
    public function redesign(Request $request)
    {
        $screenshotUrl  = $request->input('screenshot_url', '');
        $style          = $request->input('style', 'modern-saas');
        $projectContext = $request->input('project_context', '');

        $result = $this->ai->execute(['type' => 'redesign', 'screenshot_url' => $screenshotUrl ?: null, 'style' => $style, 'project_context' => $projectContext]);
        $parsed = $this->parseJsonResponse($result);

        return response()->json(array_merge($result, ['parsed' => $parsed]));
    }

    // ─── POST /api/ai/copywriting ─────────────────────────────────────────
    public function copywriting(Request $request)
    {
        $type           = $request->input('type', 'landing-page');
        $productContext = $request->input('product_context', '');
        $tone           = $request->input('tone', 'modern');

        $result = $this->ai->execute(['type' => 'copywrite', 'copy_type' => $type, 'prompt' => $productContext, 'tone' => $tone]);
        $parsed = $this->parseJsonResponse($result);

        return response()->json(array_merge($result, ['parsed' => $parsed]));
    }

    // ─── POST /api/ai/research ─────────────────────────────────────────────
    public function research(Request $request)
    {
        $topic = $request->input('topic', 'UI design trends');
        $query = $request->input('query', '');
        $niche = $request->input('niche', '');
        // Combine topic + query for richer context
        $fullTopic = $query ? "{$topic} — {$query}" : $topic;

        $result = $this->ai->execute(['type' => 'research', 'topic' => $fullTopic, 'niche' => $niche]);
        $parsed = $this->parseJsonResponse($result);

        return response()->json(array_merge($result, ['parsed' => $parsed]));
    }

    // ─── POST /api/ai/consultant ───────────────────────────────────────────
    public function consultant(Request $request)
    {
        $question      = $request->input('question', '');
        $context       = $request->input('context', '');
        $screenshotUrl = $request->input('screenshot_url', '');
        $analysisType = $request->input('analysis_type', '');

        if (!$question) {
            return response()->json(['error' => 'Question is required'], 400);
        }

        if ($screenshotUrl) {
            $result = $this->ai->consultWithImage($question, $screenshotUrl, 2500, $analysisType);
        } else {
            $result = $this->ai->execute(['type' => 'consult', 'question' => $question, 'context' => $context, 'analysis_type' => $analysisType]);
        }

        return response()->json($result);
    }

    // ─── POST /api/ai/autodesign ───────────────────────────────────────────
    public function autodesign(Request $request)
    {
        $description = $request->input('description', '');
        $device      = $request->input('device', 'web');
        $style       = $request->input('style', 'modern-saas');

        if (!$description) {
            return response()->json(['error' => 'Description is required'], 400);
        }

        $result = $this->ai->execute(['type' => 'autodesign', 'description' => $description, 'device' => $device, 'style' => $style]);
        $parsed = $this->parseJsonResponse($result);

        return response()->json(array_merge($result, ['parsed' => $parsed]));
    }

    // ─── POST /api/ai/annotate ────────────────────────────────────────────
    public function annotate(Request $request)
    {
        $screenshotUrl = $request->input('screenshot_url', '');
        if (!$screenshotUrl) {
            return response()->json(['error' => 'screenshot_url is required'], 400);
        }

        $result = $this->ai->execute(['type' => 'annotate', 'screenshot_url' => $screenshotUrl]);
        $parsed = $this->parseJsonResponse($result);

        return response()->json(array_merge($result, ['parsed' => $parsed]));
    }

    // ─── POST /api/ai/image-fetch (proxied) ───────────────────────────────
    public function imageFetch(Request $request)
    {
        $url = $request->input('url', '');
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['error' => 'Missing url parameter'], 400);
        }

        $cacheKey = md5($url);
        $cacheDir = storage_path('app/images');
        @mkdir($cacheDir, 0755, true);

        foreach (['jpg', 'png', 'gif', 'webp'] as $ext) {
            $cached = "{$cacheDir}/{$cacheKey}.{$ext}";
            if (file_exists($cached) && filemtime($cached) > time() - 86400 * 7) {
                $data = file_get_contents($cached);
                $mime = $ext === 'jpg' ? 'image/jpeg' : "image/{$ext}";
                return response($data, 200)
                    ->header('Content-Type', $mime)
                    ->header('Cache-Control', 'public, max-age=86400')
                    ->header('Access-Control-Allow-Origin', '*');
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$data || strlen($data) < 1024 || $httpCode !== 200) {
            return response()->json(['error' => 'Failed to fetch image'], 502);
        }

        $ext = match (true) {
            substr($data, 0, 2) === "\xff\xd8" => 'jpg',
            substr($data, 0, 4) === "GIF8"      => 'gif',
            substr($data, 0, 4) === "RIFF"
                && substr($data, 8, 4) === 'WEBP' => 'webp',
            default => 'png',
        };

        $mime = $ext === 'jpg' ? 'image/jpeg' : "image/{$ext}";
        file_put_contents("{$cacheDir}/{$cacheKey}.{$ext}", $data);

        return response($data, 200)
            ->header('Content-Type', $mime)
            ->header('Cache-Control', 'public, max-age=86400')
            ->header('Access-Control-Allow-Origin', '*');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function parseJsonResponse(array $result): array
    {
        if (isset($result['parsed'])) return $result['parsed'];
        $reply = $result['reply'] ?? (is_string($result) ? $result : '');
        if (!$reply) return [];
        try {
            return json_decode($reply, true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function getUser(Request $request): ?object
    {
        $token = $request->bearerToken();
        if (!$token) return null;
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) return null;
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            $userId = $payload['sub'] ?? $payload['user_id'] ?? null;
            if (!$userId) return null;
            return (object)['id' => $userId, 'email' => $payload['email'] ?? null];
        } catch (\Throwable) {
            return null;
        }
    }
}
