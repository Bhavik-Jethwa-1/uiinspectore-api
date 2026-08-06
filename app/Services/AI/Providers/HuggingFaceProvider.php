<?php

namespace App\Services\AI\Providers;

use App\Services\AI\ImageProviderInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * HuggingFace Inference API — free tier img2img provider.
 *
 * No API key required for basic usage (rate-limited).
 * Get a free token at: https://huggingface.co/settings/tokens
 *
 * Supports img2img via: black-forest-labs/FLUX.1-schnell
 * Also: stabilityai/stable-diffusion-3-medium (free, img2img capable)
 * And: runwayml/stable-diffusion-v1-5 (reliable, fast)
 *
 * URL format: https://api-inference.huggingface.co/v1/models/{model}
 */
class HuggingFaceProvider implements ImageProviderInterface
{
    private string $apiToken;
    private string $baseHost = 'api-inference.huggingface.co';
    // Known working IP for huggingface.co - use as fallback for api-inference
    private array $fallbackIps = [
        '140.82.112.21',  // huggingface.co primary
        '143.204.181.85', // huggingface.co alternate
    ];

    public function __construct(?string $apiToken = null)
    {
        $this->apiToken = $apiToken ?? env('HF_API_KEY', '');
    }

    public function getName(): string { return 'HuggingFace Inference'; }
    public function getId(): string { return 'huggingface'; }

    public function getModels(): array
    {
        return [
            [
                'id' => 'runwayml/stable-diffusion-v1-5',
                'name' => 'SD 1.5 (Fast)',
                'supportsImg2Img' => true,
                'costPerCall' => 0,
                'description' => 'Fast, reliable img2img — great for quick UI mockups',
            ],
            [
                'id' => 'stabilityai/stable-diffusion-xl-base-1.0',
                'name' => 'SDXL 1.0',
                'supportsImg2Img' => true,
                'costPerCall' => 0,
                'description' => 'Higher quality SDXL — better for polished designs',
            ],
            [
                'id' => 'black-forest-labs/FLUX.1-schnell',
                'name' => 'FLUX.1 Schnell',
                'supportsImg2Img' => true,
                'costPerCall' => 0,
                'description' => 'Fast, free img2img — good for UI mockups',
            ],
            [
                'id' => 'stabilityai/stable-diffusion-3-medium',
                'name' => 'SD3 Medium',
                'supportsImg2Img' => true,
                'costPerCall' => 0,
                'description' => 'SD3 img2img — higher quality, slower',
            ],
        ];
    }

    public function availability(): array
    {
        // Test connectivity to HF API
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

        if ($httpCode === 200 && $response === 'OK') {
            if (!empty($this->apiToken) && !str_starts_with($this->apiToken, 'your_h')) {
                return ['available' => true, 'hasToken' => true, 'status' => 'connected'];
            }
            return ['available' => true, 'hasToken' => false, 'status' => 'connected'];
        }

        return [
            'available' => false,
            'hasToken' => !empty($this->apiToken) && !str_starts_with($this->apiToken, 'your_h'),
            'status' => 'disconnected',
            'error' => $error ?: "HTTP $httpCode",
        ];
    }

    /**
     * Generate an image using HuggingFace Inference API
     * 
     * @param string|null $inputImagePath Path to input image for img2img, or null for text2img
     * @param string $prompt Text prompt describing the desired output
     * @param array $options Model and generation options
     * @return array Result with success flag and image path or error
     */
    public function supportsImg2Img(): bool
    {
        return true; // HuggingFace Inference supports img2img
    }

    public function generate(?string $inputImagePath, string $prompt, array $options = []): array
    {
        $start = microtime(true);
        $model = $options['model'] ?? 'runwayml/stable-diffusion-v1-5';
        $width = (int)($options['width'] ?? 1024);
        $height = (int)($options['height'] ?? 1024);
        $strength = (float)($options['strength'] ?? 0.75);
        $seed = $options['seed'] ?? random_int(1, 999999);
        $numInferenceSteps = (int)($options['num_inference_steps'] ?? 30);
        $guidanceScale = (float)($options['guidance_scale'] ?? 7.5);

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($this->apiToken) && !str_starts_with($this->apiToken, 'your_h')) {
            $headers['Authorization'] = 'Bearer ' . $this->apiToken;
        }

        // Build the request payload for img2img
        $payload = [
            'inputs' => $prompt,
            'parameters' => [
                'width' => min($width, 768),  // Cap at reasonable size for free tier
                'height' => min($height, 768),
                'seed' => $seed,
                'num_inference_steps' => $numInferenceSteps,
                'guidance_scale' => $guidanceScale,
            ],
        ];

        // If we have an input image, do img2img
        if ($inputImagePath && file_exists($inputImagePath)) {
            $imageData = base64_encode(file_get_contents($inputImagePath));
            $mimeType = mime_content_type($inputImagePath) ?: 'image/png';
            $payload['inputs'] = "data:{$mimeType};base64,{$imageData}";
            $payload['parameters']['strength'] = $strength;
        }

        $url = "https://{$this->baseHost}/models/{$model}";
        $jsonPayload = json_encode($payload);

