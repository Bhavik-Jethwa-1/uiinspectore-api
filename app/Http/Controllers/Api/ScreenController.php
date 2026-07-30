<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;

class ScreenController extends \Illuminate\Routing\Controller 
{
    private function userPath(int $userId): string
    {
        $path = base_path("database/uizard/user_{$userId}.json");
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (!file_exists($path)) file_put_contents($path, json_encode(['projects' => []]));
        return $path;
    }

    private function loadData(int $userId): array
    {
        return json_decode(file_get_contents($this->userPath($userId)), true);
    }

    private function saveData(int $userId, array $data): void
    {
        file_put_contents($this->userPath($userId), json_encode($data, JSON_PRETTY_PRINT));
    }

    private function findProject(array $data, string $projectId): ?array
    {
        foreach ($data['projects'] as &$p) {
            if ($p['id'] === $projectId) return &$p;
        }
        return null;
    }

    public function store(Request $request, string $projectId): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        $data = $this->loadData($user['id']);
        $project = $this->findProject($data, $projectId);

        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $screen = [
            'id' => uniqid('scr_'),
            'name' => $request->input('name', 'Untitled Screen'),
            'width' => $request->input('width', 1440),
            'height' => $request->input('height', 900),
            'background' => $request->input('background', '#f8f9ff'),
            'elements' => $request->input('elements', []),
            'hotspots' => [],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $project['screens'][] = $screen;
        $project['updated_at'] = date('Y-m-d H:i:s');
        $this->saveData($user['id'], $data);

        return response()->json(['success' => true, 'data' => $screen]);
    }

    public function update(Request $request, string $projectId, string $screenId): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        $data = $this->loadData($user['id']);
        $project = $this->findProject($data, $projectId);

        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        foreach ($project['screens'] as &$s) {
            if ($s['id'] === $screenId) {
                if ($request->has('name')) $s['name'] = $request->name;
                if ($request->has('width')) $s['width'] = $request->width;
                if ($request->has('height')) $s['height'] = $request->height;
                if ($request->has('background')) $s['background'] = $request->background;
                if ($request->has('elements')) $s['elements'] = $request->elements;
                if ($request->has('hotspots')) $s['hotspots'] = $request->hotspots;
                $s['updated_at'] = date('Y-m-d H:i:s');
                $project['updated_at'] = date('Y-m-d H:i:s');
                $this->saveData($user['id'], $data);
                return response()->json(['success' => true, 'data' => $s]);
            }
        }

        return response()->json(['error' => 'Screen not found'], 404);
    }

    public function destroy(Request $request, string $projectId, string $screenId): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        $data = $this->loadData($user['id']);
        $project = $this->findProject($data, $projectId);

        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $before = count($project['screens']);
        $project['screens'] = array_values(array_filter($project['screens'], fn($s) => $s['id'] !== $screenId));

        if (count($project['screens']) === $before) {
            return response()->json(['error' => 'Screen not found'], 404);
        }

        $project['updated_at'] = date('Y-m-d H:i:s');
        $this->saveData($user['id'], $data);

        return response()->json(['success' => true, 'message' => 'Deleted']);
    }

    public function duplicate(Request $request, string $projectId, string $screenId): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        $data = $this->loadData($user['id']);
        $project = $this->findProject($data, $projectId);

        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        foreach ($project['screens'] as $s) {
            if ($s['id'] === $screenId) {
                $new = $s;
                $new['id'] = uniqid('scr_');
                $new['name'] = $s['name'] . ' (Copy)';
                $new['created_at'] = date('Y-m-d H:i:s');
                $new['updated_at'] = date('Y-m-d H:i:s');
                $project['screens'][] = $new;
                $project['updated_at'] = date('Y-m-d H:i:s');
                $this->saveData($user['id'], $data);
                return response()->json(['success' => true, 'data' => $new]);
            }
        }

        return response()->json(['error' => 'Screen not found'], 404);
    }
}
