<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // projects — frequent user_id + created_at queries
        DB::statement('CREATE INDEX IF NOT EXISTS idx_projects_user_id ON projects(user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_projects_created_at ON projects(created_at)');

        // screenshots — project_id lookups
        DB::statement('CREATE INDEX IF NOT EXISTS idx_screenshots_project_id ON screenshots(project_id)');

        // analyses — project_id + created_at
        DB::statement('CREATE INDEX IF NOT EXISTS idx_analyses_project_id ON analyses(project_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_analyses_created_at ON analyses(created_at)');

        // issues — project_id + severity/status filters
        DB::statement('CREATE INDEX IF NOT EXISTS idx_issues_project_id ON issues(project_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_issues_severity ON issues(severity)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_issues_status ON issues(status)');

        // tasks — project_id lookups
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tasks_project_id ON tasks(project_id)');

        // reports — project_id lookups
        DB::statement('CREATE INDEX IF NOT EXISTS idx_reports_project_id ON reports(project_id)');

        // comments — project_id + user_id
        DB::statement('CREATE INDEX IF NOT EXISTS idx_comments_project_id ON comments(project_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_comments_user_id ON comments(user_id)');

        // annotations — screenshot_id lookups
        DB::statement('CREATE INDEX IF NOT EXISTS idx_annotations_screenshot_id ON annotations(screenshot_id)');

        // activity_logs — project_id + user_id + created_at (feed queries)
        DB::statement('CREATE INDEX IF NOT EXISTS idx_activity_logs_project_id ON activity_logs(project_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_activity_logs_user_id ON activity_logs(user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_activity_logs_created_at ON activity_logs(created_at)');

        // subscriptions — user_id + status
        DB::statement('CREATE INDEX IF NOT EXISTS idx_subscriptions_user_id ON subscriptions(user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_subscriptions_status ON subscriptions(status)');

        // transactions — user_id + created_at (wallet history)
        DB::statement('CREATE INDEX IF NOT EXISTS idx_transactions_user_id ON transactions(user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_transactions_created_at ON transactions(created_at)');

        // credit_purchases — user_id + status
        DB::statement('CREATE INDEX IF NOT EXISTS idx_credit_purchases_user_id ON credit_purchases(user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_credit_purchases_status ON credit_purchases(status)');

        // user_credits — user_id (unique lookup)
        DB::statement('CREATE INDEX IF NOT EXISTS idx_user_credits_user_id ON user_credits(user_id)');

        // credit_packs — is_active
        DB::statement('CREATE INDEX IF NOT EXISTS idx_credit_packs_is_active ON credit_packs(is_active)');

        // wallet_topups — user_id + payment_status
        DB::statement('CREATE INDEX IF NOT EXISTS idx_wallet_topups_user_id ON wallet_topups(user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_wallet_topups_payment_status ON wallet_topups(payment_status)');

        // ai_agents — user_id
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ai_agents_user_id ON ai_agents(user_id)');

        // ai_usage — user_id + created_at (usage dashboard)
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ai_usage_user_id ON ai_usage(user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ai_usage_created_at ON ai_usage(created_at)');
    }

    public function down(): void
    {
        $indexes = [
            'idx_projects_user_id', 'idx_projects_created_at',
            'idx_screenshots_project_id',
            'idx_analyses_project_id', 'idx_analyses_created_at',
            'idx_issues_project_id', 'idx_issues_severity', 'idx_issues_status',
            'idx_tasks_project_id', 'idx_reports_project_id',
            'idx_comments_project_id', 'idx_comments_user_id',
            'idx_annotations_screenshot_id',
            'idx_activity_logs_project_id', 'idx_activity_logs_user_id', 'idx_activity_logs_created_at',
            'idx_subscriptions_user_id', 'idx_subscriptions_status',
            'idx_transactions_user_id', 'idx_transactions_created_at',
            'idx_credit_purchases_user_id', 'idx_credit_purchases_status',
            'idx_user_credits_user_id', 'idx_credit_packs_is_active',
            'idx_wallet_topups_user_id', 'idx_wallet_topups_payment_status',
            'idx_ai_agents_user_id', 'idx_ai_usage_user_id', 'idx_ai_usage_created_at',
        ];
        foreach ($indexes as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }
    }
};
