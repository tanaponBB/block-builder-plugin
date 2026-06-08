=== Block Builder ===
Contributors: addtocraft
Tags: page builder, gutenberg, block patterns, headless, woocommerce
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 3.2.0

Build pages visually using Gutenberg Block Patterns. Global header/footer, style overrides, REST API for headless dashboard.

== Description ==

Block Builder turns Gutenberg Block Patterns into a full page building system. Design sections in the WordPress editor, then assemble pages through a REST API — perfect for headless setups with Next.js, React, or any frontend.

**How it works:**

1. Create sections as Synced Patterns (Reusable Blocks) in Gutenberg
2. Use the REST API to assemble patterns into page layouts
3. Override text and styles per page without changing the original pattern
4. Set a global header and footer that appear on every page automatically

**Key features:**

* **Pattern-based sections** — Design in Gutenberg, no PHP coding required
* **Global Header/Footer** — Set once, appears on every page automatically
* **Text overrides** — Change headings, paragraphs, buttons per page
* **Style overrides** — Adjust colors, fonts, font-size, weight (layout stays locked)
* **Live preview** — Render patterns with overrides via API before saving
* **Screenshot endpoint** — Live HTML preview of any pattern for thumbnails
* **All patterns included** — Lists both user-created and theme/plugin patterns
* **Page management** — List, create, and manage builder pages via API
* **Auto-setup** — Saves automatically set template and shortcode
* **WooCommerce compatible** — Works alongside WooCommerce blocks and patterns

**REST API endpoints (11 total):**

* `GET /patterns` — List all patterns with editable fields and cover images
* `GET /patterns/{id}/screenshot` — Live HTML preview page (public)
* `POST /patterns/render` — Preview pattern with overrides
* `GET /pages` — List all pages (builder pages + others)
* `GET /pages/{id}` — Get page layout with sections
* `POST /pages/{id}` — Save page layout (atomic)
* `POST /pages/{id}/render` — Preview page without saving
* `GET /globals` — Get global header/footer settings
* `POST /globals` — Save global header/footer
* `POST /globals/render` — Preview full page with header + footer

== Installation ==

1. Upload the `block-builder` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Settings → Permalinks → click Save Changes (flush rewrite rules)
4. Create Synced Patterns in Gutenberg (Appearance → Editor → Patterns)
5. Use the REST API to build pages

**Quick test:**

Visit `https://your-site.com/wp-json/builder/v1/patterns` (must be logged in) to see all available patterns.

== Frequently Asked Questions ==

= Do I need ACF or Elementor? =

No. Block Builder uses only WordPress core Gutenberg blocks. No third-party plugin dependencies.

= How do I create a section? =

Open any page in the Gutenberg editor, design your section using blocks (Cover, Columns, Heading, Image, Button, etc.), select all blocks, click the three-dot menu, choose "Create pattern", enable "Synced", and click Create.

= How does the global header/footer work? =

Create a header and footer as Synced Patterns, then set them via the API: `POST /builder/v1/globals` with `header_pattern_id` and `footer_pattern_id`. They automatically appear on every page. No shortcode or template setup needed.

= What styles can I override? =

Colors (text, background, gradient) and typography (font-size, font-family, font-weight, font-style, letter-spacing, line-height, text-transform, text-decoration). Layout properties like padding, margin, and width are intentionally blocked.

= Can I use theme patterns? =

Yes. The API returns both user-created Synced Patterns and theme/plugin registered patterns. Filter with `?source=synced` or `?source=theme`.

= Does it work with WooCommerce? =

Yes. WooCommerce block patterns appear in the patterns list and can be used as sections.

= What authentication does the API use? =

WordPress Application Passwords with Basic Auth. Create one at Users → Edit Profile → Application Passwords.

== Changelog ==

= 3.2.0 =
* Added style overrides (color + typography, layout blocked)
* Added page layout CSS (1440px max-width, 40px padding, responsive)
* Style fields included in editable_fields response

= 3.1.0 =
* Global header/footer auto-injected on every page via hooks
* No shortcode needed for header/footer
* Theme header/footer auto-hidden when custom ones active
* Body classes: bb-has-header, bb-has-footer

= 3.0.0 =
* Global header/footer system
* GET/POST /globals endpoints
* POST /globals/render preview endpoint
* 4 shortcodes: page_builder_sections, sb_header, sb_footer, sb_full_page

= 2.3.0 =
* GET /pages endpoint (page list)
* cover_image field on all patterns
* screenshot_url + GET /patterns/{id}/screenshot endpoint
* Pattern preview_document for iframe srcDoc

= 2.2.0 =
* All patterns support (synced + theme)
* String pattern_id for theme patterns
* Category and search filtering
* WP_Block_Patterns_Registry integration

= 2.1.0 =
* Auto-set template on save
* Auto-inject shortcode on save
* Fixed public page not updating after save

= 2.0.0 =
* Complete rewrite: ACF removed, Gutenberg patterns as section templates
* Editable fields extracted from block content
* Content overrides per page
* 6 REST API endpoints

== Upgrade Notice ==

= 3.2.0 =
Adds style overrides for colors and typography. No breaking changes from 3.1.
