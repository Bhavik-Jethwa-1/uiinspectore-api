<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ScreenshotController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\IssueController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\AnnotationController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\Billing\BillingController;
use App\Http\Controllers\Api\Billing\WalletController;
use App\Http\Controllers\Api\Billing\AdminBillingController;
use App\Http\Controllers\Api\CreditController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\AiResearchController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\AIChatController;
use App\Http\Controllers\Api\AIGatewayController;
use App\Http\Controllers\Api\AIProvidersController;
use App\Http\Controllers\Api\Admin\AISettingsController;
use App\Http\Controllers\Api\AutoDesignerController;
use App\Http\Controllers\Api\PaymentGatewayController;
use App\Http\Controllers\Api\TemplateController;

Route::get('/health', fn() => response()->json(['status' => 'ok']));

// Auth
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('api.auth');
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('api.auth');
Route::put('/auth/profile', [AuthController::class, 'updateProfile'])->middleware('api.auth');
Route::post('/auth/avatar', [AuthController::class, 'uploadAvatar'])->middleware('api.auth');

// Billing / Subscription
Route::prefix('billing')->middleware('api.auth')->group(function () {
    Route::get('/plans',       [BillingController::class, 'plans']);
    Route::get('/plans/{slug}', [BillingController::class, 'plan']);
    Route::get('/subscription', [BillingController::class, 'subscription']);
    Route::post('/subscribe',  [BillingController::class, 'subscribe']);
    Route::post('/cancel',    [BillingController::class, 'cancel']);
    Route::post('/resume',    [BillingController::class, 'resume']);
    Route::post('/change-plan',[BillingController::class, 'changePlan']);
    Route::get('/usage',       [BillingController::class, 'usage']);
    Route::get('/payments',    [BillingController::class, 'payments']);
    Route::get('/invoices',    [BillingController::class, 'invoices']);
    Route::get('/dashboard',   [BillingController::class, 'dashboard']);
    Route::get('/check-feature/{feature}', [BillingController::class, 'checkFeature']);
    Route::get('/check-usage/{feature}',    [BillingController::class, 'checkUsage']);
    Route::post('/portal',                [BillingController::class, 'billingPortal']);

    // Credits / Wallet (new unified system)
    Route::get('/wallet',               [\App\Http\Controllers\Api\Billing\WalletController::class, 'show']);
    Route::get('/wallet/history',       [\App\Http\Controllers\Api\Billing\WalletController::class, 'history']);
    Route::get('/wallet/usage',          [\App\Http\Controllers\Api\Billing\WalletController::class, 'usage']);
    Route::get('/wallet/pricing',       [\App\Http\Controllers\Api\Billing\WalletController::class, 'pricing']);
    Route::post('/wallet/auto-recharge',[\App\Http\Controllers\Api\Billing\WalletController::class, 'updateAutoRecharge']);
    Route::post('/wallet/topup/prepare',[\App\Http\Controllers\Api\Billing\WalletController::class, 'prepareTopup']);
    Route::post('/wallet/verify-topup', [\App\Http\Controllers\Api\Billing\WalletController::class, 'verifyWalletTopup']);
    Route::get('/wallet/topup/{id}',    [\App\Http\Controllers\Api\Billing\WalletController::class, 'topupStatus']);

    // Legacy credits (keep for backward compat during transition)
    Route::get('/credits/balance',  [CreditController::class, 'balance']);
    Route::get('/credits/packs',    [CreditController::class, 'packs']);
    Route::post('/credits/packs/{id}/purchase', [CreditController::class, 'purchase']);
});

// Admin Billing
Route::prefix('admin/billing')->middleware(['api.auth', 'admin'])->group(function () {
    Route::get('/plans',           [AdminBillingController::class, 'plans']);
    Route::post('/plans',          [AdminBillingController::class, 'createPlan']);
    Route::put('/plans/{id}',      [AdminBillingController::class, 'updatePlan']);
    Route::delete('/plans/{id}',   [AdminBillingController::class, 'deletePlan']);
    Route::get('/subscriptions',   [AdminBillingController::class, 'subscriptions']);
    Route::post('/subscriptions/{id}/cancel', [AdminBillingController::class, 'cancelSubscriptionAdmin']);
    Route::get('/payments',        [AdminBillingController::class, 'payments']);
    Route::post('/payments/{id}/refund', [AdminBillingController::class, 'issueRefund']);
    Route::get('/analytics',       [AdminBillingController::class, 'analytics']);
    Route::get('/usage',           [AdminBillingController::class, 'globalUsage']);

    // New admin billing dashboard
    Route::get('/billing/dashboard',   [\App\Http\Controllers\Api\Billing\AdminBillingController::class, 'dashboard']);
    Route::get('/billing/topups',      [\App\Http\Controllers\Api\Billing\AdminBillingController::class, 'topups']);
    Route::post('/billing/users/{id}/credit', [\App\Http\Controllers\Api\Billing\AdminBillingController::class, 'creditUser']);
});