        // Try with normal connection first, then fallback to IP workaround
        $result = $this->makeRequest($url, $headers, $jsonPayload, $start);

        // If failed, try with IP fallback (for servers with DNS issues)
        if (!$result['success'] && isset($result['retry'])) {
            foreach ($this->fallbackIps as $ip) {
                $urlWithIp = "https://{$ip}/v1/models/{$model}";
                // Note: This won't work for HTTPS without proper Host header
                // So we use a different approach - set Host header manually
                $headersWithHost = $headers;
                $headersWithHost['Host'] = $this->baseHost;
                
                $result = $this->makeRequestWithCurl($urlWithIp, $headersWithHost, $jsonPayload, $start, $ip);
                if ($result['success']) {
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Make HTTP request using PHP curl
     */
    private function makeRequest(string $url, array $headers, string $payload, float $start): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
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
        $elapsed = (int)((microtime(true) - $start) * 1000);
        curl_close($ch);

        return $this->parseResponse($response, $httpCode, $contentType, $error, $elapsed, $start);
    }

    /**
     * Make HTTP request with specific IP (for DNS workaround)
     */
    private function makeRequestWithCurl(string $url, array $headers, string $payload, float $start, string $ip): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            // Force connection to specific IP
            CURLOPT_RESOLVE => [$this->baseHost . ':443:' . $ip],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        $elapsed = (int)((microtime(true) - $start) * 1000);
        curl_close($ch);

        return $this->parseResponse($response, $httpCode, $contentType, $error, $elapsed, $start);
    }

    /**
     * Parse the HTTP response and handle various HF API response types
     */
    private function parseResponse(string $response, int $httpCode, ?string $contentType, string $error, int $elapsed, float $start): array
    {
        // Check for connection errors
        if ($httpCode === 0 || empty($response)) {
            return [
                'success' => false,
                'error' => 'Could not connect to HuggingFace API: ' . ($error ?: 'Unknown error'),
                'errorCode' => 'CONNECTION_FAILED',
                'generationTimeMs' => $elapsed,
                'retry' => true,  // Signal that we should retry with different IP
            ];
        }

        // HTTP error codes
        if ($httpCode >= 400) {
            $json = json_decode($response, true);
            $errorMsg = is_array($json) ? ($json['error'] ?? "HTTP $httpCode") : "HTTP $httpCode";
            
            // Model loading (503) - retry might help
            if ($httpCode === 503) {
                return [
                    'success' => false,
                    'error' => 'HuggingFace model is loading. Please try again in a few seconds.',
                    'errorCode' => 'MODEL_LOADING',
                    'generationTimeMs' => $elapsed,
                    'retry' => true,
                ];
            }
            
            return [
                'success' => false,
                'error' => 'HuggingFace error: ' . $errorMsg,
                'errorCode' => 'HF_HTTP_' . $httpCode,
                'generationTimeMs' => $elapsed,
            ];
        }

        // Success
        if ($httpCode === 200) {
            // Check content type
            $contentType = $contentType ?: '';
            
            // JSON response (error message or revised prompt)
            if (str_contains($contentType, 'application/json')) {
                $json = json_decode($response, true);
                if (isset($json['error'])) {
                    return [
                        'success' => false,
                        'error' => 'HuggingFace: ' . $json['error'],
                        'errorCode' => 'HF_ERROR',
                        'generationTimeMs' => $elapsed,
                    ];
                }
                // Revised prompt in response
                if (isset($json[0]['revised_prompt'])) {
                    return [
                        'success' => false,
                        'error' => 'Unexpected JSON response without image',
                        'errorCode' => 'UNEXPECTED_JSON',
                        'generationTimeMs' => $elapsed,
                    ];
                }
            }

            // Binary image response
            if (str_contains($contentType, 'image') || strlen($response) > 1000) {
                // Validate it's actually image data (PNG header)
                if (substr($response, 0, 8) !== "\x89PNG\r\n\x1a\n" && 
                    substr($response, 0, 2) !== "\xff\xd8") {
                    return [
                        'success' => false,
                        'error' => 'Response does not appear to be a valid image',
                        'errorCode' => 'INVALID_IMAGE',
                        'generationTimeMs' => $elapsed,
                        'retry' => true,
                    ];
                }

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
                        'generationTimeMs' => $elapsed,
                    ];
                }

                return [
                    'success' => true,
                    'imagePath' => $filename,
                    'revisedPrompt' => null,
                    'model' => 'huggingface',
                    'generationTimeMs' => $elapsed,
                    'costUsd' => 0,
                    'provider' => 'huggingface',
                ];
            }

            // "OK" response means model is loading
            if (trim($response) === 'OK') {
                return [
                    'success' => false,
                    'error' => 'HuggingFace model is initializing. Please wait and retry.',
                    'errorCode' => 'MODEL_INIT',
                    'generationTimeMs' => $elapsed,
                    'retry' => true,
                ];
            }
        }

        return [
            'success' => false,
            'error' => 'Unexpected response from HuggingFace',
            'errorCode' => 'UNEXPECTED_RESPONSE',
            'generationTimeMs' => $elapsed,
        ];
    }
}
