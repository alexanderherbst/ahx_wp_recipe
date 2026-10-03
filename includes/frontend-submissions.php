<?php
if (!defined('ABSPATH')) {
    exit;
}

function ahx_wp_recipe_validate_submission($input) {
    if (!is_array($input)) {
        return new WP_Error('invalid_submission', __('Ungültige Einreichung.', 'ahx_wp_recipe'));
    }
    foreach (['kind', 'title', 'description', 'instructions', 'url', 'website', 'token'] as $field) {
        if (isset($input[$field]) && !is_string($input[$field])) {
            return new WP_Error('invalid_submission', __('Ungültige Eingabefelder.', 'ahx_wp_recipe'));
        }
    }
    if (!empty($input['website']) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $input['token'] ?? '')) {
        return new WP_Error('invalid_submission', __('Die Einreichung konnte nicht geprüft werden.', 'ahx_wp_recipe'));
    }
    $kind = $input['kind'] ?? '';
    $title = trim(sanitize_text_field($input['title'] ?? ''));
    if (!preg_match('/^.{0,200}$/us', $title)) {
        return new WP_Error('invalid_title', __('Der Titel darf maximal 200 Zeichen enthalten.', 'ahx_wp_recipe'));
    }
    if ($kind === 'url') {
        $url = esc_url_raw($input['url'] ?? '');
        if (strlen($url) > 2000 || !ahx_wp_recipe_is_import_url($url)) {
            return new WP_Error('invalid_url', __('Bitte eine gültige HTTPS-Rezept-URL von chefkoch.de oder gaumenfreundin.de eingeben.', 'ahx_wp_recipe'));
        }
        return ['kind' => 'url', 'title' => $title ?: sprintf(__('%s-Importvorschlag', 'ahx_wp_recipe'), ahx_wp_recipe_import_provider($url)), 'url' => $url];
    }
    if ($kind !== 'recipe' || $title === '') {
        return new WP_Error('invalid_title', __('Bitte einen Rezepttitel eingeben.', 'ahx_wp_recipe'));
    }
    $servings = filter_var($input['servings'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
    $ingredients = $input['ingredients'] ?? null;
    if ($servings === false || !is_array($ingredients) || !$ingredients || count($ingredients) > 100) {
        return new WP_Error('invalid_ingredients', __('Bitte Portionen und mindestens eine Zutat angeben. Maximal 100 Zutaten sind möglich.', 'ahx_wp_recipe'));
    }
    $clean_ingredients = [];
    foreach ($ingredients as $ingredient) {
        if (!is_array($ingredient)) {
            return new WP_Error('invalid_ingredients', __('Ungültige Zutatenangabe.', 'ahx_wp_recipe'));
        }
        $clean = [];
        foreach (['quantity', 'unit', 'label', 'addition'] as $field) {
            $value = $ingredient[$field] ?? '';
            if (!is_string($value) || !preg_match('/^.{0,200}$/us', $value)) {
                return new WP_Error('invalid_ingredients', __('Zutatenfelder dürfen maximal 200 Zeichen enthalten.', 'ahx_wp_recipe'));
            }
            $clean[$field] = trim(sanitize_text_field($value));
        }
        if ($clean['label'] === '' && implode('', $clean) === '') {
            continue;
        }
        if ($clean['label'] === '' || ($clean['quantity'] !== '' && (!preg_match('/^[0-9.,\/\s]+$/', $clean['quantity']) || ahx_wp_recipe_parse_quantity($clean['quantity']) === null))) {
            return new WP_Error('invalid_ingredients', __('Jede Zutat benötigt eine Bezeichnung und eine gültige Mengenangabe oder eine leere Menge.', 'ahx_wp_recipe'));
        }
        $clean_ingredients[] = $clean;
    }
    $instructions = trim(sanitize_textarea_field($input['instructions'] ?? ''));
    $description = trim(sanitize_textarea_field($input['description'] ?? ''));
    if (!$clean_ingredients || $instructions === '' || strlen($instructions) > 30000 || strlen($description) > 10000) {
        return new WP_Error('invalid_instructions', __('Bitte Zutaten und Zubereitung angeben und die Textlängen begrenzen.', 'ahx_wp_recipe'));
    }
    $types = $input['types'] ?? [];
    if (!is_array($types) || count($types) > 30) {
        return new WP_Error('invalid_types', __('Ungültige Rezepttypen.', 'ahx_wp_recipe'));
    }
    $type_ids = [];
    foreach ($types as $type) {
        if (!is_scalar($type) || !ctype_digit((string) $type) || !term_exists((int) $type, 'ahx_recipe_type')) {
            return new WP_Error('invalid_types', __('Ein Rezepttyp ist nicht mehr verfügbar.', 'ahx_wp_recipe'));
        }
        $type_ids[] = (int) $type;
    }
    return [
        'kind' => 'recipe', 'title' => $title, 'description' => $description,
        'servings' => $servings, 'ingredients' => $clean_ingredients,
        'instructions' => array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/u', $instructions)))),
        'types' => array_values(array_unique($type_ids)),
    ];
}

function ahx_wp_recipe_validate_submission_image($file) {
    if (!$file || ($file['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
        return true;
    }
    if (!is_array($file) || !is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null)
        || ($file['error'] ?? null) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > min(5 * MB_IN_BYTES, wp_max_upload_size())
        || !is_uploaded_file($file['tmp_name'])) {
        return new WP_Error('invalid_image', sprintf(__('Bitte ein Bild mit höchstens %s hochladen.', 'ahx_wp_recipe'), size_format(min(5 * MB_IN_BYTES, wp_max_upload_size()))));
    }
    $mimes = ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $mimes);
    $dimensions = wp_getimagesize($file['tmp_name']);
    if (empty($checked['ext']) || !in_array($checked['type'], $mimes, true) || !$dimensions
        || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] * $dimensions[1] > 16000000) {
        return new WP_Error('invalid_image', __('Nur gültige JPG-, PNG- oder WebP-Bilder mit maximal 16 Megapixeln sind erlaubt.', 'ahx_wp_recipe'));
    }
    return true;
}

