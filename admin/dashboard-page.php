<?php
if (!defined('ABSPATH')) {
    exit;
}

function ahx_wp_recipe_dashboard_data() {
    global $wpdb;
    $post_type = get_post_type_object('ahx_recipe');
    $own_only = !current_user_can($post_type->cap->edit_others_posts);
    $where = $wpdb->prepare('WHERE post_type = %s', 'ahx_recipe');
    if ($own_only) {
        $where .= $wpdb->prepare(' AND post_author = %d', get_current_user_id());
    }
    $rows = $wpdb->get_results("SELECT post_status, COUNT(*) AS total FROM {$wpdb->posts} {$where} GROUP BY post_status");
    $counts = [];
    foreach ($rows as $row) {
        $counts[$row->post_status] = (int) $row->total;
    }
    $query = [
        'post_type' => 'ahx_recipe', 'post_status' => 'pending',
        'posts_per_page' => 8, 'orderby' => 'date', 'order' => 'ASC',
    ];
    if ($own_only) {
        $query['author'] = get_current_user_id();
    }
    $pending = new WP_Query($query);
    $proposal_query = $query;
    $proposal_query['posts_per_page'] = 1;
    $proposal_query['fields'] = 'ids';
    $proposal_query['meta_key'] = '_ahx_recipe_submission_kind';
    $proposal_query['meta_value'] = 'url';
    $proposals = new WP_Query($proposal_query);
    $types = wp_count_terms(['taxonomy' => 'ahx_recipe_type', 'hide_empty' => false]);
    return [
        'counts' => $counts,
        'total' => array_sum(array_intersect_key($counts, array_flip(['publish', 'pending', 'draft', 'private', 'future']))),
        'pending' => $pending->posts,
        'proposals' => (int) $proposals->found_posts,
        'types' => is_wp_error($types) ? 0 : (int) $types,
        'synonyms' => count(ahx_wp_recipe_get_synonym_groups()),
    ];
}

