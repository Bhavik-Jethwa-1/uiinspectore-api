<?php

namespace App\Services\AI;

use App\Services\AI\Providers\ProviderManager;

/**
 * AIService — Central facade for all AI operations.
 *
 * Routes to dedicated services:
 *   ChatService           → /api/ai/chat
 *   VisionService         → /api/ai/analyze
 *   ImageGenerationService → /api/ai/image
 *   CodeGenerationService → /api/ai/code
 */
class AIService
{
    private \App\Services\AI\Providers\ProviderManager $manager;
    private ChatService $chat;
    private VisionService $vision;
    private ImageGenerationService $image;
    private CodeGenerationService $code;

    public function __construct(?\App\Services\AI\Providers\ProviderManager $manager = null)
    {
        $this->manager = $manager ?? new \App\Services\AI\Providers\ProviderManager();
        $this->chat    = new ChatService($this->manager);
        $this->vision  = new VisionService($this->manager);
        $this->image   = new ImageGenerationService($this->manager);
        $this->code    = new CodeGenerationService($this->manager);
    }

    public function chat(array $messages, array $opts = []): array
    {
        return $this->chat->complete($messages, $opts);
    }

    public function streamChat(array $messages, array $opts = []): \Generator
    {
        yield from $this->chat->stream($messages, $opts);
    }

    public function vision(string $imageUrl, string $prompt, array $opts = []): array
    {
        return $this->vision->analyze($imageUrl, $prompt, $opts);
    }

    public function image(string $prompt, array $opts = []): array
    {
        return $this->image->generate($prompt, $opts);
    }

    public function code(array $messages, array $opts = []): array
    {
        return $this->code->generate($messages, $opts);
    }

    public function chatService(): ChatService   { return $this->chat; }
    public function visionService(): VisionService { return $this->vision; }
    public function imageService(): ImageGenerationService { return $this->image; }
    public function codeService(): CodeGenerationService { return $this->code; }

    public function manager(): \App\Services\AI\Providers\ProviderManager
    {
        return $this->manager;
    }

    public function primaryProviderName(): string
    {
        return $this->manager->primaryName();
    }

    public function listProviders(): array
    {
        return $this->manager->listProviders();
    }

    public function isProviderAvailable(string $name): bool
    {
        $p = $this->manager->getProvider($name);
        return $p ? $p->isAvailable() : false;
    }

    public function anyAvailable(): bool
    {
        foreach ($this->manager->listProviders() as $info) {
            if (!empty($info['available'])) return true;
        }
        return false;
    }

    public function health(): array
    {
        $primary = $this->manager->getPrimary();
        if (!$primary || !$primary->isAvailable()) {
            return ['status' => 'unhealthy', 'gateway' => ['status' => 'unavailable']];
        }
        return [
            'status'  => 'healthy',
            'gateway' => ['status' => 'available'],
            'image'   => ['status' => in_array('image', $primary->capabilities()) ? 'available' : 'unavailable'],
            'vision'  => ['status' => in_array('vision', $primary->capabilities()) ? 'available' : 'unavailable'],
            'code'    => ['status' => in_array('code', $primary->capabilities()) ? 'available' : 'unavailable'],
        ];
    }

    public function diagnostic(): array
    {
        $primary = $this->manager->getPrimary();
        $diag = [
            'primary_provider' => $primary ? $primary->providerName() : null,
            'providers' => [],
        ];

        foreach ($this->manager->listProviders() as $name => $info) {
            $diag['providers'][$name] = [
                'available'    => $info['available'],
                'isPrimary'    => $info['isPrimary'],
                'model'       => $info['model'],
                'capabilities' => $info['capabilities'],
            ];
            if ($info['isPrimary']) {
                $diag['chat_model']    = $info['model'];
                $diag['vision_model']  = $info['model'];
                $diag['image_model']   = $info['model'];
                $diag['code_model']    = $info['model'];
            }
        }

        if ($primary) {
            $pn = $primary->providerName();
            $diag['chat_endpoint']   = $pn === 'openai'
                ? 'https://api.openai.com/v1/chat/completions'
                : 'http://127.0.0.1:18789/v1/chat/completions';
            $diag['vision_endpoint'] = $diag['chat_endpoint'];
            $diag['image_endpoint']  = $pn === 'openai'
                ? 'https://api.openai.com/v1/images/generations'
                : 'https://api.minimax.io/v1/image_generation';
            $diag['code_endpoint']   = $diag['chat_endpoint'];
        }

        return $diag;
    }
}
