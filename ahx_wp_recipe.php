<?php
/**
 * Plugin Name: AHX WP Recipe
 * Description: Rezepte verwalten, skalieren, anzeigen und für Bring! vorbereiten.
 * Version: v2.2.0
 * Author: Alexander Herbst
 * Text Domain: ahx_wp_recipe
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AHX_WP_RECIPE_VERSION', 'v2.2.0');
define('AHX_WP_RECIPE_PATH', plugin_dir_path(__FILE__));
define('AHX_WP_RECIPE_URL', plugin_dir_url(__FILE__));

function ahx_wp_recipe_register_post_type() {
    register_post_type('ahx_recipe', [
        'labels' => [
            'name' => __('AHX Rezepte', 'ahx_wp_recipe'),
            'singular_name' => __('Rezept', 'ahx_wp_recipe'),
            'add_new_item' => __('Rezept hinzufügen', 'ahx_wp_recipe'),
            'edit_item' => __('Rezept bearbeiten', 'ahx_wp_recipe'),
            'search_items' => __('Rezepte suchen', 'ahx_wp_recipe'),
            'not_found' => __('Keine Rezepte gefunden.', 'ahx_wp_recipe'),
        ],
        'public' => true,
        'has_archive' => true,
        'show_in_rest' => true,
        'menu_icon' => 'dashicons-food',
        'supports' => ['title', 'editor', 'excerpt', 'thumbnail'],
        'rewrite' => ['slug' => 'rezepte'],
    ]);
    register_taxonomy('ahx_recipe_type', ['ahx_recipe'], [
        'labels' => [
            'name' => __('Rezepttypen', 'ahx_wp_recipe'),
            'singular_name' => __('Rezepttyp', 'ahx_wp_recipe'),
            'search_items' => __('Rezepttypen suchen', 'ahx_wp_recipe'),
            'all_items' => __('Alle Rezepttypen', 'ahx_wp_recipe'),
            'edit_item' => __('Rezepttyp bearbeiten', 'ahx_wp_recipe'),
            'update_item' => __('Rezepttyp aktualisieren', 'ahx_wp_recipe'),
            'add_new_item' => __('Neuen Rezepttyp hinzufügen', 'ahx_wp_recipe'),
            'new_item_name' => __('Name des neuen Rezepttyps', 'ahx_wp_recipe'),
            'parent_item' => __('Übergeordneter Rezepttyp', 'ahx_wp_recipe'),
            'parent_item_colon' => __('Übergeordneter Rezepttyp:', 'ahx_wp_recipe'),
        ],
        'hierarchical' => true,
        'public' => false,
        'show_ui' => true,
        'show_admin_column' => true,
        'show_in_rest' => true,
        'rewrite' => false,
    ]);
}
add_action('init', 'ahx_wp_recipe_register_post_type');

function ahx_wp_recipe_split_ingredient_item($item) {
    $additions = [];
    $label = preg_replace_callback('/\(([^()]*)\)/u', static function ($matches) use (&$additions) {
        $text = trim($matches[1]);
        if (preg_match('/^(n|en|e)$/iu', $text)) {
            return $matches[0];
        }
        if ($text !== '') {
            $additions[] = $text;
        }
        return '';
    }, (string) $item);

    return [
        'label' => trim(preg_replace('/\s+/u', ' ', (string) $label)),
        'addition' => implode('; ', $additions),
    ];
}

function ahx_wp_recipe_normalize_ingredients($ingredients) {
    $normalized = [];
    foreach ((array) $ingredients as $ingredient) {
        if (!is_array($ingredient)) {
            continue;
        }
        if (array_key_exists('label', $ingredient)) {
            $label = trim((string) $ingredient['label']);
            $addition = trim((string) ($ingredient['addition'] ?? ''));
        } else {
            $split = ahx_wp_recipe_split_ingredient_item($ingredient['item'] ?? '');
            $label = $split['label'];
            $addition = $split['addition'];
        }

        if ($label === '' && $addition === '') {
            continue;
        }
        $normalized[] = [
            'quantity' => trim((string) ($ingredient['quantity'] ?? '')),
            'unit' => trim((string) ($ingredient['unit'] ?? '')),
            'label' => $label,
            'addition' => $addition,
        ];
    }
    return $normalized;
}

function ahx_wp_recipe_get_ingredients($post_id, $persist_migration = false) {
    $stored = get_post_meta($post_id, '_ahx_recipe_ingredients', true);
    $normalized = ahx_wp_recipe_normalize_ingredients($stored);
    if ($persist_migration && is_array($stored) && $stored !== $normalized) {
        update_post_meta($post_id, '_ahx_recipe_ingredients', $normalized);
    }
    return $normalized;
}

function ahx_wp_recipe_activate($network_wide) {
    if (is_multisite() && $network_wide) {
        $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
        foreach ($site_ids as $site_id) {
            switch_to_blog((int) $site_id);
            ahx_wp_recipe_register_post_type();
            flush_rewrite_rules();
            restore_current_blog();
        }
        return;
    }

    ahx_wp_recipe_register_post_type();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'ahx_wp_recipe_activate');

function ahx_wp_recipe_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'ahx_wp_recipe_deactivate');

function ahx_wp_recipe_add_meta_box() {
    add_meta_box(
        'ahx_wp_recipe_details',
        __('Rezeptdaten', 'ahx_wp_recipe'),
        'ahx_wp_recipe_render_meta_box',
        'ahx_recipe',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes_ahx_recipe', 'ahx_wp_recipe_add_meta_box');

function ahx_wp_recipe_render_meta_box($post) {
    wp_nonce_field('ahx_wp_recipe_save', 'ahx_wp_recipe_nonce');
    $servings = max(1, (int) get_post_meta($post->ID, '_ahx_recipe_servings', true));
    $layout = get_post_meta($post->ID, '_ahx_recipe_layout', true) ?: 'classic';
    $ingredients = ahx_wp_recipe_get_ingredients($post->ID, true);
    $instructions = get_post_meta($post->ID, '_ahx_recipe_instructions', true);
    if (!$ingredients) {
        $ingredients = [['quantity' => '', 'unit' => '', 'label' => '', 'addition' => '']];
    }
    $instructions = is_array($instructions) ? $instructions : [];
    ?>
    <div class="ahx-recipe-editor">
        <p>
            <label for="ahx-recipe-servings"><strong><?php esc_html_e('Portionen', 'ahx_wp_recipe'); ?></strong></label>
            <input id="ahx-recipe-servings" name="ahx_recipe_servings" type="number" min="1" max="999" value="<?php echo esc_attr($servings); ?>">
        </p>
        <p>
            <label for="ahx-recipe-layout"><strong><?php esc_html_e('Darstellung', 'ahx_wp_recipe'); ?></strong></label>
            <select id="ahx-recipe-layout" name="ahx_recipe_layout">
                <option value="classic" <?php selected($layout, 'classic'); ?>><?php esc_html_e('Klassisch: Zutaten und Schritte untereinander', 'ahx_wp_recipe'); ?></option>
                <option value="split" <?php selected($layout, 'split'); ?>><?php esc_html_e('Zweiteilig: Zutaten neben der Anleitung', 'ahx_wp_recipe'); ?></option>
                <option value="checklist" <?php selected($layout, 'checklist'); ?>><?php esc_html_e('Kochmodus: Zutaten und Schritte zum Abhaken', 'ahx_wp_recipe'); ?></option>
            </select>
        </p>
        <p>
            <label for="ahx-recipe-source"><strong><?php esc_html_e('Quelle (optional)', 'ahx_wp_recipe'); ?></strong></label>
            <input id="ahx-recipe-source" class="large-text" name="ahx_recipe_source_url" type="url" placeholder="https://www.chefkoch.de/rezepte/..." value="<?php echo esc_attr(get_post_meta($post->ID, '_ahx_recipe_source_url', true)); ?>">
        </p>
        <h3><?php esc_html_e('Zutaten', 'ahx_wp_recipe'); ?></h3>
        <p><?php esc_html_e('Mengen als Zahl oder Bruch (z. B. 1/2) eingeben. Mengenangaben wie „nach Bedarf“ können leer bleiben.', 'ahx_wp_recipe'); ?></p>
        <div class="ahx-recipe-ingredient-rows">
            <?php foreach ($ingredients as $ingredient_index => $ingredient) : ?>
                <div class="ahx-recipe-row">
                    <input name="ahx_recipe_ingredients[<?php echo esc_attr($ingredient_index); ?>][quantity]" type="text" inputmode="decimal" placeholder="<?php esc_attr_e('Menge', 'ahx_wp_recipe'); ?>" value="<?php echo esc_attr($ingredient['quantity'] ?? ''); ?>">
                    <input name="ahx_recipe_ingredients[<?php echo esc_attr($ingredient_index); ?>][unit]" type="text" placeholder="<?php esc_attr_e('Einheit', 'ahx_wp_recipe'); ?>" value="<?php echo esc_attr($ingredient['unit'] ?? ''); ?>">
                    <input name="ahx_recipe_ingredients[<?php echo esc_attr($ingredient_index); ?>][label]" type="text" placeholder="<?php esc_attr_e('Bezeichnung', 'ahx_wp_recipe'); ?>" value="<?php echo esc_attr($ingredient['label'] ?? ''); ?>">
                    <input name="ahx_recipe_ingredients[<?php echo esc_attr($ingredient_index); ?>][addition]" type="text" placeholder="<?php esc_attr_e('Ergänzung', 'ahx_wp_recipe'); ?>" value="<?php echo esc_attr($ingredient['addition'] ?? ''); ?>">
                    <button type="button" class="button ahx-recipe-remove-row" aria-label="<?php esc_attr_e('Zutat entfernen', 'ahx_wp_recipe'); ?>">&minus;</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button ahx-recipe-add-ingredient"><?php esc_html_e('Zutat hinzufügen', 'ahx_wp_recipe'); ?></button>
        <h3><?php esc_html_e('Zubereitung', 'ahx_wp_recipe'); ?></h3>
        <p><?php esc_html_e('Jeder nicht-leere Absatz wird als eigener Zubereitungsschritt angezeigt.', 'ahx_wp_recipe'); ?></p>
        <textarea class="large-text" rows="8" name="ahx_recipe_instructions"><?php echo esc_textarea(implode("\n\n", $instructions)); ?></textarea>
    </div>
    <?php
}

function ahx_wp_recipe_save_meta($post_id) {
    if (!isset($_POST['ahx_wp_recipe_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ahx_wp_recipe_nonce'])), 'ahx_wp_recipe_save')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $servings = isset($_POST['ahx_recipe_servings']) ? min(999, max(1, absint($_POST['ahx_recipe_servings']))) : 1;
    update_post_meta($post_id, '_ahx_recipe_servings', $servings);
    $layout = sanitize_key(wp_unslash($_POST['ahx_recipe_layout'] ?? 'classic'));
    update_post_meta($post_id, '_ahx_recipe_layout', in_array($layout, ['classic', 'split', 'checklist'], true) ? $layout : 'classic');

    $source_url = esc_url_raw(wp_unslash($_POST['ahx_recipe_source_url'] ?? ''));
    if ($source_url !== '') {
        update_post_meta($post_id, '_ahx_recipe_source_url', $source_url);
    } else {
        delete_post_meta($post_id, '_ahx_recipe_source_url');
    }

    $ingredients = [];
    foreach ((array) wp_unslash($_POST['ahx_recipe_ingredients'] ?? []) as $ingredient) {
        if (!is_array($ingredient)) {
            continue;
        }
        $label = sanitize_text_field($ingredient['label'] ?? '');
        $addition = sanitize_text_field($ingredient['addition'] ?? '');
        $quantity = preg_replace('/[^0-9.,\/\s-]/', '', (string) ($ingredient['quantity'] ?? ''));
        $unit = sanitize_text_field($ingredient['unit'] ?? '');
        if ($label !== '' || $addition !== '') {
            $ingredients[] = ['quantity' => trim($quantity), 'unit' => $unit, 'label' => $label, 'addition' => $addition];
        }
    }
    update_post_meta($post_id, '_ahx_recipe_ingredients', $ingredients);

    $raw_instructions = sanitize_textarea_field(wp_unslash($_POST['ahx_recipe_instructions'] ?? ''));
    $instructions = preg_split('/\R\s*\R/u', trim($raw_instructions), -1, PREG_SPLIT_NO_EMPTY);
    update_post_meta($post_id, '_ahx_recipe_instructions', array_values(array_map('trim', $instructions ?: [])));
}
add_action('save_post_ahx_recipe', 'ahx_wp_recipe_save_meta');

function ahx_wp_recipe_admin_menu() {
    global $ahx_wp_recipe_import_hook, $ahx_wp_recipe_synonyms_hook, $ahx_wp_recipe_dashboard_hook;
    $ahx_wp_recipe_dashboard_hook = add_submenu_page(
        'edit.php?post_type=ahx_recipe',
        __('Rezepte-Dashboard', 'ahx_wp_recipe'),
        __('Dashboard', 'ahx_wp_recipe'),
        'edit_posts',
        'ahx-wp-recipe-dashboard',
        'ahx_wp_recipe_render_dashboard',
        0
    );
    $ahx_wp_recipe_import_hook = add_submenu_page(
        'edit.php?post_type=ahx_recipe',
        __('Rezepte importieren', 'ahx_wp_recipe'),
        __('Importieren', 'ahx_wp_recipe'),
        'edit_posts',
        'ahx-wp-recipe-import',
        'ahx_wp_recipe_render_import_page'
    );
    $ahx_wp_recipe_synonyms_hook = add_submenu_page(
        'edit.php?post_type=ahx_recipe',
        __('Zutaten-Synonyme', 'ahx_wp_recipe'),
        __('Zutaten-Synonyme', 'ahx_wp_recipe'),
        'manage_options',
        'ahx-wp-recipe-synonyms',
        'ahx_wp_recipe_render_synonyms_page'
    );
}
add_action('admin_menu', 'ahx_wp_recipe_admin_menu');

function ahx_wp_recipe_admin_assets($hook) {
    global $ahx_wp_recipe_import_hook, $ahx_wp_recipe_synonyms_hook, $ahx_wp_recipe_dashboard_hook;
    if ($ahx_wp_recipe_dashboard_hook && $hook === $ahx_wp_recipe_dashboard_hook) {
        wp_enqueue_style('ahx-wp-recipe-admin', AHX_WP_RECIPE_URL . 'assets/admin.css', [], (string) filemtime(AHX_WP_RECIPE_PATH . 'assets/admin.css'));
    }
    $screen = get_current_screen();
    if (in_array($hook, ['post.php', 'post-new.php'], true) && $screen && $screen->post_type === 'ahx_recipe') {
        wp_enqueue_script('ahx-wp-recipe-admin', AHX_WP_RECIPE_URL . 'assets/admin.js', [], (string) filemtime(AHX_WP_RECIPE_PATH . 'assets/admin.js'), true);
        wp_enqueue_style('ahx-wp-recipe-admin', AHX_WP_RECIPE_URL . 'assets/admin.css', [], (string) filemtime(AHX_WP_RECIPE_PATH . 'assets/admin.css'));
    }
    if ($hook && in_array($hook, [$ahx_wp_recipe_import_hook, $ahx_wp_recipe_synonyms_hook], true)) {
        wp_enqueue_script('ahx-wp-recipe-admin', AHX_WP_RECIPE_URL . 'assets/admin.js', [], (string) filemtime(AHX_WP_RECIPE_PATH . 'assets/admin.js'), true);
        wp_enqueue_style('ahx-wp-recipe-admin', AHX_WP_RECIPE_URL . 'assets/admin.css', [], (string) filemtime(AHX_WP_RECIPE_PATH . 'assets/admin.css'));
    }
}
add_action('admin_enqueue_scripts', 'ahx_wp_recipe_admin_assets');

function ahx_wp_recipe_normalize_synonym_name($value) {
    $value = strtr((string) $value, ['ä' => 'ae', 'Ä' => 'ae', 'ö' => 'oe', 'Ö' => 'oe', 'ü' => 'ue', 'Ü' => 'ue', 'ß' => 'ss', 'ẞ' => 'ss']);
    $value = strtolower(remove_accents($value, 'en_US'));
    $value = preg_replace('/\((n|en|e)\)/', '$1', $value);
    $value = preg_replace('/[^a-z0-9\s]/u', ' ', $value);
    return trim(preg_replace('/\s+/u', ' ', $value));
}

function ahx_wp_recipe_default_synonym_groups() {
    return [
        ['name' => 'Karotte', 'aliases' => ['Karotten', 'Möhre', 'Möhren', 'Mohrrübe', 'Mohrrüben']],
        ['name' => 'Kartoffel', 'aliases' => ['Kartoffeln']],
        ['name' => 'Zwiebel', 'aliases' => ['Zwiebeln']],
        ['name' => 'Ei', 'aliases' => ['Eier', 'Ei(er)']],
    ];
}

function ahx_wp_recipe_get_synonym_groups() {
    $groups = get_option('ahx_wp_recipe_synonym_groups', ahx_wp_recipe_default_synonym_groups());
    return is_array($groups) ? array_values(array_filter($groups, static function ($group) {
        return is_array($group) && isset($group['name'], $group['aliases']) && is_string($group['name']) && is_array($group['aliases']);
    })) : ahx_wp_recipe_default_synonym_groups();
}

function ahx_wp_recipe_sanitize_synonym_groups($input) {
    $reject = static function ($message) {
        add_settings_error('ahx_wp_recipe_synonym_groups', 'invalid_synonyms', $message);
        return ahx_wp_recipe_get_synonym_groups();
    };
    if (!is_array($input) || count($input) > 200) {
        return $reject(__('Ungültige Synonymgruppen. Maximal 200 Gruppen sind möglich.', 'ahx_wp_recipe'));
    }
    $groups = [];
    $seen = [];
    foreach ($input as $row) {
        if (!is_array($row) || !isset($row['name'], $row['aliases']) || !is_string($row['name']) || (!is_string($row['aliases']) && !is_array($row['aliases']))) {
            return $reject(__('Ungültige Synonymgruppe.', 'ahx_wp_recipe'));
        }
        $name = trim(sanitize_text_field($row['name']));
        $aliases = is_array($row['aliases']) ? $row['aliases'] : preg_split('/\R/u', $row['aliases']);
        if (!is_array($aliases)) {
            return $reject(__('Ungültige Zeichenkodierung in den Synonymnamen.', 'ahx_wp_recipe'));
        }
        if (count($aliases) > 50) {
            return $reject(__('Maximal 50 Synonyme pro Gruppe sind möglich.', 'ahx_wp_recipe'));
        }
        $clean_aliases = [];
        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                return $reject(__('Ungültiger Synonymname.', 'ahx_wp_recipe'));
            }
            $alias = trim(sanitize_text_field($alias));
            if ($alias !== '') {
                $clean_aliases[] = $alias;
            }
        }
        if ($name === '' && !$clean_aliases) {
            continue;
        }
        $canonical = ahx_wp_recipe_normalize_synonym_name($name);
        if ($canonical === '' || !preg_match('/^.{1,120}$/us', $name)) {
            return $reject(__('Jede Gruppe benötigt einen gültigen Hauptnamen mit maximal 120 Zeichen.', 'ahx_wp_recipe'));
        }
        $local = [];
        $unique_aliases = [];
        foreach (array_merge([$name], $clean_aliases) as $position => $value) {
            $normalized = ahx_wp_recipe_normalize_synonym_name($value);
            if ($normalized === '' || !preg_match('/^.{1,120}$/us', $value)) {
                return $reject(__('Synonymnamen müssen gültig sein und dürfen maximal 120 Zeichen enthalten.', 'ahx_wp_recipe'));
            }
            if (isset($seen[$normalized])) {
                return $reject(sprintf(__('„%s“ ist mehreren Gruppen zugeordnet. Die bisherigen Gruppen wurden beibehalten.', 'ahx_wp_recipe'), $value));
            }
            if (isset($local[$normalized])) {
                continue;
            }
            $local[$normalized] = true;
            if ($position > 0) {
                $unique_aliases[] = $value;
            }
        }
        $seen += $local;
        $groups[] = ['name' => $name, 'aliases' => $unique_aliases];
    }
    return $groups;
}

function ahx_wp_recipe_synonym_map($groups) {
    $map = [];
    foreach ($groups as $group) {
        $canonical = ahx_wp_recipe_normalize_synonym_name($group['name']);
        foreach (array_merge([$group['name']], $group['aliases']) as $name) {
            $map[ahx_wp_recipe_normalize_synonym_name($name)] = $canonical;
        }
    }
    return $map;
}

function ahx_wp_recipe_register_synonym_settings() {
    register_setting('ahx_wp_recipe_synonyms', 'ahx_wp_recipe_synonym_groups', [
        'type' => 'array',
        'sanitize_callback' => 'ahx_wp_recipe_sanitize_synonym_groups',
        'default' => ahx_wp_recipe_default_synonym_groups(),
    ]);
}
add_action('admin_init', 'ahx_wp_recipe_register_synonym_settings');

function ahx_wp_recipe_render_synonym_row($index, $group) {
    ?>
    <div class="ahx-recipe-synonym-row" data-ahx-synonym-index="<?php echo esc_attr($index); ?>">
        <label><?php esc_html_e('Zutat (Hauptname)', 'ahx_wp_recipe'); ?><input type="text" maxlength="120" name="ahx_wp_recipe_synonym_groups[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($group['name']); ?>"></label>
        <label><?php esc_html_e('Synonyme (je eine Zeile)', 'ahx_wp_recipe'); ?><textarea rows="3" name="ahx_wp_recipe_synonym_groups[<?php echo esc_attr($index); ?>][aliases]"><?php echo esc_textarea(implode("\n", $group['aliases'])); ?></textarea></label>
        <button type="button" class="button" data-ahx-synonym-remove aria-label="<?php esc_attr_e('Synonymgruppe entfernen', 'ahx_wp_recipe'); ?>" title="<?php esc_attr_e('Synonymgruppe entfernen', 'ahx_wp_recipe'); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
    </div>
    <?php
}

function ahx_wp_recipe_render_synonyms_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $groups = ahx_wp_recipe_get_synonym_groups();
    if (!$groups) {
        $groups = [['name' => '', 'aliases' => []]];
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Zutaten-Synonyme', 'ahx_wp_recipe'); ?></h1>
        <?php settings_errors(); ?>
        <form method="post" action="options.php" class="ahx-recipe-synonyms" data-ahx-synonyms>
            <?php settings_fields('ahx_wp_recipe_synonyms'); ?>
            <div data-ahx-synonym-rows>
                <?php foreach ($groups as $index => $group) { ahx_wp_recipe_render_synonym_row($index, $group); } ?>
            </div>
            <template data-ahx-synonym-template><?php ahx_wp_recipe_render_synonym_row('__index__', ['name' => '', 'aliases' => []]); ?></template>
            <button type="button" class="button" data-ahx-synonym-add><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e('Synonymgruppe hinzufügen', 'ahx_wp_recipe'); ?></button>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function ahx_wp_recipe_store_import_payload($payload) {
    $token = wp_generate_password(20, false, false);
    set_transient('ahx_wp_recipe_import_' . $token, [
        'user_id' => get_current_user_id(),
        'payload' => $payload,
    ], 15 * MINUTE_IN_SECONDS);
    return $token;
}

function ahx_wp_recipe_get_import_payload($token) {
    $data = get_transient('ahx_wp_recipe_import_' . sanitize_key($token));
    if (!is_array($data) || (int) ($data['user_id'] ?? 0) !== get_current_user_id()) {
        return null;
    }
    return $data['payload'];
}

function ahx_wp_recipe_delete_import_payload($token) {
    delete_transient('ahx_wp_recipe_import_' . sanitize_key($token));
}

function ahx_wp_recipe_render_image_review($token, $payload) {
    $images = $payload['images'] ?? [];
    $title = $payload['parsed']['title'] ?? '';
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Bilder auswählen', 'ahx_wp_recipe'); ?></h1>
        <p><?php echo esc_html(sprintf(
            /* translators: %s: recipe title */
            __('Wähle die Bilder aus, die zum Rezept „%s“ übernommen werden sollen. Das erste ausgewählte Bild wird das Beitragsbild, weitere werden als Bildergalerie gespeichert.', 'ahx_wp_recipe'),
            $title
        )); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ahx_wp_recipe_import_finish">
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
            <?php wp_nonce_field('ahx_wp_recipe_import_finish_' . $token); ?>
            <p class="ahx-recipe-image-actions">
                <button type="button" class="button" data-ahx-recipe-select-images="all"><?php esc_html_e('Alle auswählen', 'ahx_wp_recipe'); ?></button>
                <button type="button" class="button" data-ahx-recipe-select-images="none"><?php esc_html_e('Alle abwählen', 'ahx_wp_recipe'); ?></button>
            </p>
            <div class="ahx-recipe-image-grid">
                <?php foreach ($images as $index => $image_url) : ?>
                    <label class="ahx-recipe-image-choice">
                        <input type="checkbox" name="recipe_images[]" value="<?php echo esc_attr($index); ?>" checked>
                        <img src="<?php echo esc_url($image_url); ?>" alt="" loading="lazy">
                        <span class="ahx-recipe-image-dimensions" data-unavailable="<?php esc_attr_e('Größe nicht verfügbar', 'ahx_wp_recipe'); ?>"><?php esc_html_e('Größe wird geladen ...', 'ahx_wp_recipe'); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php submit_button(__('Rezept mit ausgewählten Bildern anlegen', 'ahx_wp_recipe')); ?>
        </form>
    </div>
    <?php
}

