<?php

namespace App\Services\AI;

/**
 * Interface for AI Image Generation Providers
 * 
 * All image generation providers must implement this interface.
 * The factory creates the appropriate provider based on environment.
 */
interface ImageProviderInterface
{
    /**
     * Get provider display name
     */
    public function getName(): string;

    /**
     * Get provider ID
     */
    public function getId(): string;

    /**
     * Get available models for this provider
     */
    public function getModels(): array;

    /**
     * Check if provider is available and working
     */
    public function availability(): array;

    /**
     * Generate an image (text-to-image or image-to-image)
     * 
     * @param string|null $inputImagePath Path to input image for img2img, null for text2img
     * @param string $prompt Text prompt describing desired output
     * @param array $options Generation options (model, size, strength, etc.)
     * @return array Result with success flag, image path or error
     */
    public function generate(?string $inputImagePath, string $prompt, array $options = []): array;

    /**
     * Check if this provider supports image-to-image generation
     */
    public function supportsImg2Img(): bool;
}
