<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Screenshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StorageController extends Controller
{
    /**
     * Serve a screenshot file with proper authorization.
     * 
     * Path format: screenshots/{stored_name}
     * Files are stored in storage/app/private/screenshots/
     */
    public function screenshot(Request $request, string $path): StreamedResponse
    {
        // Authenticate the request
        $user = $request->user();
        
        // Only authenticated users can access files
        if (!$user) {
            abort(401, 'Unauthorized');
        }
        
        // Validate path format - only allow screenshots directory
        if (!str_starts_with($path, 'screenshots/')) {
            abort(403, 'Forbidden');
        }
        
        // Extract the stored filename
        $filename = basename($path);
        
        // Find the screenshot by stored name
        $screenshot = Screenshot::where('stored_name', $filename)->first();
        
        if (!$screenshot) {
            abort(404, 'File not found');
        }
        
        // Verify ownership: user must own the project that owns this screenshot
        $project = $screenshot->project;
        
        if (!$project || $project->user_id !== $user->id) {
            // Also check if user is admin (admins can access all)
            if (!$user->is_admin) {
                abort(403, 'Forbidden');
            }
        }
        
        // Build the correct file path (files are in private/screenshots/)
        $fullPath = 'screenshots/' . $filename;
        
        // Verify file exists on disk
        if (!Storage::disk('local')->exists($fullPath)) {
            abort(404, 'File not found on disk');
        }
        
        // Get the MIME type
        $mimeType = Storage::disk('local')->mimeType($fullPath);
        
        // Serve the file
        $disk = Storage::disk('local');
        $absolutePath = $disk->path($fullPath);
        
        return response()->stream(
            function () use ($absolutePath) {
                readfile($absolutePath);
            },
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
                'Cache-Control' => 'private, max-age=3600',
            ]
        );
    }
}
