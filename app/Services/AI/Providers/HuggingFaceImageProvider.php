<?php

namespace App\Services\AI\Providers;

use App\Services\AI\ImageProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * HuggingFace Inference API Provider
 * 
 * Image-to-image generation with automatic model fallback.
 * Models are tried in priority order until one succeeds.
 * 
 * Model Priority:
 * 1. black-forest-labs/FLUX.1-Kontext-dev (best quality)
 * 2. stabilityai/stable-diffusion-xl-base-1.0 (SDXL)
 * 3. stabilityai/sdxl-turbo (fast SDXL)
 * 4. black-forest-labs/FLUX.1-dev (FLUX dev)
 */
class HuggingFaceImageProvider implements ImageProviderInterface
{
    private string $apiToken;
    private string $baseHost = 'api-inference.huggingface.co';
    
    /**
     * Model priority list - tried in order until one works
     */
    private array $modelPriority = [
        [
            'id' => 'black-forest-labs/FLUX.1-Kontext-dev',
            'name' => 'FLUX.1 Kontext Dev',
            'supportsImg2Img' => true,
            'costPerCall' => 0,
            'description' => 'Best quality - FLUX.1 Kontext for image-to-image',
        ],
        [
            'id' => 'stabilityai/stable-diffusion-xl-base-1.0',
            'name' => 'SDXL 1.0 Base',
            'supportsImg2Img' => true,
            'costPerCall' => 0,
            'description' => 'High quality Stable Diffusion XL',
        ],
        [
            'id' => 'stabilityai/sdxl-turbo',
            'name' => 'SDXL Turbo',
            'supportsImg2Img' => true,
            'costPerCall' => 0,
            'description' => 'Fast SDXL - 4 steps to image',
        ],
        [
            'id' => 'black-forest-labs/FLUX.1-dev',
            'name' => 'FLUX.1 Dev',
            'supportsImg2Img' => true,
            'costPerCall' => 0,
            'description' => 'FLUX development version',
        ],
    ];

    public function __construct(?string $apiToken = null)
    {
        $this->apiToken = $apiToken ?? env('HF_API_KEY', '');
    }

    public function getName(): string
    {
        return 'Hugging Face';
    }

    public function getId(): string
    {
        return 'huggingface';
    }

    public function getModels(): array
    {
        return $this->modelPriority;
    }

    public function availability(): array
    {
        // Test basic connectivity
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://' . $this->baseHost . '/status',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && trim($response) === 'OK') {
            return [
                'available' => true,
                'hasToken' => $this->hasValidToken(),
                'status' => 'connected',
            ];
        }