function ahx_wp_recipe_render_import_page() {
    if (!current_user_can('edit_posts')) {
        return;
    }
    $review_token = isset($_GET['review']) ? sanitize_key(wp_unslash($_GET['review'])) : '';
    if ($review_token !== '') {
        $payload = ahx_wp_recipe_get_import_payload($review_token);
        if ($payload) {
            ahx_wp_recipe_render_image_review($review_token, $payload);
            return;
        }
        ?>
        <div class="wrap"><div class="notice notice-error"><p><?php esc_html_e('Die Bildauswahl ist abgelaufen. Bitte importiere das Rezept erneut.', 'ahx_wp_recipe'); ?></p></div></div>
        <?php
    }
    $suggestion_id = isset($_GET['suggestion']) ? absint($_GET['suggestion']) : 0;
    $suggestion_url = '';
    $suggestion_title = '';
    if ($suggestion_id) {
        $suggestion = ahx_wp_recipe_get_url_suggestion($suggestion_id);
        if (is_wp_error($suggestion)) {
            wp_die(esc_html($suggestion->get_error_message()), 403);
        }
        $suggestion_url = get_post_meta($suggestion_id, '_ahx_recipe_source_url', true);
        $default_titles = [__('Chefkoch-Importvorschlag', 'ahx_wp_recipe'), sprintf(__('%s-Importvorschlag', 'ahx_wp_recipe'), ahx_wp_recipe_import_provider($suggestion_url))];
        $suggestion_title = in_array($suggestion->post_title, $default_titles, true) ? '' : $suggestion->post_title;
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Rezept importieren', 'ahx_wp_recipe'); ?></h1>
        <h2><?php esc_html_e('Von Rezept-URL importieren', 'ahx_wp_recipe'); ?></h2>
        <p><?php esc_html_e('Füge eine HTTPS-Rezept-URL von chefkoch.de oder gaumenfreundin.de ein. Erkannte Zutaten und Zubereitung werden als bearbeitbarer Entwurf übernommen.', 'ahx_wp_recipe'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ahx_wp_recipe_import">
            <input type="hidden" name="recipe_suggestion_id" value="<?php echo esc_attr($suggestion_id); ?>">
            <?php wp_nonce_field('ahx_wp_recipe_import'); ?>
            <table class="form-table" role="presentation">
                <tr><th><label for="ahx-recipe-import-url"><?php esc_html_e('Rezept-URL', 'ahx_wp_recipe'); ?></label></th><td><input class="large-text" id="ahx-recipe-import-url" name="recipe_url" type="url" placeholder="https://www.gaumenfreundin.de/rezeptname/" value="<?php echo esc_attr($suggestion_url); ?>" required></td></tr>
                <tr><th><label for="ahx-recipe-url-title"><?php esc_html_e('Rezepttitel (optional)', 'ahx_wp_recipe'); ?></label></th><td><input class="regular-text" id="ahx-recipe-url-title" name="recipe_title" type="text" value="<?php echo esc_attr($suggestion_title); ?>"></td></tr>
            </table>
            <?php submit_button(__('URL importieren', 'ahx_wp_recipe')); ?>
        </form>
        <hr>
        <h2><?php esc_html_e('Datei importieren', 'ahx_wp_recipe'); ?></h2>
        <p><?php esc_html_e('HTML, DOCX, PDF oder TXT hochladen. Der erkannte Text wird als Rezeptentwurf angelegt und kann danach bearbeitet werden. Für PDF wird pdftotext auf dem Server benötigt.', 'ahx_wp_recipe'); ?></p>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ahx_wp_recipe_import">
            <?php wp_nonce_field('ahx_wp_recipe_import'); ?>
            <table class="form-table" role="presentation">
                <tr><th><label for="ahx-recipe-import-title"><?php esc_html_e('Rezepttitel (optional)', 'ahx_wp_recipe'); ?></label></th><td><input class="regular-text" id="ahx-recipe-import-title" name="recipe_title" type="text"></td></tr>
                <tr><th><label for="ahx-recipe-import-file"><?php esc_html_e('Datei', 'ahx_wp_recipe'); ?></label></th><td><input id="ahx-recipe-import-file" name="recipe_file" type="file" accept=".html,.htm,.docx,.pdf,.txt" required><p class="description"><?php esc_html_e('Maximal 15 MB.', 'ahx_wp_recipe'); ?></p></td></tr>
            </table>
            <?php submit_button(__('Als Entwurf importieren', 'ahx_wp_recipe')); ?>
        </form>
    </div>
    <?php
}

function ahx_wp_recipe_extract_file_text($file_path, $extension) {
    if (in_array($extension, ['html', 'htm'], true)) {
        $html = file_get_contents($file_path);
        if ($html === false) {
            return new WP_Error('read_failed', __('Die Datei konnte nicht gelesen werden.', 'ahx_wp_recipe'));
        }
        if (class_exists('DOMDocument')) {
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            foreach (['script', 'style', 'noscript'] as $tag) {
                while (($nodes = $document->getElementsByTagName($tag))->length) {
                    $nodes->item(0)->parentNode->removeChild($nodes->item(0));
                }
            }
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//br | //p | //div | //li | //h1 | //h2 | //h3 | //h4 | //tr') as $node) {
                $node->parentNode->insertBefore($document->createTextNode("\n"), $node->nextSibling);
            }
            $text = $document->textContent;
        } else {
            $text = preg_replace('/<\/(?:p|div|li|h[1-6]|tr)\s*>|<br\s*\/?\s*>/i', "\n", $html);
            $text = wp_strip_all_tags($text);
        }
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    if ($extension === 'docx') {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('zip_unavailable', __('DOCX-Import benötigt die PHP-Erweiterung ZipArchive.', 'ahx_wp_recipe'));
        }
        $zip = new ZipArchive();
        if ($zip->open($file_path) !== true) {
            return new WP_Error('docx_invalid', __('Die DOCX-Datei ist ungültig oder beschädigt.', 'ahx_wp_recipe'));
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            return new WP_Error('docx_content_missing', __('In der DOCX-Datei wurde kein Dokumenttext gefunden.', 'ahx_wp_recipe'));
        }
        $xml = preg_replace('/<\/w:p\s*>/i', "\n", $xml);
        $xml = preg_replace('/<w:tab\b[^>]*\/>/i', "\t", $xml);
        return html_entity_decode(wp_strip_all_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    if ($extension === 'pdf') {
        if (!function_exists('proc_open')) {
            return new WP_Error('pdf_unavailable', __('PDF-Import ist auf diesem Server nicht verfügbar (proc_open fehlt).', 'ahx_wp_recipe'));
        }
        $process = proc_open(['pdftotext', '-layout', $file_path, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return new WP_Error('pdf_unavailable', __('PDF-Import konnte nicht gestartet werden. Installiere pdftotext auf dem Server.', 'ahx_wp_recipe'));
        }
        $text = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || trim((string) $text) === '') {
            return new WP_Error('pdf_extract_failed', $error ? sanitize_text_field($error) : __('Der Text konnte nicht aus der PDF-Datei ausgelesen werden.', 'ahx_wp_recipe'));
        }
        return $text;
    }

    $text = file_get_contents($file_path);
    return $text === false ? new WP_Error('read_failed', __('Die Datei konnte nicht gelesen werden.', 'ahx_wp_recipe')) : $text;
}

function ahx_wp_recipe_parse_import($text) {
    $text = str_replace(["\r\n", "\r"], "\n", wp_check_invalid_utf8((string) $text));
    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static function ($line) {
        return $line !== '';
    }));
    $title = $lines[0] ?? __('Importiertes Rezept', 'ahx_wp_recipe');
    $section = '';
    $ingredients = [];
    $instructions = [];
    $has_sections = false;

    foreach ($lines as $index => $line) {
        $line = preg_replace('/^[\s\x{2022}\-*]+/u', '', $line);
        if (preg_match('/^(zutaten|ingredients)\b\s*:?/iu', $line)) {
            $section = 'ingredients';
            $has_sections = true;
            continue;
        }
        if (preg_match('/^(zubereitung|anleitung|instructions|method|schritte)\b\s*:?/iu', $line)) {
            $section = 'instructions';
            $has_sections = true;
            continue;
        }
        if ($index === 0 && $line === $title) {
            continue;
        }
        if ($section === 'ingredients') {
            if (preg_match('/^((?:\d+\s*\/\s*\d+)|(?:\d+(?:[.,]\d+)?))\s*([\p{L}µ]+)?\s+(.+)$/u', $line, $matches)) {
                $ingredients[] = [
                    'quantity' => trim(str_replace(',', '.', $matches[1])),
                    'unit' => trim($matches[2] ?? ''),
                    'item' => trim($matches[3]),
                ];
            } else {
                $ingredients[] = ['quantity' => '', 'unit' => '', 'item' => $line];
            }
        } elseif ($section === 'instructions') {
            $instructions[] = preg_replace('/^\d+[.)]\s*/', '', $line);
        }
    }

    return [
        'title' => sanitize_text_field($title),
        'ingredients' => $ingredients,
        'instructions' => $instructions,
        'has_sections' => $has_sections,
        'source' => sanitize_textarea_field($text),
    ];
}

function ahx_wp_recipe_import_provider($url) {
    $parts = wp_parse_url($url);
    $providers = ['chefkoch.de' => 'Chefkoch', 'www.chefkoch.de' => 'Chefkoch', 'gaumenfreundin.de' => 'Gaumenfreundin', 'www.gaumenfreundin.de' => 'Gaumenfreundin'];
    if (!is_array($parts)
        || strtolower($parts['scheme'] ?? '') !== 'https'
        || !isset($providers[strtolower($parts['host'] ?? '')])
        || isset($parts['user'])
        || isset($parts['pass'])
        || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
        return '';
    }

    return wp_http_validate_url($url) !== false ? $providers[strtolower($parts['host'])] : '';
}

function ahx_wp_recipe_is_chefkoch_url($url) {
    return ahx_wp_recipe_import_provider($url) === 'Chefkoch';
}

function ahx_wp_recipe_is_import_url($url) {
    $provider = ahx_wp_recipe_import_provider($url);
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    return $provider === 'Chefkoch' ? (bool) preg_match('~^/rezepte/[0-9]+/~', $path)
        : ($provider === 'Gaumenfreundin' && (bool) preg_match('~^/[a-z0-9][a-z0-9-]*/?$~i', $path));
}

function ahx_wp_recipe_fetch_import_html($url) {
    $provider = ahx_wp_recipe_import_provider($url);
    $current_url = esc_url_raw($url);
    for ($redirect_count = 0; $redirect_count <= 3; $redirect_count++) {
        if (!$provider || ahx_wp_recipe_import_provider($current_url) !== $provider) {
            return new WP_Error('invalid_import_url', __('Es sind nur HTTPS-URLs von chefkoch.de und gaumenfreundin.de erlaubt. Weiterleitungen müssen beim selben Anbieter bleiben.', 'ahx_wp_recipe'));
        }

        $response = wp_safe_remote_get($current_url, [
            'timeout' => 20,
            'redirection' => 0,
            'limit_response_size' => 2 * MB_IN_BYTES,
            'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('import_fetch_failed', __('Die Rezeptseite konnte nicht abgerufen werden.', 'ahx_wp_recipe'));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 300 && $status < 400) {
            $location = wp_remote_retrieve_header($response, 'location');
            if (!$location || $redirect_count === 3) {
                break;
            }
            $current_url = WP_Http::make_absolute_url($location, $current_url);
            continue;
        }
        if ($status < 200 || $status >= 300) {
            return new WP_Error('import_http_error', sprintf(__('%1$s hat die Seite mit HTTP-Status %2$d nicht bereitgestellt.', 'ahx_wp_recipe'), $provider, $status));
        }

        return wp_remote_retrieve_body($response);
    }

    return new WP_Error('import_redirect_failed', __('Die Rezeptseite leitete auf eine nicht erlaubte oder zu oft umgeleitete URL weiter.', 'ahx_wp_recipe'));
}

function ahx_wp_recipe_fetch_chefkoch_html($url) {
    if (!ahx_wp_recipe_is_chefkoch_url($url)) {
        return new WP_Error('invalid_chefkoch_url', __('Es sind nur HTTPS-URLs von chefkoch.de erlaubt.', 'ahx_wp_recipe'));
    }
    return ahx_wp_recipe_fetch_import_html($url);
}

function ahx_wp_recipe_find_schema_recipe($value) {
    if (!is_array($value)) {
        return null;
    }
    $types = (array) ($value['@type'] ?? []);
    foreach ($types as $type) {
        if (preg_match('~(?:^|[/#])Recipe$~i', (string) $type)) {
            return $value;
        }
    }
    foreach ($value as $child) {
        if (is_array($child)) {
            $recipe = ahx_wp_recipe_find_schema_recipe($child);
            if ($recipe) {
                return $recipe;
            }
        }
    }
    return null;
}

function ahx_wp_recipe_flatten_schema_instructions($instructions) {
    $result = [];
    foreach ((array) $instructions as $instruction) {
        if (is_string($instruction)) {
            $text = trim(wp_strip_all_tags($instruction));
            if ($text !== '') {
                $result[] = $text;
            }
            continue;
        }
        if (!is_array($instruction)) {
            continue;
        }
        if (!empty($instruction['text'])) {
            $result[] = trim(wp_strip_all_tags((string) $instruction['text']));
        } elseif (!empty($instruction['name']) && empty($instruction['itemListElement'])) {
            $result[] = trim(wp_strip_all_tags((string) $instruction['name']));
        }
        if (!empty($instruction['itemListElement'])) {
            $result = array_merge($result, ahx_wp_recipe_flatten_schema_instructions($instruction['itemListElement']));
        }
    }
    return array_values(array_filter($result));
}

function ahx_wp_recipe_is_allowed_image_url($url) {
    $parts = wp_parse_url($url);
    if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        return false;
    }
    $host = strtolower($parts['host']);
    $allowed_suffixes = ['chefkoch.de', 'chefkoch-cdn.de', 'gaumenfreundin.de'];
    $is_allowed_host = false;
    foreach ($allowed_suffixes as $suffix) {
        if ($host === $suffix || substr($host, -(strlen($suffix) + 1)) === '.' . $suffix) {
            $is_allowed_host = true;
            break;
        }
    }
    if (!$is_allowed_host) {
        return false;
    }
    return wp_http_validate_url($url) !== false;
}

function ahx_wp_recipe_extract_import_images($html, $recipe) {
    $schema_image = $recipe['image'] ?? null;
    $candidates = [];
    if (is_string($schema_image)) {
        $candidates[] = $schema_image;
    } elseif (is_array($schema_image)) {
        if (!empty($schema_image['url'])) {
            $candidates[] = (string) $schema_image['url'];
        } else {
            foreach ($schema_image as $image) {
                if (is_string($image)) {
                    $candidates[] = $image;
                } elseif (is_array($image) && !empty($image['url'])) {
                    $candidates[] = (string) $image['url'];
                }
            }
        }
    }

    if (preg_match_all('~https://img\.chefkoch-cdn\.de/rezepte/[0-9]+/[^\s"\'<>]+\.(?:jpe?g|png|webp)~i', (string) $html, $matches)) {
        $candidates = array_merge($candidates, $matches[0]);
    }

    $unique = [];
    foreach ($candidates as $candidate) {
        $url = esc_url_raw(html_entity_decode((string) $candidate, ENT_QUOTES, 'UTF-8'));
        if ($url === '' || !ahx_wp_recipe_is_allowed_image_url($url)) {
            continue;
        }
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $key = preg_replace('/-(?:crop|resize)[^.\/]*(?=\.[a-z0-9]+$)/i', '', $path);
        if ($key === null || $key === '') {
            $key = $url;
        }
        if (!isset($unique[$key])) {
            $unique[$key] = $url;
        }
        if (count($unique) >= 24) {
            break;
        }
    }

    return array_values($unique);
}

function ahx_wp_recipe_extract_chefkoch_images($html, $recipe) {
    return ahx_wp_recipe_extract_import_images($html, $recipe);
}

function ahx_wp_recipe_extract_gaumenfreundin_ingredients($document, $recipe) {
    $xpath = new DOMXPath($document);
    $fragment = (string) wp_parse_url($recipe['@id'] ?? '', PHP_URL_FRAGMENT);
    $container = strpos($fragment, 'wprm-recipe-container-') === 0 ? $document->getElementById($fragment) : null;
    if (!$container) {
        $container = $xpath->query('//*[starts-with(@id, "wprm-recipe-container-")]')->item(0);
    }
    if (!$container) {
        return [];
    }
    $rows = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " wprm-recipe-ingredient ")]', $container);
    $ingredients = [];
    foreach ($rows as $row) {
        $ingredient = [];
        foreach (['quantity' => 'amount', 'unit' => 'unit', 'label' => 'name', 'addition' => 'notes'] as $field => $suffix) {
            $node = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " wprm-recipe-ingredient-' . $suffix . ' ")]', $row)->item(0);
            $ingredient[$field] = $node ? sanitize_text_field(trim(preg_replace('/[\p{Z}\s]+/u', ' ', $node->textContent))) : '';
        }
        $ingredient['quantity'] = trim(preg_replace('/\s+/', ' ', strtr($ingredient['quantity'], ['½' => ' 1/2', '¼' => ' 1/4', '¾' => ' 3/4', '⅓' => ' 1/3', '⅔' => ' 2/3', '⅛' => ' 1/8', '⅜' => ' 3/8', '⅝' => ' 5/8', '⅞' => ' 7/8'])));
        if ($ingredient['label'] === '') {
            return [];
        }
        $ingredients[] = $ingredient;
    }
    return count($ingredients) === count((array) ($recipe['recipeIngredient'] ?? [])) ? $ingredients : [];
}

