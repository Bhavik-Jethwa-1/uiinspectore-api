<?php

namespace App\Http\Controllers\Api\Inspector;

use App\Http\Controllers\Controller;
use App\Models\Inspector\UiProject;
use App\Models\Inspector\UiScreenshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InspectorScreenshotController extends Controller
{
    private function getUserId(Request $request): ?int
    {
        $auth = $request->get('auth_user');
        return $auth ? (int) $auth['id'] : null;
    }

    /**
     * POST /api/inspector/projects/{projectId}/screenshots
     * Upload a screenshot (multipart file upload)
     */
    public function store(Request $request, int $projectId)
    {
        $userId = $this->getUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $project = UiProject::where('id', $projectId)->where('user_id', $userId)->first();
        if (!$project) {
            return response()->json(['success' => false, 'error' => 'Project not found'], 404);
        }

        $request->validate([
            'screenshot' => ['required', 'image', 'max:10240'], // 10MB max
            'page_goal' => ['nullable', 'string', 'max:500'],
            'persona' => ['nullable', 'string', 'max:50'],
        ]);

        $file = $request->file('screenshot');
        $ext = $file->getClientOriginalExtension() ?: 'png';
        $filename = Str::uuid() . '.' . $ext;

        // Save to public storage
        $path = $file->storeAs('inspector-screenshots', $filename, 'public');

        $screenshot = UiScreenshot::create([
            'ui_project_id' => $project->id,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'variant' => 'original',
            'page_goal' => $request->input('page_goal'),
            'persona' => $request->input('persona', 'general'),
        ]);

        return response()->json([
            'success' => true,
            'screenshot' => [
                'id' => $screenshot->id,
                'file_path' => $screenshot->file_path,
                'file_name' => $screenshot->file_name,
                'file_size' => $screenshot->file_size,
                'variant' => $screenshot->variant,
                'page_goal' => $screenshot->page_goal,
                'persona' => $screenshot->persona,
                'url' => "/storage/{$path}",
                'created_at' => $screenshot->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * DELETE /api/inspector/screenshots/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $userId = $this->getUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $screenshot = UiScreenshot::find($id);
        if (!$screenshot) {
            return response()->json(['success' => false, 'error' => 'Screenshot not found'], 404);
        }

        // Verify ownership
        $project = UiProject::where('id', $screenshot->ui_project_id)->where('user_id', $userId)->first();
        if (!$project) {
            return response()->json(['success' => false, 'error' => 'Not authorized'], 403);
        }

        // Delete file
        if ($screenshot->file_path && Storage::disk('public')->exists($screenshot->file_path)) {
            Storage::disk('public')->delete($screenshot->file_path);
        }

        $screenshot->delete();

        return response()->json(['success' => true]);
    }
}
