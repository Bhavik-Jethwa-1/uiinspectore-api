<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AdminDashboardController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/login', [AuthController::class, 'login'])->name('login');

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user', [AuthController::class, 'update']);

    // Dashboard (aggregated)
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Projects
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{id}', [ProjectController::class, 'show']);
    Route::put('/projects/{id}', [ProjectController::class, 'update']);
    Route::delete('/projects/{id}', [ProjectController::class, 'destroy']);

    // Reviews
    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::get('/reviews/{id}', [ReviewController::class, 'show']);
    Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']);
    Route::post('/reviews/{id}/screenshot', [ReviewController::class, 'uploadScreenshot']);
    Route::post('/reviews/{id}/analyze', [ReviewController::class, 'analyze']);
    Route::post('/reviews/{id}/retry', [ReviewController::class, 'retry']);

    // Admin routes
    Route::middleware('admin')->group(function () {
        Route::get('/admin/dashboard', [AdminDashboardController::class, 'index']);
        Route::get('/admin/reviews', [AdminDashboardController::class, 'reviews']);
        Route::get('/admin/projects', [AdminDashboardController::class, 'projects']);
        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::get('/admin/users/{id}', [AdminUserController::class, 'show']);
        Route::patch('/admin/users/{id}', [AdminUserController::class, 'update']);
        Route::put('/admin/users/{id}/settings', [AdminUserController::class, 'updateSettings']);
        Route::delete('/admin/users/{id}/settings', [AdminUserController::class, 'deleteSetting']);
        Route::post('/admin/users/{id}/reset-password', [AdminUserController::class, 'resetPassword']);
        Route::post('/admin/users/{id}/suspend', [AdminUserController::class, 'suspendUser']);
        Route::post('/admin/users/{id}/activate', [AdminUserController::class, 'activateUser']);
        Route::post('/admin/users/{id}/reset-preferences', [AdminUserController::class, 'resetPreferences']);
        Route::get('/admin/settings/meta', [AdminUserController::class, 'settingsMeta']);
        Route::delete('/admin/users/{id}', [AdminUserController::class, 'destroy']);
        Route::get('/admin/settings', [AdminSettingsController::class, 'show']);
        Route::post('/admin/settings', [AdminSettingsController::class, 'store']);
    });
});

// Storage for screenshots (protected by auth for now, can be opened later)
Route::get('/storage/{path}', function ($path) {
    $path = storage_path('app/' . $path);
    if (!file_exists($path)) {
        abort(404);
    }
    return response()->file($path);
})->where('path', '.*');