        return [
            'available' => false,
            'hasToken' => $this->hasValidToken(),
            'status' => 'disconnected',
            'error' => $error ?: 'Cannot connect to HuggingFace API',
        ];
    }

    private function hasValidToken(): bool
    {
        return !empty($this->apiToken) && !str_starts_with($this->apiToken, 'your_') && strlen($this->apiToken) > 20;
    }

    public function supportsImg2Img(): bool
    {
        return true;
    }

    /**
     * Generate an image with automatic model fallback
     * 
     * Tries each model in priority order until one succeeds.
     */
    public function generate(?string $inputImagePath, string $prompt, array $options = []): array
    {
        $startTime = microtime(true);
        
        // Get preferred model from options, or use priority list
        $preferredModel = $options['model'] ?? null;
        
        // Build ordered list of models to try
        $modelsToTry = [];
        
        if ($preferredModel) {
            // Put preferred model first
            array_unshift($modelsToTry, [
                'id' => $preferredModel,
                'name' => $preferredModel,
                'supportsImg2Img' => true,
            ]);
            // Add all others as fallback
            foreach ($this->modelPriority as $model) {
                if ($model['id'] !== $preferredModel) {
                    $modelsToTry[] = $model;
                }
            }
        } else {
            $modelsToTry = $this->modelPriority;
        }

        // Try each model until one succeeds
        $lastError = null;
        $triedModels = [];
        
        foreach ($modelsToTry as $model) {
            $modelId = $model['id'];
            $triedModels[] = $modelId;
            
            Log::info("HuggingFaceImageProvider: Trying model", [
                'model' => $modelId,
                'attempt' => count($triedModels),
            ]);
            
            $result = $this->tryGenerateWithModel(
                $modelId,
                $inputImagePath,
                $prompt,
                $options,
                $startTime
            );

            if ($result['success']) {
                $result['tried_models'] = $triedModels;
                $result['used_model'] = $modelId;
                return $result;
            }

            $lastError = $result;
            
            // If model explicitly says don't retry, stop
            if (isset($result['fatal']) && $result['fatal']) {
                break;
            }
        }

        // All models failed
        Log::error("HuggingFaceImageProvider: All models failed", [
            'tried' => $triedModels,
            'last_error' => $lastError['error'] ?? 'Unknown',
        ]);

        return [
            'success' => false,
            'error' => 'All HuggingFace models failed: ' . ($lastError['error'] ?? 'Unknown error'),
            'errorCode' => $lastError['errorCode'] ?? 'ALL_MODELS_FAILED',
            'provider' => 'huggingface',
            'model' => $preferredModel ?? 'unknown',
            'generationTimeMs' => (int)((microtime(true) - $startTime) * 1000),
            'tried_models' => $triedModels,
            'fatal' => true,
        ];
    }

    /**
     * Try generating with a specific model
     */
    private function tryGenerateWithModel(
        string $model,
        ?string $inputImagePath,
        string $prompt,
        array $options,
        float $startTime
    ): array {
        $width = min((int)($options['width'] ?? 1024), 1024);
        $height = min((int)($options['height'] ?? 1024), 1024);
        $strength = (float)($options['strength'] ?? 0.75);
        $seed = $options['seed'] ?? random_int(1, 999999);
        $numInferenceSteps = (int)($options['num_inference_steps'] ?? 30);
        $guidanceScale = (float)($options['guidance_scale'] ?? 7.5);

        // Validate input
        if ($inputImagePath && !file_exists($inputImagePath)) {
            return [
                'success' => false,
                'error' => 'Input image not found: ' . $inputImagePath,
                'errorCode' => 'FILE_NOT_FOUND',
                'provider' => 'huggingface',
                'model' => $model,
                'fatal' => true,
            ];
        }

        // Build headers
        $headers = ['Content-Type: application/json'];
        if ($this->hasValidToken()) {
            $headers[] = 'Authorization: Bearer ' . $this->apiToken;
        }

        // Build payload for img2img
        $payload = [
            'inputs' => $prompt,
            'parameters' => [
                'width' => $width,
                'height' => $height,
                'seed' => $seed,
                'num_inference_steps' => $numInferenceSteps,
                'guidance_scale' => $guidanceScale,
            ],
        ];

        // Add image for img2img
        if ($inputImagePath) {
            $imageData = base64_encode(file_get_contents($inputImagePath));
            $mimeType = mime_content_type($inputImagePath) ?: 'image/png';
            $payload['inputs'] = "data:{$mimeType};base64,{$imageData}";
            $payload['parameters']['strength'] = $strength;
        }

        $jsonPayload = json_encode($payload);

        // Make request
        $result = $this->makeRequest($model, $headers, $jsonPayload, $startTime);
        
        if ($result['success']) {
            $result['model'] = $model;
        } else {
            $result['model'] = $model;
        }
        
        return $result;
    }

    /**
     * Make HTTP request to HuggingFace Inference API
     */
    private function makeRequest(string $model, array $headers, string $payload, float $startTime): array
    {
        $url = "https://{$this->baseHost}/models/{$model}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        $elapsed = (int)((microtime(true) - $startTime) * 1000);

        // Connection error
        if ($httpCode === 0 || empty($response)) {
            return [
                'success' => false,
                'error' => 'Cannot connect to HuggingFace: ' . ($error ?: 'Connection failed'),
                'errorCode' => 'CONNECTION_FAILED',
                'provider' => 'huggingface',
                'model' => $model,
                'generationTimeMs' => $elapsed,
                'retry' => true,
            ];
        }

        // HTTP error responses
        if ($httpCode >= 400) {
            return $this->parseErrorResponse($response, $httpCode, $model, $elapsed);
        }

        // Success (200)
        if ($httpCode === 200) {
            return $this->parseSuccessResponse($response, $contentType, $model, $startTime);
        }

        return [
            'success' => false,
            'error' => 'Unexpected HTTP response: ' . $httpCode,
            'errorCode' => 'UNEXPECTED_HTTP_' . $httpCode,
            'provider' => 'huggingface',
            'model' => $model,
            'generationTimeMs' => $elapsed,
        ];
    }

    /**
     * Parse error response from HuggingFace
     */
    private function parseErrorResponse(string $response, int $httpCode, string $model, int $elapsed): array
    {
        $json = json_decode($response, true);
        $errorMsg = is_array($json) ? ($json['error'] ?? "HTTP $httpCode") : "HTTP $httpCode";

        // Model loading (503) - retry might help
        if ($httpCode === 503) {
            return [
                'success' => false,
                'error' => 'Model is loading on HuggingFace. Please try again.',
                'errorCode' => 'MODEL_LOADING',
                'provider' => 'huggingface',
                'model' => $model,
                'generationTimeMs' => $elapsed,
                'retry' => true,
            ];
        }

        // Model not found (404) - skip to next model
        if ($httpCode === 404) {
            return [
                'success' => false,
                'error' => 'Model not found: ' . $model,
                'errorCode' => 'MODEL_NOT_FOUND',
                'provider' => 'huggingface',
                'model' => $model,
                'generationTimeMs' => $elapsed,
                'skip' => true, // Don't retry this model
            ];
        }

        // Rate limit (429) - retry after delay
        if ($httpCode === 429) {
            return [
                'success' => false,
                'error' => 'Rate limited by HuggingFace. Please wait and retry.',
                'errorCode' => 'RATE_LIMITED',
                'provider' => 'huggingface',
                'model' => $model,
                'generationTimeMs' => $elapsed,
                'retry' => true,
            ];
        }

        return [
            'success' => false,
            'error' => 'HuggingFace error: ' . $errorMsg,
            'errorCode' => 'HF_ERROR_' . $httpCode,
            'provider' => 'huggingface',
            'model' => $model,
            'generationTimeMs' => $elapsed,
            'retry' => $httpCode >= 500,
        ];
    }

    /**
     * Parse success response - save image
     */
    private function parseSuccessResponse(string $response, ?string $contentType, string $model, float $startTime): array
    {
        $elapsed = (int)((microtime(true) - $startTime) * 1000);
        $contentType = $contentType ?: '';

        // Check for model initializing response
        if (trim($response) === 'OK' || trim($response) === '{"status":"OK"}') {
            return [
                'success' => false,
                'error' => 'Model is initializing. Please wait 20-30 seconds and retry.',
                'errorCode' => 'MODEL_INIT',
                'provider' => 'huggingface',
                'model' => $model,
                'generationTimeMs' => $elapsed,
                'retry' => true,
            ];
        }

        // JSON error response
        if (str_contains($contentType, 'application/json')) {
            $json = json_decode($response, true);
            if (isset($json['error'])) {
                return [
                    'success' => false,
                    'error' => 'HuggingFace: ' . $json['error'],
                    'errorCode' => 'HF_JSON_ERROR',
                    'provider' => 'huggingface',
                    'model' => $model,
                    'generationTimeMs' => $elapsed,
                    'retry' => true,
                ];
            }
        }

        // Check if response is valid image
        $isPng = substr($response, 0, 8) === "\x89PNG\r\n\x1a\n";
        $isJpeg = substr($response, 0, 2) === "\xff\xd8";
        
        if (!$isPng && !$isJpeg && strlen($response) < 1000) {
            return [
                'success' => false,
                'error' => 'Invalid response from HuggingFace',
                'errorCode' => 'INVALID_RESPONSE',
                'provider' => 'huggingface',
                'model' => $model,
                'generationTimeMs' => $elapsed,
                'retry' => true,
            ];
        }

        // Save image
        $filename = 'inspector-redesigns/' . Str::uuid() . '.png';
        $savePath = storage_path("app/public/{$filename}");
        $dir = dirname($savePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $bytesWritten = file_put_contents($savePath, $response);

        if ($bytesWritten === false || $bytesWritten < 1000) {
            return [
                'success' => false,
                'error' => 'Failed to save generated image',
                'errorCode' => 'SAVE_FAILED',
                'provider' => 'huggingface',
                'model' => $model,
                'generationTimeMs' => $elapsed,
            ];
        }

        Log::info("HuggingFaceImageProvider: Image saved", [
            'path' => $filename,
            'size' => $bytesWritten,
            'model' => $model,
        ]);

        return [
            'success' => true,
            'imagePath' => $filename,
            'imageUrl' => "/storage/{$filename}",
            'model' => $model,
            'provider' => 'huggingface',
            'generationTimeMs' => $elapsed,
            'costUsd' => 0,
        ];
    }
}
