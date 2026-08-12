<?php

namespace App\Exceptions;

use Exception;

/**
 * Used when the AI provider returns an error response (429 rate limit, 401 auth, etc.)
 */
class AIResponseException extends Exception
{
    public function __construct(
        public int $statusCode,
        public ?string $errorBody = null,
    ) {
        $message = "AI API request failed: {$statusCode}";
        parent::__construct($message, $statusCode);
    }

    public function isRateLimit(): bool
    {
        return $this->statusCode === 429;
    }

    public function isAuthError(): bool
    {
        return in_array($this->statusCode, [401, 403]);
    }
}
