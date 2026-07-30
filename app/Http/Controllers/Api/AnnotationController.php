<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AnnotationController extends \Illuminate\Routing\Controller
{
    /** Allowed annotation types */
    private array $validTypes = ['highlight', 'arrow', 'rectangle', 'freehand'];

    /** Allowed severity levels */
    private array $validSeverities = ['info', 'low', 'medium', 'high', 'critical'];

    /**
     * Resolve the authenticated user.
     *
     * Tries `auth('api')->user()` first (spec requirement) and falls back to
     * the custom ApiAuthMiddleware, which sets the user on the request as
     * `auth_user`. Either way returns the same user array.
     */
    private function authUser(Request $request): ?array
    {
        $u = auth('api')->user();
        if ($u) {
            return is_object($u) ? (array) $u : $u;
        }
        return $request->get('auth_user');
    }

    private function userPath(int $userId): string
    {
        return base_path("database/uizard/user_{$userId}.json");
    }

    private function loadData(int $userId): array
    {
        $path = $this->userPath($userId);
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!file_exists($path)) {
            file_put_contents($path, json_encode(['projects' => [], 'annotations' => []]));
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            $data = ['projects' => [], 'annotations' => []];
        }
        if (!isset($data['annotations']) || !is_array($data['annotations'])) {
            $data['annotations'] = [];
        }
        return $data;
    }

    private function saveData(int $userId, array $data): void
    {
        $path = $this->userPath($userId);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * GET /api/annotations
     *
     * List annotations for a screenshot (or for the current user, optionally
     * filtered by project, screenshot or type). Query params:
     *   - screenshot_id (optional)
     *   - project_id    (optional)
     *   - type          (optional: highlight|arrow|rectangle|freehand)
     */
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $this->loadData((int) $user['id']);
        $annotations = $data['annotations'] ?? [];

        $screenshotId = $request->input('screenshot_id');
        $projectId    = $request->input('project_id');
        $type         = $request->input('type');

        if ($screenshotId) {
            $annotations = array_values(array_filter(
                $annotations,
                fn($a) => (string) ($a['screenshot_id'] ?? '') === (string) $screenshotId
            ));
        }
        if ($projectId) {
            $annotations = array_values(array_filter(
                $annotations,
                fn($a) => (string) ($a['project_id'] ?? '') === (string) $projectId
            ));
        }
        if ($type) {
            $annotations = array_values(array_filter(
                $annotations,
                fn($a) => (string) ($a['type'] ?? '') === (string) $type
            ));
        }

        usort($annotations, fn($a, $b) => ($a['created_at'] ?? '') <=> ($b['created_at'] ?? ''));

        return response()->json([
            'success' => true,
            'data'    => $annotations,
            'count'   => count($annotations),
        ]);
    }

    /**
     * POST /api/annotations
     *
     * Create a new annotation.
     * Required fields: screenshot_id, type, x, y
     * Optional: severity, width, height, points, color, note, project_id
     */
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $v = Validator::make($request->all(), [
            'screenshot_id' => 'required|string',
            'project_id'    => 'nullable|string',
            'type'          => 'required|string|in:' . implode(',', $this->validTypes),
            'severity'      => 'nullable|string|in:' . implode(',', $this->validSeverities),
            'x'             => 'required|numeric',
            'y'             => 'required|numeric',
            'width'         => 'nullable|numeric',
            'height'        => 'nullable|numeric',
            'points'        => 'nullable|array',
            'color'         => 'nullable|string|max:32',
            'note'          => 'nullable|string|max:2000',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $annotation = [
            'id'            => uniqid('ann_'),
            'screenshot_id' => (string) $request->input('screenshot_id'),
            'project_id'    => $request->input('project_id') ? (string) $request->input('project_id') : null,
            'user_id'       => (int) $user['id'],
            'type'          => (string) $request->input('type'),
            'severity'      => (string) ($request->input('severity') ?? 'info'),
            'x'             => (float) $request->input('x'),
            'y'             => (float) $request->input('y'),
            'width'         => $request->has('width')  ? (float) $request->input('width')  : null,
            'height'        => $request->has('height') ? (float) $request->input('height') : null,
            'points'        => $request->has('points') ? $request->input('points') : null,
            'color'         => $request->has('color')  ? (string) $request->input('color') : null,
            'note'          => $request->has('note')   ? (string) $request->input('note')  : null,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        $data = $this->loadData((int) $user['id']);
        $data['annotations'][] = $annotation;
        $this->saveData((int) $user['id'], $data);

        return response()->json(['success' => true, 'data' => $annotation], 201);
    }

    /**
     * DELETE /api/annotations/{id}
     */
    public function destroy(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $this->loadData((int) $user['id']);
        $before = count($data['annotations']);

        $data['annotations'] = array_values(array_filter(
            $data['annotations'],
            fn($a) => (string) ($a['id'] ?? '') !== (string) $id
        ));

        if (count($data['annotations']) === $before) {
            return response()->json(['error' => 'Annotation not found'], 404);
        }

        $this->saveData((int) $user['id'], $data);

        return response()->json([
            'success' => true,
            'message' => 'Annotation deleted',
            'id'      => $id,
        ]);
    }
}
