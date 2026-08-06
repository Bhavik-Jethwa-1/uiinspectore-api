<?php

namespace App\Services\AI\Providers;

use App\Services\AI\ImageProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * OpenAI Image Generation Provider
 * 
 * Uses GPT Image (gpt-image-1) for image-to-image generation.
 * Primary provider for production - highest quality results.
 * 
 * API: https://api.openai.com/v1/images/edits
 */
class OpenAIImageProvider implements ImageProviderInterface
{
    private string $apiKey;
    private string $baseUrl = 'https://api.openai.com/v1';

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? env('OPENAI_API_KEY', '');
    }

    public function getName(): string
    {
        return 'OpenAI GPT Image';
    }

    public function getId(): string
    {
        return 'openai';
    }

    public function getModels(): array
    {
        return [
            [
                'id' => 'gpt-image-1',
                'name' => 'GPT Image 1',
                'supportsImg2Img' => true,
                'description' => 'Latest GPT Image model - highest quality, best for professional designs',
                'costPerCall' => 'Pay per use',
            ],
            [
                'id' => 'dall-e-3',
                'name' => 'DALL-E 3',
                'supportsImg2Img' => false,
                'description' => 'DALL-E 3 - text-to-image only, not img2img',
                'costPerCall' => 'Pay per use',
            ],
        ];
    }

    public function availability(): array
    {
        if (empty($this->apiKey)) {
            return [
                'available' => false,
                'reason' => 'OpenAI API key not configured',
                'hint' => 'Add OPENAI_API_KEY to .env',
            ];
        }

        // Test with a minimal API call
        try {
            $res = Http::withToken($this->apiKey)
                ->timeout(10)
                ->get("{$this->baseUrl}/models/gpt-image-1");

            if ($res->status() === 200) {
                return [
                    'available' => true,
                    'status' => 'connected',
                    'model' => 'gpt-image-1',
                ];
            }

            // Check for billing issues
            $body = $res->json();
            if (isset($body['error']['code']) && $body['error']['code'] === 'billing_hard_limit_reached') {
                return [
                    'available' => false,
                    'reason' => 'OpenAI billing limit reached',
                    'hint' => 'Add payment method or credits at platform.openai.com',
                    'status' => 'billing_limit',
                ];
            }

            return [
                'available' => false,
                'reason' => "OpenAI API error: " . ($body['error']['message'] ?? 'Unknown'),
                'status' => 'error',
            ];
        } catch (\Exception $e) {
            return [
                'available' => false,
                'reason' => 'Cannot connect to OpenAI: ' . $e->getMessage(),
                'status' => 'connection_failed',
            ];
        }
    }

    public function supportsImg2Img(): bool
    {
        return true;
    }

    /**
     * Generate image using OpenAI GPT Image
     * 
     * @param string|null $inputImagePath Path to input image for img2img
     * @param string $prompt Redesign prompt
     * @param array $options Generation options
     * @return array Result with success, image_path, model, generation_time_ms, etc.
     */
    public function generate(?string $inputImagePath, string $prompt, array $options = []): array
    {
        $startTime = microtime(true);
        $model = $options['model'] ?? 'gpt-image-1';
        $size = $options['size'] ?? '1024x1024';

        // Validate input
        if ($inputImagePath && !file_exists($inputImagePath)) {
            return [
                'success' => false,
                'error' => 'Input image not found: ' . $inputImagePath,
                'errorCode' => 'FILE_NOT_FOUND',
                'provider' => 'openai',
                'model' => $model,
                'generationTimeMs' => 0,
            ];
        }

        try {
            // Build the API request
            $multipart = [
                [
                    'name' => 'prompt',
                    'contents' => $prompt,
                ],
                [
                    'name' => 'model',
                    'contents' => $model,
                ],
                [
                    'name' => 'n',
                    'contents' => 1,
                ],
                [
                    'name' => 'size',
                    'contents' => $size,
                ],
                [
                    'name' => 'response_format',
                    'contents' => 'url',
                ],
            ];

            // Add image for img2img
            if ($inputImagePath) {
                $imageData = file_get_contents($inputImagePath);
                $mimeType = mime_content_type($inputImagePath) ?: 'image/png';
                $originalFilename = basename($inputImagePath);
                
                $multipart[] = [
                    'name' => 'image',
                    'contents' => $imageData,
                    'filename' => $originalFilename,
                    'headers' => ['Content-Type' => $mimeType],
                ];
            }

            $res = Http::withToken($this->apiKey)
                ->timeout(120)
                ->async(false)
                ->attach($multipart)
                ->post("{$this->baseUrl}/images/edits");

            // Note: Laravel Http doesn't support attach() with multipart the same way
            // We need to use curl directly for file uploads
            $result = $this->generateWithCurl($inputImagePath, $prompt, $model, $size, $startTime);

            return $result;

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'OpenAI exception: ' . $e->getMessage(),
                'errorCode' => 'EXCEPTION',
                'provider' => 'openai',
                'model' => $model,
                'generationTimeMs' => (int)((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * Generate using raw curl (required for file uploads)
     */
    private function generateWithCurl(?string $inputImagePath, string $prompt, string $model, string $size, float $startTime): array
    {
        $ch = curl_init();

        $url = "{$this->baseUrl}/images/edits";
        
        // Build multipart form data
        $postFields = [
            'prompt' => $prompt,
            'model' => $model,
            'n' => 1,
            'size' => $size,
            'response_format' => 'url',
        ];

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
        ];

        if ($inputImagePath) {
            // For image edit, we need to send the image file
            $postFields['image'] = new \CURLFile(
                $inputImagePath,
                mime_content_type($inputImagePath) ?: 'image/png',
                basename($inputImagePath)
            );
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $elapsed = (int)((microtime(true) - $startTime) * 1000);

        if ($httpCode === 0) {
            return [
                'success' => false,
                'error' => 'OpenAI connection failed: ' . $error,
                'errorCode' => 'CONNECTION_FAILED',
                'provider' => 'openai',
                'model' => $model,
                'generationTimeMs' => $elapsed,
            ];
        }

        if ($httpCode >= 400) {
            $body = json_decode($response, true);
            $errorMsg = $body['error']['message'] ?? "HTTP $httpCode";

            // Check for specific errors
            if (str_contains($errorMsg, 'billing_hard_limit')) {
                return [
                    'success' => false,
                    'error' => 'OpenAI billing limit reached. Please add credits.',
                    'errorCode' => 'BILLING_LIMIT',
                    'provider' => 'openai',
                    'model' => $model,
                    'generationTimeMs' => $elapsed,
                    'retry' => false, // Don't retry billing errors
                ];
            }

            return [
                'success' => false,
                'error' => 'OpenAI error: ' . $errorMsg,
                'errorCode' => 'OPENAI_ERROR_' . $httpCode,
                'provider' => 'openai',
                'model' => $model,
                'generationTimeMs' => $elapsed,
                'retry' => $httpCode >= 500, // Retry on server errors
            ];
        }

        // Success
        $body = json_decode($response, true);
        
        if (!isset($body['data'][0]['url'])) {
            return [
                'success' => false,
                'error' => 'Unexpected OpenAI response format',
                'errorCode' => 'INVALID_RESPONSE',
                'provider' => 'openai',
                'model' => $model,
                'generationTimeMs' => $elapsed,
            ];
        }

        // Download the generated image
        $imageUrl = $body['data'][0]['url'];
        $imageData = file_get_contents($imageUrl);

        if (!$imageData) {
            return [
                'success' => false,
                'error' => 'Failed to download generated image from OpenAI',
                'errorCode' => 'DOWNLOAD_FAILED',
                'provider' => 'openai',
                'model' => $model,
                'generationTimeMs' => $elapsed,
            ];
        }

        // Save to storage
        $filename = 'inspector-redesigns/' . Str::uuid() . '.png';
        $savePath = storage_path("app/public/{$filename}");
        $dir = dirname($savePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $bytesWritten = file_put_contents($savePath, $imageData);

        if ($bytesWritten === false || $bytesWritten < 1000) {
            return [
                'success' => false,
                'error' => 'Failed to save generated image',
                'errorCode' => 'SAVE_FAILED',
                'provider' => 'openai',
                'model' => $model,
                'generationTimeMs' => $elapsed,
            ];
        }

        return [
            'success' => true,
            'imagePath' => $filename,
            'imageUrl' => "/storage/{$filename}",
            'revisedPrompt' => $body['data'][0]['revised_prompt'] ?? null,
            'model' => $model,
            'provider' => 'openai',
            'generationTimeMs' => $elapsed,
            'costUsd' => 0, // OpenAI bills separately
        ];
    }
}