// Projects (Module 3) - static-prefix routes MUST be declared before /projects/{id}.
Route::get('/projects', [ProjectController::class, 'index'])->middleware('api.auth');
Route::post('/projects', [ProjectController::class, 'store'])->middleware('api.auth');
Route::get('/projects/templates', [ProjectController::class, 'templates'])->middleware('api.auth');
Route::get('/projects/categories', [ProjectController::class, 'categories'])->middleware('api.auth');
Route::get('/projects/tags', [ProjectController::class, 'tags'])->middleware('api.auth');
Route::get('/projects/{id}', [ProjectController::class, 'show'])->middleware('api.auth');
Route::put('/projects/{id}', [ProjectController::class, 'update'])->middleware('api.auth');
Route::delete('/projects/{id}', [ProjectController::class, 'destroy'])->middleware('api.auth');
Route::post('/projects/{id}/duplicate', [ProjectController::class, 'duplicate'])->middleware('api.auth');
Route::post('/projects/{id}/archive', [ProjectController::class, 'archive'])->middleware('api.auth');
Route::get('/projects/{id}/team', [ProjectController::class, 'team'])->middleware('api.auth');
Route::post('/projects/{id}/team', [ProjectController::class, 'addMember'])->middleware('api.auth');
Route::delete('/projects/{id}/team/{memberId}', [ProjectController::class, 'removeMember'])->middleware('api.auth');
Route::get('/projects/{id}/timeline', [ProjectController::class, 'timeline'])->middleware('api.auth');

// Screenshots (Module 5)
Route::get('/projects/{projectId}/screenshots', [ScreenshotController::class, 'index'])->middleware('api.auth');
Route::post('/projects/{projectId}/screenshots', [ScreenshotController::class, 'store'])->middleware('api.auth');
Route::post('/projects/{projectId}/screenshots/upload', [ScreenshotController::class, 'upload'])->middleware('api.auth');
// Project-less screenshot upload (AI Studio, general use)
Route::post('/screenshots', [ScreenshotController::class, 'upload'])->middleware('api.auth');
Route::get('/projects/{projectId}/screenshots/{screenshotId}', [ScreenshotController::class, 'show'])->middleware('api.auth');
Route::delete('/projects/{projectId}/screenshots/{screenshotId}', [ScreenshotController::class, 'destroy'])->middleware('api.auth');

// Analyses
Route::get('/projects/{projectId}/analyses', [AnalysisController::class, 'index'])->middleware('api.auth');
Route::post('/projects/{projectId}/analyses', [AnalysisController::class, 'store'])->middleware('api.auth');
Route::get('/projects/{projectId}/analyses/{analysisId}', [AnalysisController::class, 'show'])->middleware('api.auth');
Route::post('/projects/{projectId}/analyses/{analysisId}/run', [AnalysisController::class, 'run'])->middleware('api.auth');
Route::delete('/projects/{projectId}/analyses/{analysisId}', [AnalysisController::class, 'destroy'])->middleware('api.auth');

// Issues
Route::get('/projects/{projectId}/issues', [IssueController::class, 'index'])->middleware('api.auth');
Route::post('/projects/{projectId}/issues', [IssueController::class, 'store'])->middleware('api.auth');
Route::get('/issues/{id}', [IssueController::class, 'show'])->middleware('api.auth');
Route::put('/issues/{id}', [IssueController::class, 'update'])->middleware('api.auth');
Route::delete('/issues/{id}', [IssueController::class, 'destroy'])->middleware('api.auth');
Route::post('/projects/{projectId}/issues/bulk', [IssueController::class, 'bulkUpdate'])->middleware('api.auth');
Route::get('/projects/{projectId}/issues/stats', [IssueController::class, 'statistics'])->middleware('api.auth');