function ahx_wp_recipe_render_dashboard() {
    if (!current_user_can('edit_posts')) {
        return;
    }
    $data = ahx_wp_recipe_dashboard_data();
    $list_url = admin_url('edit.php?post_type=ahx_recipe');
    $cards = [
        ['label' => __('Rezepte gesamt', 'ahx_wp_recipe'), 'icon' => 'dashicons-food', 'value' => $data['total'], 'url' => $list_url],
        ['label' => __('Veröffentlicht', 'ahx_wp_recipe'), 'icon' => 'dashicons-yes-alt', 'value' => $data['counts']['publish'] ?? 0, 'url' => add_query_arg('post_status', 'publish', $list_url)],
        ['label' => __('Ausstehend', 'ahx_wp_recipe'), 'icon' => 'dashicons-visibility', 'value' => $data['counts']['pending'] ?? 0, 'url' => add_query_arg('post_status', 'pending', $list_url), 'attention' => !empty($data['counts']['pending'])],
        ['label' => __('Entwürfe', 'ahx_wp_recipe'), 'icon' => 'dashicons-edit', 'value' => $data['counts']['draft'] ?? 0, 'url' => add_query_arg('post_status', 'draft', $list_url)],
        ['label' => __('URL-Vorschläge', 'ahx_wp_recipe'), 'icon' => 'dashicons-admin-links', 'value' => $data['proposals'], 'url' => add_query_arg('post_status', 'pending', $list_url), 'attention' => $data['proposals'] > 0],
        ['label' => __('Rezepttypen', 'ahx_wp_recipe'), 'icon' => 'dashicons-category', 'value' => $data['types'], 'url' => current_user_can('manage_categories') ? admin_url('edit-tags.php?taxonomy=ahx_recipe_type&post_type=ahx_recipe') : ''],
        ['label' => __('Synonymgruppen', 'ahx_wp_recipe'), 'icon' => 'dashicons-editor-spellcheck', 'value' => $data['synonyms'], 'url' => current_user_can('manage_options') ? add_query_arg('page', 'ahx-wp-recipe-synonyms', $list_url) : ''],
    ];
    $links = [
        ['label' => __('Alle Rezepte', 'ahx_wp_recipe'), 'icon' => 'dashicons-list-view', 'url' => $list_url],
        ['label' => __('Rezept hinzufügen', 'ahx_wp_recipe'), 'icon' => 'dashicons-plus-alt2', 'url' => admin_url('post-new.php?post_type=ahx_recipe')],
        ['label' => __('Einreichungen prüfen', 'ahx_wp_recipe'), 'icon' => 'dashicons-visibility', 'url' => add_query_arg('post_status', 'pending', $list_url)],
        ['label' => __('Importieren', 'ahx_wp_recipe'), 'icon' => 'dashicons-download', 'url' => add_query_arg('page', 'ahx-wp-recipe-import', $list_url)],
        ['label' => __('Rezeptübersicht öffnen', 'ahx_wp_recipe'), 'icon' => 'dashicons-external', 'url' => get_post_type_archive_link('ahx_recipe')],
    ];
    if (current_user_can('manage_categories')) {
        $links[] = ['label' => __('Rezepttypen', 'ahx_wp_recipe'), 'icon' => 'dashicons-category', 'url' => admin_url('edit-tags.php?taxonomy=ahx_recipe_type&post_type=ahx_recipe')];
    }
    if (current_user_can('manage_options')) {
        $links[] = ['label' => __('Zutaten-Synonyme', 'ahx_wp_recipe'), 'icon' => 'dashicons-editor-spellcheck', 'url' => add_query_arg('page', 'ahx-wp-recipe-synonyms', $list_url)];
    }
    $kind_labels = ['recipe' => __('Frontend-Rezept', 'ahx_wp_recipe'), 'url' => __('URL-Vorschlag', 'ahx_wp_recipe'), 'imported' => __('Importierter Vorschlag', 'ahx_wp_recipe')];
    ?>
    <div class="wrap ahx-recipe-dashboard">
        <div class="ahx-recipe-dashboard__heading">
            <div>
                <h1><?php esc_html_e('AHX Rezepte', 'ahx_wp_recipe'); ?></h1>
                <p class="ahx-recipe-dashboard__version"><?php esc_html_e('Version', 'ahx_wp_recipe'); ?> <?php echo esc_html(AHX_WP_RECIPE_VERSION); ?></p>
            </div>
            <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=ahx_recipe')); ?>"><?php esc_html_e('Rezept hinzufügen', 'ahx_wp_recipe'); ?></a>
        </div>
        <div class="ahx-recipe-dashboard__stats">
            <?php foreach ($cards as $card) : ?>
                <?php $element = $card['url'] ? 'a' : 'div'; ?>
                <<?php echo $element; ?> class="ahx-recipe-dashboard__stat<?php echo !empty($card['attention']) ? ' ahx-recipe-dashboard__stat--attention' : ''; ?>" <?php if ($card['url']) : ?>href="<?php echo esc_url($card['url']); ?>"<?php endif; ?>>
                    <span class="dashicons <?php echo esc_attr($card['icon']); ?>" aria-hidden="true"></span>
                    <span><strong><?php echo esc_html(number_format_i18n($card['value'])); ?></strong><?php echo esc_html($card['label']); ?></span>
                    <?php if ($card['url']) : ?><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span><?php endif; ?>
                </<?php echo $element; ?>>
            <?php endforeach; ?>
        </div>
        <section class="ahx-recipe-dashboard__section">
            <div class="ahx-recipe-dashboard__section-heading"><h2><?php esc_html_e('Schnellzugriff', 'ahx_wp_recipe'); ?></h2></div>
            <nav class="ahx-recipe-dashboard__links" aria-label="<?php esc_attr_e('Rezeptverwaltung', 'ahx_wp_recipe'); ?>">
                <?php foreach ($links as $link) : ?>
                    <?php if ($link['url']) : ?><a href="<?php echo esc_url($link['url']); ?>"><?php if ($link['icon']) : ?><span class="dashicons <?php echo esc_attr($link['icon']); ?>" aria-hidden="true"></span><?php endif; ?><?php echo esc_html($link['label']); ?></a><?php endif; ?>
                <?php endforeach; ?>
            </nav>
        </section>
        <div class="ahx-recipe-dashboard__summary">
        <section class="ahx-recipe-dashboard__section">
        <div class="ahx-recipe-dashboard__section-heading">
            <h2><?php esc_html_e('Zur Prüfung ausstehend', 'ahx_wp_recipe'); ?></h2>
            <a href="<?php echo esc_url(add_query_arg('post_status', 'pending', $list_url)); ?>"><?php esc_html_e('Alle ansehen', 'ahx_wp_recipe'); ?></a>
        </div>
        <?php if (!$data['pending']) : ?>
            <div class="ahx-recipe-dashboard__empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><p><?php esc_html_e('Keine Rezepte oder URL-Vorschläge zur Prüfung vorhanden.', 'ahx_wp_recipe'); ?></p></div>
        <?php else : ?>
            <div class="ahx-recipe-dashboard__table">
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Rezept', 'ahx_wp_recipe'); ?></th><th><?php esc_html_e('Einreichung', 'ahx_wp_recipe'); ?></th><th><?php esc_html_e('Datum', 'ahx_wp_recipe'); ?></th><th><?php esc_html_e('Aktion', 'ahx_wp_recipe'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($data['pending'] as $recipe) : ?>
                            <tr>
                                <td><?php echo esc_html(get_the_title($recipe)); ?></td>
                                <td><?php echo esc_html($kind_labels[get_post_meta($recipe->ID, '_ahx_recipe_submission_kind', true)] ?? __('Backend-Rezept', 'ahx_wp_recipe')); ?></td>
                                <td><?php echo esc_html(get_the_date(get_option('date_format'), $recipe)); ?></td>
                                <td><?php if (current_user_can('edit_post', $recipe->ID)) : ?><a class="button" href="<?php echo esc_url(get_edit_post_link($recipe->ID, 'raw')); ?>"><?php esc_html_e('Prüfen', 'ahx_wp_recipe'); ?></a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </section>
        <section class="ahx-recipe-dashboard__section">
        <div class="ahx-recipe-dashboard__section-heading"><h2><?php esc_html_e('Frontend-Shortcodes', 'ahx_wp_recipe'); ?></h2></div>
        <dl class="ahx-recipe-dashboard__shortcodes">
            <div><dt><code>[ahx_wp_recipes]</code></dt><dd><?php esc_html_e('Rezeptübersicht mit Vorratssuche', 'ahx_wp_recipe'); ?></dd></div>
            <div><dt><code>[ahx_wp_recipe_submit_recipe]</code></dt><dd><?php esc_html_e('Rezept einreichen', 'ahx_wp_recipe'); ?></dd></div>
            <div><dt><code>[ahx_wp_recipe_submit_url]</code></dt><dd><?php esc_html_e('Rezept-URL vorschlagen', 'ahx_wp_recipe'); ?></dd></div>
        </dl>
        </section>
        </div>
    </div>
    <?php
}