function ahx_wp_recipe_store_submission($data, $token, $file) {
    $post_id = wp_insert_post(wp_slash([
        'post_type' => 'ahx_recipe', 'post_status' => 'pending',
        'post_title' => $data['title'], 'post_author' => get_current_user_id(),
        'post_content' => $data['kind'] === 'recipe' ? $data['description'] : '',
    ]), true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }
    update_post_meta($post_id, '_ahx_recipe_submission_kind', $data['kind']);
    update_post_meta($post_id, '_ahx_recipe_submission_token', hash('sha256', $token));
    if ($data['kind'] === 'url') {
        update_post_meta($post_id, '_ahx_recipe_source_url', $data['url']);
        update_post_meta($post_id, '_ahx_recipe_submission_complete', 1);
        return $post_id;
    }
    update_post_meta($post_id, '_ahx_recipe_servings', $data['servings']);
    update_post_meta($post_id, '_ahx_recipe_layout', 'classic');
    update_post_meta($post_id, '_ahx_recipe_ingredients', wp_slash($data['ingredients']));
    update_post_meta($post_id, '_ahx_recipe_instructions', wp_slash($data['instructions']));
    $terms = wp_set_object_terms($post_id, $data['types'], 'ahx_recipe_type');
    if (is_wp_error($terms)) {
        wp_delete_post($post_id, true);
        return $terms;
    }
    if ($file) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload('image', $post_id, [], [
            'test_form' => false,
            'mimes' => ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'],
        ]);
        if (is_wp_error($attachment_id)) {
            wp_delete_post($post_id, true);
            return $attachment_id;
        }
        set_post_thumbnail($post_id, $attachment_id);
    }
    update_post_meta($post_id, '_ahx_recipe_submission_complete', 1);
    return $post_id;
}