function ahx_wp_recipe_parse_import_html($html, $source_url) {
    if (!class_exists('DOMDocument')) {
        return new WP_Error('dom_unavailable', __('Der Rezeptimport benötigt die PHP-Erweiterung DOM.', 'ahx_wp_recipe'));
    }
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return new WP_Error('import_html_invalid', __('Die Rezeptseite enthält kein lesbares HTML.', 'ahx_wp_recipe'));
    }

    $recipe = null;
    foreach ($document->getElementsByTagName('script') as $script) {
        if (strtolower($script->getAttribute('type')) !== 'application/ld+json') {
            continue;
        }
        $data = json_decode($script->textContent, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $recipe = ahx_wp_recipe_find_schema_recipe($data);
            if ($recipe) {
                break;
            }
        }
    }
    if (!$recipe) {
        return new WP_Error('import_recipe_not_found', __('Auf der Seite wurden keine strukturierten Rezeptdaten gefunden. Prüfe die URL oder importiere die Seite als HTML-Datei.', 'ahx_wp_recipe'));
    }

    $ingredients = [];
    foreach ((array) ($recipe['recipeIngredient'] ?? []) as $ingredient_text) {
        $ingredient_text = trim(wp_strip_all_tags((string) $ingredient_text));
        if ($ingredient_text === '') {
            continue;
        }
        if (preg_match('/^((?:\d+\s+\d+\s*\/\s*\d+)|(?:\d+\s*\/\s*\d+)|(?:\d+(?:[.,]\d+)?))\s*([\p{L}µ]+\.?)?\s+(.+)$/u', $ingredient_text, $matches)) {
            $ingredients[] = [
                'quantity' => trim(str_replace(',', '.', $matches[1])),
                'unit' => trim($matches[2] ?? ''),
                'item' => trim($matches[3]),
            ];
        } else {
            $ingredients[] = ['quantity' => '', 'unit' => '', 'item' => $ingredient_text];
        }
    }

    if (ahx_wp_recipe_import_provider($source_url) === 'Gaumenfreundin') {
        $structured_ingredients = ahx_wp_recipe_extract_gaumenfreundin_ingredients($document, $recipe);
        if ($structured_ingredients) {
            $ingredients = $structured_ingredients;
        }
    }
    $yield = is_array($recipe['recipeYield'] ?? null) ? reset($recipe['recipeYield']) : ($recipe['recipeYield'] ?? '');
    $servings = preg_match('/\d+/', (string) $yield, $serving_match) ? max(1, min(999, (int) $serving_match[0])) : 4;
    return [
        'title' => sanitize_text_field($recipe['name'] ?? __('Importiertes Rezept', 'ahx_wp_recipe')),
        'ingredients' => $ingredients,
        'instructions' => ahx_wp_recipe_flatten_schema_instructions($recipe['recipeInstructions'] ?? []),
        'servings' => $servings,
        'source_url' => esc_url_raw($source_url),
        'images' => ahx_wp_recipe_extract_import_images($html, $recipe),
    ];
}

