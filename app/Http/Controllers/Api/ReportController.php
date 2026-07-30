<?php

namespace App\Http\Controllers\Api;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReportController extends \Illuminate\Routing\Controller
{
    /**
     * Allowed values for type/format enums (kept in sync with migrations).
     */
    private const ALLOWED_TYPES = ['executive', 'ui', 'ux', 'accessibility', 'conversion', 'product'];
    private const ALLOWED_FORMATS = ['pdf', 'markdown', 'json'];

    /**
     * Resolve the authenticated API user.
     */
    private function authUser(Request $request): ?\App\Models\User
    {
        $user = auth('api')->user();
        if ($user) {
            return $user;
        }

        // Fallback: read auth_user attribute set by ApiAuthMiddleware
        $payload = $request->attributes->get('auth_user') ?? $request->input('auth_user');
        if ($payload && isset($payload['id'])) {
            $existing = \App\Models\User::find($payload['id']);
            if ($existing) {
                return $existing;
            }
            $hydrated = new \App\Models\User();
            $hydrated->setRawAttributes([
                'id' => (int) $payload['id'],
                'name' => $payload['name'] ?? '',
                'email' => $payload['email'] ?? '',
            ], true);
            $hydrated->exists = true;
            return $hydrated;
        }

        return null;
    }

    /**
     * Validate the project belongs to the current user.
     */
    private function ensureProject(Request $request, int $projectId): JsonResponse|Project
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $project = Project::find($projectId);
        if (!$project) {
            return response()->json(['success' => false, 'error' => 'Project not found'], 404);
        }

        if ((int) $project->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        return $project;
    }

    /**
     * Build the report payload (issues summary + metadata) for a given project.
     */
    private function buildReportContent(Project $project, string $type): array
    {
        $issues = Issue::where('project_id', $project->id)->get();

        $summary = [
            'total_issues' => $issues->count(),
            'by_severity' => $issues->groupBy('severity')->map->count()->toArray(),
            'by_type' => $issues->groupBy('type')->map->count()->toArray(),
            'by_status' => $issues->groupBy('status')->map->count()->toArray(),
        ];

        $sections = [];

        if ($type === 'executive' || $type === 'product') {
            $sections['overview'] = [
                'project' => $project->name,
                'description' => $project->description,
                'generated_at' => now()->toIso8601String(),
            ];
            $sections['summary'] = $summary;
            $sections['top_critical'] = $issues
                ->where('severity', 'critical')
                ->where('status', '!=', 'resolved')
                ->take(10)
                ->values()
                ->toArray();
        }

        if ($type === 'ui' || $type === 'product') {
            $sections['ui_issues'] = $issues
                ->where('type', 'ui')
                ->values()
                ->toArray();
        }

        if ($type === 'ux' || $type === 'product') {
            $sections['ux_issues'] = $issues
                ->where('type', 'ux')
                ->values()
                ->toArray();
        }

        if ($type === 'accessibility') {
            $sections['accessibility_issues'] = $issues
                ->where('type', 'accessibility')
                ->values()
                ->toArray();
            $sections['summary'] = $summary;
        }

        if ($type === 'conversion') {
            $sections['conversion_issues'] = $issues
                ->where('type', 'conversion')
                ->values()
                ->toArray();
            $sections['summary'] = $summary;
        }

        if ($type === 'executive') {
            $sections['recommendations'] = $issues
                ->whereNotNull('recommendation')
                ->where('severity', 'critical')
                ->pluck('recommendation')
                ->filter()
                ->values()
                ->toArray();
        }

        return [
            'type' => $type,
            'project_id' => $project->id,
            'project_name' => $project->name,
            'generated_at' => now()->toIso8601String(),
            'summary' => $summary,
            'sections' => $sections,
            'all_issues' => $issues->toArray(),
        ];
    }

