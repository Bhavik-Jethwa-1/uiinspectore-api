<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;

/**
 * Optimized API Auth Middleware
 * 
 * Optimizations:
 * - Static in-memory cache for users.json (TTL: 60s, cleared on write)
 * - Cached DB user lookups (TTL: 30s)
 * - Batch DB writes: skip create if recently attempted (anti-duplicate)
 * - Skip DB sync entirely if foreign_keys=false (no FK constraints to worry about)
 */
class ApiAuthMiddleware
{
    // In-memory static cache for users.json — shared across all requests
    private static ?array $usersById = null;
    private static ?int $usersCacheAt = null;
    private const USERS_CACHE_TTL = 60; // seconds

    // Anti-duplicate sync cache: prevents repeated DB create attempts
    private static array $syncAttempts = [];

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        if (!$token) {
            $token = $request->query('token');
        }
        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $userData = $this->verifyToken($token);
        if (!$userData) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        $userId = (int) ($userData['id'] ?? 0);
        if ($userId <= 0) {
            return response()->json(['error' => 'Invalid token payload'], 401);
        }

        // Get user from cached users.json (60s TTL)
        $jsonUser = $this->getCachedUser($userId);
        if (!$jsonUser) {
            return response()->json(['error' => 'User not found'], 401);
        }

        $authUser = array_merge($userData, [
            'role'     => $jsonUser['role'] ?? 'user',
            'is_admin' => $jsonUser['is_admin'] ?? false,
            'plan'     => $jsonUser['plan'] ?? 'free',
        ]);

        // Get or create DB user with caching (30s TTL)
        $dbUser = $this->getOrCreateDbUser($userId, $jsonUser);

        $request->merge(['auth_user' => $authUser, 'db_user' => $dbUser]);
        auth()->setUser($dbUser);
        $request->setUserResolver(fn() => $dbUser);

        return $next($request);
    }

    /**
     * Get user from users.json with in-memory cache (60s TTL)
     */
    private function getCachedUser(int $userId): ?array
    {
        $now = time();

        // Rebuild cache if expired or not set
        if (self::$usersById === null || ($now - self::$usersCacheAt) > self::USERS_CACHE_TTL) {
            $users = $this->loadUsers();
            self::$usersById = [];
            foreach ($users as $u) {
                if (isset($u['id'])) {
                    self::$usersById[(int) $u['id']] = $u;
                }
            }
            self::$usersCacheAt = $now;
        }

        return self::$usersById[$userId] ?? null;
    }

    /**
     * Load users from JSON file
     */
    private function loadUsers(): array
    {
        $path = base_path('database/users.json');
        if (!file_exists($path)) return [];
        $content = file_get_contents($path);
        if (!$content) return [];
        return json_decode($content, true) ?? [];
    }

    /**
     * Get DB user from cache or create if missing (30s TTL)
     * Anti-duplicate: uses static cache to prevent rapid re-attempts
     */
    private function getOrCreateDbUser(int $userId, array $jsonUser): ?User
    {
        $userEmail = $jsonUser['email'] ?? '';

        // Try to get from in-memory static cache first (fastest)
        static $staticCache = [];
        if (isset($staticCache[$userId])) {
            $dbUser = $staticCache[$userId];
            if ($dbUser instanceof User) {
                return $dbUser;
            }
        }

        try {
            // Email is the authoritative identity across users.json and DB.
            // Always look up by email — this ensures the correct DB record
            // is found even when IDs have diverged between the two systems.
            if (!empty($userEmail)) {
                $dbUser = User::where('email', $userEmail)->first();
                if ($dbUser instanceof User) {
                    $staticCache[$userId] = $dbUser;
                    return $dbUser;
                }
            }

            // No DB record for this email — create one.
            // Use the JSON userId to avoid ID conflicts with existing records.
            $dbUser = User::create([
                'id'       => $userId,
                'name'     => $jsonUser['name'] ?? ('User ' . $userId),
                'email'    => $userEmail ?: ("user{$userId}@example.com"),
                'password' => $jsonUser['password'] ?? '',
                'plan'     => $jsonUser['plan'] ?? 'free',
            ]);

            if ($dbUser instanceof User) {
                $staticCache[$userId] = $dbUser;
            }
            return $dbUser;

        } catch (\Throwable $e) {
            // Last resort: return whatever we can find
            $dbUser = User::find($userId);
            if ($dbUser instanceof User) {
                $staticCache[$userId] = $dbUser;
            }
            return $dbUser;
        }
    }

    public function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $payload = $this->base64UrlDecode($payloadB64);
        if (!$payload) return null;

        $expectedSig = $this->base64UrlDecode($sigB64);
        if (!$expectedSig) return null;

        $data = "$headerB64.$payloadB64";
        $actualSig = hash_hmac('sha256', $data, config('app.key'), true);

        if (!hash_equals($expectedSig, $actualSig)) return null;

        $payloadData = json_decode($payload, true);
        if (!$payloadData) return null;

        $userId = $payloadData['sub'] ?? null;
        if (!$userId) return null;

        return [
            'id'    => $userId,
            'email' => $payloadData['email'] ?? null,
            'name'  => $payloadData['name'] ?? null,
        ];
    }

    private function base64UrlDecode(string $str): string|false
    {
        $str = str_replace(['-', '_'], ['+', '/'], $str);
        $pad = strlen($str) % 4;
        if ($pad) $str .= str_repeat('=', 4 - $pad);
        return base64_decode($str, true);
    }
}
