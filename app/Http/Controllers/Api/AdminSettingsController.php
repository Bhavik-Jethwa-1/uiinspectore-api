<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class AdminSettingsController extends Controller
{
    private function getSettingsPath()
    {
        return base_path('.ai_settings.json');
    }

    public function show(Request $request): \Illuminate\Http\JsonResponse
    {
        $path = $this->getSettingsPath();
        if (File::exists($path)) {
            $data = json_decode(File::get($path), true);
            $key = $data['openai_key'] ?? '';
            // Always return masked key — never expose the actual key to the frontend
            if ($key && strlen($key) > 4) {
                $masked = 'sk-...' . substr($key, -4);
            } else {
                $masked = $key ? 'sk-...' . substr($key, -1) : '';
            }
            return response()->json(['openai_key' => $masked]);
        }
        return response()->json(['openai_key' => '']);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'openai_key' => 'nullable|string|max:255',
        ]);

        $path = $this->getSettingsPath();

        // Preserve existing key if no new key is provided
        $existingKey = '';
        if (File::exists($path)) {
            $existingData = json_decode(File::get($path), true);
            $existingKey = $existingData['openai_key'] ?? '';
        }

        $newKey = $validated['openai_key'] ?? null;

        // Determine if this looks like the masked version of the existing key
        // Masked format: "sk-..." + last 4 chars of real key
        $isMaskedValue = false;
        if ($newKey !== null && $existingKey && strlen($existingKey) > 4) {
            $existingMasked = 'sk-...' . substr($existingKey, -4);
            if ($newKey === $existingMasked) {
                $isMaskedValue = true;
            }
        }

        // Only update if a genuinely new key is provided (not empty, not masked)
        if ($newKey !== null && $newKey !== '' && strlen($newKey) > 8 && !$isMaskedValue) {
            $keyToSave = $newKey;
        } else {
            $keyToSave = $existingKey;
        }

        File::put($path, json_encode([
            'openai_key' => $keyToSave,
            'updated_at' => now()->toIso8601String(),
            'updated_by' => $request->user()->id,
        ], JSON_PRETTY_PRINT));

        // Return masked key so frontend gets fresh masked value
        $masked = '';
        if ($keyToSave && strlen($keyToSave) > 4) {
            $masked = 'sk-...' . substr($keyToSave, -4);
        }

        return response()->json(['message' => 'Settings saved', 'openai_key' => $masked]);
    }
}
