<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;

class InspectorAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $userData = $this->verifyToken($token);
        if (!$userData) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        $uid = (int) ($userData['uid'] ?? 0);
        if ($uid <= 0) {
            return response()->json(['error' => 'Invalid token payload'], 401);
        }

        $dbUser = User::find($uid);
        if (!$dbUser) {
            return response()->json(['error' => 'User not found'], 401);
        }

        // Include both 'id' and 'uid' so existing controllers work
        $request->merge([
            'auth_user' => [
                'id'    => $uid,
                'uid'   => $uid,
                'email' => $dbUser->email,
                'name'  => $dbUser->name,
            ],
            'db_user' => $dbUser,
        ]);
        auth()->setUser($dbUser);
        $request->setUserResolver(fn() => $dbUser);

        return $next($request);
    }

    private function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;

        [$payloadB64, $sigB64] = $parts;

        $decodedSig = $this->base64UrlDecode($sigB64);
        if (!$decodedSig) return null;

        $secret = config('app.key', 'fallback-secret-key');
        $computedSig = substr(hash_hmac('sha256', $payloadB64, $secret), 0, 16);

        if (!hash_equals($decodedSig, $computedSig)) return null;

        $payload = $this->base64UrlDecode($payloadB64);
        if (!$payload) return null;

        $data = json_decode($payload, true);
        if (!$data) return null;

        $exp = $data['exp'] ?? 0;
        if ($exp > 0 && $exp < time()) return null;

        $uid = $data['uid'] ?? 0;
        if ($uid <= 0) return null;

        return [
            'uid'   => (int) $uid,
            'email' => $data['email'] ?? null,
            'name'  => $data['name'] ?? null,
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