function ahx_wp_recipe_submit_frontend() {
    if (!is_string($_POST['nonce'] ?? null) || !wp_verify_nonce(wp_unslash($_POST['nonce']), 'ahx_wp_recipe_submit')) {
        wp_send_json_error(['message' => __('Die Sicherheitsprüfung ist abgelaufen. Bitte die Seite neu laden; der Entwurf bleibt erhalten.', 'ahx_wp_recipe')], 403);
    }
    $payload = $_POST['payload'] ?? null;
    if (!is_string($payload) || strlen($payload) > 80000) {
        wp_send_json_error(['message' => __('Die Einreichung ist zu groß oder ungültig.', 'ahx_wp_recipe')], 400);
    }
    $input = json_decode(wp_unslash($payload), true);
    $data = ahx_wp_recipe_validate_submission($input);
    if (is_wp_error($data)) {
        wp_send_json_error(['message' => $data->get_error_message()], 400);
    }
    $token = $input['token'];
    $existing = get_posts([
        'post_type' => 'ahx_recipe', 'post_status' => ['pending', 'draft', 'publish', 'private', 'trash'],
        'posts_per_page' => 1, 'fields' => 'ids',
        'meta_query' => [
            ['key' => '_ahx_recipe_submission_token', 'value' => hash('sha256', $token)],
            ['key' => '_ahx_recipe_submission_complete', 'value' => '1'],
        ],
    ]);
    if ($existing) {
        wp_send_json_success(['message' => __('Die Einreichung wurde bereits zur Prüfung gespeichert.', 'ahx_wp_recipe')]);
    }
    $rate_key = 'ahx_recipe_submit_' . hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), wp_salt('nonce'));
    $rate = get_transient($rate_key);
    if (is_array($rate) && $rate['count'] >= 10) {
        wp_send_json_error(['message' => __('Zu viele Einreichungen. Bitte später erneut versuchen; der Entwurf bleibt erhalten.', 'ahx_wp_recipe')], 429);
    }
    $file = $_FILES['image'] ?? null;
    $image_check = ahx_wp_recipe_validate_submission_image($file);
    if (is_wp_error($image_check)) {
        wp_send_json_error(['message' => $image_check->get_error_message()], 400);
    }
    if ($file && $file['error'] === UPLOAD_ERR_NO_FILE) {
        $file = null;
    }
    if ($data['kind'] === 'url' && $file) {
        wp_send_json_error(['message' => __('Importvorschläge benötigen keinen Bildupload.', 'ahx_wp_recipe')], 400);
    }
    $lock = 'ahx_recipe_submit_lock_' . hash('sha256', $token);
    if ((int) get_option($lock, 0) < time() - 300) {
        delete_option($lock);
    }
    if (!add_option($lock, time(), '', false)) {
        wp_send_json_error(['message' => __('Diese Einreichung wird bereits gespeichert. Bitte kurz darauf erneut versuchen.', 'ahx_wp_recipe')], 409);
    }
    $result = ahx_wp_recipe_store_submission($data, $token, $file);
    delete_option($lock);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => __('Die Einreichung konnte nicht gespeichert werden. Bitte erneut versuchen; der Entwurf bleibt erhalten.', 'ahx_wp_recipe')], 500);
    }
    $until = is_array($rate) ? $rate['until'] : time() + HOUR_IN_SECONDS;
    set_transient($rate_key, ['count' => is_array($rate) ? $rate['count'] + 1 : 1, 'until' => $until], max(1, $until - time()));
    wp_send_json_success(['message' => __('Gespeichert. Deine Einreichung wird im Backend geprüft und ist noch nicht veröffentlicht.', 'ahx_wp_recipe')]);
}
add_action('wp_ajax_ahx_wp_recipe_submit', 'ahx_wp_recipe_submit_frontend');
add_action('wp_ajax_nopriv_ahx_wp_recipe_submit', 'ahx_wp_recipe_submit_frontend');

function ahx_wp_recipe_submission_meta_box($post) {
    $kind = get_post_meta($post->ID, '_ahx_recipe_submission_kind', true);
    if ($kind === 'url') {
        $url = get_post_meta($post->ID, '_ahx_recipe_source_url', true);
        $import_url = add_query_arg(['post_type' => 'ahx_recipe', 'page' => 'ahx-wp-recipe-import', 'suggestion' => $post->ID], admin_url('edit.php'));
        echo '<p>' . esc_html__('Rezept-URL aus dem Frontend (Chefkoch oder Gaumenfreundin). Erst importieren, dann das entstandene Rezept prüfen und veröffentlichen.', 'ahx_wp_recipe') . '</p>';
        echo '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a></p>';
        echo '<p><a class="button button-primary" href="' . esc_url($import_url) . '">' . esc_html__('URL importieren', 'ahx_wp_recipe') . '</a></p>';
    } else {
        echo '<p>' . esc_html__('Aus dem Frontend eingereicht. Zutaten, Zubereitung, Rezepttypen und Beitragsbild vor dem Veröffentlichen prüfen.', 'ahx_wp_recipe') . '</p>';
    }
}

function ahx_wp_recipe_get_url_suggestion($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'ahx_recipe' || !in_array($post->post_status, ['pending', 'draft'], true)
        || get_post_meta($post_id, '_ahx_recipe_submission_kind', true) !== 'url'
        || !current_user_can('edit_post', $post_id)) {
        return new WP_Error('invalid_suggestion', __('Dieser Importvorschlag ist nicht verfügbar oder wurde bereits importiert.', 'ahx_wp_recipe'));
    }
    return $post;
}

