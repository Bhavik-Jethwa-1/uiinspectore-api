<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;

class TemplateController extends \Illuminate\Routing\Controller 
{
    private string $baseUrl = 'http://uiinspectore.167.233.101.27.nip.io/template-previews/';

    private array $templates = [
        [
            'id' => 'tmpl_saas_dashboard',
            'name' => 'SaaS Dashboard',
            'description' => 'Complete SaaS dashboard with charts, stats, and navigation',
            'category' => 'dashboard',
            'thumbnail' => 'http://uiinspectore.167.233.101.27.nip.io/template-previews/tmpl_saas_dashboard.svg',
            'screens' => [
                ['name' => 'Main Dashboard', 'elements' => [
                    ['id' => 'e1', 'type' => 'header', 'text' => 'Dashboard', 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 64,
                        'props' => ['backgroundColor' => '#12121f', 'color' => '#ffffff']],
                    ['id' => 'e2', 'type' => 'card', 'text' => 'Total Users', 'x' => 24, 'y' => 100, 'width' => 300, 'height' => 140,
                        'props' => ['backgroundColor' => '#1a1a2e', 'borderRadius' => 16, 'borderColor' => '#2a2a3e']],
                    ['id' => 'e3', 'type' => 'card', 'text' => 'Revenue', 'x' => 348, 'y' => 100, 'width' => 300, 'height' => 140,
                        'props' => ['backgroundColor' => '#1a1a2e', 'borderRadius' => 16, 'borderColor' => '#2a2a3e']],
                    ['id' => 'e4', 'type' => 'card', 'text' => 'Active Sessions', 'x' => 672, 'y' => 100, 'width' => 300, 'height' => 140,
                        'props' => ['backgroundColor' => '#1a1a2e', 'borderRadius' => 16, 'borderColor' => '#2a2a3e']],
                    ['id' => 'e5', 'type' => 'card', 'text' => 'Growth', 'x' => 996, 'y' => 100, 'width' => 420, 'height' => 140,
                        'props' => ['backgroundColor' => '#7c5cff', 'borderRadius' => 16, 'borderColor' => '#7c5cff']],
                ]],
                ['name' => 'Analytics', 'elements' => [
                    ['id' => 'e1', 'type' => 'header', 'text' => 'Analytics', 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 64,
                        'props' => ['backgroundColor' => '#12121f', 'color' => '#ffffff']],
                ]],
            ],
        ],
        [
            'id' => 'tmpl_mobile_app',
            'name' => 'Mobile App',
            'description' => 'iOS/Android style mobile app with bottom navigation',
            'category' => 'mobile',
            'thumbnail' => 'http://uiinspectore.167.233.101.27.nip.io/template-previews/tmpl_mobile_app.svg',
            'screens' => [
                ['name' => 'Home Feed', 'elements' => [
                    ['id' => 'e1', 'type' => 'header', 'text' => 'Discover', 'x' => 0, 'y' => 0, 'width' => 375, 'height' => 56,
                        'props' => ['backgroundColor' => '#ffffff', 'color' => '#1a1a2e']],
                    ['id' => 'e2', 'type' => 'card', 'text' => '', 'x' => 16, 'y' => 72, 'width' => 343, 'height' => 180,
                        'props' => ['backgroundColor' => '#f0f2f8', 'borderRadius' => 16]],
                    ['id' => 'e3', 'type' => 'text', 'text' => 'Featured', 'x' => 16, 'y' => 270, 'width' => 100, 'height' => 28,
                        'props' => ['color' => '#1a1a2e', 'fontSize' => 18, 'fontWeight' => '700']],
                    ['id' => 'e4', 'type' => 'list', 'text' => '', 'x' => 16, 'y' => 310, 'width' => 343, 'height' => 80,
                        'props' => ['backgroundColor' => '#ffffff', 'borderRadius' => 12, 'items' => ['Home', 'Search', 'Profile']]],
                    ['id' => 'e5', 'type' => 'navigation', 'text' => '', 'x' => 0, 'y' => 748, 'width' => 375, 'height' => 64,
                        'props' => ['backgroundColor' => '#ffffff', 'borderRadius' => 24, 'items' => ['Home', 'Search', 'Add', 'Profile']]],
                ]],
            ],
        ],
        [
            'id' => 'tmpl_landing_page',
            'name' => 'Landing Page',
            'description' => 'Modern SaaS landing page with hero and pricing',
            'category' => 'marketing',
            'thumbnail' => 'http://uiinspectore.167.233.101.27.nip.io/template-previews/tmpl_landing_page.svg',
            'screens' => [
                ['name' => 'Hero + Pricing', 'elements' => [
                    ['id' => 'e1', 'type' => 'navigation', 'text' => '', 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 72,
                        'props' => ['backgroundColor' => 'transparent', 'color' => '#ffffff', 'items' => ['Product', 'Pricing', 'About', 'Blog']]],
                    ['id' => 'e2', 'type' => 'text', 'text' => 'Build Products FASTER', 'x' => 200, 'y' => 180, 'width' => 1040, 'height' => 80,
                        'props' => ['color' => '#ffffff', 'fontSize' => 56, 'fontWeight' => '800', 'align' => 'center']],
                    ['id' => 'e3', 'type' => 'text', 'text' => 'The AI-powered design tool that turns your ideas into reality in seconds.', 'x' => 320, 'y' => 280, 'width' => 800, 'height' => 40,
                        'props' => ['color' => 'rgba(255,255,255,0.6)', 'fontSize' => 18, 'align' => 'center']],
                    ['id' => 'e4', 'type' => 'button', 'text' => 'Start Free Trial', 'x' => 570, 'y' => 360, 'width' => 300, 'height' => 56,
                        'props' => ['backgroundColor' => '#7c5cff', 'color' => '#ffffff', 'borderRadius' => 28, 'fontWeight' => '600', 'variant' => 'primary']],
                    ['id' => 'e5', 'type' => 'card', 'text' => 'Starter', 'x' => 200, 'y' => 500, 'width' => 300, 'height' => 380,
                        'props' => ['backgroundColor' => '#12121f', 'borderRadius' => 20, 'borderColor' => '#2a2a3e']],
                    ['id' => 'e6', 'type' => 'card', 'text' => 'Pro', 'x' => 570, 'y' => 480, 'width' => 300, 'height' => 420,
                        'props' => ['backgroundColor' => '#7c5cff', 'borderRadius' => 20, 'borderColor' => '#7c5cff']],
                    ['id' => 'e7', 'type' => 'card', 'text' => 'Enterprise', 'x' => 940, 'y' => 500, 'width' => 300, 'height' => 380,
                        'props' => ['backgroundColor' => '#12121f', 'borderRadius' => 20, 'borderColor' => '#2a2a3e']],
                ]],
            ],
        ],
        [
            'id' => 'tmpl_e_commerce',
            'name' => 'E-Commerce',
            'description' => 'Online store with product grid and cart',
            'category' => 'ecommerce',
            'thumbnail' => 'http://uiinspectore.167.233.101.27.nip.io/template-previews/tmpl_e_commerce.svg',
            'screens' => [
                ['name' => 'Product Grid', 'elements' => [
                    ['id' => 'e1', 'type' => 'header', 'text' => 'Shop', 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 64,
                        'props' => ['backgroundColor' => '#ffffff', 'color' => '#1a1a2e']],
                    ['id' => 'e2', 'type' => 'input', 'text' => '', 'x' => 1000, 'y' => 16, 'width' => 280, 'height' => 40,
                        'props' => ['backgroundColor' => '#f0f2f8', 'borderRadius' => 20, 'placeholder' => 'Search products...']],
                    ['id' => 'e3', 'type' => 'grid', 'text' => '', 'x' => 24, 'y' => 96, 'width' => 1392, 'height' => 800,
                        'props' => ['columns' => 4, 'rows' => 3, 'gap' => 24]],
                ]],
            ],
        ],
        [
            'id' => 'tmpl_portfolio',
            'name' => 'Designer Portfolio',
            'description' => 'Minimalist portfolio for designers and creatives',
            'category' => 'portfolio',
            'thumbnail' => 'http://uiinspectore.167.233.101.27.nip.io/template-previews/tmpl_portfolio.svg',
            'screens' => [
                ['name' => 'Home', 'elements' => [
                    ['id' => 'e1', 'type' => 'text', 'text' => 'Alex Morgan', 'x' => 60, 'y' => 100, 'width' => 600, 'height' => 72,
                        'props' => ['color' => '#1a1a2e', 'fontSize' => 48, 'fontWeight' => '700']],
                    ['id' => 'e2', 'type' => 'text', 'text' => 'Product Designer', 'x' => 60, 'y' => 170, 'width' => 400, 'height' => 32,
                        'props' => ['color' => '#7c5cff', 'fontSize' => 20, 'fontWeight' => '500']],
                    ['id' => 'e3', 'type' => 'text', 'text' => 'I craft digital experiences that people love to use.', 'x' => 60, 'y' => 220, 'width' => 500, 'height' => 40,
                        'props' => ['color' => '#6b7280', 'fontSize' => 16]],
                    ['id' => 'e4', 'type' => 'button', 'text' => 'View Projects', 'x' => 60, 'y' => 300, 'width' => 180, 'height' => 52,
                        'props' => ['backgroundColor' => '#7c5cff', 'color' => '#ffffff', 'borderRadius' => 12, 'variant' => 'primary']],
                    ['id' => 'e5', 'type' => 'image', 'text' => '', 'x' => 700, 'y' => 80, 'width' => 700, 'height' => 500,
                        'props' => ['backgroundColor' => '#f0f2f8', 'borderRadius' => 24]],
                ]],
            ],
        ],
        [
            'id' => 'tmpl_blog',
            'name' => 'Blog / CMS',
            'description' => 'Content-focused blog with article layout',
            'category' => 'blog',
            'thumbnail' => 'http://uiinspectore.167.233.101.27.nip.io/template-previews/tmpl_blog.svg',
            'screens' => [
                ['name' => 'Article', 'elements' => [
                    ['id' => 'e1', 'type' => 'header', 'text' => 'Blog', 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 64,
                        'props' => ['backgroundColor' => '#ffffff', 'color' => '#1a1a2e']],
                    ['id' => 'e2', 'type' => 'text', 'text' => 'Article Title Here', 'x' => 200, 'y' => 120, 'width' => 1040, 'height' => 80,
                        'props' => ['color' => '#1a1a2e', 'fontSize' => 40, 'fontWeight' => '700', 'align' => 'center']],
                    ['id' => 'e3', 'type' => 'text', 'text' => 'By Author Name · Jan 15, 2026 · 5 min read', 'x' => 200, 'y' => 210, 'width' => 1040, 'height' => 28,
                        'props' => ['color' => '#9ca3af', 'fontSize' => 14, 'align' => 'center']],
                    ['id' => 'e4', 'type' => 'image', 'text' => '', 'x' => 200, 'y' => 260, 'width' => 1040, 'height' => 400,
                        'props' => ['backgroundColor' => '#f0f2f8', 'borderRadius' => 16]],
                    ['id' => 'e5', 'type' => 'paragraph', 'text' => 'Article content goes here...', 'x' => 200, 'y' => 700, 'width' => 800, 'height' => 200,
                        'props' => ['color' => '#4b5563', 'fontSize' => 16, 'lineHeight' => 1.8]],
                ]],
            ],
        ],
    ];

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $category = $request->query('category');
        $templates = $this->templates;
        if ($category) {
            $templates = array_filter($templates, fn($t) => $t['category'] === $category);
        }
        return response()->json(['success' => true, 'data' => array_values($templates)]);
    }

    public function show(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        foreach ($this->templates as $t) {
            if ($t['id'] === $id) {
                return response()->json(['success' => true, 'data' => $t]);
            }
        }
        return response()->json(['error' => 'Template not found'], 404);
    }

    public function categories(): \Illuminate\Http\JsonResponse
    {
        $categories = array_unique(array_column($this->templates, 'category'));
        return response()->json(['success' => true, 'data' => array_values($categories)]);
    }
}
