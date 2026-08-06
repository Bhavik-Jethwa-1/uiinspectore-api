<?php

namespace App\Http\Controllers\Api\Inspector;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class InspectorAuthController extends Controller
{
    /**
     * POST /api/inspector/register
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $token = $this->generateToken($user);

        return response()->json([
            'success' => true,
            'user' => $this->userInfo($user),
            'token' => $token,
        ], 201);
    }

    /**
     * POST /api/inspector/login
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['success' => false, 'error' => 'Invalid credentials'], 401);
        }

        $token = $this->generateToken($user);

        return response()->json([
            'success' => true,
            'user' => $this->userInfo($user),
            'token' => $token,
        ]);
    }

    /**
     * GET /api/inspector/me
     */
    public function me(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        return response()->json([
            'success' => true,
            'user' => $this->userInfo($user),
        ]);
    }

    /**
     * PUT /api/inspector/profile
     */
    public function updateProfile(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'current_password' => ['nullable', 'string'],
            'password' => ['sometimes', 'confirmed', Password::min(8)],
        ]);

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }
        if (isset($data['email']) && $data['email'] !== $user->email) {
            $user->email = $data['email'];
        }
        if (isset($data['password'])) {
            if (empty($data['current_password'])) {
                return response()->json(['success' => false, 'error' => 'Current password is required to change password'], 400);
            }
            if (!Hash::check($data['current_password'], $user->password)) {
                return response()->json(['success' => false, 'error' => 'Current password is incorrect'], 400);
            }
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return response()->json([
            'success' => true,
            'user' => $this->userInfo($user),
        ]);
    }

    /**
     * POST /api/inspector/logout
     */
    public function logout(Request $request)
    {
        // Stateless JWT — just return success
        return response()->json(['success' => true]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function generateToken(User $user): string
    {
        $payload = base64_encode(json_encode([
            'uid' => $user->id,
            'email' => $user->email,
            'iat' => time(),
            'exp' => time() + (86400 * 30),
        ]));

        // Simple signature
        $secret = config('app.key', 'fallback-secret-key');
        $sig = base64_encode(substr(hash_hmac('sha256', $payload, $secret), 0, 16));
        return $payload . '.' . $sig;
    }

    private function getUser(Request $request)
    {
        $auth = $request->get('auth_user');
        if (!$auth) {
            return null;
        }
        return User::find($auth['id']);
    }

    private function userInfo(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    public function deleteAccount(Request $request)
    {
        $auth = $request->get('auth_user');
        if (!$auth) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $user = User::find($auth['id']);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $userId = $user->id;

        // Delete all related data
        $projects = \App\Models\Inspector\UiProject::where('user_id', $userId)->pluck('id');

        foreach ($projects as $projectId) {
            // Get reviews first (before deleting them)
            $reviews = \App\Models\Inspector\UiReview::where('ui_project_id', $projectId)->pluck('id');

            // Delete suggestions & annotations by review
            foreach ($reviews as $reviewId) {
                \App\Models\Inspector\UiSuggestion::where('ui_review_id', $reviewId)->delete();
                \App\Models\Inspector\UiAnnotation::where('ui_review_id', $reviewId)->delete();
            }
            \App\Models\Inspector\UiReview::where('ui_project_id', $projectId)->delete();

            // Delete screenshots
            \App\Models\Inspector\UiScreenshot::where('ui_project_id', $projectId)->delete();

            // Delete redesigns and generated codes (generated_codes has ui_redesign_id, not ui_project_id)
            $redesigns = \App\Models\Inspector\UiRedesign::where('ui_project_id', $projectId)->pluck('id');
            foreach ($redesigns as $redesignId) {
                \App\Models\Inspector\UiGeneratedCode::where('ui_redesign_id', $redesignId)->delete();
            }
            \App\Models\Inspector\UiRedesign::where('ui_project_id', $projectId)->delete();
        }

        // Delete projects
        \App\Models\Inspector\UiProject::where('user_id', $userId)->delete();

        // Delete user
        $user->delete();

        return response()->json(['success' => true, 'message' => 'Account deleted successfully']);
    }
}