function ahx_wp_recipe_parse_chefkoch_html($html, $source_url) {
    return ahx_wp_recipe_parse_import_html($html, $source_url);
}

function ahx_wp_recipe_create_imported_draft($parsed, $title_override = '', $suggestion_id = 0) {
    if ($suggestion_id) {
        $suggestion = ahx_wp_recipe_get_url_suggestion($suggestion_id);
        if (is_wp_error($suggestion)) {
            return $suggestion;
        }
    }
    $title = sanitize_text_field($title_override) ?: ($parsed['title'] ?? __('Importiertes Rezept', 'ahx_wp_recipe'));
    $content = !empty($parsed['has_sections']) ? '' : sanitize_textarea_field($parsed['source'] ?? '');
    $post_data = [
        'post_type' => 'ahx_recipe',
        'post_status' => 'draft',
        'post_title' => $title,
        'post_content' => $content,
    ];
    if ($suggestion_id) {
        $post_data['ID'] = $suggestion_id;
        $post_id = wp_update_post($post_data, true);
    } else {
        $post_id = wp_insert_post($post_data, true);
    }
    if (is_wp_error($post_id)) {
        return $post_id;
    }
    update_post_meta($post_id, '_ahx_recipe_servings', max(1, (int) ($parsed['servings'] ?? 4)));
    update_post_meta($post_id, '_ahx_recipe_layout', 'classic');
    update_post_meta($post_id, '_ahx_recipe_ingredients', ahx_wp_recipe_normalize_ingredients($parsed['ingredients'] ?? []));
    update_post_meta($post_id, '_ahx_recipe_instructions', (array) ($parsed['instructions'] ?? []));
    if (!empty($parsed['source_url'])) {
        update_post_meta($post_id, '_ahx_recipe_source_url', esc_url_raw($parsed['source_url']));
    }
    if ($suggestion_id) {
        update_post_meta($post_id, '_ahx_recipe_submission_kind', 'imported');
    }
    return $post_id;
}

