<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Analysis;
use App\Models\Project;
use App\Models\Screenshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ScreenshotController extends \Illuminate\Routing\Controller
{
    /**
     * Allowed MIME / file extensions for uploads.
     */
    private array $allowedMimeTypes = [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg', 'image/jpg'],
        'jpeg' => ['image/jpeg', 'image/jpg'],
        'pdf' => ['application/pdf'],
    ];

    /**
     * Where to store uploaded files on disk.
     */
    private function storageDir(): string
    {
        $dir = storage_path('app/screenshots');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Resolve the authenticated user id.
     * Prefers auth('api')->user() but falls back to the request-level
     * auth_user array set by App\Http\Middleware\ApiAuthMiddleware.
     *
     * Also lazy-syncs the JSON-backed user into the SQL users table so that
     * foreign-key constraints on user_id columns don't fire.
     */
    private function userId(Request $request): ?int
    {
        try {
            $u = auth('api')->user();
            if ($u && isset($u->id)) {
                return (int) $u->id;
            }
        } catch (\Throwable $e) {
            // 'api' guard not configured in this app; fall through to auth_user.
        }
        $authUser = $request->get('auth_user');
        if (is_array($authUser) && isset($authUser['id'])) {
            $this->ensureUserExistsInDb($authUser);
            return (int) $authUser['id'];
        }
        return null;
    }

    /**
     * Make sure an authenticated user (from database/users.json) also has a row
     * in the SQL `users` table so FK constraints don't fire on inserts.
     */
    private function ensureUserExistsInDb(array $authUser): void
    {
        try {
            $id = (int) ($authUser['id'] ?? 0);
            if ($id <= 0) return;
            $existing = \App\Models\User::find($id);
            if ($existing) return;
            \App\Models\User::create([
                'id' => $id,
                'name' => (string) ($authUser['name'] ?? 'User ' . $id),
                'email' => (string) ($authUser['email'] ?? ('user' . $id . '@example.com')),
                'password' => (string) ($authUser['password'] ?? \Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(8)))),
                'created_at' => $authUser['created_at'] ?? now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * Find a project owned by the current user.
     */
    private function findOwnedProject(int $userId, string $projectId): ?Project
    {
        return Project::query()
            ->where('user_id', $userId)
            ->where('id', $projectId)
            ->first();
    }

    /**
     * Best-effort activity log entry.
     */
    private function log(int $userId, int $projectId, string $action, ?string $subjectType = null, ?int $subjectId = null, array $metadata = []): void
    {
        try {
            ActivityLog::create([
                'project_id' => $projectId,
                'user_id' => $userId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * Public disk-relative URL for a stored screenshot.
     */
    private function fileUrl(string $relativePath): string
    {
        // Symlink: /var/www/uiinspectore/storage -> /var/www/uiinspectore-api/storage/app/screenshots
        // Backend stores: user_<id>/<file> inside screenshots dir
        // URL should be: /storage/<user_folder>/<file>
        return '/storage/' . ltrim($relativePath, '/');
    }

    /**
     * GET /api/projects/{projectId}/screenshots
     */
    public function index(Request $request, string $projectId): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwnedProject($userId, $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $query = $project->screenshots()->orderByDesc('created_at');

        if ($version = $request->query('version')) {
            $query->where('version', $version);
        }
        if ($type = $request->query('type')) {
            $query->where('file_type', $type);
        }
        if ($search = $request->query('search')) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where('name', 'like', $like);
        }

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min($perPage, 200));
        $page = $query->paginate($perPage);

        $page->getCollection()->transform(function (Screenshot $s) {
            $s->url = $s->file_path ? $this->fileUrl($s->file_path) : null;
            return $s;
        });

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * POST /api/projects/{projectId}/screenshots
     *
     * Accepts either an uploaded file (multipart "file") OR an external URL.
     */
    public function store(Request $request, string $projectId): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwnedProject($userId, $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $v = Validator::make($request->all(), [
            'name' => 'required_without:file|string|max:255',
            'file' => 'required_without:name|file|max:10240', // 10 MB max; mimes validated below
            'url' => 'nullable|string|max:2048|url',
            'version' => 'nullable|string|max:64',
            'metadata' => 'nullable|array',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $name = $request->input('name') ?: ($request->file('file')?->getClientOriginalName() ?: 'screenshot');
        $version = $request->input('version');
        $metadata = $request->input('metadata', []);

        $filePath = null;
        $fileType = null;
        $fileSize = null;

        // Case 1: file is a freshly uploaded file
        if ($request->hasFile('file')) {
            $result = $this->handleUpload($request);
            if (!empty($result['error'])) {
                return response()->json(['error' => $result['error']], 422);
            }
            $filePath = $result['data']['file_path'];
            $fileType = $result['data']['file_type'];
            $fileSize = $result['data']['file_size'];
        }
        // Case 2: external URL pasted in
        elseif ($url = $request->input('url')) {
            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            $filePath = $url;
            $fileType = in_array($ext, ['png', 'jpg', 'jpeg', 'pdf'], true) ? $ext : 'png';
            $fileSize = null;
        } else {
            // Pure metadata record without an attached file
            $filePath = $request->input('file_path', '');
            $fileType = $request->input('file_type', 'png');
        }

        $screenshot = Screenshot::create([
            'project_id' => $project->id,
            'user_id' => $userId,
            'name' => $name,
            'file_path' => (string) $filePath,
            'file_type' => (string) $fileType,
            'file_size' => $fileSize,
            'version' => $version,
            'metadata' => $metadata,
        ]);

        $screenshot->url = $screenshot->file_path ? $this->fileUrl($screenshot->file_path) : null;

        $this->log($userId, $project->id, 'screenshot.uploaded', 'screenshot', $screenshot->id, [
            'description' => "Uploaded screenshot \"{$screenshot->name}\"",
            'file_type' => $screenshot->file_type,
            'version' => $screenshot->version,
        ]);

        return response()->json(['success' => true, 'data' => $screenshot], 201);
    }

    /**
     * GET /api/projects/{projectId}/screenshots/{screenshotId}
     */
    public function show(Request $request, string $projectId, string $screenshotId): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwnedProject($userId, $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $screenshot = $project->screenshots()->where('id', $screenshotId)->first();
        if (!$screenshot) {
            return response()->json(['error' => 'Screenshot not found'], 404);
        }

        $screenshot->url = $screenshot->file_path ? $this->fileUrl($screenshot->file_path) : null;
        $screenshot->analyses_count = (int) $screenshot->analyses()->count();
        $screenshot->annotations_count = (int) $screenshot->annotations()->count();

        return response()->json(['success' => true, 'data' => $screenshot]);
    }

    /**
     * DELETE /api/projects/{projectId}/screenshots/{screenshotId}
     */
    public function destroy(Request $request, string $projectId, string $screenshotId): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $project = $this->findOwnedProject($userId, $projectId);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $screenshot = $project->screenshots()->where('id', $screenshotId)->first();
        if (!$screenshot) {
            return response()->json(['error' => 'Screenshot not found'], 404);
        }

        // Also delete dependent analyses so the screenshot can be fully purged.
        $screenshot->analyses()->delete();

        $name = $screenshot->name;

        // Best-effort delete of the file from disk (only for local uploads).
        if ($screenshot->file_path && !str_starts_with($screenshot->file_path, 'http')) {
            $absolute = $this->storageDir() . '/' . $screenshot->file_path;
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }

        $screenshot->delete();

        $this->log($userId, $project->id, 'screenshot.deleted', null, null, [
            'description' => "Deleted screenshot \"{$name}\"",
        ]);

        return response()->json(['success' => true, 'message' => 'Screenshot deleted']);
    }

    /**
     * POST /api/projects/{projectId}/screenshots/upload
     *
     * Dedicated endpoint for file upload only (returns payload suitable for chaining
     * into POST /api/projects/{projectId}/screenshots on the front-end).
     */
    public function upload(Request $request, string $projectId = '0'): \Illuminate\Http\JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Optional ownership check; upload is allowed even if projectId missing.
        if ($projectId !== '0' && $projectId !== '') {
            $project = $this->findOwnedProject($userId, $projectId);
            if (!$project) {
                return response()->json(['error' => 'Project not found'], 404);
            }
        }

        $result = $this->handleUpload($request, $projectId);
        if (!empty($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json($result);
    }

    /**
     * Internal helper: validate + persist an uploaded file, return [error?] or [screen=>...].
     *
     * @return array{error?: string, screen?: array}
     */
    private function handleUpload(Request $request, string $projectId): array
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return ['error' => 'Unauthorized'];
        }

        if (!$request->hasFile('file')) {
            return ['error' => 'No file uploaded under "file"'];
        }

        $file = $request->file('file');
        if (!$file->isValid()) {
            return ['error' => 'Uploaded file is corrupt or incomplete'];
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!isset($this->allowedMimeTypes[$ext])) {
            return ['error' => 'Invalid file type. Allowed: ' . implode(', ', array_keys($this->allowedMimeTypes))];
        }

        $realMime = $file->getMimeType() ?: $file->getClientMimeType();
        if ($realMime && !in_array($realMime, $this->allowedMimeTypes[$ext], true)) {
            return [
                'error' => "MIME type mismatch: extension .{$ext} expected one of "
                    . implode(', ', $this->allowedMimeTypes[$ext]) . ", got {$realMime}",
            ];
        }

        // Build a stable, unique on-disk filename: user_<id>/<timestamp>_<rand>.<ext>
        $userFolder = 'user_' . $userId;
        $absoluteDir = $this->storageDir() . '/' . $userFolder;
        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }

        $relative = $userFolder . '/' . date('Ymd_His') . '_' . Str::random(8) . '.' . $ext;
        $absolute = $this->storageDir() . '/' . $relative;

        try {
            $file->move(dirname($absolute), basename($absolute));
        } catch (\Throwable $e) {
            return ['error' => 'Failed to store uploaded file: ' . $e->getMessage()];
        }

        if (!is_file($absolute)) {
            return ['error' => 'Uploaded file did not land on disk'];
        }

        return [
            'data' => [
                'file_path' => $relative,
                'file_type' => $ext,
                'file_size' => (int) filesize($absolute),
                'url' => $this->fileUrl($relative),
            ],
        ];
    }
}
