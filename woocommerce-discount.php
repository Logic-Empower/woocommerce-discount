<?php
/**
 * Plugin Name: WooCommerce Discount Rules
 * Description: Apply and remove WooCommerce sale prices from JSON-backed discount rules.
 * Version: 1.0.0
 * Author: Henry Kozhya
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/functions.php';

add_action('admin_menu', 'wcdp_register_admin_page');
add_action('admin_enqueue_scripts', 'wcdp_admin_assets');

function wcdp_register_admin_page(): void
{
    add_submenu_page(
        'woocommerce',
        'Discount Rules',
        'Discount Rules',
        'manage_woocommerce',
        'wcdp-discount-rules',
        'wcdp_render_admin_page'
    );
}

function wcdp_admin_assets(string $hook): void
{
    if ($hook !== 'woocommerce_page_wcdp-discount-rules') {
        return;
    }

    if (function_exists('WC')) {
        wp_enqueue_style('wcdp-select2', WC()->plugin_url() . '/assets/css/select2.css', [], WC_VERSION);
    }

    wp_enqueue_style(
        'wcdp-admin',
        plugins_url('assets/admin.css', __FILE__),
        function_exists('WC') ? ['wcdp-select2'] : [],
        (string) filemtime(__DIR__ . '/assets/admin.css')
    );

    wp_enqueue_script('wcdp-csv-import', plugins_url('assets/csv-import.js', __FILE__), [], (string) filemtime(__DIR__ . '/assets/csv-import.js'), true);
    wp_enqueue_script(
        'wcdp-admin',
        plugins_url('assets/admin.js', __FILE__),
        function_exists('WC') ? ['jquery', 'selectWoo', 'wcdp-csv-import'] : ['jquery', 'wcdp-csv-import'],
        (string) filemtime(__DIR__ . '/assets/admin.js'),
        true
    );
}

function wcdp_render_admin_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have permission to manage discount rules.', 'woocommerce-discount'));
    }

    $active_tab = isset($_GET['tab']) && $_GET['tab'] === 'remove' ? 'remove' : 'add';
    $message = '';
    $output = '';
    $csv_url = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('wcdp_save_rules');

        $action = isset($_POST['discount_action']) && $_POST['discount_action'] === 'remove' ? 'remove' : 'add';
        $active_tab = $action;
        $rules = wcdp_rules_from_post($action);
        $saved = wcdp_write_rules($action, $rules);

        if ($saved) {
            $message = 'Rules saved.';

            if (isset($_POST['run_rules'])) {
                if (!class_exists('WooCommerce')) {
                    $output = "WooCommerce is not active.\n";
                } else {
                    ob_start();

                    if ($action === 'add') {
                        apply_sale_price_discount($rules);
                    } else {
                        remove_product_discount($rules);
                    }

                    $output = (string) ob_get_clean();
                    $csv_url = wcdp_save_run_output_csv($output, $action);
                }
            }
        } else {
            $message = 'Could not save the JSON file. Check file permissions.';
        }
    }

    $rules = wcdp_read_rules($active_tab);
    $attributes = wcdp_get_product_attributes();
    ?>
    <div class="wrap wcdp-wrap">
        <h1>WooCommerce Discount Rules</h1>

        <?php if ($message !== '') : ?>
            <div class="notice notice-info is-dismissible"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>

        <nav class="nav-tab-wrapper">
            <a class="nav-tab <?php echo $active_tab === 'add' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=wcdp-discount-rules&tab=add')); ?>">Add Discount</a>
            <a class="nav-tab <?php echo $active_tab === 'remove' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=wcdp-discount-rules&tab=remove')); ?>">Remove Discount</a>
        </nav>

        <?php if ($output !== '') : ?>
            <?php wcdp_render_run_results($output, $active_tab, !empty($rules['dry_run']), $csv_url); ?>
        <?php endif; ?>

        <form method="post" class="wcdp-form">
            <?php wp_nonce_field('wcdp_save_rules'); ?>
            <input type="hidden" name="discount_action" value="<?php echo esc_attr($active_tab); ?>">

            <section class="wcdp-panel">
                <h2>Run Settings</h2>
                <label class="wcdp-check">
                    <input type="checkbox" name="dry_run" value="1" aria-describedby="wcdp-dry-run-help" <?php checked(!empty($rules['dry_run'])); ?>>
                    Dry run
                </label>
                <p class="description wcdp-check-help" id="wcdp-dry-run-help">Preview the results without changing products. Uncheck to apply changes with Save and Run.</p>
                <label class="wcdp-check">
                    <input type="checkbox" name="apply_to_all" value="1" aria-describedby="wcdp-all-help" <?php checked(!empty($rules['apply_to_all'])); ?>>
                    Start from all products
                </label>
                <p class="description wcdp-check-help" id="wcdp-all-help"><?php echo $active_tab === 'remove' ? 'Start from products with sale prices or sale schedules.' : 'Start from all published simple and variable products.'; ?> Include rules are ignored when checked; exclusions still apply.</p>

                <?php if ($active_tab === 'add') : ?>
                    <label class="wcdp-field">
                        <span>Discount type</span>
                        <select name="discount_type">
                            <option value="percentage" <?php selected($rules['discount_type'] ?? '', 'percentage'); ?>>Percentage</option>
                            <option value="fixed" <?php selected($rules['discount_type'] ?? '', 'fixed'); ?>>Fixed amount</option>
                        </select>
                    </label>
                    <label class="wcdp-field">
                        <span id="wcdp-discount-value-label">Discount value<?php echo ($rules['discount_type'] ?? '') === 'fixed' ? ' (fixed amount)' : ' (%)'; ?></span>
                        <input type="number" name="discount_value" min="0" step="0.01" aria-describedby="wcdp-discount-value-help" value="<?php echo esc_attr((string) ($rules['discount_value'] ?? 0)); ?>">
                        <small class="description" id="wcdp-discount-value-help"><?php echo ($rules['discount_type'] ?? '') === 'fixed' ? 'Amount off the regular price in store currency, e.g. 10 means 10 currency units off.' : 'Percentage off the regular price, e.g. 20 means 20% off.'; ?></small>
                    </label>
                    <label class="wcdp-check">
                        <input type="checkbox" name="exclude_on_sale" value="1" <?php checked(!empty($rules['exclude_on_sale'])); ?>>
                        Exclude products already on sale
                    </label>
                    <label class="wcdp-field">
                        <span>Add product tag after discount</span>
                        <input type="text" name="add_tag" value="<?php echo esc_attr((string) ($rules['add_tag'] ?? '')); ?>" placeholder="discounted">
                        <small class="description">Optional tag slug to add to discounted products. A new tag is created if needed.</small>
                    </label>
                    <div class="wcdp-schedule">
                        <label class="wcdp-check">
                            <input type="checkbox" name="schedule_enabled" value="1" <?php checked(!empty($rules['schedule']['enabled'])); ?>>
                            Schedule discount
                        </label>
                        <div class="wcdp-date-grid">
                            <label class="wcdp-field">
                                <span>Start date</span>
                                <input type="date" name="schedule_start_date" value="<?php echo esc_attr((string) ($rules['schedule']['start_date'] ?? '')); ?>" <?php disabled(empty($rules['schedule']['enabled'])); ?>>
                            </label>
                            <label class="wcdp-field">
                                <span>End date</span>
                                <input type="date" name="schedule_end_date" value="<?php echo esc_attr((string) ($rules['schedule']['end_date'] ?? '')); ?>" <?php disabled(empty($rules['schedule']['enabled'])); ?>>
                            </label>
                        </div>
                        <p class="description">When enabled, both dates are required; the end date includes the full day. When disabled, existing sale dates are cleared on matched products.</p>
                    </div>
                <?php endif; ?>
            </section>

            <?php wcdp_render_rule_group('include', $rules['include'] ?? [], $attributes, !empty($rules['apply_to_all'])); ?>
            <?php wcdp_render_rule_group('exclude', $rules['exclude'] ?? [], $attributes); ?>

            <p class="submit">
                <button type="submit" name="save_rules" class="button button-secondary">Save Rules</button>
                <button type="submit" name="run_rules" class="button button-primary">Save and Run</button>
            </p>
            <p class="description">Save Rules only saves the settings. Save and Run also processes the products, or previews the results when Dry run is checked.</p>
        </form>

    </div>
    <?php
}

function wcdp_render_rule_group(string $group, array $rules, array $attributes, bool $apply_to_all = false): void
{
    $label = ucfirst($group);
    ?>
    <section class="wcdp-panel">
        <h2><?php echo esc_html($label); ?> Rules</h2>
        <?php if ($group === 'include') : ?>
            <p class="description wcdp-group-help" id="wcdp-include-help" aria-live="polite"><?php echo $apply_to_all ? 'These rules are ignored while Start from all products is checked.' : 'Choose at least one rule; otherwise no products are selected. IDs and SKUs form one combined list. Products must also match every filled taxonomy field, with any selected term matching within each field.'; ?></p>
        <?php else : ?>
            <p class="description wcdp-group-help">Products matching any exclusion are skipped, even if included above.</p>
        <?php endif; ?>
        <p class="description wcdp-group-help">Search and select one or more terms per dropdown. Leave a field empty to skip that filter.</p>
        <div class="wcdp-grid">
            <?php wcdp_textarea_field($group, 'product_ids', 'Product IDs', $rules['product_ids'] ?? []); ?>
            <label class="wcdp-field">
                <span>Product SKUs</span>
                <textarea name="<?php echo esc_attr($group); ?>_product_skus" rows="2" placeholder="e.g. WATCH-001"><?php echo esc_textarea(implode("\n", $rules['product_skus'] ?? [])); ?></textarea>
                <small class="description">One SKU per line. Use simple or parent product SKUs; a variable product includes all its variations.</small>
            </label>
        </div>
        <div class="wcdp-import" data-group="<?php echo esc_attr($group); ?>">
            <div class="wcdp-import-controls">
                <label for="wcdp-<?php echo esc_attr($group); ?>-csv">Import IDs / SKUs</label>
                <input type="file" id="wcdp-<?php echo esc_attr($group); ?>-csv" accept=".csv,text/csv" aria-describedby="wcdp-<?php echo esc_attr($group); ?>-import-help">
                <button type="button" class="button wcdp-import-button">Import CSV</button>
                <a href="<?php echo esc_url(plugins_url('assets/product-import-sample.csv', __FILE__)); ?>" download="product-import-sample.csv">Download sample CSV</a>
            </div>
            <p class="description" id="wcdp-<?php echo esc_attr($group); ?>-import-help">Use the columns ID and SKU. Either cell may be blank. Replace the sample values with your products. Import appends to the fields above without saving or running. Each matched product is processed once.</p>
            <p class="wcdp-import-status" role="status" aria-live="polite"></p>
        </div>
        <div class="wcdp-grid wcdp-taxonomy-grid">
            <?php wcdp_term_select_field($group . '_categories', 'Categories', 'product_cat', $rules['categories'] ?? []); ?>
            <?php wcdp_term_select_field($group . '_brands', 'Brands', 'product_brand', $rules['brands'] ?? []); ?>
            <?php wcdp_term_select_field($group . '_tags', 'Tags', 'product_tag', $rules['tags'] ?? []); ?>
        </div>

        <h3>Attributes</h3>
        <div class="wcdp-attributes">
            <?php if (empty($attributes)) : ?>
                <p>No global WooCommerce attributes were found.</p>
            <?php endif; ?>

            <?php foreach ($attributes as $attribute) : ?>
                <?php
                $taxonomy = $attribute['taxonomy'];
                $value = $rules['attributes'][$taxonomy] ?? [];
                ?>
                <?php wcdp_term_select_field($group . '_attributes[' . $taxonomy . ']', $attribute['label'], $taxonomy, $value); ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

function wcdp_textarea_field(string $group, string $name, string $label, array $value): void
{
    ?>
    <label class="wcdp-field">
        <span><?php echo esc_html($label); ?></span>
        <textarea name="<?php echo esc_attr($group . '_' . $name); ?>" rows="2" placeholder="e.g. 123, 456"><?php echo esc_textarea(implode(', ', $value)); ?></textarea>
        <small class="description">Separate product IDs with commas, spaces or new lines.</small>
    </label>
    <?php
}

function wcdp_term_select_field(string $name, string $label, string $taxonomy, array $selected_terms): void
{
    // Share each taxonomy lookup between the include and exclude fields.
    static $term_cache = [];

    if (!array_key_exists($taxonomy, $term_cache)) {
        $term_cache[$taxonomy] = taxonomy_exists($taxonomy)
            ? get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'])
            : new WP_Error('wcdp_missing_taxonomy', 'This taxonomy is not available.');
    }

    $result = $term_cache[$taxonomy];
    $terms = is_wp_error($result) ? [] : $result;
    $options = [];

    foreach ($terms as $term) {
        $options[$term->slug] = $term->name;
    }

    // Keep saved slugs even if a term was deleted or its taxonomy is unavailable.
    // Silently dropping them could broaden the next run's product selection.
    foreach ($selected_terms as $slug) {
        if (!array_key_exists($slug, $options)) {
            $options[$slug] = $slug . ' (saved term unavailable)';
        }
    }

    $id = 'wcdp-' . sanitize_html_class(str_replace(['[', ']'], ['-', ''], $name));
    ?>
    <div class="wcdp-field">
        <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
        <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>[]" class="wcdp-term-select" multiple data-placeholder="Search and select…" aria-describedby="<?php echo esc_attr($id); ?>-help">
            <?php foreach ($options as $slug => $term_label) : ?>
                <option value="<?php echo esc_attr($slug); ?>" <?php selected(in_array((string) $slug, $selected_terms, true)); ?>><?php echo esc_html($term_label); ?></option>
            <?php endforeach; ?>
        </select>
        <small class="description" id="<?php echo esc_attr($id); ?>-help"><?php
            if (is_wp_error($result)) {
                echo 'Terms could not be loaded. Existing selections are retained.';
            } elseif (empty($terms)) {
                echo 'No terms are available yet. Existing selections are retained.';
            }
        ?></small>
    </div>
    <?php
}

function wcdp_rules_from_post(string $action): array
{
    $rules = [
        'dry_run' => !empty($_POST['dry_run']),
        'apply_to_all' => !empty($_POST['apply_to_all']),
        'exclude' => wcdp_rule_group_from_post('exclude'),
        'include' => wcdp_rule_group_from_post('include'),
    ];

    if ($action === 'add') {
        $rules['discount_type'] = isset($_POST['discount_type']) && $_POST['discount_type'] === 'fixed' ? 'fixed' : 'percentage';
        $rules['discount_value'] = isset($_POST['discount_value']) ? (float) sanitize_text_field(wp_unslash($_POST['discount_value'])) : 0;
        $rules['exclude_on_sale'] = !empty($_POST['exclude_on_sale']);
        $rules['add_tag'] = isset($_POST['add_tag']) ? sanitize_title(wp_unslash($_POST['add_tag'])) : '';
        $rules['schedule'] = [
            'enabled' => !empty($_POST['schedule_enabled']),
            'start_date' => wcdp_sanitize_date($_POST['schedule_start_date'] ?? ''),
            'end_date' => wcdp_sanitize_date($_POST['schedule_end_date'] ?? ''),
        ];
    }

    return $rules;
}

function wcdp_sanitize_date($value): string
{
    $value = is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function wcdp_rule_group_from_post(string $group): array
{
    return [
        'product_ids' => wcdp_parse_ids($_POST[$group . '_product_ids'] ?? ''),
        'product_skus' => wcdp_parse_skus($_POST[$group . '_product_skus'] ?? ''),
        'categories' => wcdp_parse_slug_list($_POST[$group . '_categories'] ?? ''),
        'tags' => wcdp_parse_slug_list($_POST[$group . '_tags'] ?? ''),
        'brands' => wcdp_parse_slug_list($_POST[$group . '_brands'] ?? ''),
        'attributes' => wcdp_parse_attributes($_POST[$group . '_attributes'] ?? []),
    ];
}

function wcdp_parse_ids($value): array
{
    return array_values(array_unique(array_filter(array_map('absint', wcdp_parse_list($value)))));
}

function wcdp_parse_skus($value): array
{
    $lines = is_string($value) ? preg_split('/\r\n|\r|\n/', wp_unslash($value)) : [];
    $skus = array_map('sanitize_text_field', array_map('trim', $lines));
    return array_values(array_unique(array_filter($skus, static function ($sku) { return $sku !== ''; })));
}

function wcdp_parse_slug_list($value): array
{
    return array_values(array_filter(array_map('sanitize_title', wcdp_parse_list($value))));
}

function wcdp_parse_list($value): array
{
    if (is_array($value)) {
        return array_values(array_map('wp_unslash', array_filter($value, 'is_string')));
    }

    $value = is_string($value) ? wp_unslash($value) : '';
    $parts = preg_split('/[\s,]+/', $value);

    return is_array($parts) ? $parts : [];
}

function wcdp_parse_attributes($attributes): array
{
    if (!is_array($attributes)) {
        return [];
    }

    $parsed = [];

    foreach ($attributes as $attribute => $terms) {
        $taxonomy = normalize_discount_attribute_taxonomy((string) $attribute);
        $terms = wcdp_parse_slug_list($terms);

        if ($taxonomy !== '' && !empty($terms)) {
            $parsed[$taxonomy] = $terms;
        }
    }

    return $parsed;
}

function wcdp_read_rules(string $action): array
{
    $file = wcdp_rules_file($action);

    if (!file_exists($file)) {
        return wcdp_default_rules($action);
    }

    $json = file_get_contents($file);
    $rules = json_decode((string) $json, true);

    if (!is_array($rules)) {
        return wcdp_default_rules($action);
    }

    return array_replace_recursive(wcdp_default_rules($action), $rules);
}

function wcdp_write_rules(string $action, array $rules): bool
{
    $json = wp_json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (!is_string($json)) {
        return false;
    }

    return file_put_contents(wcdp_rules_file($action), $json . "\n") !== false;
}

function wcdp_save_run_output_csv(string $output, string $action): string
{
    $csv = wcdp_extract_csv_from_run_output($output);

    if ($csv === '') {
        return '';
    }

    $uploads = wp_upload_dir();

    if (!empty($uploads['error'])) {
        return '';
    }

    $dir = trailingslashit($uploads['basedir']) . 'woocommerce-discount';

    if (!wp_mkdir_p($dir)) {
        return '';
    }

    $filename = sprintf(
        'discount-%s-%s.csv',
        sanitize_key($action),
        gmdate('Ymd-His')
    );

    $path = trailingslashit($dir) . $filename;

    if (file_put_contents($path, $csv) === false) {
        return '';
    }

    return trailingslashit($uploads['baseurl']) . 'woocommerce-discount/' . $filename;
}

function wcdp_extract_csv_from_run_output(string $output): string
{
    $result = wcdp_parse_run_output($output);

    if (empty($result['columns'])) {
        return '';
    }

    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, $result['columns'], ',', '"', '');
    foreach ($result['rows'] as $row) {
        fputcsv($stream, array_values($row), ',', '"', '');
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return (string) $csv;
}

function wcdp_parse_run_output(string $output): array
{
    $result = ['columns' => [], 'rows' => [], 'messages' => [], 'products' => 0, 'variations' => 0, 'complete' => false];
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $output);
    rewind($stream);

    // Read CSV records, not lines: quoted product names can contain line breaks.
    while (($record = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $first = trim((string) ($record[0] ?? ''));
        if ($first === 'ID' && ($record[1] ?? '') === 'RegularPrice') {
            $result['columns'] = $record;
            continue;
        }
        if ($result['columns'] && ctype_digit($first) && count($record) === count($result['columns'])) {
            $result['rows'][] = array_combine($result['columns'], $record);
            continue;
        }
        $message = trim(implode(',', $record));
        if ($message === '' || strpos($message, '🔍 Processing products in batches of ') === 0) {
            continue;
        }
        if (preg_match('/^- Processed (products|variations): (\d+)$/', $message, $matches)) {
            $result[$matches[1]] = (int) $matches[2];
        } elseif (in_array($message, ['Discount process completed.', 'Discount removal process completed.'], true)) {
            $result['complete'] = true;
        } else {
            $result['messages'][] = $message === 'Error with discount rules'
                ? 'The run could not start. Check the discount value, rule selections and schedule dates.'
                : $message;
        }
    }
    fclose($stream);

    return $result;
}

function wcdp_result_price($value): string
{
    return $value !== null && $value !== '' && is_numeric($value)
        ? wp_kses_post(wc_price((float) $value))
        : '<span class="wcdp-muted">—</span>';
}

function wcdp_render_run_results(string $output, string $action, bool $dry_run, string $csv_url): void
{
    $result = wcdp_parse_run_output($output);
    $remove = $action === 'remove';
    $complete = $result['complete'];
    $dates = in_array('StartDate', $result['columns'], true);
    $title = !$complete ? 'Run needs attention' : ($dry_run ? 'Preview complete' : 'Run complete');
    ?>
    <section class="wcdp-panel wcdp-results" aria-labelledby="wcdp-results-title">
        <div class="wcdp-output-header">
            <div>
                <span class="wcdp-result-badge <?php echo !$complete ? 'wcdp-badge-warning' : ($dry_run ? 'wcdp-badge-preview' : 'wcdp-badge-complete'); ?>"><?php echo $dry_run ? 'Dry run' : 'Live run'; ?> · <?php echo $remove ? 'Remove discounts' : 'Add discounts'; ?></span>
                <h2 id="wcdp-results-title"><?php echo esc_html($title); ?></h2>
                <p class="description"><?php
                    echo $dry_run ? 'No product prices were changed. Review the results before applying your rules.'
                        : ($complete ? 'Processing finished. The table shows the prices recorded during this run.' : 'Review the messages below before running again.');
                ?></p>
            </div>
            <?php if ($csv_url !== '') : ?>
                <a class="button button-secondary" href="<?php echo esc_url($csv_url); ?>" download>Download CSV</a>
            <?php endif; ?>
        </div>

        <?php if ($complete) : ?>
            <dl class="wcdp-result-stats">
                <div><dt>Products processed</dt><dd><?php echo esc_html(number_format_i18n($result['products'])); ?></dd></div>
                <div><dt>Variations processed</dt><dd><?php echo esc_html(number_format_i18n($result['variations'])); ?></dd></div>
                <div><dt>Price rows reported</dt><dd><?php echo esc_html(number_format_i18n(count($result['rows']))); ?></dd></div>
            </dl>
        <?php endif; ?>

        <?php foreach ($result['messages'] as $message) : ?>
            <p class="wcdp-result-message"><?php echo esc_html($message); ?></p>
        <?php endforeach; ?>
        <?php if ($result['columns'] && $csv_url === '') : ?>
            <p class="wcdp-result-message">The CSV file could not be created. Results are still shown below.</p>
        <?php endif; ?>

        <?php if ($result['rows']) : ?>
            <p class="description wcdp-table-help"><?php echo $remove
                ? 'Compare the sale price being removed with the regular price being restored. A dash means no sale price was set; scheduled sale prices are included.'
                : 'Compare the regular price with the calculated sale price for each product or variation.'; ?></p>
            <div class="wcdp-results-scroll" role="region" aria-label="Product price results" tabindex="0">
                <table class="widefat striped wcdp-results-table">
                    <caption class="screen-reader-text">Product prices for this discount run</caption>
                    <thead><tr>
                        <th scope="col">Product / variation</th>
                        <th scope="col">Brand</th>
                        <?php if ($remove) : ?>
                            <th scope="col"><?php echo $dry_run ? 'Sale price to remove' : 'Removed sale price'; ?></th><th scope="col"><?php echo $dry_run ? 'Regular price to restore' : 'Restored regular price'; ?></th>
                        <?php else : ?>
                            <th scope="col">Regular price</th><th scope="col">New sale price</th>
                        <?php endif; ?>
                        <th scope="col"><?php echo $remove ? ($dry_run ? 'Discount to remove' : 'Discount removed') : 'Discount'; ?></th>
                        <?php if ($dates) : ?><th scope="col">Sale schedule</th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($result['rows'] as $row) : ?>
                        <?php
                        $regular = $row['RegularPrice'] ?? '';
                        $sale = $row['SalePrice'] ?? '';
                        $has_discount = is_numeric($regular) && is_numeric($sale) && (float) $regular >= (float) $sale;
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($row['Name'] ?? ''); ?></strong><small class="wcdp-product-id">ID #<?php echo esc_html($row['ID']); ?></small></td>
                            <td><?php echo esc_html(($row['Brand'] ?? '') !== '' ? $row['Brand'] : '—'); ?></td>
                            <td class="wcdp-price"><?php echo wcdp_result_price($remove ? $sale : $regular); ?></td>
                            <td class="wcdp-price wcdp-result-target"><?php echo wcdp_result_price($remove ? $regular : $sale); ?></td>
                            <td class="wcdp-price"><?php
                                echo wcdp_result_price($has_discount ? (float) $regular - (float) $sale : '');
                                if ($has_discount && (float) $regular > 0) {
                                    echo '<small class="wcdp-product-id">' . esc_html(number_format_i18n(((float) $regular - (float) $sale) / (float) $regular * 100, 2)) . '%</small>';
                                }
                            ?></td>
                            <?php if ($dates) : ?>
                                <td class="wcdp-schedule-cell"><?php echo esc_html(($row['StartDate'] ?? '') . ' → ' . ($row['EndDate'] ?? '')); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($complete) : ?>
            <div class="wcdp-empty-results"><strong>No price changes to show</strong><p>No price rows were produced. Review your include/exclude rules and the products’ existing prices.</p></div>
        <?php endif; ?>
    </section>
    <?php
}

function wcdp_rules_file(string $action): string
{
    return __DIR__ . '/' . ($action === 'remove' ? 'remove' : 'add') . '-discount.json';
}

function wcdp_default_rules(string $action): array
{
    $rules = [
        'dry_run' => true,
        'apply_to_all' => false,
        'exclude' => wcdp_empty_rule_group(),
        'include' => wcdp_empty_rule_group(),
    ];

    if ($action === 'add') {
        $rules = array_merge(
            [
                'discount_type' => 'percentage',
                'discount_value' => 0,
                'exclude_on_sale' => true,
                'schedule' => [
                    'enabled' => false,
                    'start_date' => '',
                    'end_date' => '',
                ],
            ],
            $rules,
            [
                'add_tag' => '',
            ]
        );
    }

    return $rules;
}

function wcdp_empty_rule_group(): array
{
    return [
        'product_ids' => [],
        'product_skus' => [],
        'categories' => [],
        'tags' => [],
        'brands' => [],
        'attributes' => [],
    ];
}

function wcdp_get_product_attributes(): array
{
    if (!function_exists('wc_get_attribute_taxonomies')) {
        return [];
    }

    $attributes = [];

    foreach (wc_get_attribute_taxonomies() as $attribute) {
        $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);

        if (!taxonomy_exists($taxonomy)) {
            continue;
        }

        $attributes[] = [
            'taxonomy' => $taxonomy,
            'label' => $attribute->attribute_label ?: $attribute->attribute_name,
        ];
    }

    return $attributes;
}