function ahx_wp_recipe_handle_import() {
    if (!current_user_can('edit_posts')) {
        wp_die(esc_html__('Keine Berechtigung.', 'ahx_wp_recipe'), 403);
    }
    check_admin_referer('ahx_wp_recipe_import');

    $recipe_url = esc_url_raw(wp_unslash($_POST['recipe_url'] ?? ''));
    if ($recipe_url !== '') {
        $suggestion_id = absint($_POST['recipe_suggestion_id'] ?? 0);
        if ($suggestion_id) {
            $suggestion = ahx_wp_recipe_get_url_suggestion($suggestion_id);
            if (is_wp_error($suggestion) || get_post_meta($suggestion_id, '_ahx_recipe_source_url', true) !== $recipe_url) {
                wp_die(esc_html__('Ungültiger Importvorschlag oder abweichende Quelle.', 'ahx_wp_recipe'), 403);
            }
        }
        if (!ahx_wp_recipe_is_import_url($recipe_url)) {
            wp_die(esc_html__('Bitte eine gültige HTTPS-Rezept-URL von chefkoch.de oder gaumenfreundin.de eingeben.', 'ahx_wp_recipe'), 400);
        }
        $html = ahx_wp_recipe_fetch_import_html($recipe_url);
        if (is_wp_error($html)) {
            wp_die(esc_html($html->get_error_message()), 400);
        }
        $parsed = ahx_wp_recipe_parse_import_html($html, $recipe_url);
        if (is_wp_error($parsed)) {
            wp_die(esc_html($parsed->get_error_message()), 400);
        }
        $title_override = sanitize_text_field(wp_unslash($_POST['recipe_title'] ?? ''));
        $images = $parsed['images'] ?? [];
        if ($images) {
            $token = ahx_wp_recipe_store_import_payload([
                'parsed' => $parsed,
                'images' => $images,
                'title_override' => $title_override,
                'suggestion_id' => $suggestion_id,
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'ahx-wp-recipe-import', 'review' => $token], admin_url('edit.php?post_type=ahx_recipe')));
            exit;
        }
        $post_id = ahx_wp_recipe_create_imported_draft($parsed, $title_override, $suggestion_id);
        if (is_wp_error($post_id)) {
            wp_die(esc_html($post_id->get_error_message()), 500);
        }
        wp_safe_redirect(get_edit_post_link($post_id, 'raw'));
        exit;
    }

    $file = $_FILES['recipe_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 15 * MB_IN_BYTES) {
        wp_die(esc_html__('Bitte eine gültige Datei mit höchstens 15 MB hochladen.', 'ahx_wp_recipe'), 400);
    }
    $extension = strtolower(pathinfo(sanitize_file_name($file['name']), PATHINFO_EXTENSION));
    if (!in_array($extension, ['html', 'htm', 'docx', 'pdf', 'txt'], true) || !is_uploaded_file($file['tmp_name'])) {
        wp_die(esc_html__('Dateityp nicht unterstützt.', 'ahx_wp_recipe'), 400);
    }

    $text = ahx_wp_recipe_extract_file_text($file['tmp_name'], $extension);
    if (is_wp_error($text)) {
        wp_die(esc_html($text->get_error_message()), 400);
    }
    $parsed = ahx_wp_recipe_parse_import($text);
    $post_id = ahx_wp_recipe_create_imported_draft($parsed, sanitize_text_field(wp_unslash($_POST['recipe_title'] ?? '')));
    if (is_wp_error($post_id)) {
        wp_die(esc_html($post_id->get_error_message()), 500);
    }
    wp_safe_redirect(get_edit_post_link($post_id, 'raw'));
    exit;
}
add_action('admin_post_ahx_wp_recipe_import', 'ahx_wp_recipe_handle_import');

