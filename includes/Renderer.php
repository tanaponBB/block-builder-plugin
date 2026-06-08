<?php

namespace SectionBuilder;

/**
 * Renderer — Pattern-based
 *
 * อ่าน _sb_page_layout จาก post meta → render แต่ละ pattern ตาม order
 * รองรับ content overrides per section
 */
class Renderer
{
    /**
     * Render page จาก post meta
     */
    public static function render(int $post_id): string
    {
        $layout = get_post_meta($post_id, '_sb_page_layout', true);

        if (!$layout || !is_array($layout)) {
            return defined('WP_DEBUG') && WP_DEBUG
                ? sprintf('<!-- SB: no layout for post_id=%d -->', $post_id)
                : '';
        }

        return self::render_from_layout($layout);
    }

    /**
     * Render จาก layout array โดยตรง (ใช้ทั้ง frontend และ preview API)
     *
     * @param array $layout [{ pattern_id: int|string, overrides: array }, ...]
     *   pattern_id = numeric (wp_block post ID) หรือ string (theme pattern name)
     */
    public static function render_from_layout(array $layout): string
    {
        if (empty($layout)) return '';

        $output = '';

        foreach ($layout as $index => $item) {
            $pattern_id = $item['pattern_id'] ?? 0;
            $overrides  = $item['overrides'] ?? [];

            // Resolve pattern content — support ทั้ง synced (ID) และ theme (name)
            $content = null;
            $slug    = '';

            if (is_numeric($pattern_id)) {
                // wp_block post
                $pattern = get_post((int) $pattern_id);
                if ($pattern && $pattern->post_type === 'wp_block') {
                    $content = $pattern->post_content;
                    $slug    = sanitize_title($pattern->post_title);
                }
            } else {
                // Theme/plugin registered pattern
                if (class_exists('\WP_Block_Patterns_Registry')) {
                    $registry = \WP_Block_Patterns_Registry::get_instance();
                    $pattern  = $registry->get_registered((string) $pattern_id);
                    if ($pattern) {
                        $content = $pattern['content'] ?? '';
                        $slug    = sanitize_title($pattern['title'] ?? $pattern_id);
                    }
                }
            }

            if ($content === null) {
                $output .= sprintf('<!-- SB: pattern %s not found -->', esc_html($pattern_id));
                continue;
            }

            // Apply overrides
            if (!empty($overrides)) {
                $content = self::apply_overrides($content, $overrides);
            }

            // Render blocks → HTML
            $html = do_blocks($content);
            $html = apply_filters('the_content', $html);

            // Wrap in section container
            $output .= sprintf(
                '<section class="sb-section sb-section--%s" data-section-index="%d" data-pattern-id="%s">%s</section>',
                esc_attr($slug),
                $index,
                esc_attr($pattern_id),
                $html
            );
        }

        return $output;
    }

    /**
     * Apply text + style overrides to block content
     */
    private static function apply_overrides(string $content, array $overrides): string
    {
        if (empty($overrides)) return $content;

        $blocks = parse_blocks($content);
        self::apply_overrides_walk($blocks, $overrides, '');
        return serialize_blocks($blocks);
    }

    private static function apply_overrides_walk(array &$blocks, array $overrides, string $path): void
    {
        $allowed_styles = [
            'color.text', 'color.background', 'color.gradient',
            'typography.fontSize', 'typography.fontFamily', 'typography.fontWeight',
            'typography.fontStyle', 'typography.letterSpacing', 'typography.lineHeight',
            'typography.textTransform', 'typography.textDecoration',
        ];

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
                foreach ($overrides[$style_key] as $property => $value) {
                    if (!in_array($property, $allowed_styles)) continue;

                    $parts = explode('.', $property);
                    if (count($parts) !== 2) continue;

                    $group = $parts[0];
                    $prop  = $parts[1];

                    if ($value === null || $value === '') {
                        unset($block['attrs']['style'][$group][$prop]);
                    } else {
                        if (!isset($block['attrs'])) $block['attrs'] = [];
                        if (!isset($block['attrs']['style'])) $block['attrs']['style'] = [];
                        if (!isset($block['attrs']['style'][$group])) $block['attrs']['style'][$group] = [];
                        $block['attrs']['style'][$group][$prop] = $value;
                    }

                    // Remove conflicting presets
                    if ($property === 'color.text')       unset($block['attrs']['textColor']);
                    if ($property === 'color.background') unset($block['attrs']['backgroundColor']);
                    if ($property === 'typography.fontSize') unset($block['attrs']['fontSize']);
                }
            }

            if (!empty($block['innerBlocks'])) {
                self::apply_overrides_walk($block['innerBlocks'], $overrides, $current_path);
            }
        }
    }

    // ================================================================
    //  Global Header / Footer
    // ================================================================

    /**
     * Render global header or footer
     *
     * @param string $slot 'header' or 'footer'
     */
    public static function render_global(string $slot): string
    {
        $settings = get_option('sb_global_settings', []);
        $pattern_id = $settings["{$slot}_pattern_id"] ?? null;
        $overrides  = $settings["{$slot}_overrides"] ?? [];

        if (!$pattern_id) {
            return defined('WP_DEBUG') && WP_DEBUG
                ? "<!-- SB: no global {$slot} set -->"
                : '';
        }

        return self::render_single_pattern($pattern_id, $overrides, "sb-global-{$slot}");
    }

    /**
     * Render single pattern by ID with optional overrides
     */
    public static function render_single_pattern($pattern_id, array $overrides = [], string $wrapper_class = ''): string
    {
        $content = null;
        $slug    = '';

        if (is_numeric($pattern_id)) {
            $pattern = get_post((int) $pattern_id);
            if ($pattern && $pattern->post_type === 'wp_block') {
                $content = $pattern->post_content;
                $slug    = sanitize_title($pattern->post_title);
            }
        } else {
            if (class_exists('\WP_Block_Patterns_Registry')) {
                $registry = \WP_Block_Patterns_Registry::get_instance();
                $pattern  = $registry->get_registered((string) $pattern_id);
                if ($pattern) {
                    $content = $pattern['content'] ?? '';
                    $slug    = sanitize_title($pattern['title'] ?? $pattern_id);
                }
            }
        }

        if ($content === null) {
            return sprintf('<!-- SB: pattern %s not found -->', esc_html($pattern_id));
        }

        if (!empty($overrides)) {
            $content = self::apply_overrides($content, $overrides);
        }

        $html = do_blocks($content);
        $html = apply_filters('the_content', $html);

        $class = $wrapper_class ? esc_attr($wrapper_class) . ' ' : '';
        return sprintf(
            '<div class="%ssb-pattern sb-pattern--%s" data-pattern-id="%s">%s</div>',
            $class,
            esc_attr($slug),
            esc_attr($pattern_id),
            $html
        );
    }
}
