<?php
if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<main id="content" class="ahx-recipe-archive">
    <header class="ahx-recipe-archive__header">
        <h1><?php post_type_archive_title(); ?></h1>
    </header>
    <?php
    global $wp_query;
    echo ahx_wp_recipe_render_collection($wp_query->posts);
    ?>
</main>
<?php get_footer(); ?>