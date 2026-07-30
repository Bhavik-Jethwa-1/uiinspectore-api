<?php

namespace App\DTOs\AI;

class ChatResponse
{
    public function __construct(
        public readonly string          $reply,
        public readonly string          $model,
        public readonly string          $provider,
        public readonly array           $usage = [],
        public readonly ?string         $error = null,
        public readonly bool            $streaming = false,
        public readonly ?string         $finishReason = null,
    ) {}

    public function toArray(): array
    {
        if ($this->error) {
            return ['error' => $this->error];
        }

        return array_filter([
            'reply'       => $this->reply,
            'model'       => $this->model,
            'provider'    => $this->provider,
            'usage'       => $this->usage,
            'finish_reason' => $this->finishReason,
        ], fn($v) => $v !== null && $v !== []);
    }

    public static function error(string $message, int $status = 500): array
    {
        return [
            'error'   => $message,
            'status'  => $status,
        ];
    }
}
