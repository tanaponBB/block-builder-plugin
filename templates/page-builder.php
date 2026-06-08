<?php
/**
 * Template Name: Section Builder — Full Page
 *
 * Header/Footer render อัตโนมัติจาก wp_body_open + wp_footer hooks
 * Template นี้แค่ render sections ตรงกลาง
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
</head>
<body <?php body_class('sb-page'); ?>>
<?php wp_body_open(); ?>

<main id="sb-main" class="sb-main">
    <?php echo SectionBuilder\Renderer::render(get_the_ID()); ?>
</main>

<?php wp_footer(); ?>
</body>
</html>
