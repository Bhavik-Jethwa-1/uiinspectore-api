<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 10), 50);
        $page = max((int) $request->input('page', 1), 1);

        $query = $request->user()->projects();
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }
        $paginator = $query
            ->with(['reviews' => function ($q) {
                $q->orderBy('created_at', 'desc');
            }])
            ->withCount('reviews')
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'projects' => $paginator->items(),
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $project = $request->user()->projects()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        ActivityLogger::log($request->user(), 'project_created', "Created project '{$project->name}'", ['project_id' => $project->id]);

        return response()->json(['project' => $project], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $project = $request->user()
            ->projects()
            ->with(['reviews' => function ($q) {
                $q->orderBy('created_at', 'desc');
            }])
            ->findOrFail($id);

        return response()->json(['project' => $project]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $project = $request->user()->projects()->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $project->update($validated);

        return response()->json(['project' => $project]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        // Admins can delete any project; regular users can only delete their own
        if ($request->user()->is_admin) {
            $project = Project::find($id);
        } else {
            $project = $request->user()->projects()->find($id);
        }

        if (!$project) {
            // Already gone or doesn't exist — treat as success from the client's perspective
            return response()->json(['message' => 'Project deleted'], 200);
        }

        $projectName = $project->name;
        $projectId = $project->id;
        $project->delete();

        ActivityLogger::log($request->user(), 'project_deleted', "Deleted project '{$projectName}'", ['project_id' => $projectId]);

        return response()->json(['message' => 'Project deleted']);
    }
}