// Tasks
Route::get('/projects/{projectId}/tasks', [TaskController::class, 'index'])->middleware('api.auth');
Route::post('/projects/{projectId}/tasks', [TaskController::class, 'store'])->middleware('api.auth');
Route::get('/tasks/{id}', [TaskController::class, 'show'])->middleware('api.auth');
Route::put('/tasks/{id}', [TaskController::class, 'update'])->middleware('api.auth');
Route::delete('/tasks/{id}', [TaskController::class, 'destroy'])->middleware('api.auth');
Route::post('/projects/{projectId}/tasks/from-issue/{issueId}', [TaskController::class, 'convertFromIssue'])->middleware('api.auth');

// Reports
Route::get('/projects/{projectId}/reports', [ReportController::class, 'index'])->middleware('api.auth');
Route::post('/projects/{projectId}/reports', [ReportController::class, 'store'])->middleware('api.auth');
Route::get('/reports/{id}', [ReportController::class, 'show'])->middleware('api.auth');
Route::get('/reports/{id}/download', [ReportController::class, 'download'])->middleware('api.auth');
Route::delete('/reports/{id}', [ReportController::class, 'destroy'])->middleware('api.auth');

// Annotations
Route::get('/screenshots/{screenshotId}/annotations', [AnnotationController::class, 'index'])->middleware('api.auth');
Route::post('/screenshots/{screenshotId}/annotations', [AnnotationController::class, 'store'])->middleware('api.auth');
Route::delete('/annotations/{id}', [AnnotationController::class, 'destroy'])->middleware('api.auth');

// AI Chat
Route::get('/projects/{projectId}/chat', [ChatController::class, 'index'])->middleware('api.auth');
Route::post('/projects/{projectId}/chat', [ChatController::class, 'send'])->middleware('api.auth');

// Export
Route::post('/export', [ExportController::class, 'export'])->middleware('api.auth');

// Team
Route::get('/projects/{projectId}/team', [TeamController::class, 'index'])->middleware('api.auth');
Route::post('/projects/{projectId}/team/invite', [TeamController::class, 'invite'])->middleware('api.auth');
Route::put('/projects/{projectId}/team/{memberId}', [TeamController::class, 'updateRole'])->middleware('api.auth');
Route::delete('/projects/{projectId}/team/{memberId}', [TeamController::class, 'remove'])->middleware('api.auth');
Route::get('/projects/{projectId}/activities', [TeamController::class, 'activities'])->middleware('api.auth');

// Billing
Route::get('/billing', [BillingController::class, 'index'])->middleware('api.auth');
Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->middleware('api.auth');
Route::post('/billing/create-checkout', [BillingController::class, 'createCheckoutSession'])->middleware('api.auth');
Route::post('/billing/verify-checkout', [BillingController::class, 'verifyCheckout'])->middleware('api.auth');
Route::post('/billing/cancel', [BillingController::class, 'cancel'])->middleware('api.auth');
Route::get('/billing/invoices', [BillingController::class, 'invoices'])->middleware('api.auth');
Route::get('/billing/usage', [BillingController::class, 'usage'])->middleware('api.auth');

// Payment Gateways
Route::get('/payment-gateways', [PaymentGatewayController::class, 'show'])->middleware('api.auth');
Route::put('/payment-gateways', [PaymentGatewayController::class, 'update'])->middleware('api.auth');

// Integrations
Route::get('/integrations', [IntegrationController::class, 'index'])->middleware('api.auth');
Route::post('/integrations/connect', [IntegrationController::class, 'connect'])->middleware('api.auth');
Route::post('/integrations/{id}/disconnect', [IntegrationController::class, 'disconnect'])->middleware('api.auth');
Route::post('/integrations/{id}/sync', [IntegrationController::class, 'sync'])->middleware('api.auth');
Route::get('/integrations/status', [IntegrationController::class, 'status'])->middleware('api.auth');

// AI Research
Route::post('/ai/research-legacy', [AiResearchController::class, 'research'])->middleware('api.auth');

