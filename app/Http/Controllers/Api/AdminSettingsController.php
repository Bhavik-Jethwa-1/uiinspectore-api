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
            return response()->json($data);
        }
        return response()->json(['openai_key' => '']);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'openai_key' => 'nullable|string|max:255',
        ]);

        $path = $this->getSettingsPath();
        File::put($path, json_encode([
            'openai_key' => $validated['openai_key'] ?? '',
            'updated_at' => now()->toIso8601String(),
            'updated_by' => $request->user()->id,
        ], JSON_PRETTY_PRINT));

        return response()->json(['message' => 'Settings saved', 'openai_key' => $validated['openai_key'] ?? '']);
    }
}
