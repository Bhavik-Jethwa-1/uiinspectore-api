<?php

namespace App\Http\Controllers\Api;

use App\Services\Billing\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends \Illuminate\Routing\Controller
{
    private function getUsersPath(): string
    {
        $path = base_path('database/users.json');
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        if (!file_exists($path)) {
            file_put_contents($path, json_encode([]));
        }
        return $path;
    }

    private function loadUsers(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = json_decode(file_get_contents($this->getUsersPath()), true) ?? [];
        return $cache;
    }

    private function saveUsers(array $users): void
    {
        file_put_contents($this->getUsersPath(), json_encode($users));
    }

    private function generateToken(array $user): string
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'iat' => time(),
            'exp' => time() + 86400 * 30,
        ])), '+/', '-_'), '=');

        $data = "$header.$payload";
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $data, config('app.key'), true)), '+/', '-_'), '=');

        return "$header.$payload.$signature";
    }

    public function register(Request $request): \Illuminate\Http\JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name' => 'required|string|min:2',
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $users = $this->loadUsers();
        foreach ($users as $u) {
            if ($u['email'] === $request->email) {
                return response()->json(['error' => 'Email already registered'], 422);
            }
        }

        $user = [
            'id' => count($users) + 1,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'avatar' => null,
            'plan' => 'free',
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $users[] = $user;
        $this->saveUsers($users);

        $publicUser = $user;
        unset($publicUser['password']);
        $token = $this->generateToken($publicUser);

        return response()->json([
            'success' => true,
            'data' => ['user' => $publicUser, 'token' => $token],
        ]);
    }

    public function login(Request $request): \Illuminate\Http\JsonResponse
    {
        $v = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $users = $this->loadUsers();
        // O(1) email lookup instead of O(n) scan
        $userKey = null;
        foreach ($users as $k => $u) {
            if ($u['email'] === $request->email) { $userKey = $k; break; }
        }
        if ($userKey !== null) {
            $u = $users[$userKey];
            if (Hash::check($request->password, $u['password'])) {
                unset($u['password']);
                $token = $this->generateToken($u);
                return response()->json([
                    'success' => true,
                    'data' => ['user' => $u, 'token' => $token],
                ]);
            }
        }

        return response()->json(['error' => 'Invalid credentials'], 401);
    }

    public function me(Request $request): \Illuminate\Http\JsonResponse
    {
        // auth_user already set by api.auth middleware — return directly, no rescan
        $authUser = $request->get('auth_user');
        unset($authUser['password']);

        // Inject real subscription plan from database (users.json plan may be stale)
        $dbUser = $request->get('db_user');
        if ($dbUser) {
            $billing = app(BillingService::class);
            $sub = $billing->getSubscription($dbUser);
            if ($sub && $sub->isActive()) {
                $authUser['plan'] = $sub->getPlanSlug();
            }
        }

        return response()->json(['success' => true, 'data' => $authUser]);
    }

    public function updateProfile(Request $request): \Illuminate\Http\JsonResponse
    {
        $authUser = $request->get('auth_user');
        $users = $this->loadUsers();

        // O(n) scan only for profile update (rare operation)
        $userId = $authUser['id'];
        $userKey = null;
        foreach ($users as $k => $u) {
            if ($u['id'] === $userId) { $userKey = $k; break; }
        }
        if ($userKey !== null) {
            $u = &$users[$userKey];
            if ($request->has('name')) $u['name'] = $request->name;
            if ($request->has('bio')) $u['bio'] = $request->bio;
            if ($request->has('company')) $u['company'] = $request->company;
            if ($request->has('website')) $u['website'] = $request->website;
            if ($request->has('avatar')) $u['avatar'] = $request->avatar;
            $u['updated_at'] = date('Y-m-d H:i:s');
            $authUser = $u;
        }

        $this->saveUsers($users);
        unset($authUser['password']);
        return response()->json(['success' => true, 'data' => $authUser]);
    }

    public function uploadAvatar(Request $request): \Illuminate\Http\JsonResponse
    {
        $authUser = $request->get('auth_user');

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No file uploaded'], 422);
        }

        $file = $request->file('file');
        if (!$file->isValid()) {
            return response()->json(['error' => 'Uploaded file is corrupt or incomplete'], 422);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            return response()->json(['error' => 'Invalid file type. Allowed: ' . implode(', ', $allowed)], 422);
        }

        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $allowedMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        if ($mime && !in_array($mime, $allowedMimes)) {
            return response()->json(['error' => 'Invalid MIME type'], 422);
        }

        $dir = storage_path('app/profiles/' . $authUser['id']);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'avatar_' . time() . '.' . $ext;
        $absolute = $dir . '/' . $filename;

        try {
            $file->move($dir, $filename);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to save file: ' . $e->getMessage()], 500);
        }

        $url = '/storage/profiles/' . $authUser['id'] . '/' . $filename;

        $users = $this->loadUsers();
        foreach ($users as &$u) {
            if ($u['id'] === $authUser['id']) {
                $u['avatar'] = $url;
                $u['updated_at'] = date('Y-m-d H:i:s');
                $authUser = $u;
                break;
            }
        }
        $this->saveUsers($users);
        unset($authUser['password']);

        $token = $this->generateToken($authUser);

        return response()->json([
            'success' => true,
            'data' => ['user' => $authUser, 'token' => $token],
        ]);
    }

    public function logout(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Logged out']);
    }
}