// Admin
Route::get('/admin/users', [AdminController::class, 'users'])->middleware('api.auth');
Route::get('/admin/users/{id}', [AdminController::class, 'user'])->middleware('api.auth');
Route::put('/admin/users/{id}', [AdminController::class, 'updateUser'])->middleware('api.auth');
Route::get('/admin/analytics', [AdminController::class, 'analytics'])->middleware('api.auth');
Route::get('/admin/feature-flags', [AdminController::class, 'featureFlags'])->middleware('api.auth');
Route::put('/admin/feature-flags/{id}', [AdminController::class, 'updateFeatureFlag'])->middleware('api.auth');
Route::get('/admin/logs', [AdminController::class, 'systemLogs'])->middleware('api.auth');
Route::get('/admin/plans', [AdminController::class, 'plans'])->middleware('api.auth');

// Admin — AI Provider Settings (admin-only)
Route::get('/admin/subscriptions', [AdminController::class, 'subscriptions'])->middleware('api.auth');

// AI (Autodesigner)
// ─── All AI routes now use OpenClaw/MiniMax exclusively ────────────────────────
// All AI features require authentication + subscription feature gate
Route::post('/ai/chat',        [AIController::class, 'chat'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');
Route::post('/ai/stream',      [AIController::class, 'stream'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');
Route::post('/ai/analyze',     [AIController::class, 'analyze'])
    ->middleware('api.auth')->middleware('subscription:screenshot_analysis');
Route::post('/ai/analyze-screenshot', [AIController::class, 'analyze'])
    ->middleware('api.auth')->middleware('subscription:screenshot_analysis');
Route::post('/ai/detect',      [AIController::class, 'detect'])
    ->middleware('api.auth')->middleware('subscription:ai_ui_review');
Route::post('/ai/suggestions', [AIController::class, 'suggestions'])
    ->middleware('api.auth')->middleware('subscription:ai_ui_review');
Route::post('/ai/redesign',    [AIController::class, 'redesign'])
    ->middleware('api.auth')->middleware('subscription:ai_redesign');
Route::post('/ai/copywriting', [AIController::class, 'copywriting'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');
Route::post('/ai/research',    [AIController::class, 'research'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');
Route::post('/ai/consultant', [AIController::class, 'consultant'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');
Route::post('/ai/autodesign',  [AIController::class, 'autodesign'])
    ->middleware('api.auth')->middleware('subscription:ai_autodesigner');
Route::post('/ai/annotate',    [AIController::class, 'annotate'])
    ->middleware('api.auth')->middleware('subscription:screenshot_analysis');

// ─── Legacy / re-exported endpoints ─────────────────────────────────────
Route::post('/ai/autodesign-chat',   [AIController::class, 'chat'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');
Route::post('/ai/analyze-url',       [AIController::class, 'analyze'])
    ->middleware('api.auth')->middleware('subscription:screenshot_analysis');
Route::post('/ai/rewrite',          [AIController::class, 'copywriting'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');

// ─── AI Chat UI (unified, single provider) ─────────────────────────────────
Route::post('/ai/chat/ui',   [AIChatController::class, 'chat'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');
Route::post('/ai/chat/stream/ui', [AIChatController::class, 'stream'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');
Route::post('/ai/image',      [AIChatController::class, 'image'])
    ->middleware('api.auth')->middleware('subscription:basic_image_gen');
Route::get('/ai/providers',   [AIChatController::class, 'providers']);

// ─── Dedicated AI Services ──────────────────────────────────────────────────
// Chat      → ChatService
// Analyze   → VisionService (screenshot, UI, UX, accessibility, typography, color)
// Image     → ImageGenerationService (NEVER uses chat endpoint)
// Code      → CodeGenerationService
// Models    → Available models list (like Gemini/OpenAI models API)
Route::post('/ai/chat',       [AIGatewayController::class, 'chat'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');
Route::post('/ai/analyze',     [AIGatewayController::class, 'analyze'])
    ->middleware('api.auth')->middleware('subscription:screenshot_analysis');
Route::post('/ai/image',       [AIGatewayController::class, 'image'])
    ->middleware('api.auth')->middleware('subscription:basic_image_gen');
Route::post('/ai/code',        [AIGatewayController::class, 'code'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');
Route::get('/ai/models',       [AIGatewayController::class, 'models']);

// ─── User AI Agents (multi-provider) ──────────────────────────────────────
// Users add their own API keys for any provider and switch between them
Route::get('/ai/agents',         [AIProvidersController::class, 'index'])->middleware('api.auth');
Route::post('/ai/agents',         [AIProvidersController::class, 'store'])->middleware('api.auth');
Route::get('/ai/agents/providers', [AIProvidersController::class, 'providers']);
Route::get('/ai/agents/{id}',      [AIProvidersController::class, 'show'])->middleware('api.auth');
Route::put('/ai/agents/{id}',      [AIProvidersController::class, 'update'])->middleware('api.auth');
Route::delete('/ai/agents/{id}',   [AIProvidersController::class, 'destroy'])->middleware('api.auth');
Route::post('/ai/agents/{id}/default', [AIProvidersController::class, 'setDefault'])->middleware('api.auth');
Route::post('/ai/agents/{id}/test',   [AIProvidersController::class, 'test'])->middleware('api.auth');
Route::post('/ai/agents/test',        [AIProvidersController::class, 'testCreate'])->middleware('api.auth');
Route::post('/ai/settings',   [AIChatController::class, 'saveSettings'])->middleware('api.auth');
Route::get('/ai/settings',    [AIChatController::class, 'getSettings'])->middleware('api.auth');
Route::post('/ai/health',     [AIChatController::class, 'health']);

// ─── AI Engine (unified) ─────────────────────────────────────────────────────
Route::post('/ai/engine',      [AIChatController::class, 'engine'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');
Route::post('/ai/engine/stream', [AIChatController::class, 'engineStream'])
    ->middleware('api.auth')->middleware('subscription:ai_chat');

// ─── Admin AI Settings ──────────────────────────────────────────────────────
Route::prefix('admin/ai')->middleware(['api.auth', 'admin'])->group(function () {
    Route::get('/settings',       [AISettingsController::class, 'show']);
    Route::put('/settings',       [AISettingsController::class, 'update']);
    Route::post('/test-connection', [AISettingsController::class, 'testConnection']);
    Route::get('/providers',      [AISettingsController::class, 'providers']);
    Route::get('/diagnostics',     [AIGatewayController::class, 'diagnostics']);
});

// Templates
Route::get('/templates', [TemplateController::class, 'index']);
Route::get('/templates/categories', [TemplateController::class, 'categories']);
Route::get('/templates/{id}', [TemplateController::class, 'show']);

// ─── Premium Auto Designer ──────────────────────────────────────────────────
Route::prefix('auto-designer')->group(function () {
    Route::post('/analyze',        [AutoDesignerController::class, 'analyze']);
    Route::post('/optimize-prompt',[AutoDesignerController::class, 'optimizePrompt']);
    Route::post('/generate',        [AutoDesignerController::class, 'generate']);
    Route::post('/analyze-design',  [AutoDesignerController::class, 'analyzeDesign']);
    Route::post('/redesign',        [AutoDesignerController::class, 'redesign']);
    Route::post('/generate-code',   [AutoDesignerController::class, 'generateCode']);

    // History
    Route::get ('/history',               [AutoDesignerController::class, 'loadHistory']);
    Route::post('/history/save',           [AutoDesignerController::class, 'saveToHistory']);
    Route::delete('/history/{id}',         [AutoDesignerController::class, 'deleteFromHistory']);
    Route::post('/history/{id}/favorite',  [AutoDesignerController::class, 'toggleFavorite']);
});

// ─── AI Studio ───────────────────────────────────────────────────────────
Route::prefix('ai/studio')->middleware('api.auth')->group(function () {
    // Conversations
    Route::get ('/conversations',       [\App\Http\Controllers\Api\AI\AIStudioController::class, 'listConversations']);
    Route::post('/conversations',        [\App\Http\Controllers\Api\AI\AIStudioController::class, 'createConversation']);
    Route::get ('/conversations/{id}',   [\App\Http\Controllers\Api\AI\AIStudioController::class, 'getConversation']);
    Route::put ('/conversations/{id}',   [\App\Http\Controllers\Api\AI\AIStudioController::class, 'updateConversation']);
    Route::delete('/conversations/{id}', [\App\Http\Controllers\Api\AI\AIStudioController::class, 'deleteConversation']);
    Route::post('/conversations/clear-history', [\App\Http\Controllers\Api\AI\AIStudioController::class, 'clearHistory']);
    Route::post('/conversations/{id}/pin', [\App\Http\Controllers\Api\AI\AIStudioController::class, 'pinConversation']);

    // Messages
    Route::get ('/conversations/{conversationId}/messages', [\App\Http\Controllers\Api\AI\AIStudioController::class, 'listMessages']);

    // Chat
    Route::post('/chat',        [\App\Http\Controllers\Api\AI\AIStudioController::class, 'sendMessage']);
    Route::post('/chat/stream',  [\App\Http\Controllers\Api\AI\AIStudioController::class, 'streamMessage']);

    // Providers & Models
    Route::get ('/providers',              [\App\Http\Controllers\Api\AI\AIStudioController::class, 'listProviders']);
    Route::get ('/providers/{provider}',   [\App\Http\Controllers\Api\AI\AIStudioController::class, 'listModels']);
    Route::post('/providers/{provider}/health', [\App\Http\Controllers\Api\AI\AIStudioController::class, 'healthCheck']);

    // User settings
    Route::get ('/settings',  [\App\Http\Controllers\Api\AI\AIStudioController::class, 'getUserSettings']);
    Route::put ('/settings',  [\App\Http\Controllers\Api\AI\AIStudioController::class, 'saveUserSettings']);
});


// ─── UI Inspector (public auth — no middleware) ──────────────────────
Route::prefix('inspector')->group(function () {
    Route::post('/register',    [\App\Http\Controllers\Api\Inspector\InspectorAuthController::class, 'register']);
    Route::post('/login',       [\App\Http\Controllers\Api\Inspector\InspectorAuthController::class, 'login']);
});

// ─── UI Inspector (protected) ─────────────────────────────────────────────
Route::prefix('inspector')->middleware('inspector.auth')->group(function () {
    Route::get ('/me',          [\App\Http\Controllers\Api\Inspector\InspectorAuthController::class, 'me']);
    Route::put ('/profile',     [\App\Http\Controllers\Api\Inspector\InspectorAuthController::class, 'updateProfile']);
    Route::post('/logout',      [\App\Http\Controllers\Api\Inspector\InspectorAuthController::class, 'logout']);
    Route::delete('/account',   [\App\Http\Controllers\Api\Inspector\InspectorAuthController::class, 'deleteAccount']);

    // Projects
    Route::get   ('/projects',               [\App\Http\Controllers\Api\Inspector\InspectorProjectController::class, 'index']);
    Route::post  ('/projects',                [\App\Http\Controllers\Api\Inspector\InspectorProjectController::class, 'store']);
    Route::get   ('/projects/{id}',           [\App\Http\Controllers\Api\Inspector\InspectorProjectController::class, 'show']);
    Route::put   ('/projects/{id}',           [\App\Http\Controllers\Api\Inspector\InspectorProjectController::class, 'update']);
    Route::delete('/projects/{id}',           [\App\Http\Controllers\Api\Inspector\InspectorProjectController::class, 'destroy']);

    // Screenshots
    Route::post  ('/projects/{projectId}/screenshots',  [\App\Http\Controllers\Api\Inspector\InspectorScreenshotController::class, 'store']);
    Route::delete('/screenshots/{id}',                   [\App\Http\Controllers\Api\Inspector\InspectorScreenshotController::class, 'destroy']);

    // Reviews
    Route::post  ('/projects/{projectId}/review',   [\App\Http\Controllers\Api\Inspector\InspectorReviewController::class, 'generate']);
    Route::get   ('/projects/{projectId}/reviews',  [\App\Http\Controllers\Api\Inspector\InspectorReviewController::class, 'forProject']);
    Route::get   ('/reviews/{id}',                  [\App\Http\Controllers\Api\Inspector\InspectorReviewController::class, 'show']);

    // Redesigns
    Route::post  ('/projects/{projectId}/redesign',     [\App\Http\Controllers\Api\Inspector\InspectorRedesignController::class, 'generate']);
    Route::post  ('/redesigns/{id}/regenerate',         [\App\Http\Controllers\Api\Inspector\InspectorRedesignController::class, 'regenerate']);
    Route::get   ('/redesigns/{id}',                    [\App\Http\Controllers\Api\Inspector\InspectorRedesignController::class, 'show']);
});

// ─── Stripe Webhooks (no auth — Stripe signs with webhook secret) ───────────
Route::post('/billing/credits/webhook', [CreditController::class, 'webhook']);
Route::post('/billing/stripe/webhook', [\App\Http\Controllers\Api\Billing\StripeWebhookController::class, 'handle']);
Route::post('/wallet/webhook/stripe', [\App\Http\Controllers\Api\Billing\WalletWebhookController::class, 'stripe']);
