<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Additional performance indexes based on actual table schemas.
     * Covers composite indexes for common query patterns.
     */
    public function up(): void
    {
        // ── Projects: composite indexes for common access patterns ──────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_projects_user_status ON projects(user_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_projects_user_archived ON projects(user_id, archived_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_projects_updated_desc ON projects(updated_at DESC)');

        // ── Screenshots: composite + foreign key ─────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_screenshots_project_created ON screenshots(project_id, created_at DESC)');

        // ── Analyses: heavy aggregation queries ──────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_analyses_project_created ON analyses(project_id, created_at DESC)');

        // ── Issues: filter + sort combos ──────────────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_issues_project_severity ON issues(project_id, severity)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_issues_project_status ON issues(project_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_issues_severity_status ON issues(severity, status)');

        // ── Tasks: project + status ─────────────────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tasks_project_status ON tasks(project_id, status)');

        // ── Comments: recent comments feed ───────────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_comments_project_created ON comments(project_id, created_at DESC)');

        // ── Team Members: invite token lookups ───────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_team_members_invite_token ON team_members(invite_token)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_team_members_project_status ON team_members(project_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_team_members_user_project ON team_members(user_id, project_id)');

        // ── AI Usage: analytics + rate limiting ────────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ai_usage_user_created ON ai_usage(user_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ai_usage_user_feature ON ai_usage(user_id, feature)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ai_usage_provider_created ON ai_usage(provider, created_at)');

        // ── Activity Logs: recent feed queries ───────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_activity_logs_project_created ON activity_logs(project_id, created_at DESC)');

        // ── Transactions: wallet history ───────────────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_transactions_user_created ON transactions(user_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_transactions_user_type ON transactions(user_id, type)');

        // ── Notifications: user + read status ─────────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, read_at)');

        // ── Jobs table: failed jobs cleanup ───────────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_jobs_queue ON jobs(queue)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_jobs_available ON jobs(available_at)');

        // ── Organizations: owner lookups ───────────────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_organizations_owner ON organizations(owner_id)');

        // ── Credit Packs: active packs ordering ─────────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_credit_packs_active_sort ON credit_packs(is_active, sort_order)');

        // ── Wallet Transactions: wallet lookups ──────────────────────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_wallet_transactions_wallet_created ON wallet_transactions(wallet_id, created_at DESC)');
    }

    public function down(): void
    {
        $indexes = [
            'idx_projects_user_status', 'idx_projects_user_archived', 'idx_projects_updated_desc',
            'idx_screenshots_project_created', 'idx_analyses_project_created',
            'idx_issues_project_severity', 'idx_issues_project_status', 'idx_issues_severity_status',
            'idx_tasks_project_status', 'idx_comments_project_created',
            'idx_team_members_invite_token', 'idx_team_members_project_status', 'idx_team_members_user_project',
            'idx_ai_usage_user_created', 'idx_ai_usage_user_feature', 'idx_ai_usage_provider_created',
            'idx_activity_logs_project_created', 'idx_transactions_user_created',
            'idx_transactions_user_type', 'idx_notifications_user_read',
            'idx_jobs_queue', 'idx_jobs_available',
            'idx_organizations_owner', 'idx_credit_packs_active_sort',
            'idx_wallet_transactions_wallet_created',
        ];

        foreach ($indexes as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }
    }
};
