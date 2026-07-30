<?php

namespace Database\Seeders;

use App\Models\Billing\{Plan, FeaturePermission};
use Illuminate\Database\Seeder;

class BillingSeeder extends Seeder
{
    public function run(): void
    {
        // ── FREE ────────────────────────────────────────────────────────────
        $free = Plan::updateOrCreate(
            ['slug' => 'free'],
            [
                'name'          => 'Free',
                'price_monthly' => 0,
                'price_yearly'  => 0,
                'description'   => 'Get started with basic AI-powered UI analysis. No credit card required.',
                'sort_order'    => 1,
                'is_active'     => true,
                'limits'   => [
                    'ai_generations'       => 10,
                    'image_generations'     => 10,
                    'ai_chat'              => 50,
                    'screenshot_analysis'    => 20,
                    'projects'             => 2,
                    'exports'               => 5,
                    'templates'            => 3,
                    'storage_mb'           => 100,
                    'team_members'          => 1,
                    'history_days'         => 7,
                ],
                'features' => [
                    'ai_chat'              => true,
                    'ai_ui_review'         => true,
                    'screenshot_analysis'   => true,
                    'basic_image_gen'      => true,
                    'basic_templates'       => true,
                    'export_png'           => true,
                    'history'              => true,
                    'basic_dashboard'      => true,
                    'ai_autodesigner'      => false,
                    'ai_redesign'          => false,
                    'ai_prompt_optimizer'  => false,
                    'premium_templates'    => false,
                    'advanced_dashboard'   => false,
                    'export_react'         => false,
                    'export_nextjs'        => false,
                    'export_tailwind'      => false,
                    'export_html'          => false,
                    'export_vue'           => false,
                    'export_components'    => false,
                    'version_history'      => false,
                    'ai_design_comparison'=> false,
                    'ai_suggestions'       => false,
                    'ai_accessibility'     => false,
                    'ai_ux_report'        => false,
                    'ai_color_analysis'    => false,
                    'batch_processing'     => false,
                    'api_access'           => false,
                    'priority_support'     => false,
                    'team_workspace'       => false,
                    'organization_management' => false,
                    'role_management'      => false,
                    'invite_members'       => false,
                    'shared_projects'      => false,
                    'shared_assets'        => false,
                    'team_templates'       => false,
                    'activity_logs'        => false,
                    'audit_logs'           => false,
                    'team_billing'         => false,
                    'team_usage_analytics'=> false,
                    'webhooks'             => false,
                    'sso_ready'            => false,
                    'white_label'          => false,
                    'custom_branding'      => false,
                    'dedicated_support'    => false,
                    'custom_onboarding'    => false,
                    'enterprise_security'  => false,
                    'figma_export'         => false,
                    'react_export'         => false,
                    'nextjs_export'        => false,
                    'vue_export'           => false,
                    'unlimited_history'    => false,
                ],
            ]
        );
        $this->seedFeaturePermissions($free);

        // ── PRO ──────────────────────────────────────────────────────────────
        $pro = Plan::updateOrCreate(
            ['slug' => 'pro'],
            [
                'name'          => 'Pro',
                'price_monthly' => 19,
                'price_yearly'  => 190,
                'description'   => 'Everything you need to build and ship AI-powered interfaces faster.',
                'sort_order'    => 2,
                'is_active'     => true,
                'limits'   => [
                    'ai_generations'       => -1,    // unlimited
                    'image_generations'     => -1,
                    'ai_chat'              => -1,
                    'screenshot_analysis'  => -1,
                    'projects'             => -1,
                    'exports'              => -1,
                    'templates'            => 40,
                    'storage_mb'           => 20480, // 20GB
                    'team_members'         => 5,
                    'history_days'         => -1,    // unlimited
                ],
                'features' => [
                    'ai_chat'              => true,
                    'ai_ui_review'         => true,
                    'screenshot_analysis'  => true,
                    'basic_image_gen'      => true,
                    'basic_templates'      => true,
                    'export_png'           => true,
                    'history'              => true,
                    'basic_dashboard'      => true,
                    'ai_autodesigner'      => true,
                    'ai_redesign'          => true,
                    'ai_prompt_optimizer'  => true,
                    'premium_templates'    => true,
                    'advanced_dashboard'   => true,
                    'export_react'         => true,
                    'export_nextjs'        => true,
                    'export_tailwind'      => true,
                    'export_html'          => true,
                    'export_vue'           => true,
                    'export_components'    => true,
                    'dark_light_theme'     => true,
                    'version_history'      => true,
                    'ai_design_comparison'=> true,
                    'ai_suggestions'       => true,
                    'ai_accessibility'     => true,
                    'ai_ux_report'        => true,
                    'ai_color_analysis'    => true,
                    'batch_processing'     => true,
                    'api_access'           => true,
                    'priority_support'     => true,
                    'team_workspace'       => false,
                    'organization_management' => false,
                    'role_management'      => false,
                    'invite_members'       => false,
                    'shared_projects'      => false,
                    'shared_assets'        => false,
                    'team_templates'       => false,
                    'activity_logs'        => false,
                    'audit_logs'           => false,
                    'team_billing'         => false,
                    'team_usage_analytics'=> false,
                    'webhooks'             => false,
                    'sso_ready'            => false,
                    'white_label'          => false,
                    'custom_branding'      => false,
                    'dedicated_support'    => false,
                    'custom_onboarding'    => false,
                    'enterprise_security'  => false,
                    'figma_export'         => false,
                    'react_export'         => true,
                    'nextjs_export'        => true,
                    'vue_export'           => true,
                    'unlimited_history'    => true,
                ],
            ]
        );
        $this->seedFeaturePermissions($pro);

        // ── TEAM ────────────────────────────────────────────────────────────
        $team = Plan::updateOrCreate(
            ['slug' => 'team'],
            [
                'name'          => 'Team',
                'price_monthly' => 49,
                'price_yearly'  => 490,
                'description'   => 'Everything in Pro, plus team collaboration and enterprise features.',
                'sort_order'    => 3,
                'is_active'     => true,
                'limits'   => [
                    'ai_generations'       => -1,
                    'image_generations'    => -1,
                    'ai_chat'             => -1,
                    'screenshot_analysis'  => -1,
                    'projects'            => -1,
                    'exports'            => -1,
                    'templates'           => -1,
                    'storage_mb'          => 102400, // 100GB
                    'team_members'        => 5,     // base seats
                    'history_days'        => -1,
                ],
                'features' => [
                    'ai_chat'              => true,
                    'ai_ui_review'         => true,
                    'screenshot_analysis'  => true,
                    'basic_image_gen'      => true,
                    'basic_templates'      => true,
                    'export_png'           => true,
                    'history'              => true,
                    'basic_dashboard'      => true,
                    'ai_autodesigner'      => true,
                    'ai_redesign'          => true,
                    'ai_prompt_optimizer'  => true,
                    'premium_templates'    => true,
                    'advanced_dashboard'   => true,
                    'export_react'         => true,
                    'export_nextjs'        => true,
                    'export_tailwind'      => true,
                    'export_html'          => true,
                    'export_vue'           => true,
                    'export_components'    => true,
                    'dark_light_theme'     => true,
                    'version_history'      => true,
                    'ai_design_comparison'=> true,
                    'ai_suggestions'       => true,
                    'ai_accessibility'     => true,
                    'ai_ux_report'        => true,
                    'ai_color_analysis'    => true,
                    'batch_processing'     => true,
                    'api_access'           => true,
                    'priority_support'     => true,
                    'team_workspace'       => true,
                    'organization_management' => true,
                    'role_management'      => true,
                    'invite_members'       => true,
                    'shared_projects'      => true,
                    'shared_assets'        => true,
                    'team_templates'       => true,
                    'activity_logs'        => true,
                    'audit_logs'           => true,
                    'team_billing'         => true,
                    'team_usage_analytics'=> true,
                    'webhooks'             => true,
                    'sso_ready'            => true,
                    'white_label'          => true,
                    'custom_branding'      => true,
                    'dedicated_support'    => true,
                    'custom_onboarding'    => true,
                    'enterprise_security'  => true,
                    'figma_export'         => false,
                    'react_export'         => true,
                    'nextjs_export'        => true,
                    'vue_export'           => true,
                    'unlimited_history'    => true,
                ],
            ]
        );
        $this->seedFeaturePermissions($team);
    }

    private function seedFeaturePermissions(Plan $plan): void
    {
        FeaturePermission::where('plan_id', $plan->id)->delete();
        $features = $plan->features;
        foreach ($features as $feature => $enabled) {
            $limit = $plan->getLimit($feature, null);
            FeaturePermission::create([
                'plan_id'  => $plan->id,
                'feature' => $feature,
                'enabled'  => $enabled,
                'limit'    => $limit,
                'period'   => 'monthly',
            ]);
        }
    }
}
