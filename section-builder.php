<?php
/**
 * Plugin Name: Section Builder
 * Description: Pattern-based page builder — Gutenberg Patterns + Global Header/Footer + Next.js Dashboard
 * Version: 3.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.9
 */

defined('ABSPATH') || exit;

define('SB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SB_VERSION', '3.0.0');

// Version check
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Section Builder requires PHP 7.4+. Current: ' . PHP_VERSION . '</p></div>';
    });
    return;
}

// Autoload
spl_autoload_register(function ($class) {
    $prefix = 'SectionBuilder\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = SB_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require $file;
});

// ============================================================
//  Boot
// ============================================================

add_action('init', function () {
    // Shortcode — render page sections
    add_shortcode('page_builder_sections', function ($atts) {
        $atts    = shortcode_atts(['post_id' => get_the_ID()], $atts);
        $post_id = (int) $atts['post_id'];
        if (!$post_id) return '<!-- SB: no post ID -->';
        return SectionBuilder\Renderer::render($post_id);
    });

    // Pattern categories
    if (function_exists('register_block_pattern_category')) {
        register_block_pattern_category('section-builder', ['label' => 'Section Builder']);
        register_block_pattern_category('sb-header', ['label' => 'SB — Headers']);
        register_block_pattern_category('sb-footer', ['label' => 'SB — Footers']);
    }
});

// ============================================================
//  Auto-inject Global Header — ทุกหน้าอัตโนมัติ
// ============================================================
add_action('wp_body_open', function () {
    $settings = get_option('sb_global_settings', []);
    if (empty($settings['header_pattern_id'])) return;

    echo '<header id="sb-header" class="sb-header">';
    echo SectionBuilder\Renderer::render_global('header');
    echo '</header>';
}, 1); // priority 1 = render ก่อนอย่างอื่น

// ============================================================
//  Auto-inject Global Footer — ทุกหน้าอัตโนมัติ
// ============================================================
add_action('wp_footer', function () {
    $settings = get_option('sb_global_settings', []);
    if (empty($settings['footer_pattern_id'])) return;

    echo '<footer id="sb-footer" class="sb-footer">';
    echo SectionBuilder\Renderer::render_global('footer');
    echo '</footer>';
}, 5); // priority 5 = render ก่อน scripts

add_action('plugins_loaded', function () {
    SectionBuilder\REST_API::instance();
});

// ============================================================
//  Enqueue CSS
// ============================================================
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('section-builder', SB_PLUGIN_URL . 'assets/css/section-builder.css', [], SB_VERSION);

    // ถ้ามี global header/footer → inject CSS ซ่อน theme header/footer
    $settings = get_option('sb_global_settings', []);

    $inline_css = '';
    if (!empty($settings['header_pattern_id'])) {
        // ซ่อน theme header — ปรับ selector ตาม theme ที่ใช้
        $inline_css .= '.site-header, #masthead, header.entry-header, .elementor-location-header { display: none !important; }';
    }
    if (!empty($settings['footer_pattern_id'])) {
        $inline_css .= '.site-footer, #colophon, footer.entry-footer, .elementor-location-footer { display: none !important; }';
    }

    if ($inline_css) {
        wp_add_inline_style('section-builder', $inline_css);
    }
});

// เพิ่ม body class สำหรับ CSS targeting
add_filter('body_class', function ($classes) {
    $settings = get_option('sb_global_settings', []);
    if (!empty($settings['header_pattern_id'])) $classes[] = 'sb-has-header';
    if (!empty($settings['footer_pattern_id'])) $classes[] = 'sb-has-footer';
    return $classes;
});

// ============================================================
//  CORS
// ============================================================
add_action('rest_api_init', function () {
    $allowed_origins = [
        'http://localhost:3000',
        'https://dashboard.your-domain.com',
    ];

    add_filter('rest_pre_serve_request', function ($served) use ($allowed_origins) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $allowed_origins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
            header('Access-Control-Allow-Credentials: true');
        }
        return $served;
    });
}, 1);

// ============================================================
//  Page Template — Full page with Header + Sections + Footer
// ============================================================
add_filter('theme_page_templates', function ($templates) {
    $templates['sb-page-builder.php'] = 'Section Builder — Full Page';
    return $templates;
});
add_filter('template_include', function ($template) {
    if (get_page_template_slug() === 'sb-page-builder.php') {
        $custom = SB_PLUGIN_DIR . 'templates/page-builder.php';
        if (file_exists($custom)) return $custom;
    }
    return $template;
});
