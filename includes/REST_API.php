<?php

namespace SectionBuilder;

/**
 * REST API — Pattern-based
 *
 * Endpoints:
 * GET    /builder/v1/patterns                  → list ทุก reusable blocks (section templates)
 * GET    /builder/v1/patterns/{id}             → get pattern เดียว + rendered HTML
 * GET    /builder/v1/pages/{id}                → get page layout (pattern order + overrides)
 * POST   /builder/v1/pages/{id}                → save page layout (atomic)
 * POST   /builder/v1/pages/{id}/render         → preview rendered HTML (ไม่ save)
 * POST   /builder/v1/patterns/render           → render pattern เดียว + overrides
 */
class REST_API
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        $ns = 'builder/v1';

        // ── Patterns ──
        register_rest_route($ns, '/patterns', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_patterns'],
            'permission_callback' => [$this, 'can_read'],
            'args'                => [
                'source'   => ['type' => 'string', 'default' => 'all',  'enum' => ['all', 'synced', 'theme']],
                'category' => ['type' => 'string', 'default' => ''],
                'search'   => ['type' => 'string', 'default' => ''],
            ],
        ]);

        // รับทั้ง numeric (wp_block ID) และ string (theme pattern name)
        register_rest_route($ns, '/patterns/(?P<id>[a-zA-Z0-9\-\_\/]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_pattern'],
            'permission_callback' => [$this, 'can_read'],
        ]);

        register_rest_route($ns, '/patterns/render', [
            'methods'             => 'POST',
            'callback'            => [$this, 'render_pattern'],
            'permission_callback' => [$this, 'can_read'],
        ]);

        // GET /patterns/{id}/screenshot — rendered HTML as screenshot page
        register_rest_route($ns, '/patterns/(?P<pid>[a-zA-Z0-9\-\_\/]+)/screenshot', [
            'methods'             => 'GET',
            'callback'            => [$this, 'pattern_screenshot'],
            'permission_callback' => '__return_true', // public — ใช้แสดงใน <img> tag
        ]);

        // ── Pages ──

        // GET /pages — list ทุก pages ที่ใช้ section builder
        register_rest_route($ns, '/pages', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_pages'],
            'permission_callback' => [$this, 'can_read'],
        ]);

        register_rest_route($ns, '/pages/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_page_layout'],
                'permission_callback' => [$this, 'can_read'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'save_page_layout'],
                'permission_callback' => [$this, 'can_write'],
            ],
        ]);

        register_rest_route($ns, '/pages/(?P<id>\d+)/render', [
            'methods'             => 'POST',
            'callback'            => [$this, 'render_page_preview'],
            'permission_callback' => [$this, 'can_read'],
        ]);

        // ── Global Settings (Header / Footer) ──
        register_rest_route($ns, '/globals', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_globals'],
                'permission_callback' => [$this, 'can_read'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'save_globals'],
                'permission_callback' => [$this, 'can_write'],
            ],
        ]);

        register_rest_route($ns, '/globals/render', [
            'methods'             => 'POST',
            'callback'            => [$this, 'render_globals_preview'],
            'permission_callback' => [$this, 'can_read'],
        ]);
    }

    // ================================================================
    //  Patterns — list, get, render
    // ================================================================

    /**
     * GET /patterns
     * Return ทุก patterns ในเว็บ — synced (wp_block) + theme/plugin registered
     * แต่ละ pattern มี cover_image + screenshot_url
     */
    public function list_patterns(\WP_REST_Request $request): \WP_REST_Response
    {
        $source   = $request->get_param('source') ?? 'all';
        $category = $request->get_param('category') ?? '';
        $search   = $request->get_param('search') ?? '';

        $all_patterns = [];
        $screenshot_base = rest_url('builder/v1/patterns/');

        // ── 1. Synced Patterns (wp_block post type) ──
        if ($source === 'all' || $source === 'synced') {
            $args = [
                'post_type'      => 'wp_block',
                'posts_per_page' => 200,
                'post_status'    => 'publish',
                'orderby'        => 'title',
                'order'          => 'ASC',
            ];
            if ($search) $args['s'] = $search;

            $blocks = get_posts($args);
            foreach ($blocks as $b) {
                if ($category) {
                    $content = $b->post_content;
                    if (preg_match('/\"categories\":\[([^\]]+)\]/', $content, $cm)) {
                        $cats_str = str_replace(['"', "'"], '', $cm[1]);
                        $cats = array_map('trim', explode(',', $cats_str));
                        if (!in_array($category, $cats)) continue;
                    }
                }

                $p = $this->format_pattern($b);
                $p['source'] = 'synced';
                $p['screenshot_url'] = $screenshot_base . $b->ID . '/screenshot';
                $all_patterns[] = $p;
            }
        }

        // ── 2. Theme + Plugin registered patterns ──
        if ($source === 'all' || $source === 'theme') {
            if (class_exists('\WP_Block_Patterns_Registry')) {
                $registry = \WP_Block_Patterns_Registry::get_instance();
                $registered = $registry->get_all_registered();

                foreach ($registered as $pattern) {
                    $name = $pattern['name'] ?? '';
                    $cats = $pattern['categories'] ?? [];

                    if ($category && !in_array($category, $cats)) continue;

                    if ($search) {
                        $title = $pattern['title'] ?? '';
                        $desc  = $pattern['description'] ?? '';
                        if (stripos($title, $search) === false && stripos($desc, $search) === false) continue;
                    }

                    $content = $pattern['content'] ?? '';

                    // Extract ALL images
                    $images = [];
                    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches)) {
                        $images = array_unique($matches[1]);
                    }

                    // Cover image = first image
                    $cover = !empty($images) ? $images[0] : '';

                    // Render preview HTML
                    $rendered_html = '';
                    try {
                        $rendered_html = do_blocks($content);
                        $rendered_html = apply_filters('the_content', $rendered_html);
                    } catch (\Exception $e) {
                        $rendered_html = '';
                    }

                    $all_patterns[] = [
                        'id'              => $name,
                        'title'           => $pattern['title'] ?? $name,
                        'slug'            => sanitize_title($name),
                        'description'     => $pattern['description'] ?? '',
                        'category'        => implode(', ', $cats),
                        'categories'      => $cats,
                        'cover_image'     => $cover,
                        'thumbnail'       => $cover,
                        'images'          => array_values($images),
                        'source'          => 'theme',
                        'modified'        => null,
                        'raw_content'     => $content,
                        'rendered_html'   => $rendered_html,
                        'preview_document'=> $this->wrap_html_doc($rendered_html),
                        'screenshot_url'  => $screenshot_base . urlencode($name) . '/screenshot',
                        'editable_fields' => $this->extract_editable_fields($content),
                        'viewportWidth'   => $pattern['viewportWidth'] ?? null,
                        'blockTypes'      => $pattern['blockTypes'] ?? [],
                    ];
                }
            }
        }

        // ── Categories ──
        $categories = [];
        if (class_exists('\WP_Block_Pattern_Categories_Registry')) {
            $cat_registry = \WP_Block_Pattern_Categories_Registry::get_instance();
            foreach ($cat_registry->get_all_registered() as $cat) {
                $categories[] = [
                    'name'  => $cat['name'],
                    'label' => $cat['label'],
                ];
            }
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'patterns'   => $all_patterns,
                'categories' => $categories,
                'total'      => count($all_patterns),
            ],
        ]);
    }

    /**
     * GET /patterns/{id}
     * Return pattern เดียว + rendered HTML
     * id = numeric (wp_block post ID) หรือ string (theme pattern name)
     */
    public function get_pattern(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = $request->get_param('id');
        $result = $this->resolve_pattern($id);

        if (!$result) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Pattern not found'], 404);
        }

        $result['rendered_html'] = $this->render_block_content($result['raw_content']);

        return new \WP_REST_Response(['success' => true, 'data' => $result]);
    }

    /**
     * POST /patterns/render
     * Render pattern ทีละตัว + apply content overrides
     * Body: { pattern_id: 42 or "pattern-name", overrides: { ... } }
     */
    public function render_pattern(\WP_REST_Request $request): \WP_REST_Response
    {
        $body       = $request->get_json_params();
        $pattern_id = $body['pattern_id'] ?? '';
        $overrides  = $body['overrides'] ?? [];

        $result = $this->resolve_pattern($pattern_id);
        if (!$result) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Pattern not found'], 404);
        }

        $content = $result['raw_content'];
        $content = $this->apply_overrides($content, $overrides);
        $html    = $this->render_block_content($content);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'html'     => $html,
                'document' => $this->wrap_html_doc($html),
            ],
        ]);
    }

    /**
     * GET /patterns/{pid}/screenshot
     *
     * Return full rendered HTML page — ใช้เป็น cover image ใน Next.js
     *
     * วิธีใช้ใน Next.js:
     * 1. <iframe src="/wp-json/builder/v1/patterns/1020/screenshot"> — live preview
     * 2. Scaled iframe as thumbnail card
     *
     * Public endpoint — ไม่ต้อง auth
     */
    public function pattern_screenshot(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = $request->get_param('pid');
        $result = $this->resolve_pattern($id);

        if (!$result) {
            $r = new \WP_REST_Response('<h1>Pattern not found</h1>', 404);
            $r->header('Content-Type', 'text/html');
            return $r;
        }

        $html  = $this->render_block_content($result['raw_content'] ?? '');
        $title = esc_html($result['title'] ?? 'Preview');

        $block_css = includes_url('css/dist/block-library/style.min.css');
        $theme_css = get_stylesheet_uri();
        $sb_css    = SB_PLUGIN_URL . 'assets/css/section-builder.css';

        $global_styles = '';
        if (function_exists('wp_get_global_stylesheet')) {
            $global_styles = wp_get_global_stylesheet();
        }

        $page = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
              . "<title>{$title}</title>"
              . "<link rel=\"stylesheet\" href=\"" . esc_url($block_css) . "\">"
              . "<link rel=\"stylesheet\" href=\"" . esc_url($theme_css) . "\">"
              . "<link rel=\"stylesheet\" href=\"" . esc_url($sb_css) . "\">"
              . "<style>{$global_styles}\nbody{margin:0;padding:0;background:#fff}</style>"
              . "</head><body class=\"wp-site-blocks\">{$html}</body></html>";

        $r = new \WP_REST_Response($page);
        $r->header('Content-Type', 'text/html; charset=UTF-8');
        $r->header('Cache-Control', 'public, max-age=3600');
        return $r;
    }

    /**
     * Resolve pattern by ID (numeric = wp_block) or name (string = theme pattern)
     */
    private function resolve_pattern($id): ?array
    {
        // Numeric → wp_block post
        if (is_numeric($id)) {
            $post = get_post((int) $id);
            if ($post && $post->post_type === 'wp_block') {
                $data = $this->format_pattern($post);
                $data['source'] = 'synced';
                return $data;
            }
        }

        // String → theme/plugin registered pattern
        if (class_exists('\WP_Block_Patterns_Registry')) {
            $registry = \WP_Block_Patterns_Registry::get_instance();
            $pattern  = $registry->get_registered($id);
        } else {
            $pattern = null;
        }

        if ($pattern) {
            $content = $pattern['content'] ?? '';
            $thumb   = '';
            if (preg_match('/src=["\']([^"\']+)/', $content, $m)) $thumb = $m[1];

            return [
                'id'              => $pattern['name'],
                'title'           => $pattern['title'] ?? $pattern['name'],
                'slug'            => sanitize_title($pattern['name']),
                'description'     => $pattern['description'] ?? '',
                'category'        => implode(', ', $pattern['categories'] ?? []),
                'categories'      => $pattern['categories'] ?? [],
                'thumbnail'       => $thumb,
                'source'          => 'theme',
                'raw_content'     => $content,
                'editable_fields' => $this->extract_editable_fields($content),
            ];
        }

        return null;
    }

    // ================================================================
    //  Pages — list, layout read/write
    // ================================================================

    /**
     * GET /pages
     *
     * List ทุก pages ที่ใช้ section builder (มี _sb_page_layout meta)
     * + ทุก pages ที่มี shortcode [page_builder_sections]
     * + ทุก pages ที่ใช้ template sb-page-builder.php
     *
     * Next.js ใช้แสดงรายการ pages ใน dashboard — ไม่ต้องหา page ID เอง
     */
    public function list_pages(\WP_REST_Request $request): \WP_REST_Response
    {
        // ดึงทุก pages
        $all_pages = get_posts([
            'post_type'      => 'page',
            'posts_per_page' => 200,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $builder_pages = [];
        $other_pages   = [];

        foreach ($all_pages as $page) {
            $layout   = get_post_meta($page->ID, '_sb_page_layout', true);
            $template = get_post_meta($page->ID, '_wp_page_template', true);
            $has_shortcode = strpos($page->post_content, '[page_builder_sections]') !== false;
            $has_layout = !empty($layout) && is_array($layout);

            $is_builder_page = $has_layout || $has_shortcode || $template === 'sb-page-builder.php';

            $page_data = [
                'id'             => $page->ID,
                'title'          => $page->post_title ?: '(no title)',
                'slug'           => $page->post_name,
                'status'         => $page->post_status,
                'permalink'      => get_permalink($page->ID),
                'modified'       => $page->post_modified_gmt,
                'template'       => $template ?: 'default',
                'is_builder_page'=> $is_builder_page,
                'sections_count' => $has_layout ? count($layout) : 0,
                'has_shortcode'  => $has_shortcode,
            ];

            // แสดง featured image ถ้ามี
            $thumb_id = get_post_thumbnail_id($page->ID);
            if ($thumb_id) {
                $page_data['thumbnail'] = wp_get_attachment_image_url($thumb_id, 'medium');
            }

            if ($is_builder_page) {
                $builder_pages[] = $page_data;
            } else {
                $other_pages[] = $page_data;
            }
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'builder_pages' => $builder_pages,
                'other_pages'   => $other_pages,
                'total_builder' => count($builder_pages),
                'total_other'   => count($other_pages),
            ],
        ]);
    }

    /**
     * GET /pages/{id}
     * Return page layout — ordered array of { pattern_id, overrides }
     */
    public function get_page_layout(\WP_REST_Request $request): \WP_REST_Response
    {
        $post_id = (int) $request->get_param('id');
        $post    = get_post($post_id);

        if (!$post || $post->post_type !== 'page') {
            return new \WP_REST_Response(['success' => false, 'message' => 'Page not found'], 404);
        }

        $layout = get_post_meta($post_id, '_sb_page_layout', true);
        if (!$layout || !is_array($layout)) $layout = [];

        // Enrich layout with pattern info
        $sections = [];
        foreach ($layout as $i => $item) {
            $pid = $item['pattern_id'] ?? 0;
            $pattern_data = null;

            // Resolve pattern — support ทั้ง numeric (wp_block) และ string (theme pattern)
            if (is_numeric($pid)) {
                $pattern_post = get_post((int) $pid);
                if ($pattern_post && $pattern_post->post_type === 'wp_block') {
                    $pattern_data = $this->format_pattern($pattern_post);
                    $pattern_data['source'] = 'synced';
                }
            } else {
                $resolved = $this->resolve_pattern($pid);
                if ($resolved) {
                    $pattern_data = $resolved;
                }
            }

            $sections[] = [
                'order'      => $i,
                'pattern_id' => $pid,
                'pattern'    => $pattern_data,
                'overrides'  => $item['overrides'] ?? [],
            ];
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'id'        => $post_id,
                'title'     => $post->post_title,
                'slug'      => $post->post_name,
                'status'    => $post->post_status,
                'permalink' => get_permalink($post_id),
                'sections'  => $sections,
            ],
        ]);
    }

    /**
     * POST /pages/{id}
     * Atomic save — เขียน layout ทั้งหน้า
     *
     * Body:
     * {
     *   "title": "Page Title",
     *   "slug": "page-slug",
     *   "status": "draft|publish",
     *   "sections": [
     *     { "pattern_id": 42, "overrides": { "heading": "custom text" } },
     *     { "pattern_id": 55, "overrides": {} }
     *   ]
     * }
     */
    public function save_page_layout(\WP_REST_Request $request): \WP_REST_Response
    {
        $post_id = (int) $request->get_param('id');
        $post    = get_post($post_id);

        if (!$post || $post->post_type !== 'page') {
            return new \WP_REST_Response(['success' => false, 'message' => 'Page not found'], 404);
        }

        $body     = $request->get_json_params();
        $sections = $body['sections'] ?? [];

        // Validate — pattern_id ต้องเป็น wp_block (numeric) หรือ registered pattern (string)
        $errors = [];
        foreach ($sections as $i => $sec) {
            $pid = $sec['pattern_id'] ?? null;

            if (!$pid) {
                $errors[] = "Section #{$i}: missing pattern_id";
                continue;
            }

            // Numeric → ต้องเป็น wp_block post ที่มีอยู่จริง
            if (is_numeric($pid)) {
                $p = get_post((int) $pid);
                if (!$p || $p->post_type !== 'wp_block') {
                    $errors[] = "Section #{$i}: synced pattern #{$pid} not found";
                }
            } else {
                // String → ต้องเป็น registered pattern
                if (class_exists('\WP_Block_Patterns_Registry')) {
                    $registry = \WP_Block_Patterns_Registry::get_instance();
                    if (!$registry->get_registered((string) $pid)) {
                        $errors[] = "Section #{$i}: theme pattern '{$pid}' not found";
                    }
                }
                // ถ้าไม่มี Registry class → skip validation สำหรับ theme patterns
            }
        }

        if ($errors) {
            return new \WP_REST_Response(['success' => false, 'errors' => $errors], 400);
        }

        // ── Build post update args (ทำครั้งเดียว) ──
        $update = ['ID' => $post_id];
        if (isset($body['title']))  $update['post_title']  = sanitize_text_field($body['title']);
        if (isset($body['slug']))   $update['post_name']   = sanitize_title($body['slug']);
        if (isset($body['status'])) $update['post_status'] = in_array($body['status'], ['draft','publish','pending','private']) ? $body['status'] : 'draft';

        // ── Auto-inject shortcode ถ้ายังไม่มี (รวมใน wp_update_post ครั้งเดียว) ──
        $current_content = $post->post_content ?? '';
        if (strpos($current_content, '[page_builder_sections]') === false) {
            // Prepend shortcode block — ไม่ overwrite content เดิม
            $shortcode_block = '<!-- wp:shortcode -->' . "\n"
                             . '[page_builder_sections]' . "\n"
                             . '<!-- /wp:shortcode -->';

            // ถ้า content ว่าง → ใส่ shortcode อย่างเดียว
            // ถ้ามี content อยู่ → prepend shortcode ไว้ข้างบน
            if (trim($current_content) === '') {
                $update['post_content'] = $shortcode_block;
            } else {
                $update['post_content'] = $shortcode_block . "\n\n" . $current_content;
            }
        }

        // ── Update post (ครั้งเดียว) ──
        wp_update_post($update);

        // ── Auto-set page template ──
        $current_template = get_post_meta($post_id, '_wp_page_template', true);
        if (!$current_template || $current_template === 'default' || $current_template === '') {
            update_post_meta($post_id, '_wp_page_template', 'sb-page-builder.php');
        }

        // ── Save layout as post meta ──
        $layout = [];
        foreach ($sections as $sec) {
            $pid = $sec['pattern_id'];
            $layout[] = [
                'pattern_id' => is_numeric($pid) ? (int) $pid : (string) $pid,
                'overrides'  => $sec['overrides'] ?? [],
            ];
        }
        update_post_meta($post_id, '_sb_page_layout', $layout);

        // Purge cache
        $this->purge_cache($post_id);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'id'             => $post_id,
                'permalink'      => get_permalink($post_id),
                'sections_count' => count($layout),
                'template'       => get_post_meta($post_id, '_wp_page_template', true),
            ],
        ]);
    }

    /**
     * POST /pages/{id}/render
     * Preview — render ทุก section ตาม order โดยไม่ save
     * Body: { sections: [{ pattern_id, overrides }, ...] }
     */
    public function render_page_preview(\WP_REST_Request $request): \WP_REST_Response
    {
        $body     = $request->get_json_params();
        $sections = $body['sections'] ?? null;
        $post_id  = (int) $request->get_param('id');

        // ถ้าไม่ส่ง sections → ใช้ที่ save ไว้
        if ($sections === null) {
            $html = Renderer::render($post_id);
        } else {
            $html = Renderer::render_from_layout($sections);
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'html'     => $html,
                'document' => $this->wrap_html_doc($html),
            ],
        ]);
    }

    // ================================================================
    //  Helpers
    // ================================================================

    /**
     * Format wp_block post → API response
     */
    private function format_pattern(\WP_Post $post): array
    {
        $content = $post->post_content;

        // Extract ALL images from content (ไม่ใช่แค่ตัวแรก)
        $images = [];
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches)) {
            $images = array_unique($matches[1]);
        }

        // Thumbnail = first image หรือ featured image ของ wp_block post
        $thumbnail = '';
        $featured_id = get_post_thumbnail_id($post->ID);
        if ($featured_id) {
            $thumbnail = wp_get_attachment_image_url($featured_id, 'medium');
        } elseif (!empty($images)) {
            $thumbnail = $images[0];
        }

        // Render preview HTML — ใช้แสดง thumbnail ใน Next.js ผ่าน iframe
        $rendered_html = '';
        try {
            $rendered_html = do_blocks($content);
            $rendered_html = apply_filters('the_content', $rendered_html);
        } catch (\Exception $e) {
            $rendered_html = '<!-- render error -->';
        }

        // Extract category from pattern meta
        $category = get_post_meta($post->ID, '_sb_pattern_category', true) ?: 'uncategorized';

        // Extract block pattern categories if original pattern data exists
        $categories = [];
        $meta = $post->post_content;
        if (preg_match('/\"categories\":\[([^\]]+)\]/', $meta, $cm)) {
            $raw = str_replace(['"', "'"], '', $cm[1]);
            $categories = array_map('trim', explode(',', $raw));
        }

        return [
            'id'              => $post->ID,
            'title'           => $post->post_title,
            'slug'            => $post->post_name,
            'category'        => $category,
            'categories'      => $categories,
            'cover_image'     => $thumbnail,
            'thumbnail'       => $thumbnail,
            'images'          => array_values($images),
            'modified'        => $post->post_modified_gmt,
            'raw_content'     => $content,
            'rendered_html'   => $rendered_html,
            'preview_document'=> $this->wrap_html_doc($rendered_html),
            'editable_fields' => $this->extract_editable_fields($content),
        ];
    }

    /**
     * Extract editable text from Gutenberg block content
     * ค้นหา headings, paragraphs, buttons → return เป็น array ที่ Next.js ใช้สร้าง edit form
     */
    private function extract_editable_fields(string $content): array
    {
        $fields = [];
        $blocks = parse_blocks($content);

        $this->walk_blocks($blocks, $fields);

        return $fields;
    }

    /**
     * Recursively walk blocks and extract editable text + styleable properties
     */
    private function walk_blocks(array $blocks, array &$fields, string $path = ''): void
    {
        // Style properties that CAN be overridden
        $allowed_styles = [
            'color'      => ['text', 'background', 'gradient'],
            'typography' => ['fontSize', 'fontFamily', 'fontWeight', 'fontStyle', 'letterSpacing', 'lineHeight', 'textTransform', 'textDecoration'],
        ];

        // Style properties that CANNOT be overridden (layout-related)
        // padding, margin, blockGap, width, minHeight, flex, columns — all blocked

        foreach ($blocks as $i => $block) {
            $name = $block['blockName'] ?? '';
            $html = $block['innerHTML'] ?? '';
            $attrs = $block['attrs'] ?? [];
            $current_path = $path ? "{$path}.{$i}" : (string) $i;

            // ── Extract editable text ──
            if (in_array($name, ['core/heading', 'core/paragraph', 'core/button'])) {
                $text = wp_strip_all_tags($html);
                $text = trim($text);

                if ($text) {
                    $type_map = [
                        'core/heading'   => 'heading',
                        'core/paragraph' => 'text',
                        'core/button'    => 'button',
                    ];

                    $level = '';
                    if ($name === 'core/heading' && preg_match('/<h(\d)/', $html, $m)) {
                        $level = 'h' . $m[1];
                    }

                    $fields[] = [
                        'path'  => $current_path,
                        'type'  => $type_map[$name] ?? 'text',
                        'level' => $level,
                        'value' => $text,
                        'block' => $name,
                    ];
                }
            }

            // ── Extract image ──
            if ($name === 'core/image' || $name === 'core/cover') {
                if (preg_match('/src=["\']([^"\']+)/', $html, $m)) {
                    $fields[] = [
                        'path'  => $current_path,
                        'type'  => 'image',
                        'value' => $m[1],
                        'block' => $name,
                    ];
                }
            }

            // ── Extract styleable properties ──
            if ($name && !in_array($name, ['core/spacer', 'core/separator', 'core/nextpage'])) {
                $current_styles = [];

                // From attrs.style (inline styles)
                $style = $attrs['style'] ?? [];
                foreach ($allowed_styles as $group => $props) {
                    if (!isset($style[$group])) continue;
                    foreach ($props as $prop) {
                        if (isset($style[$group][$prop])) {
                            $current_styles[] = [
                                'property' => "{$group}.{$prop}",
                                'value'    => $style[$group][$prop],
                                'source'   => 'inline',
                            ];
                        }
                    }
                }

                // From attrs.textColor / attrs.backgroundColor (preset colors)
                if (isset($attrs['textColor'])) {
                    $current_styles[] = [
                        'property' => 'color.text',
                        'value'    => $attrs['textColor'],
                        'source'   => 'preset',
                    ];
                }
                if (isset($attrs['backgroundColor'])) {
                    $current_styles[] = [
                        'property' => 'color.background',
                        'value'    => $attrs['backgroundColor'],
                        'source'   => 'preset',
                    ];
                }
                if (isset($attrs['gradient'])) {
                    $current_styles[] = [
                        'property' => 'color.gradient',
                        'value'    => $attrs['gradient'],
                        'source'   => 'preset',
                    ];
                }

                // From attrs.fontSize (preset size)
                if (isset($attrs['fontSize'])) {
                    $current_styles[] = [
                        'property' => 'typography.fontSize',
                        'value'    => $attrs['fontSize'],
                        'source'   => 'preset',
                    ];
                }
                if (isset($attrs['fontFamily'])) {
                    $current_styles[] = [
                        'property' => 'typography.fontFamily',
                        'value'    => $attrs['fontFamily'],
                        'source'   => 'preset',
                    ];
                }

                if (!empty($current_styles)) {
                    $fields[] = [
                        'path'       => $current_path,
                        'type'       => 'style',
                        'block'      => $name,
                        'block_label'=> $this->get_block_label($name, $html),
                        'styles'     => $current_styles,
                    ];
                }
            }

            // Recurse inner blocks
            if (!empty($block['innerBlocks'])) {
                $this->walk_blocks($block['innerBlocks'], $fields, $current_path);
            }
        }
    }

    /**
     * Get human-readable label for a block (for style editor UI)
     */
    private function get_block_label(string $block_name, string $html): string
    {
        $labels = [
            'core/group'     => 'Section container',
            'core/cover'     => 'Cover section',
            'core/columns'   => 'Columns layout',
            'core/column'    => 'Column',
            'core/heading'   => 'Heading',
            'core/paragraph' => 'Text',
            'core/button'    => 'Button',
            'core/buttons'   => 'Buttons group',
            'core/image'     => 'Image',
            'core/list'      => 'List',
            'core/quote'     => 'Quote',
        ];

        $label = $labels[$block_name] ?? str_replace('core/', '', $block_name);

        // Add text preview for context
        $text = trim(wp_strip_all_tags($html));
        if ($text && strlen($text) > 0) {
            $preview = mb_substr($text, 0, 30);
            if (mb_strlen($text) > 30) $preview .= '...';
            $label .= ": \"{$preview}\"";
        }

        return $label;
    }

    /**
     * Apply content + style overrides to block content
     *
     * Override format:
     *   Text:  { "1.1.0": "New heading text" }
     *   Style: { "1.1.0__style": { "color.text": "#ff0000", "typography.fontSize": "2rem" } }
     *
     * Key = block path for text, block path + "__style" suffix for styles
     */
    private function apply_overrides(string $content, array $overrides): string
    {
        if (empty($overrides)) return $content;

        $blocks = parse_blocks($content);
        $this->apply_overrides_recursive($blocks, $overrides, '');
        return serialize_blocks($blocks);
    }

    private function apply_overrides_recursive(array &$blocks, array $overrides, string $path): void
    {
        foreach ($blocks as $i => &$block) {
            $current_path = $path ? "{$path}.{$i}" : (string) $i;
            $name = $block['blockName'] ?? '';

            // ── Text overrides ──
            if (isset($overrides[$current_path]) && is_string($overrides[$current_path])) {
                $new_value = $overrides[$current_path];

                if (in_array($name, ['core/heading', 'core/paragraph'])) {
                    $block['innerHTML'] = preg_replace(
                        '/>(.*?)</s',
                        '>' . esc_html($new_value) . '<',
                        $block['innerHTML'],
                        1
                    );
                    if (!empty($block['innerContent'])) {
                        $block['innerContent'][0] = $block['innerHTML'];
                    }
                } elseif ($name === 'core/button') {
                    $block['innerHTML'] = preg_replace(
                        '/>(.*?)</a/s',
                        '>' . esc_html($new_value) . '</a',
                        $block['innerHTML'],
                        1
                    );
                    if (!empty($block['innerContent'])) {
                        $block['innerContent'][0] = $block['innerHTML'];
                    }
                }
            }

            // ── Style overrides ──
            $style_key = $current_path . '__style';
            if (isset($overrides[$style_key]) && is_array($overrides[$style_key])) {
                $style_changes = $overrides[$style_key];

                // Allowed style properties (NO layout!)
                $allowed = [
                    'color.text', 'color.background', 'color.gradient',
                    'typography.fontSize', 'typography.fontFamily', 'typography.fontWeight',
                    'typography.fontStyle', 'typography.letterSpacing', 'typography.lineHeight',
                    'typography.textTransform', 'typography.textDecoration',
                ];

                foreach ($style_changes as $property => $value) {
                    // Block anything not in allowed list
                    if (!in_array($property, $allowed)) continue;

                    $parts = explode('.', $property);
                    if (count($parts) !== 2) continue;

                    $group = $parts[0]; // "color" or "typography"
                    $prop  = $parts[1]; // "text", "fontSize", etc.

                    if ($value === null || $value === '') {
                        // Remove the property
                        unset($block['attrs']['style'][$group][$prop]);

                        // Also remove preset attrs
                        if ($property === 'color.text')       unset($block['attrs']['textColor']);
                        if ($property === 'color.background') unset($block['attrs']['backgroundColor']);
                        if ($property === 'color.gradient')   unset($block['attrs']['gradient']);
                        if ($property === 'typography.fontSize')   unset($block['attrs']['fontSize']);
                        if ($property === 'typography.fontFamily') unset($block['attrs']['fontFamily']);
                    } else {
                        // Set inline style
                        if (!isset($block['attrs'])) $block['attrs'] = [];
                        if (!isset($block['attrs']['style'])) $block['attrs']['style'] = [];
                        if (!isset($block['attrs']['style'][$group])) $block['attrs']['style'][$group] = [];
                        $block['attrs']['style'][$group][$prop] = $value;

                        // Remove conflicting preset attrs (inline takes priority)
                        if ($property === 'color.text')       unset($block['attrs']['textColor']);
                        if ($property === 'color.background') unset($block['attrs']['backgroundColor']);
                        if ($property === 'color.gradient')   unset($block['attrs']['gradient']);
                        if ($property === 'typography.fontSize')   unset($block['attrs']['fontSize']);
                        if ($property === 'typography.fontFamily') unset($block['attrs']['fontFamily']);
                    }

                    // Update innerHTML to reflect style changes
                    $block['innerHTML'] = $this->rebuild_block_html($block);
                    if (!empty($block['innerContent'])) {
                        $block['innerContent'][0] = $block['innerHTML'];
                    }
                }
            }

            if (!empty($block['innerBlocks'])) {
                $this->apply_overrides_recursive($block['innerBlocks'], $overrides, $current_path);
            }
        }
    }

    /**
     * Rebuild block HTML from attrs.style → inline CSS
     * Only handles color + typography (not layout)
     */
    private function rebuild_block_html(array $block): string
    {
        $html = $block['innerHTML'] ?? '';
        $style = $block['attrs']['style'] ?? [];

        $css_parts = [];

        // Color
        if (isset($style['color']['text']))       $css_parts[] = 'color:' . $style['color']['text'];
        if (isset($style['color']['background'])) $css_parts[] = 'background-color:' . $style['color']['background'];
        if (isset($style['color']['gradient']))    $css_parts[] = 'background:' . $style['color']['gradient'];

        // Typography
        if (isset($style['typography']['fontSize']))       $css_parts[] = 'font-size:' . $style['typography']['fontSize'];
        if (isset($style['typography']['fontFamily']))      $css_parts[] = 'font-family:' . $style['typography']['fontFamily'];
        if (isset($style['typography']['fontWeight']))      $css_parts[] = 'font-weight:' . $style['typography']['fontWeight'];
        if (isset($style['typography']['fontStyle']))       $css_parts[] = 'font-style:' . $style['typography']['fontStyle'];
        if (isset($style['typography']['letterSpacing']))   $css_parts[] = 'letter-spacing:' . $style['typography']['letterSpacing'];
        if (isset($style['typography']['lineHeight']))      $css_parts[] = 'line-height:' . $style['typography']['lineHeight'];
        if (isset($style['typography']['textTransform']))   $css_parts[] = 'text-transform:' . $style['typography']['textTransform'];
        if (isset($style['typography']['textDecoration']))  $css_parts[] = 'text-decoration:' . $style['typography']['textDecoration'];

        if (empty($css_parts)) return $html;

        $new_css = implode(';', $css_parts);

        // Inject or update style attribute on the first HTML tag
        if (preg_match('/style=["\']([^"\']*)["\']/', $html, $m)) {
            // Existing style — merge (keep layout styles, replace color/typography)
            $existing = $m[1];

            // Remove old color/typography from existing
            $existing = preg_replace('/(?:^|;)\s*(?:color|background-color|background|font-size|font-family|font-weight|font-style|letter-spacing|line-height|text-transform|text-decoration)\s*:[^;]*/', '', $existing);
            $existing = trim($existing, '; ');

            $merged = $existing ? $existing . ';' . $new_css : $new_css;
            $html = preg_replace('/style=["\'][^"\']*["\']/', 'style="' . esc_attr($merged) . '"', $html, 1);
        } else {
            // No existing style — add it after the tag name
            $html = preg_replace('/^(<\w+)/', '$1 style="' . esc_attr($new_css) . '"', $html, 1);
        }

        return $html;
    }

    /**
     * Render Gutenberg block content → HTML
     */
    private function render_block_content(string $content): string
    {
        // do_blocks() parses and renders all Gutenberg blocks
        $html = do_blocks($content);
        // Apply content filters (shortcodes, autop, etc.)
        $html = apply_filters('the_content', $html);
        return $html;
    }

    /**
     * Wrap HTML in full document for iframe srcDoc
     */
    private function wrap_html_doc(string $html): string
    {
        $css_url  = SB_PLUGIN_URL . 'assets/css/section-builder.css';
        $theme_css = get_stylesheet_uri();

        return sprintf(
            '<!DOCTYPE html><html><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="stylesheet" href="%s">'
            . '<link rel="stylesheet" href="%s">'
            . '<style>body{margin:0;padding:0}</style>'
            . '</head><body class="sb-preview">%s</body></html>',
            esc_url($theme_css),
            esc_url($css_url),
            $html
        );
    }

    // ================================================================
    //  Global Settings — Header / Footer
    // ================================================================

    /**
     * GET /globals
     * Return current global settings (header/footer pattern + overrides)
     */
    public function get_globals(\WP_REST_Request $request): \WP_REST_Response
    {
        $settings = get_option('sb_global_settings', []);

        // Resolve pattern data for header and footer
        $header_data = null;
        $footer_data = null;

        if (!empty($settings['header_pattern_id'])) {
            $header_data = $this->resolve_pattern($settings['header_pattern_id']);
        }
        if (!empty($settings['footer_pattern_id'])) {
            $footer_data = $this->resolve_pattern($settings['footer_pattern_id']);
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'header' => [
                    'pattern_id' => $settings['header_pattern_id'] ?? null,
                    'pattern'    => $header_data,
                    'overrides'  => $settings['header_overrides'] ?? [],
                ],
                'footer' => [
                    'pattern_id' => $settings['footer_pattern_id'] ?? null,
                    'pattern'    => $footer_data,
                    'overrides'  => $settings['footer_overrides'] ?? [],
                ],
                'site_title'  => get_bloginfo('name'),
                'site_tagline'=> get_bloginfo('description'),
                'site_url'    => home_url(),
            ],
        ]);
    }

    /**
     * POST /globals
     * Save global header/footer settings
     *
     * Body:
     * {
     *   "header_pattern_id": 1025,       // null to remove
     *   "header_overrides": { ... },
     *   "footer_pattern_id": 1030,       // null to remove
     *   "footer_overrides": { ... }
     * }
     */
    public function save_globals(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params();
        $current = get_option('sb_global_settings', []);

        // Update only fields that are present in request
        if (array_key_exists('header_pattern_id', $body)) {
            $current['header_pattern_id'] = $body['header_pattern_id'];
        }
        if (array_key_exists('header_overrides', $body)) {
            $current['header_overrides'] = $body['header_overrides'] ?? [];
        }
        if (array_key_exists('footer_pattern_id', $body)) {
            $current['footer_pattern_id'] = $body['footer_pattern_id'];
        }
        if (array_key_exists('footer_overrides', $body)) {
            $current['footer_overrides'] = $body['footer_overrides'] ?? [];
        }

        update_option('sb_global_settings', $current);

        // Purge all page caches since header/footer affect every page
        if (function_exists('rocket_clean_domain')) rocket_clean_domain();
        if (class_exists('LiteSpeed_Cache_API'))    do_action('litespeed_purge_all');

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Global settings saved',
            'data'    => $current,
        ]);
    }

    /**
     * POST /globals/render
     * Preview header + sections + footer without saving
     *
     * Body:
     * {
     *   "header_pattern_id": 1025,
     *   "header_overrides": {},
     *   "footer_pattern_id": 1030,
     *   "footer_overrides": {},
     *   "page_sections": [ { pattern_id, overrides }, ... ]
     * }
     */
    public function render_globals_preview(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params();

        $html = '';

        // Header
        $header_id = $body['header_pattern_id'] ?? null;
        if ($header_id) {
            $html .= '<header class="sb-header">';
            $html .= Renderer::render_single_pattern($header_id, $body['header_overrides'] ?? []);
            $html .= '</header>';
        }

        // Page sections
        $sections = $body['page_sections'] ?? [];
        if ($sections) {
            $html .= '<main class="sb-main">';
            $html .= Renderer::render_from_layout($sections);
            $html .= '</main>';
        }

        // Footer
        $footer_id = $body['footer_pattern_id'] ?? null;
        if ($footer_id) {
            $html .= '<footer class="sb-footer">';
            $html .= Renderer::render_single_pattern($footer_id, $body['footer_overrides'] ?? []);
            $html .= '</footer>';
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'html'     => $html,
                'document' => $this->wrap_html_doc($html),
            ],
        ]);
    }

    private function purge_cache(int $post_id): void
    {
        if (function_exists('wp_cache_post_change'))      wp_cache_post_change($post_id);
        if (class_exists('LiteSpeed_Cache_API'))           do_action('litespeed_purge_post', $post_id);
        if (function_exists('rocket_clean_post'))           rocket_clean_post($post_id);
        do_action('sb_purge_page_cache', $post_id);
    }

    public function can_read(): bool  { return is_user_logged_in(); }
    public function can_write(): bool { return current_user_can('edit_pages'); }
}