function ahx_wp_recipe_add_submission_meta_box($post) {
    if (get_post_meta($post->ID, '_ahx_recipe_submission_kind', true)) {
        add_meta_box('ahx_recipe_submission', __('Frontend-Einreichung', 'ahx_wp_recipe'), 'ahx_wp_recipe_submission_meta_box', 'ahx_recipe', 'normal', 'high');
    }
}
add_action('add_meta_boxes_ahx_recipe', 'ahx_wp_recipe_add_submission_meta_box');

function ahx_wp_recipe_submission_columns($columns) {
    $columns['ahx_recipe_submission'] = __('Einreichung', 'ahx_wp_recipe');
    return $columns;
}
add_filter('manage_ahx_recipe_posts_columns', 'ahx_wp_recipe_submission_columns');

function ahx_wp_recipe_submission_column($column, $post_id) {
    if ($column === 'ahx_recipe_submission') {
        $kind = get_post_meta($post_id, '_ahx_recipe_submission_kind', true);
        $labels = ['recipe' => __('Frontend-Rezept', 'ahx_wp_recipe'), 'url' => __('URL-Vorschlag', 'ahx_wp_recipe'), 'imported' => __('Importierter Vorschlag', 'ahx_wp_recipe')];
        echo esc_html($labels[$kind] ?? '');
    }
}
add_action('manage_ahx_recipe_posts_custom_column', 'ahx_wp_recipe_submission_column', 10, 2);

function ahx_wp_recipe_prevent_unimported_publish($data, $postarr) {
    if (($data['post_type'] ?? '') === 'ahx_recipe' && ($data['post_status'] ?? '') === 'publish'
        && get_post_meta(absint($postarr['ID'] ?? 0), '_ahx_recipe_submission_kind', true) === 'url') {
        $data['post_status'] = 'pending';
    }
    return $data;
}
add_filter('wp_insert_post_data', 'ahx_wp_recipe_prevent_unimported_publish', 10, 2);