function ahx_wp_recipe_handle_import_finish() {
    if (!current_user_can('edit_posts')) {
        wp_die(esc_html__('Keine Berechtigung.', 'ahx_wp_recipe'), 403);
    }
    $token = sanitize_key(wp_unslash($_POST['token'] ?? ''));
    check_admin_referer('ahx_wp_recipe_import_finish_' . $token);

    $payload = ahx_wp_recipe_get_import_payload($token);
    if (!$payload) {
        wp_die(esc_html__('Die Bildauswahl ist abgelaufen. Bitte importiere das Rezept erneut.', 'ahx_wp_recipe'), 400);
    }

    $parsed = $payload['parsed'] ?? [];
    $images = $payload['images'] ?? [];
    $selected_indexes = array_map('absint', (array) wp_unslash($_POST['recipe_images'] ?? []));

    $post_id = ahx_wp_recipe_create_imported_draft($parsed, $payload['title_override'] ?? '', absint($payload['suggestion_id'] ?? 0));
    if (is_wp_error($post_id)) {
        ahx_wp_recipe_delete_import_payload($token);
        wp_die(esc_html($post_id->get_error_message()), 500);
    }

    if ($selected_indexes && $images) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_ids = [];
        foreach ($selected_indexes as $index) {
            if (!isset($images[$index]) || !ahx_wp_recipe_is_allowed_image_url($images[$index])) {
                continue;
            }
            $attachment_id = media_sideload_image($images[$index], $post_id, null, 'id');
            if (!is_wp_error($attachment_id)) {
                $attachment_ids[] = (int) $attachment_id;
            }
            if (count($attachment_ids) >= 24) {
                break;
            }
        }
        if ($attachment_ids) {
            set_post_thumbnail($post_id, $attachment_ids[0]);
            $gallery_ids = array_slice($attachment_ids, 1);
            if ($gallery_ids) {
                update_post_meta($post_id, '_ahx_recipe_gallery', $gallery_ids);
            }
        }
    }

    ahx_wp_recipe_delete_import_payload($token);
    wp_safe_redirect(get_edit_post_link($post_id, 'raw'));
    exit;
}
add_action('admin_post_ahx_wp_recipe_import_finish', 'ahx_wp_recipe_handle_import_finish');

function ahx_wp_recipe_parse_quantity($quantity) {
    $quantity = str_replace(',', '.', trim((string) $quantity));
    if (preg_match('/^(\d+)\s+(\d+)\s*\/\s*(\d+)$/', $quantity, $matches) && (float) $matches[3] !== 0.0) {
        return (float) $matches[1] + ((float) $matches[2] / (float) $matches[3]);
    }
    if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $quantity, $matches) && (float) $matches[2] !== 0.0) {
        return (float) $matches[1] / (float) $matches[2];
    }
    return is_numeric($quantity) ? (float) $quantity : null;
}

function ahx_wp_recipe_bring_deeplink($recipe_url, $base_quantity) {
    $base_quantity = max(1, min(999, absint($base_quantity)));
    $url_parts = wp_parse_url($recipe_url);
    if (!is_array($url_parts)
        || strtolower($url_parts['scheme'] ?? '') !== 'https'
        || empty($url_parts['host'])
        || wp_http_validate_url($recipe_url) === false
        || $base_quantity < 1) {
        return '';
    }

    return add_query_arg([
        'url' => esc_url_raw($recipe_url),
        'baseQuantity' => $base_quantity,
        'requestedQuantity' => $base_quantity,
        'source' => 'web',
    ], 'https://api.getbring.com/rest/bringrecipes/deeplink');
}

function ahx_wp_recipe_format_bring_ingredient($quantity, $unit, $item) {
    $item = trim((string) $item);
    $item = preg_replace('/([\p{L}])\((n|en|e)\)/iu', '$1$2', $item);
    $item = preg_replace('/\s*\([^)]*\)/u', '', $item);
    $item = trim(preg_replace('/\s+/u', ' ', $item));

    $quantity = trim((string) $quantity);
    $unit = trim((string) $unit);
    $amount = $quantity;
    if ($unit !== '') {
        $amount .= $unit;
    }

    return trim($amount . ($amount !== '' && $item !== '' ? ' ' : '') . $item);
}

function ahx_wp_recipe_render_image_preview($attachment_id) {
    $url = wp_get_attachment_image_url($attachment_id, 'full');
    if (!$url) {
        return '';
    }
    return '<a class="ahx-recipe__image-link" href="' . esc_url($url) . '" data-ahx-recipe-image itemprop="image" aria-label="' . esc_attr__('Rezeptbild vergrößern', 'ahx_wp_recipe') . '" title="' . esc_attr__('Rezeptbild vergrößern', 'ahx_wp_recipe') . '">'
        . wp_get_attachment_image($attachment_id, 'medium', false, ['loading' => 'lazy', 'class' => 'ahx-recipe__gallery-image'])
        . '</a>';
}

