<?php
/**
 * Plugin Name: AHX WP Recipe
 * Description: Rezepte verwalten, skalieren, anzeigen und für Bring! vorbereiten.
 * Version: v1.1.0
 * Author: Alexander Herbst
 * Text Domain: ahx_wp_recipe
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AHX_WP_RECIPE_VERSION', 'v1.1.0');
define('AHX_WP_RECIPE_PATH', plugin_dir_path(__FILE__));
define('AHX_WP_RECIPE_URL', plugin_dir_url(__FILE__));

function ahx_wp_recipe_register_post_type() {
    register_post_type('ahx_recipe', [
        'labels' => [
            'name' => __('Rezepte', 'ahx_wp_recipe'),
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
    add_submenu_page(
        'edit.php?post_type=ahx_recipe',
        __('Rezepte importieren', 'ahx_wp_recipe'),
        __('Importieren', 'ahx_wp_recipe'),
        'edit_posts',
        'ahx-wp-recipe-import',
        'ahx_wp_recipe_render_import_page'
    );
}
add_action('admin_menu', 'ahx_wp_recipe_admin_menu');

function ahx_wp_recipe_admin_assets($hook) {
    $screen = get_current_screen();
    if (in_array($hook, ['post.php', 'post-new.php'], true) && $screen && $screen->post_type === 'ahx_recipe') {
        wp_enqueue_script('ahx-wp-recipe-admin', AHX_WP_RECIPE_URL . 'assets/admin.js', [], AHX_WP_RECIPE_VERSION, true);
        wp_enqueue_style('ahx-wp-recipe-admin', AHX_WP_RECIPE_URL . 'assets/admin.css', [], AHX_WP_RECIPE_VERSION);
    }
}
add_action('admin_enqueue_scripts', 'ahx_wp_recipe_admin_assets');

function ahx_wp_recipe_render_import_page() {
    if (!current_user_can('edit_posts')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Rezept importieren', 'ahx_wp_recipe'); ?></h1>
        <h2><?php esc_html_e('Von Chefkoch-URL importieren', 'ahx_wp_recipe'); ?></h2>
        <p><?php esc_html_e('Füge die URL eines Chefkoch-Rezepts ein. Erkannte Zutaten und Zubereitung werden als bearbeitbarer Entwurf übernommen.', 'ahx_wp_recipe'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ahx_wp_recipe_import">
            <?php wp_nonce_field('ahx_wp_recipe_import'); ?>
            <table class="form-table" role="presentation">
                <tr><th><label for="ahx-recipe-import-url"><?php esc_html_e('Chefkoch-URL', 'ahx_wp_recipe'); ?></label></th><td><input class="large-text" id="ahx-recipe-import-url" name="recipe_url" type="url" placeholder="https://www.chefkoch.de/rezepte/..." required></td></tr>
                <tr><th><label for="ahx-recipe-url-title"><?php esc_html_e('Rezepttitel (optional)', 'ahx_wp_recipe'); ?></label></th><td><input class="regular-text" id="ahx-recipe-url-title" name="recipe_title" type="text"></td></tr>
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

function ahx_wp_recipe_is_chefkoch_url($url) {
    $parts = wp_parse_url($url);
    if (!is_array($parts)
        || strtolower($parts['scheme'] ?? '') !== 'https'
        || !in_array(strtolower($parts['host'] ?? ''), ['chefkoch.de', 'www.chefkoch.de'], true)
        || isset($parts['user'])
        || isset($parts['pass'])
        || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
        return false;
    }

    return wp_http_validate_url($url) !== false;
}

function ahx_wp_recipe_fetch_chefkoch_html($url) {
    $current_url = esc_url_raw($url);
    for ($redirect_count = 0; $redirect_count <= 3; $redirect_count++) {
        if (!ahx_wp_recipe_is_chefkoch_url($current_url)) {
            return new WP_Error('invalid_chefkoch_url', __('Es sind nur HTTPS-URLs von chefkoch.de erlaubt.', 'ahx_wp_recipe'));
        }

        $response = wp_safe_remote_get($current_url, [
            'timeout' => 20,
            'redirection' => 0,
            'limit_response_size' => 2 * MB_IN_BYTES,
            'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('chefkoch_fetch_failed', __('Die Chefkoch-Seite konnte nicht abgerufen werden.', 'ahx_wp_recipe'));
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
            return new WP_Error('chefkoch_http_error', sprintf(__('Chefkoch hat die Seite mit HTTP-Status %d nicht bereitgestellt.', 'ahx_wp_recipe'), $status));
        }

        return wp_remote_retrieve_body($response);
    }

    return new WP_Error('chefkoch_redirect_failed', __('Die Chefkoch-Seite leitete auf eine nicht erlaubte oder zu oft umgeleitete URL weiter.', 'ahx_wp_recipe'));
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

function ahx_wp_recipe_parse_chefkoch_html($html, $source_url) {
    if (!class_exists('DOMDocument')) {
        return new WP_Error('dom_unavailable', __('Der Chefkoch-Import benötigt die PHP-Erweiterung DOM.', 'ahx_wp_recipe'));
    }
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return new WP_Error('chefkoch_html_invalid', __('Die Chefkoch-Seite enthält kein lesbares HTML.', 'ahx_wp_recipe'));
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
        return new WP_Error('chefkoch_recipe_not_found', __('Auf der Seite wurden keine strukturierten Rezeptdaten gefunden. Prüfe die URL oder importiere die Seite als HTML-Datei.', 'ahx_wp_recipe'));
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

    $yield = is_array($recipe['recipeYield'] ?? null) ? reset($recipe['recipeYield']) : ($recipe['recipeYield'] ?? '');
    $servings = preg_match('/\d+/', (string) $yield, $serving_match) ? max(1, min(999, (int) $serving_match[0])) : 4;
    return [
        'title' => sanitize_text_field($recipe['name'] ?? __('Importiertes Chefkoch-Rezept', 'ahx_wp_recipe')),
        'ingredients' => $ingredients,
        'instructions' => ahx_wp_recipe_flatten_schema_instructions($recipe['recipeInstructions'] ?? []),
        'servings' => $servings,
        'source_url' => esc_url_raw($source_url),
    ];
}

function ahx_wp_recipe_create_imported_draft($parsed, $title_override = '') {
    $title = sanitize_text_field($title_override) ?: ($parsed['title'] ?? __('Importiertes Rezept', 'ahx_wp_recipe'));
    $content = !empty($parsed['has_sections']) ? '' : sanitize_textarea_field($parsed['source'] ?? '');
    $post_id = wp_insert_post([
        'post_type' => 'ahx_recipe',
        'post_status' => 'draft',
        'post_title' => $title,
        'post_content' => $content,
    ], true);
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
    return $post_id;
}

function ahx_wp_recipe_handle_import() {
    if (!current_user_can('edit_posts')) {
        wp_die(esc_html__('Keine Berechtigung.', 'ahx_wp_recipe'), 403);
    }
    check_admin_referer('ahx_wp_recipe_import');

    $recipe_url = esc_url_raw(wp_unslash($_POST['recipe_url'] ?? ''));
    if ($recipe_url !== '') {
        if (!ahx_wp_recipe_is_chefkoch_url($recipe_url)) {
            wp_die(esc_html__('Bitte eine HTTPS-URL von chefkoch.de eingeben.', 'ahx_wp_recipe'), 400);
        }
        $html = ahx_wp_recipe_fetch_chefkoch_html($recipe_url);
        if (is_wp_error($html)) {
            wp_die(esc_html($html->get_error_message()), 400);
        }
        $parsed = ahx_wp_recipe_parse_chefkoch_html($html, $recipe_url);
        if (is_wp_error($parsed)) {
            wp_die(esc_html($parsed->get_error_message()), 400);
        }
        $post_id = ahx_wp_recipe_create_imported_draft($parsed, sanitize_text_field(wp_unslash($_POST['recipe_title'] ?? '')));
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

    ob_start();
    ?>
    <article class="ahx-recipe ahx-recipe--<?php echo esc_attr($layout); ?>" data-ahx-recipe itemscope itemtype="https://schema.org/Recipe">
        <meta itemprop="name" content="<?php echo esc_attr(get_the_title($post_id)); ?>">
        <meta itemprop="recipeYield" content="<?php echo esc_attr($servings); ?>">
        <?php if (has_post_thumbnail($post_id)) : ?><div class="ahx-recipe__image"><?php echo get_the_post_thumbnail($post_id, 'large', ['loading' => 'lazy', 'itemprop' => 'image']); ?></div><?php endif; ?>
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
                    ?><li class="ahx-recipe__ingredient"><meta itemprop="recipeIngredient" content="<?php echo esc_attr($bring_ingredient); ?>"><input type="checkbox" data-ahx-check><span class="ahx-recipe__ingredient-amount"><span data-ahx-amount data-base="<?php echo esc_attr($amount === null ? '' : $amount); ?>"><?php echo esc_html($shown_amount); ?></span><?php if (!empty($ingredient['unit'])) : ?> <span data-ahx-unit><?php echo esc_html($ingredient['unit']); ?></span><?php endif; ?></span><span class="<?php echo esc_attr($copy_class); ?>"><span data-ahx-item><?php echo esc_html($label); ?></span><?php if ($addition !== '') : ?><span class="ahx-recipe__ingredient-addition" data-ahx-addition><?php echo esc_html($addition); ?></span><?php endif; ?></span></li>
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

function ahx_wp_recipe_list_shortcode($attributes) {
    $attributes = shortcode_atts(['number' => 12], $attributes, 'ahx_wp_recipes');
    $query = new WP_Query([
        'post_type' => 'ahx_recipe',
        'post_status' => 'publish',
        'posts_per_page' => min(100, max(1, absint($attributes['number']))),
    ]);
    ob_start();
    echo '<div class="ahx-recipe-list">';
    while ($query->have_posts()) {
        $query->the_post();
        echo '<article class="ahx-recipe-list__item"><a href="' . esc_url(get_permalink()) . '">';
        if (has_post_thumbnail()) {
            echo get_the_post_thumbnail(get_the_ID(), 'medium', ['loading' => 'lazy']);
        }
        echo '<h2>' . esc_html(get_the_title()) . '</h2></a>';
        if (has_excerpt()) {
            echo '<p>' . esc_html(get_the_excerpt()) . '</p>';
        }
        echo '</article>';
    }
    echo '</div>';
    wp_reset_postdata();
    return ob_get_clean();
}
add_shortcode('ahx_wp_recipes', 'ahx_wp_recipe_list_shortcode');

function ahx_wp_recipe_frontend_assets() {
    if (is_singular('ahx_recipe') || is_post_type_archive('ahx_recipe') || is_singular()) {
        wp_enqueue_style('ahx-wp-recipe', AHX_WP_RECIPE_URL . 'assets/frontend.css', [], AHX_WP_RECIPE_VERSION);
        wp_enqueue_script('ahx-wp-recipe', AHX_WP_RECIPE_URL . 'assets/frontend.js', [], AHX_WP_RECIPE_VERSION, true);
    }
}
add_action('wp_enqueue_scripts', 'ahx_wp_recipe_frontend_assets');