function ahx_wp_recipe_submission_shortcode($attributes = [], $content = null, $tag = '') {
    $separate = ['ahx_wp_recipe_submit_recipe' => 'recipe', 'ahx_wp_recipe_submit_url' => 'url'];
    if (!isset($separate[$tag])) {
        return '';
    }
    $kind = $separate[$tag];
    $headings = [$kind => $kind === 'recipe' ? __('Rezept einreichen', 'ahx_wp_recipe') : __('Rezept-URL vorschlagen', 'ahx_wp_recipe')];
    wp_enqueue_style('dashicons');
    wp_enqueue_style('ahx-wp-recipe', AHX_WP_RECIPE_URL . 'assets/frontend.css', [], (string) filemtime(AHX_WP_RECIPE_PATH . 'assets/frontend.css'));
    wp_enqueue_script('ahx-wp-recipe-submissions', AHX_WP_RECIPE_URL . 'assets/submissions.js', [], (string) filemtime(AHX_WP_RECIPE_PATH . 'assets/submissions.js'), true);
    $types = get_terms(['taxonomy' => 'ahx_recipe_type', 'hide_empty' => false]);
    $types = is_array($types) ? $types : [];
    $key = 'ahx-recipe:' . get_current_blog_id() . ':' . get_current_user_id();
    $max_image_bytes = min(5 * MB_IN_BYTES, wp_max_upload_size());
    ob_start();
    ?>
    <div class="ahx-recipe-submissions">
        <?php foreach ($headings as $kind => $heading) : ?>
            <section class="ahx-recipe-submission">
                <h2><?php echo esc_html($heading); ?></h2>
                <form data-ahx-submission data-draft-key="<?php echo esc_attr($key . ':' . $kind); ?>" data-endpoint="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('ahx_wp_recipe_submit')); ?>" data-max-image-bytes="<?php echo esc_attr($max_image_bytes); ?>" hidden>
                    <input type="hidden" name="kind" value="<?php echo esc_attr($kind); ?>">
                    <div class="ahx-recipe-submission__fields">
                        <label><?php echo esc_html($kind === 'recipe' ? __('Rezepttitel', 'ahx_wp_recipe') : __('Rezepttitel (optional)', 'ahx_wp_recipe')); ?><input type="text" name="title" maxlength="200" <?php echo $kind === 'recipe' ? 'required' : ''; ?>></label>
                        <?php if ($kind === 'recipe') : ?>
                            <label><?php esc_html_e('Beschreibung (optional)', 'ahx_wp_recipe'); ?><textarea name="description" rows="2" maxlength="10000"></textarea></label>
                            <label class="ahx-recipe-submission__servings"><?php esc_html_e('Portionen', 'ahx_wp_recipe'); ?><input type="number" name="servings" value="2" min="1" max="999" step="1" required></label>
                            <fieldset>
                                <legend><?php esc_html_e('Zutaten', 'ahx_wp_recipe'); ?></legend>
                                <div data-ahx-submission-ingredients></div>
                                <button type="button" data-ahx-submission-add><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e('Zutat hinzufügen', 'ahx_wp_recipe'); ?></button>
                            </fieldset>
                            <template data-ahx-submission-row>
                                <div class="ahx-recipe-submission__ingredient">
                                    <label><?php esc_html_e('Menge', 'ahx_wp_recipe'); ?><input type="text" data-ingredient="quantity" maxlength="200" inputmode="decimal" pattern="[0-9.,\/ ]*"></label>
                                    <label><?php esc_html_e('Einheit', 'ahx_wp_recipe'); ?><input type="text" data-ingredient="unit" maxlength="200"></label>
                                    <label><?php esc_html_e('Zutat', 'ahx_wp_recipe'); ?><input type="text" data-ingredient="label" maxlength="200" required></label>
                                    <label><?php esc_html_e('Ergänzung', 'ahx_wp_recipe'); ?><input type="text" data-ingredient="addition" maxlength="200"></label>
                                    <button type="button" data-ahx-submission-remove aria-label="<?php esc_attr_e('Zutat entfernen', 'ahx_wp_recipe'); ?>" title="<?php esc_attr_e('Zutat entfernen', 'ahx_wp_recipe'); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
                                </div>
                            </template>
                            <label><?php esc_html_e('Zubereitung', 'ahx_wp_recipe'); ?><textarea name="instructions" rows="6" maxlength="30000" required></textarea></label>
                            <?php if ($types) : ?>
                                <fieldset class="ahx-recipe-submission__types"><legend><?php esc_html_e('Rezepttypen (optional)', 'ahx_wp_recipe'); ?></legend>
                                    <?php foreach ($types as $type) : ?><label><input type="checkbox" name="types" value="<?php echo esc_attr($type->term_id); ?>"><?php echo esc_html($type->name); ?></label><?php endforeach; ?>
                                </fieldset>
                            <?php endif; ?>
                            <label><?php echo esc_html(sprintf(__('Rezeptbild (optional, JPG/PNG/WebP, max. %s und 16 Megapixel)', 'ahx_wp_recipe'), size_format($max_image_bytes))); ?><input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
                            <div class="ahx-recipe-submission__image" data-ahx-submission-image hidden>
                                <img alt="<?php esc_attr_e('Ausgewähltes Rezeptbild', 'ahx_wp_recipe'); ?>">
                                <span data-ahx-submission-filename></span>
                                <button type="button" data-ahx-submission-remove-image aria-label="<?php esc_attr_e('Bild entfernen', 'ahx_wp_recipe'); ?>" title="<?php esc_attr_e('Bild entfernen', 'ahx_wp_recipe'); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
                            </div>
                        <?php else : ?>
                            <label><?php esc_html_e('Rezept-URL (chefkoch.de oder gaumenfreundin.de)', 'ahx_wp_recipe'); ?><input type="url" name="url" maxlength="2000" placeholder="https://www.gaumenfreundin.de/rezeptname/" required></label>
                        <?php endif; ?>
                    </div>
                    <label class="ahx-recipe-submission__trap" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                    <div class="ahx-recipe-submission__actions">
                        <button type="submit"><span class="dashicons dashicons-saved" aria-hidden="true"></span><?php esc_html_e('Speichern', 'ahx_wp_recipe'); ?></button>
                        <button type="reset"><span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e('Entwurf verwerfen', 'ahx_wp_recipe'); ?></button>
                    </div>
                    <p data-ahx-submission-status role="status" aria-live="polite"></p>
                </form>
            </section>
        <?php endforeach; ?>
        <noscript><?php esc_html_e('Für die Rezept-Einreichung ist JavaScript erforderlich.', 'ahx_wp_recipe'); ?></noscript>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('ahx_wp_recipe_submit_recipe', 'ahx_wp_recipe_submission_shortcode');
add_shortcode('ahx_wp_recipe_submit_url', 'ahx_wp_recipe_submission_shortcode');