function ahx_wp_recipe_render_recipe($post_id, $content = '') {
    $ingredients = ahx_wp_recipe_get_ingredients($post_id);
    $instructions = get_post_meta($post_id, '_ahx_recipe_instructions', true);
    $instructions = is_array($instructions) ? $instructions : [];
    $servings = max(1, (int) get_post_meta($post_id, '_ahx_recipe_servings', true));
    $recipe_url = get_post_status($post_id) === 'publish' ? get_permalink($post_id) : '';
    $bring_deeplink = ahx_wp_recipe_bring_deeplink($recipe_url, $servings);
    $layout = get_post_meta($post_id, '_ahx_recipe_layout', true) ?: 'classic';
    if (!in_array($layout, ['classic', 'split', 'checklist'], true)) {
        $layout = 'classic';
    }
    $source_url = get_post_meta($post_id, '_ahx_recipe_source_url', true);
    $gallery_ids = get_post_meta($post_id, '_ahx_recipe_gallery', true);
    $gallery_ids = is_array($gallery_ids) ? array_filter(array_map('absint', $gallery_ids)) : [];
    $image_ids = array_unique(array_filter(array_merge([get_post_thumbnail_id($post_id)], $gallery_ids)));

    ob_start();
    ?>
    <article class="ahx-recipe ahx-recipe--<?php echo esc_attr($layout); ?>" data-ahx-recipe itemscope itemtype="https://schema.org/Recipe">
        <meta itemprop="name" content="<?php echo esc_attr(get_the_title($post_id)); ?>">
        <meta itemprop="recipeYield" content="<?php echo esc_attr($servings); ?>">
        <?php if ($image_ids) : ?>
            <div class="ahx-recipe__gallery">
                <?php foreach ($image_ids as $attachment_id) : ?>
                    <?php echo ahx_wp_recipe_render_image_preview($attachment_id); ?>
                <?php endforeach; ?>
            </div>
            <dialog class="ahx-recipe-image-dialog" data-ahx-image-dialog aria-label="<?php esc_attr_e('Rezeptbild', 'ahx_wp_recipe'); ?>">
                <button type="button" class="ahx-recipe-image-dialog__close" data-ahx-image-close aria-label="<?php esc_attr_e('Bildansicht schließen', 'ahx_wp_recipe'); ?>" title="<?php esc_attr_e('Bildansicht schließen', 'ahx_wp_recipe'); ?>">&times;</button>
                <img class="ahx-recipe-image-dialog__image" data-ahx-image-full alt="">
                <p data-ahx-image-error role="status" hidden><?php esc_html_e('Bild konnte nicht geladen werden.', 'ahx_wp_recipe'); ?></p>
            </dialog>
        <?php endif; ?>
        <?php if ($source_url) : ?><p class="ahx-recipe__source"><?php esc_html_e('Quelle:', 'ahx_wp_recipe'); ?> <a href="<?php echo esc_url($source_url); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html(wp_parse_url($source_url, PHP_URL_HOST)); ?></a></p><?php endif; ?>
        <?php if (trim($content) !== '') : ?><div class="ahx-recipe__intro"><?php echo wp_kses_post(wpautop($content)); ?></div><?php endif; ?>
        <div class="ahx-recipe__servings">
            <label><?php esc_html_e('Portionen', 'ahx_wp_recipe'); ?> <input type="number" min="1" max="999" value="<?php echo esc_attr($servings); ?>" data-ahx-servings></label>
            <label class="ahx-recipe__layout-control"><span><?php esc_html_e('Darstellung', 'ahx_wp_recipe'); ?></span>
                <select data-ahx-layout>
                    <option value="classic" <?php selected($layout, 'classic'); ?>><?php esc_html_e('Klassisch', 'ahx_wp_recipe'); ?></option>
                    <option value="split" <?php selected($layout, 'split'); ?>><?php esc_html_e('Zweiteilig', 'ahx_wp_recipe'); ?></option>
                    <option value="checklist" <?php selected($layout, 'checklist'); ?>><?php esc_html_e('Kochmodus', 'ahx_wp_recipe'); ?></option>
                </select>
            </label>
        </div>
        <div class="ahx-recipe__body">
            <section class="ahx-recipe__ingredients">
                <h2><?php esc_html_e('Zutaten', 'ahx_wp_recipe'); ?></h2>
                <?php if ($ingredients) : ?><ul><?php foreach ($ingredients as $ingredient) :
                    $amount = ahx_wp_recipe_parse_quantity($ingredient['quantity'] ?? '');
                    $shown_amount = $amount === null ? (string) ($ingredient['quantity'] ?? '') : rtrim(rtrim(number_format($amount, 3, '.', ''), '0'), '.');
                    $label = $ingredient['label'] ?? '';
                    $addition = trim((string) ($ingredient['addition'] ?? ''));
                    $copy_class = 'ahx-recipe__ingredient-copy' . ($addition !== '' ? ' has-addition' : '');
                    $bring_ingredient = ahx_wp_recipe_format_bring_ingredient($shown_amount, $ingredient['unit'] ?? '', $label);
                    ?><li class="ahx-recipe__ingredient"><meta itemprop="recipeIngredient" content="<?php echo esc_attr($bring_ingredient); ?>"><span class="ahx-recipe__ingredient-amount"><span data-ahx-amount data-base="<?php echo esc_attr($amount === null ? '' : $amount); ?>"><?php echo esc_html($shown_amount); ?></span><?php if (!empty($ingredient['unit'])) : ?> <span data-ahx-unit><?php echo esc_html($ingredient['unit']); ?></span><?php endif; ?></span><span class="<?php echo esc_attr($copy_class); ?>"><span data-ahx-item><?php echo esc_html($label); ?></span><?php if ($addition !== '') : ?><span class="ahx-recipe__ingredient-addition" data-ahx-addition><?php echo esc_html($addition); ?></span><?php endif; ?></span></li>
                <?php endforeach; ?></ul><?php else : ?><p><?php esc_html_e('Noch keine Zutaten eingetragen.', 'ahx_wp_recipe'); ?></p><?php endif; ?>
                <?php if ($ingredients) : ?>
                    <div class="ahx-recipe__bring-form">
                        <?php if ($bring_deeplink !== '') : ?>
                            <a class="ahx-recipe__bring" href="<?php echo esc_url($bring_deeplink); ?>" target="_blank" rel="noopener noreferrer" data-ahx-bring-open data-ahx-bring-mode="recipe" data-ahx-bring-base-quantity="<?php echo esc_attr($servings); ?>"><?php esc_html_e('Mit Bring! importieren', 'ahx_wp_recipe'); ?></a>
                        <?php else : ?>
                            <a class="ahx-recipe__bring" href="https://web.getbring.com/" target="_blank" rel="noopener noreferrer" data-ahx-bring-open data-ahx-bring-mode="clipboard"><?php esc_html_e('Zutaten kopieren & Bring! öffnen', 'ahx_wp_recipe'); ?></a>
                            <p class="ahx-recipe__bring-status" data-ahx-bring-status aria-live="polite"></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
            <section class="ahx-recipe__instructions">
                <h2><?php esc_html_e('Zubereitung', 'ahx_wp_recipe'); ?></h2>
                <?php if ($instructions) : ?><ol><?php foreach ($instructions as $instruction) : ?><li itemprop="recipeInstructions" itemscope itemtype="https://schema.org/HowToStep"><label class="ahx-recipe__step-label"><input type="checkbox" data-ahx-step-check><span itemprop="text"><?php echo esc_html($instruction); ?></span></label></li><?php endforeach; ?></ol><?php else : ?><p><?php esc_html_e('Noch keine Zubereitungsschritte eingetragen.', 'ahx_wp_recipe'); ?></p><?php endif; ?>
            </section>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

function ahx_wp_recipe_filter_content($content) {
    if (is_singular('ahx_recipe') && in_the_loop() && is_main_query()) {
        return ahx_wp_recipe_render_recipe(get_the_ID(), $content);
    }
    return $content;
}
add_filter('the_content', 'ahx_wp_recipe_filter_content', 20);

function ahx_wp_recipe_render_pantry_form($recipe_data) {
    if (!$recipe_data) {
        return;
    }
    $list_id = wp_unique_id('ahx-recipe-pantry-');
    $synonym_groups = ahx_wp_recipe_get_synonym_groups();
    $synonyms = ahx_wp_recipe_synonym_map($synonym_groups);
    $used_groups = [];
    $labels = [];
    $units = ['g', 'kg', 'ml', 'l', 'Stück', 'EL', 'TL'];
    foreach ($recipe_data as $recipe) {
        foreach ($recipe['ingredients'] as $ingredient) {
            if ($ingredient['label'] !== '') {
                $labels[] = $ingredient['label'];
                $normalized = ahx_wp_recipe_normalize_synonym_name($ingredient['label']);
                if (isset($synonyms[$normalized])) {
                    $used_groups[$synonyms[$normalized]] = true;
                }
            }
            if ($ingredient['unit'] !== '') {
                $units[] = $ingredient['unit'];
            }
        }
    }
    foreach ($synonym_groups as $group) {
        if (isset($used_groups[ahx_wp_recipe_normalize_synonym_name($group['name'])])) {
            $labels = array_merge($labels, [$group['name']], $group['aliases']);
        }
    }
    $labels = array_unique($labels);
    $units = array_unique($units);
    natcasesort($labels);
    natcasesort($units);
    ?>
    <form class="ahx-recipe-pantry" data-ahx-pantry hidden>
        <h2><?php esc_html_e('Was ist vorhanden?', 'ahx_wp_recipe'); ?></h2>
        <fieldset>
            <legend><?php esc_html_e('Vorhandene Zutaten', 'ahx_wp_recipe'); ?></legend>
            <div class="ahx-recipe-pantry__rows" data-ahx-pantry-rows></div>
        </fieldset>
        <template data-ahx-pantry-row>
            <div class="ahx-recipe-pantry__row">
                <label><?php esc_html_e('Zutat', 'ahx_wp_recipe'); ?><input type="text" maxlength="120" list="<?php echo esc_attr($list_id); ?>" data-ahx-pantry-name></label>
                <label><?php esc_html_e('Menge (optional)', 'ahx_wp_recipe'); ?><input type="number" min="0" max="1000000" step="any" inputmode="decimal" data-ahx-pantry-quantity></label>
                <label><?php esc_html_e('Einheit', 'ahx_wp_recipe'); ?><select data-ahx-pantry-unit>
                    <option value=""><?php esc_html_e('Ohne Einheit', 'ahx_wp_recipe'); ?></option>
                    <?php foreach ($units as $unit) : ?><option value="<?php echo esc_attr($unit); ?>"><?php echo esc_html($unit); ?></option><?php endforeach; ?>
                </select></label>
                <button type="button" class="ahx-recipe-pantry__remove" data-ahx-pantry-remove aria-label="<?php esc_attr_e('Zutat entfernen', 'ahx_wp_recipe'); ?>" title="<?php esc_attr_e('Zutat entfernen', 'ahx_wp_recipe'); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
            </div>
        </template>
        <datalist id="<?php echo esc_attr($list_id); ?>">
            <?php foreach ($labels as $label) : ?><option value="<?php echo esc_attr($label); ?>"></option><?php endforeach; ?>
        </datalist>
        <div class="ahx-recipe-pantry__controls">
            <button type="button" data-ahx-pantry-add><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e('Zutat hinzufügen', 'ahx_wp_recipe'); ?></button>
            <label><?php esc_html_e('Portionen', 'ahx_wp_recipe'); ?><input type="number" min="1" max="999" step="1" value="2" data-ahx-pantry-servings></label>
            <label class="ahx-recipe-pantry__complete"><input type="checkbox" data-ahx-pantry-complete><?php esc_html_e('Nur mit allen Zutaten', 'ahx_wp_recipe'); ?></label>
        </div>
        <div class="ahx-recipe-pantry__actions">
            <button type="submit"><span class="dashicons dashicons-search" aria-hidden="true"></span><?php esc_html_e('Rezepte vorschlagen', 'ahx_wp_recipe'); ?></button>
            <button type="reset"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span><?php esc_html_e('Zurücksetzen', 'ahx_wp_recipe'); ?></button>
        </div>
        <p class="ahx-recipe-pantry__status" data-ahx-pantry-status role="status" aria-live="polite" hidden></p>
        <script type="application/json" data-ahx-pantry-data><?php echo wp_json_encode($recipe_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
        <script type="application/json" data-ahx-pantry-synonyms><?php echo wp_json_encode((object) $synonyms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    </form>
    <p data-ahx-pantry-empty hidden><?php esc_html_e('Keine passenden Rezepte gefunden.', 'ahx_wp_recipe'); ?></p>
    <?php
}

function ahx_wp_recipe_render_collection($recipes) {
    $groups = [];
    $untyped = [];
    $recipe_types = [];
    $recipe_data = [];
    foreach ($recipes as $recipe) {
        $ingredients = ahx_wp_recipe_get_ingredients($recipe->ID);
        $recipe_data[$recipe->ID] = [
            'servings' => max(1, (int) get_post_meta($recipe->ID, '_ahx_recipe_servings', true)),
            'ingredients' => array_map(static function ($ingredient) {
                $quantity = ahx_wp_recipe_parse_quantity($ingredient['quantity']);
                return [
                    'label' => $ingredient['label'],
                    'quantity' => $quantity !== null && is_finite($quantity) && $quantity >= 0 ? $quantity : null,
                    'unit' => $ingredient['unit'],
                ];
            }, $ingredients),
        ];
        $types = get_the_terms($recipe->ID, 'ahx_recipe_type');
        $types = is_array($types) ? $types : [];
        $recipe_types[$recipe->ID] = $types;
        if (!$types) {
            $untyped[] = $recipe;
        }
        foreach ($types as $type) {
            if (!isset($groups[$type->term_id])) {
                $groups[$type->term_id] = ['name' => $type->name, 'recipes' => []];
            }
            $groups[$type->term_id]['recipes'][] = $recipe;
        }
    }
    uasort($groups, static function ($first, $second) {
        return strnatcasecmp($first['name'], $second['name']);
    });
    if ($untyped) {
        $groups['untyped'] = ['name' => __('Ohne Rezepttyp', 'ahx_wp_recipe'), 'recipes' => $untyped];
    }
    ob_start();
    ?>
    <div class="ahx-recipe-collection">
        <?php ahx_wp_recipe_render_pantry_form($recipe_data); ?>
        <?php if (!$groups) : ?>
            <p><?php esc_html_e('Noch keine Rezepte veröffentlicht.', 'ahx_wp_recipe'); ?></p>
        <?php endif; ?>
        <?php foreach ($groups as $group) : ?>
            <section class="ahx-recipe-collection__group">
                <h2 class="ahx-recipe-collection__heading"><?php echo esc_html($group['name']); ?> <span class="ahx-recipe-collection__count">(<?php echo esc_html(count($group['recipes'])); ?>)</span></h2>
                <div class="ahx-recipe-list">
                    <?php foreach ($group['recipes'] as $recipe) : ?>
                        <article class="ahx-recipe-list__item" data-ahx-pantry-recipe="<?php echo esc_attr($recipe->ID); ?>">
                            <a class="ahx-recipe-list__link" href="<?php echo esc_url(get_permalink($recipe)); ?>">
                                <?php if (has_post_thumbnail($recipe->ID)) : ?>
                                    <?php echo get_the_post_thumbnail($recipe->ID, 'large', ['loading' => 'lazy', 'class' => 'ahx-recipe-list__image', 'alt' => '']); ?>
                                <?php else : ?>
                                    <span class="ahx-recipe-list__placeholder"><?php esc_html_e('Kein Rezeptbild', 'ahx_wp_recipe'); ?></span>
                                <?php endif; ?>
                                <div class="ahx-recipe-list__copy">
                                    <h3><?php echo esc_html(get_the_title($recipe)); ?></h3>
                                    <?php if ($recipe_types[$recipe->ID]) : ?>
                                        <p class="ahx-recipe-list__types"><?php echo esc_html(implode(' / ', wp_list_pluck($recipe_types[$recipe->ID], 'name'))); ?></p>
                                    <?php endif; ?>
                                    <?php if (has_excerpt($recipe->ID)) : ?>
                                        <p class="ahx-recipe-list__excerpt"><?php echo esc_html(get_the_excerpt($recipe)); ?></p>
                                    <?php endif; ?>
                                    <div class="ahx-recipe-list__match" data-ahx-pantry-match hidden></div>
                                </div>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

function ahx_wp_recipe_archive_query($query) {
    if (!is_admin() && $query->is_main_query() && $query->is_post_type_archive('ahx_recipe')) {
        $query->set('posts_per_page', -1);
        $query->set('orderby', 'title');
        $query->set('order', 'ASC');
    }
}
add_action('pre_get_posts', 'ahx_wp_recipe_archive_query');

function ahx_wp_recipe_archive_template($template) {
    if (is_post_type_archive('ahx_recipe')) {
        return AHX_WP_RECIPE_PATH . 'templates/archive-recipe.php';
    }
    return $template;
}
add_filter('template_include', 'ahx_wp_recipe_archive_template');

function ahx_wp_recipe_list_shortcode($attributes) {
    $attributes = shortcode_atts(['number' => 12], $attributes, 'ahx_wp_recipes');
    $query = new WP_Query([
        'post_type' => 'ahx_recipe',
        'post_status' => 'publish',
        'posts_per_page' => min(100, max(1, absint($attributes['number']))),
    ]);
    return ahx_wp_recipe_render_collection($query->posts);
}
add_shortcode('ahx_wp_recipes', 'ahx_wp_recipe_list_shortcode');

function ahx_wp_recipe_frontend_assets() {
    if (is_singular('ahx_recipe') || is_post_type_archive('ahx_recipe') || is_singular()) {
        wp_enqueue_style('dashicons');
        wp_enqueue_style('ahx-wp-recipe', AHX_WP_RECIPE_URL . 'assets/frontend.css', [], (string) filemtime(AHX_WP_RECIPE_PATH . 'assets/frontend.css'));
        wp_enqueue_script('ahx-wp-recipe', AHX_WP_RECIPE_URL . 'assets/frontend.js', [], (string) filemtime(AHX_WP_RECIPE_PATH . 'assets/frontend.js'), true);
    }
}
add_action('wp_enqueue_scripts', 'ahx_wp_recipe_frontend_assets');

require_once AHX_WP_RECIPE_PATH . 'includes/frontend-submissions.php';
require_once AHX_WP_RECIPE_PATH . 'admin/dashboard-page.php';