    /**
     * Serialize a content array into the requested format and write to disk.
     * Returns the absolute file path.
     */
    private function writeReportFile(Report $report, array $content, string $format): string
    {
        $dir = storage_path("app/reports/{$report->project_id}");
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = sprintf('report_%d_%s.%s', $report->id, date('Ymd_His'), $this->extensionFor($format));
        $path = "{$dir}/{$filename}";

        switch ($format) {
            case 'json':
                file_put_contents($path, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                break;

            case 'markdown':
                file_put_contents($path, $this->renderMarkdown($report, $content));
                break;

            case 'pdf':
            default:
                // No PDF library configured: render a plain-text/HTML file as a placeholder
                // so the download endpoint always has a real file to serve. Real PDF rendering
                // can be plugged in later (e.g. dompdf/spatie/laravel-pdf) without changing
                // the controller contract.
                file_put_contents($path, $this->renderMarkdown($report, $content));
                break;
        }

        return $path;
    }

    /**
     * File extension for the requested format.
     */
    private function extensionFor(string $format): string
    {
        return match ($format) {
            'markdown' => 'md',
            'json' => 'json',
            default => 'pdf',
        };
    }

    /**
     * Render content array as a simple Markdown document.
     */
    private function renderMarkdown(Report $report, array $content): string
    {
        $lines = [];
        $lines[] = "# {$report->title}";
        $lines[] = '';
        $lines[] = "- **Type:** " . ucfirst($report->type);
        $lines[] = "- **Project:** {$content['project_name']}";
        $lines[] = "- **Generated:** {$content['generated_at']}";
        $lines[] = '';

        if (!empty($content['summary'])) {
            $lines[] = "## Summary";
            $lines[] = '';
            $lines[] = "- Total issues: {$content['summary']['total_issues']}";
            foreach ($content['summary']['by_severity'] ?? [] as $k => $v) {
                $lines[] = "- {$k}: {$v}";
            }
            $lines[] = '';
        }

        if (!empty($content['sections']['top_critical'])) {
            $lines[] = "## Top Critical Issues";
            foreach ($content['sections']['top_critical'] as $issue) {
                $lines[] = "- **" . ($issue['title'] ?? 'Untitled') . "** — " . ($issue['category'] ?? '');
            }
            $lines[] = '';
        }

        if (!empty($content['sections']['recommendations'])) {
            $lines[] = "## Recommendations";
            foreach ($content['sections']['recommendations'] as $rec) {
                $lines[] = "- {$rec}";
            }
            $lines[] = '';
        }

        $issuesList = $content['sections']['ui_issues']
            ?? $content['sections']['ux_issues']
            ?? $content['sections']['accessibility_issues']
            ?? $content['sections']['conversion_issues']
            ?? [];

        if (!empty($issuesList)) {
            $lines[] = "## Issues";
            foreach ($issuesList as $issue) {
                $lines[] = "### " . ($issue['title'] ?? 'Untitled');
                $lines[] = '';
                $lines[] = "- Severity: " . ($issue['severity'] ?? 'n/a');
                $lines[] = "- Category: " . ($issue['category'] ?? 'n/a');
                $lines[] = "- Status: " . ($issue['status'] ?? 'n/a');
                if (!empty($issue['description'])) {
                    $lines[] = '';
                    $lines[] = $issue['description'];
                }
                if (!empty($issue['recommendation'])) {
                    $lines[] = '';
                    $lines[] = "**Recommendation:** " . $issue['recommendation'];
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * GET /api/projects/{projectId}/reports
     * List reports for a project.
     */
    public function index(Request $request, string $projectId): JsonResponse
    {
        $check = $this->ensureProject($request, (int) $projectId);
        if ($check instanceof JsonResponse) {
            return $check;
        }

        $query = Report::query()->where('project_id', (int) $projectId);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('format')) {
            $query->where('format', $request->input('format'));
        }

        $reports = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    /**
     * POST /api/projects/{projectId}/reports
     * Generate a report for a project.
     */
    public function store(Request $request, string $projectId): JsonResponse
    {
        $check = $this->ensureProject($request, (int) $projectId);
        if ($check instanceof JsonResponse) {
            return $check;
        }
        $project = $check;

        $validator = Validator::make(array_merge($request->all(), ['project_id' => (int) $projectId]), [
            'project_id' => 'required|integer|exists:projects,id',
            'type' => 'required|string|in:' . implode(',', self::ALLOWED_TYPES),
            'title' => 'required|string|max:255',
            'format' => 'sometimes|string|in:' . implode(',', self::ALLOWED_FORMATS),
            'content' => 'sometimes|nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $this->authUser($request);
        $data = $validator->validated();
        $format = $data['format'] ?? 'pdf';

        // Create the record first to get an id for file naming
        $report = Report::create([
            'project_id' => (int) $projectId,
            'user_id' => $user?->id ?? null,
            'type' => $data['type'],
            'title' => $data['title'],
            'format' => $format,
            'content' => $data['content'] ?? null,
            'generated_at' => now(),
        ]);

        // Build content (use caller-provided content if given, otherwise auto-build)
        $content = $data['content'] ?? $this->buildReportContent($project, $data['type']);

        // Persist the rendered content JSON for reference
        if ($report->content === null) {
            $report->content = $content;
        }

        // Generate and store the report file
        try {
            $path = $this->writeReportFile($report, $content, $format);
            $report->file_path = $path;
            $report->save();
        } catch (\Throwable $e) {
            // Keep the report row even if file write fails so the user can retry
            $report->save();
            return response()->json([
                'success' => true,
                'data' => $report->fresh(),
                'warning' => 'Report created but file generation failed: ' . $e->getMessage(),
            ], 201);
        }

        return response()->json([
            'success' => true,
            'data' => $report->fresh(),
        ], 201);
    }

    /**
     * GET /api/reports/{id}
     * Show report details.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $report = Report::with('project')->find((int) $id);
        if (!$report) {
            return response()->json(['success' => false, 'error' => 'Report not found'], 404);
        }

        $project = Project::find($report->project_id);
        if (!$project || (int) $project->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    /**
     * GET /api/reports/{id}/download
     * Return the file path and metadata so the client can download it.
     */
    public function download(Request $request, string $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $report = Report::find((int) $id);
        if (!$report) {
            return response()->json(['success' => false, 'error' => 'Report not found'], 404);
        }

        $project = Project::find($report->project_id);
        if (!$project || (int) $project->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        if (!$report->file_path || !file_exists($report->file_path)) {
            return response()->json([
                'success' => false,
                'error' => 'Report file is not available',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $report->id,
                'title' => $report->title,
                'format' => $report->format,
                'file_path' => $report->file_path,
                'file_url' => url('/api/reports/' . $report->id . '/file'),
                'file_name' => basename($report->file_path),
                'size' => filesize($report->file_path),
                'mime_type' => $this->mimeFor($report->format),
            ],
        ]);
    }

    /**
     * DELETE /api/reports/{id}
     * Delete a report (record + file).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $report = Report::find((int) $id);
        if (!$report) {
            return response()->json(['success' => false, 'error' => 'Report not found'], 404);
        }

        $project = Project::find($report->project_id);
        if (!$project || (int) $project->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        // Best-effort file cleanup; do not block on filesystem errors
        if ($report->file_path && file_exists($report->file_path)) {
            @unlink($report->file_path);
        }

        $report->delete();

        return response()->json([
            'success' => true,
            'data' => ['id' => (int) $id, 'message' => 'Report deleted'],
        ]);
    }

    /**
     * Map a format to a mime type for download responses.
     */
    private function mimeFor(string $format): string
    {
        return match ($format) {
            'markdown' => 'text/markdown',
            'json' => 'application/json',
            default => 'application/pdf',
        };
    }
}
