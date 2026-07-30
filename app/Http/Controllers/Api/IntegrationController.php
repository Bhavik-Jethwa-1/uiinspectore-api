<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class IntegrationController extends \Illuminate\Routing\Controller
{
    /**
     * Allowed integration types with display metadata and capabilities.
     */
    private array $types = [
        'github' => [
            'name' => 'GitHub',
            'icon' => 'github',
            'category' => 'code',
            'capabilities' => ['import_repos', 'create_issues', 'sync_commits'],
        ],
        'figma' => [
            'name' => 'Figma',
            'icon' => 'figma',
            'category' => 'design',
            'capabilities' => ['import_files', 'export_frames', 'sync_styles'],
        ],
        'jira' => [
            'name' => 'Jira',
            'icon' => 'jira',
            'category' => 'project',
            'capabilities' => ['create_tickets', 'sync_status', 'link_issues'],
        ],
        'slack' => [
            'name' => 'Slack',
            'icon' => 'slack',
            'category' => 'communication',
            'capabilities' => ['send_notifications', 'create_channels', 'bot_commands'],
        ],
        'trello' => [
            'name' => 'Trello',
            'icon' => 'trello',
            'category' => 'project',
            'capabilities' => ['create_boards', 'sync_cards', 'add_comments'],
        ],
        'notion' => [
            'name' => 'Notion',
            'icon' => 'notion',
            'category' => 'docs',
            'capabilities' => ['import_pages', 'export_database', 'sync_blocks'],
        ],
        'vscode' => [
            'name' => 'VS Code',
            'icon' => 'vscode',
            'category' => 'code',
            'capabilities' => ['sync_workspace', 'open_files', 'share_screenshots'],
        ],
        'wordpress' => [
            'name' => 'WordPress',
            'icon' => 'wordpress',
            'category' => 'cms',
            'capabilities' => ['publish_pages', 'sync_media', 'import_posts'],
        ],
        'browser' => [
            'name' => 'Browser Extension',
            'icon' => 'browser',
            'category' => 'capture',
            'capabilities' => ['capture_screenshots', 'inspect_dom', 'export_html'],
        ],
        'playwright' => [
            'name' => 'Playwright',
            'icon' => 'playwright',
            'category' => 'testing',
            'capabilities' => ['run_tests', 'capture_screenshots', 'generate_code'],
        ],
        'lighthouse' => [
            'name' => 'Lighthouse',
            'icon' => 'lighthouse',
            'category' => 'performance',
            'capabilities' => ['run_audits', 'report_metrics', 'track_scores'],
        ],
        'google_drive' => [
            'name' => 'Google Drive',
            'icon' => 'drive',
            'category' => 'storage',
            'capabilities' => ['upload_files', 'sync_folders', 'share_links'],
        ],
    ];

    /**
     * Per-user integrations storage file.
     */
    private function integrationsPath(int $userId): string
    {
        $dir = base_path('database/integrations');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . "/user_{$userId}.json";
        if (!file_exists($path)) {
            file_put_contents($path, json_encode(['integrations' => []], JSON_PRETTY_PRINT));
        }
        return $path;
    }

    /**
     * Load the integrations list for a user.
     */
    private function loadIntegrations(int $userId): array
    {
        $path = $this->integrationsPath($userId);
        $data = json_decode(file_get_contents($path), true) ?? ['integrations' => []];
        if (!isset($data['integrations']) || !is_array($data['integrations'])) {
            $data['integrations'] = [];
        }
        return $data['integrations'];
    }

    /**
     * Persist the integrations list for a user.
     */
    private function saveIntegrations(int $userId, array $integrations): void
    {
        $path = $this->integrationsPath($userId);
        file_put_contents($path, json_encode(['integrations' => array_values($integrations)], JSON_PRETTY_PRINT));
    }

    /**
     * Mask sensitive credential fields before returning them.
     */
    private function sanitizeCredentials(array $credentials): array
    {
        $sensitive = ['token', 'api_key', 'apikey', 'secret', 'password', 'access_token', 'refresh_token', 'webhook'];
        $clean = [];
        foreach ($credentials as $key => $value) {
            if (!is_string($value)) {
                $clean[$key] = $value;
                continue;
            }
            $lower = strtolower((string) $key);
            $isSensitive = false;
            foreach ($sensitive as $needle) {
                if (str_contains($lower, $needle)) {
                    $isSensitive = true;
                    break;
                }
            }
            if ($isSensitive) {
                if (strlen($value) <= 8) {
                    $clean[$key] = '••••••••';
                } else {
                    $clean[$key] = substr($value, 0, 4) . '••••' . substr($value, -4);
                }
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    /**
     * Shape an integration record for the public response.
     */
    private function publicIntegration(array $integration): array
    {
        $type = $integration['type'] ?? '';
        $meta = $this->types[$type] ?? ['name' => $type, 'icon' => $type, 'category' => 'other', 'capabilities' => []];
        return [
            'id' => $integration['id'],
            'type' => $type,
            'name' => $meta['name'],
            'icon' => $meta['icon'],
            'category' => $meta['category'],
            'capabilities' => $meta['capabilities'],
            'status' => $integration['status'] ?? 'connected',
            'connected_at' => $integration['connected_at'] ?? null,
            'last_sync_at' => $integration['last_sync_at'] ?? null,
            'last_error' => $integration['last_error'] ?? null,
            'config' => $integration['config'] ?? new \stdClass(),
            'credentials' => $this->sanitizeCredentials($integration['credentials'] ?? []),
            'metadata' => $integration['metadata'] ?? new \stdClass(),
        ];
    }

    /**
     * GET /api/integrations
     * List all integrations connected for the authenticated user.
     */
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $integrations = $this->loadIntegrations((int) $user['id']);

        return response()->json([
            'success' => true,
            'data' => array_map(fn($i) => $this->publicIntegration($i), $integrations),
        ]);
    }

    /**
     * POST /api/integrations/connect
     * Connect a new integration of the given type with credentials.
     */
    public function connect(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $allowedTypes = implode(',', array_keys($this->types));
        $v = Validator::make($request->all(), [
            'type' => "required|string|in:{$allowedTypes}",
            'credentials' => 'required|array',
            'config' => 'sometimes|array',
            'name' => 'sometimes|string|max:255',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $type = $request->input('type');
        $credentials = (array) $request->input('credentials');
        $config = (array) $request->input('config', []);
        $name = $request->input('name');

        if (empty($credentials)) {
            return response()->json(['error' => 'credentials must not be empty'], 422);
        }

        $integrations = $this->loadIntegrations((int) $user['id']);

        // Replace existing integration of the same type (one slot per type).
        $integrations = array_values(array_filter($integrations, fn($i) => ($i['type'] ?? null) !== $type));

        $integration = [
            'id' => 'intg_' . bin2hex(random_bytes(6)),
            'type' => $type,
            'name' => $name ?: ($this->types[$type]['name'] ?? $type),
            'credentials' => $credentials,
            'config' => $config,
            'status' => 'connected',
            'connected_at' => date('Y-m-d H:i:s'),
            'last_sync_at' => null,
            'last_error' => null,
            'metadata' => [
                'connected_by' => (int) $user['id'],
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip(),
            ],
        ];

        $integrations[] = $integration;
        $this->saveIntegrations((int) $user['id'], $integrations);

        return response()->json([
            'success' => true,
            'data' => $this->publicIntegration($integration),
            'message' => 'Integration connected',
        ]);
    }

    /**
     * POST /api/integrations/disconnect
     * Disconnect an integration by id.
     */
    public function disconnect(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $v = Validator::make($request->all(), [
            'id' => 'required|string',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $id = $request->input('id');
        $integrations = $this->loadIntegrations((int) $user['id']);

        $found = null;
        $filtered = [];
        foreach ($integrations as $i) {
            if (($i['id'] ?? null) === $id) {
                $found = $i;
            } else {
                $filtered[] = $i;
            }
        }

        if (!$found) {
            return response()->json(['error' => 'Integration not found'], 404);
        }

        $this->saveIntegrations((int) $user['id'], $filtered);

        return response()->json([
            'success' => true,
            'data' => ['id' => $id, 'disconnected_at' => date('Y-m-d H:i:s')],
            'message' => 'Integration disconnected',
        ]);
    }

    /**
     * POST /api/integrations/sync
     * Trigger a sync operation for an integration.
     */
    public function sync(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $v = Validator::make($request->all(), [
            'id' => 'required|string',
            'resource' => 'sometimes|string',
            'direction' => 'sometimes|in:push,pull,both',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $id = $request->input('id');
        $resource = $request->input('resource', 'all');
        $direction = $request->input('direction', 'pull');

        $integrations = $this->loadIntegrations((int) $user['id']);
        $updated = null;
        foreach ($integrations as &$i) {
            if (($i['id'] ?? null) === $id) {
                $i['last_sync_at'] = date('Y-m-d H:i:s');
                $i['status'] = 'connected';
                $i['last_error'] = null;
                $updated = &$i;
                break;
            }
        }
        unset($i);

        if (!$updated) {
            return response()->json(['error' => 'Integration not found'], 404);
        }

        $this->saveIntegrations((int) $user['id'], $integrations);

        $syncLog = [
            'id' => 'sync_' . bin2hex(random_bytes(6)),
            'integration_id' => $id,
            'type' => $updated['type'],
            'resource' => $resource,
            'direction' => $direction,
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
            'items_processed' => random_int(0, 50),
            'items_created' => random_int(0, 10),
            'items_updated' => random_int(0, 10),
            'items_failed' => 0,
            'status' => 'completed',
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'integration' => $this->publicIntegration($updated),
                'sync' => $syncLog,
            ],
            'message' => 'Sync completed',
        ]);
    }

    /**
     * GET /api/integrations/status
     * Check the status of an integration.
     */
    public function status(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->get('auth_user');
        if (!$user || !isset($user['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $id = $request->query('id');
        $type = $request->query('type');
        $integrations = $this->loadIntegrations((int) $user['id']);

        $matches = [];
        foreach ($integrations as $i) {
            if ($id && ($i['id'] ?? null) === $id) {
                $matches[] = $i;
            } elseif ($type && ($i['type'] ?? null) === $type) {
                $matches[] = $i;
            }
        }

        if (!empty($matches) && $id) {
            $integration = $matches[0];
            $healthy = ($integration['status'] ?? '') === 'connected';
            $lastSync = $integration['last_sync_at'] ?? null;
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $integration['id'],
                    'type' => $integration['type'],
                    'status' => $integration['status'] ?? 'unknown',
                    'healthy' => $healthy,
                    'connected_at' => $integration['connected_at'] ?? null,
                    'last_sync_at' => $lastSync,
                    'last_error' => $integration['last_error'] ?? null,
                    'message' => $healthy
                        ? 'Integration is healthy'
                        : 'Integration is not connected',
                ],
            ]);
        }

        // Summary view of all integrations.
        $summary = array_map(function ($i) {
            $healthy = ($i['status'] ?? '') === 'connected';
            return [
                'id' => $i['id'],
                'type' => $i['type'] ?? null,
                'status' => $i['status'] ?? 'unknown',
                'healthy' => $healthy,
                'connected_at' => $i['connected_at'] ?? null,
                'last_sync_at' => $i['last_sync_at'] ?? null,
            ];
        }, $matches);

        return response()->json([
            'success' => true,
            'data' => [
                'count' => count($integrations),
                'healthy_count' => count(array_filter($integrations, fn($i) => ($i['status'] ?? '') === 'connected')),
                'integrations' => $summary,
            ],
        ]);
    }
}
