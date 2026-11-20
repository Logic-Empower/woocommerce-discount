<?php

function build_wc_product_query_args(array $rules, int $limit = 200, int $page = 1, $action = "add"): array
{

    $args = [
        'status' => 'publish',
        'limit'  => $limit,
        'page'   => $page,
        'type'   => ['simple', 'variable'],
        'return' => 'ids',
    ];

    if ($rules["apply_to_all"]) {

        if ($action == "add") {
            $excluded_onsale_ids = [];

            if (isset($rules["exclude_on_sale"]) && $rules["exclude_on_sale"]) {
                $args['meta_query'][] = array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_sale_price',
                        'value'   => '',
                        'compare' => '='
                    ),
                    array(
                        'key'     => '_sale_price',
                        'compare' => 'NOT EXISTS'
                    ),
                );
            }

            if (!empty($excluded_onsale_ids)) {
                $args['exclude'] = isset($args['exclude'])
                    ? array_merge($args['exclude'], $excluded_onsale_ids)
                    : $excluded_onsale_ids;
            }
        } else if ($action == "remove") {
            // 1. Get product IDs with sale price or sale schedule data.
            $all_sale_ids = get_discounted_product_ids_for_removal();

            // 2. The shared exclusions below will remove custom excluded IDs and taxonomies.
            $args['include'] = $all_sale_ids;

            if (count($args["include"]) <= 0) {
                echo "No products on sale to process\n";
                $args['include'] = [0];
            }
        }
    } 
    else {
        if (!discount_rule_group_has_selectors($rules["include"])) {
            $args['include'] = [0];
        }

        if (isset($rules["exclude_on_sale"]) && $rules["exclude_on_sale"]) {
            $args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_sale_price',
                    'value'   => '',
                    'compare' => '='
                ),
                array(
                    'key'     => '_sale_price',
                    'compare' => 'NOT EXISTS'
                ),
            );
        }

        if (!empty($rules["include"]["product_ids"]) || !empty($rules['include']['product_skus'])) {
            $ids = discount_rule_product_ids($rules['include']);
            // An unmatched SKU must never turn an explicit selection into all products.
            $args['include'] = $ids ?: [0];
        }

        add_discount_tax_query($args, 'product_cat', $rules["include"]["categories"], 'IN');
        add_discount_tax_query($args, 'product_tag', $rules["include"]["tags"], 'IN');
        add_discount_tax_query($args, 'product_brand', $rules["include"]["brands"], 'IN');
        add_discount_attribute_tax_queries($args, $rules["include"], 'IN');
    }

    add_discount_product_id_exclusions($args, discount_rule_product_ids($rules['exclude']));
    add_discount_tax_query($args, 'product_cat', $rules["exclude"]["categories"], 'NOT IN');
    add_discount_tax_query($args, 'product_tag', $rules["exclude"]["tags"], 'NOT IN');
    add_discount_tax_query($args, 'product_brand', $rules["exclude"]["brands"], 'NOT IN');
    add_discount_attribute_tax_queries($args, $rules["exclude"], 'NOT IN');

    return $args;
}

function discount_rule_product_ids(array $group): array
{
    $ids = $group['product_ids'] ?? [];
    foreach (array_unique($group['product_skus'] ?? []) as $sku) {
        $id = wc_get_product_id_by_sku($sku);
        $product = $id ? wc_get_product($id) : false;
        if ($product && $product->is_type(['simple', 'variable'])) {
            $ids[] = $id;
        } else {
            echo 'SKU "' . $sku . '": no simple or parent variable product found; this SKU was skipped.' . "\n";
        }
    }

    // The same product may appear by ID, by SKU, or on several imported rows.
    return array_values(array_unique(array_filter(array_map('absint', $ids))));
}

function get_discounted_product_ids_for_removal(): array
{
    $ids = wc_get_product_ids_on_sale();

    $scheduled_ids = get_posts([
        'post_type' => ['product', 'product_variation'],
        'post_status' => 'publish',
        'fields' => 'ids',
        'posts_per_page' => -1,
        'meta_query' => [
            'relation' => 'OR',
            [
                'key' => '_sale_price',
                'value' => '',
                'compare' => '!=',
            ],
            [
                'key' => '_sale_price_dates_from',
                'compare' => 'EXISTS',
            ],
            [
                'key' => '_sale_price_dates_to',
                'compare' => 'EXISTS',
            ],
        ],
    ]);

    foreach ($scheduled_ids as $product_id) {
        $parent_id = wp_get_post_parent_id($product_id);
        $ids[] = $parent_id ? $parent_id : $product_id;
    }

    return array_values(array_unique(array_filter(array_map('absint', $ids))));
}

function add_discount_product_id_exclusions(array &$args, array $product_ids): void
{
    if (empty($product_ids)) {
        return;
    }

    if (!empty($args['include'])) {
        $args['include'] = array_values(array_diff($args['include'], $product_ids));

        if (empty($args['include'])) {
            $args['include'] = [0];
        }

        return;
    }

    $args['exclude'] = isset($args['exclude'])
        ? array_values(array_unique(array_merge($args['exclude'], $product_ids)))
        : $product_ids;
}

function discount_rule_group_has_selectors(array $rule_group): bool
{
    foreach (['product_ids', 'product_skus', 'categories', 'tags', 'brands'] as $key) {
        if (!empty($rule_group[$key])) {
            return true;
        }
    }

    if (empty($rule_group['attributes']) || !is_array($rule_group['attributes'])) {
        return false;
    }

    foreach ($rule_group['attributes'] as $terms) {
        if (!empty($terms)) {
            return true;
        }
    }

    return false;
}

function add_discount_tax_query(array &$args, string $taxonomy, array $terms, string $operator): void
{
    if (empty($terms)) {
        return;
    }

    $args['tax_query'][] = [
        'taxonomy' => $taxonomy,
        'field'    => 'slug',
        'terms'    => $terms,
        'operator' => $operator,
    ];
}

function add_discount_attribute_tax_queries(array &$args, array $rule_group, string $operator): void
{
    if (empty($rule_group['attributes']) || !is_array($rule_group['attributes'])) {
        return;
    }

    foreach ($rule_group['attributes'] as $attribute => $terms) {
        if (empty($terms) || !is_array($terms)) {
            continue;
        }

        $taxonomy = normalize_discount_attribute_taxonomy((string) $attribute);

        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            continue;
        }

        add_discount_tax_query($args, $taxonomy, $terms, $operator);
    }
}

function normalize_discount_attribute_taxonomy(string $attribute): string
{
    $attribute = sanitize_title($attribute);

    if ($attribute === '') {
        return '';
    }

    return strpos($attribute, 'pa_') === 0 ? $attribute : 'pa_' . $attribute;
}

function apply_sale_price_discount(array $rules)
{   
    if (! class_exists('WooCommerce')) {
        return;
    }

    if (!validate_add_discount_rules($rules, "add")) {
        echo "Error with discount rules\n";
        return;
    }

    $args = build_wc_product_query_args($rules, 200, 1);

    $processed_products = 0;
    $processed_variations = 0;

    echo "🔍 Processing products in batches of {$args['limit']}...\n";
    echo discount_add_output_header($rules) . "\n";

    while (true) {

        $product_ids = wc_get_products($args);

        if (empty($product_ids)) {
            break;
        }

        // echo "📦 Batch page {$args['page']} - loaded " . count($product_ids) . " product IDs\n";

        foreach ($product_ids as $product_id) {

            $product = wc_get_product($product_id);

            if (! $product) {
                continue;
            }

            if ($product->is_type('simple')) {

                if (apply_discount_to_product($product, $rules)) {
                    $processed_products++;
                }
            } elseif ($product->is_type('variable')) {

                // Apply discount to variations only
                foreach ($product->get_children() as $variation_id) {

                    $variation = wc_get_product($variation_id);

                    if ($variation) {
                        if (apply_discount_to_product($variation, $rules)) {
                            $processed_variations++;
                        }

                        // Free memory aggressively
                        unset($variation);
                    }
                }

                // Free memory aggressively
                unset($product);
            }

            // Clear product cache/transients and free memory
            wc_delete_product_transients($product_id);
            unset($product);
        }

        // Move to next page
        $args['page'] = (int) $args['page'] + 1;

        // Help PHP free memory between batches
        gc_collect_cycles();
    }

    echo "Discount process completed.\n";
    echo "   - Processed products: {$processed_products}\n";
    echo "   - Processed variations: {$processed_variations}\n";
}

function apply_discount_to_product(WC_Product $product, array $rules): bool
{
    $dryRun = isset($rules["dry_run"]) && $rules["dry_run"] === true;

    if (!empty($rules["exclude_on_sale"]) && discount_product_has_sale_price($product)) {
        return false;
    }

    $regular_price = (float) $product->get_regular_price();

    if ($regular_price <= 0) {
        return false;
    }

    if ($rules['discount_type'] === 'percentage') {
        $sale_price = $regular_price - ($regular_price * ($rules['discount_value'] / 100));
    } else {
        $sale_price = $regular_price - $rules['discount_value'];
    }

    if ($sale_price < 0) {
        $sale_price = 0;
    }

    if(!$dryRun){
        $product->set_sale_price(wc_format_decimal($sale_price));
        apply_discount_schedule_to_product($product, $rules, $sale_price, $regular_price);
        $product->save();
    }
    
    // Clear cache/transients to keep memory usage low during large runs
    wc_delete_product_transients($product->get_id());

    if (! empty($rules['add_tag']) && !$dryRun) {
        apply_tag_to_discounted_product($product, $rules['add_tag']);
    }

    $brand = '';
    $brand_product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    $terms = get_the_terms($brand_product_id, 'product_brand');
	
    if (!empty($terms) && !is_wp_error($terms)) {
	    $brand = implode(', ', wp_list_pluck($terms, 'name'));
	}     
    $row = [
        $product->get_id(),
        $regular_price,
        $sale_price,
        $product->get_name(),
        $brand,
    ];

    if (discount_schedule_has_output_dates($rules)) {
        $row[] = discount_schedule_output_date($rules, 'start_date');
        $row[] = discount_schedule_output_date($rules, 'end_date');
    }

    discount_output_csv_row($row);

    return true;
}

function discount_add_output_header(array $rules): string
{
    $columns = ['ID', 'RegularPrice', 'SalePrice', 'Name', 'Brand'];

    if (discount_schedule_has_output_dates($rules)) {
        $columns[] = 'StartDate';
        $columns[] = 'EndDate';
    }

    return implode(',', $columns);
}

function discount_output_csv_row(array $row): void
{
    // Quote names and brands correctly, including commas, quotes and line breaks.
    $stream = fopen('php://output', 'w');
    fputcsv($stream, $row, ',', '"', '');
    fclose($stream);
}

function discount_schedule_has_output_dates(array $rules): bool
{
    return discount_schedule_is_enabled($rules)
        && (discount_schedule_output_date($rules, 'start_date') !== '' || discount_schedule_output_date($rules, 'end_date') !== '');
}

function discount_schedule_output_date(array $rules, string $key): string
{
    if (empty($rules['schedule'][$key]) || !is_string($rules['schedule'][$key])) {
        return '';
    }

    $timestamp = strtotime($rules['schedule'][$key]);

    return $timestamp === false ? '' : gmdate('Y-m-d', $timestamp);
}

function discount_product_has_sale_price(WC_Product $product): bool
{
    return (string) $product->get_sale_price() !== '' || $product->is_on_sale();
}

function apply_discount_schedule_to_product(WC_Product $product, array $rules, float $sale_price, float $regular_price): void
{
    if (!discount_schedule_is_enabled($rules)) {
        $product->set_date_on_sale_from(null);
        $product->set_date_on_sale_to(null);
        $product->set_price(wc_format_decimal($sale_price));
        return;
    }

    $from = discount_schedule_date($rules, 'start_date', false);
    $to = discount_schedule_date($rules, 'end_date', true);

    $product->set_date_on_sale_from($from);
    $product->set_date_on_sale_to($to);

    $active_now = discount_schedule_is_active_now($from, $to);
    $product->set_price(wc_format_decimal($active_now ? $sale_price : $regular_price));
}

function discount_schedule_is_enabled(array $rules): bool
{
    return !empty($rules['schedule']['enabled']);
}

function discount_schedule_date(array $rules, string $key, bool $end_of_day): ?WC_DateTime
{
    if (empty($rules['schedule'][$key]) || !is_string($rules['schedule'][$key])) {
        return null;
    }

    try {
        $time = $end_of_day ? ' 23:59:59' : ' 00:00:00';
        return new WC_DateTime($rules['schedule'][$key] . $time, wp_timezone());
    } catch (Exception $e) {
        return null;
    }
}

function discount_schedule_is_active_now(?WC_DateTime $from, ?WC_DateTime $to): bool
{
    if (!$from || !$to) {
        return false;
    }

    $now = current_time('timestamp', true);

    return $from->getTimestamp() <= $now && $to->getTimestamp() >= $now;
}

function apply_tag_to_discounted_product(WC_Product $product, string $tag_slug)
{

    $product_id = $product->is_type('variation')
        ? $product->get_parent_id()
        : $product->get_id();

    if (! $product_id) {
        return;
    }

    if (has_term($tag_slug, 'product_tag', $product_id)) {
        return;
    }

    wp_set_object_terms($product_id, $tag_slug, 'product_tag', true);
}

function remove_product_discount(array $rules)
{
    if (! class_exists('WooCommerce')) {
        return;
    }

    if (!validate_add_discount_rules($rules, "remove")) {
        echo "Error with discount rules\n";
        return;
    }

    $args = build_wc_product_query_args($rules, 200, 1, "remove");

    $processed_products = 0;
    $processed_variations = 0;

    echo "🔍 Processing products in batches of {$args['limit']}...\n";
    echo "ID,RegularPrice,SalePrice,Name,Brand\n";

    while (true) {
        $product_ids = wc_get_products($args);

        if (empty($product_ids)) {
            break;
        }

        // echo "📦 Batch page {$args['page']} - loaded " . count($product_ids) . " product IDs\n";

        foreach ($product_ids as $product_id) {

            $product = wc_get_product($product_id);

            if (! $product) {
                continue;
            }

            if ($product->is_type('simple')) {

                restore_product_price($product, $rules);
                $processed_products++;
            } elseif ($product->is_type('variable')) {

                foreach ($product->get_children() as $variation_id) {
                    $variation = wc_get_product($variation_id);

                    if ($variation) {
                        restore_product_price($variation, $rules);
                        $processed_variations++;

                        // Free memory aggressively
                        unset($variation);
                    }
                }

                // Free memory aggressively
                unset($product);
            }

            // Clear product cache/transients and free memory
            wc_delete_product_transients($product_id);
            unset($product);
        }
        // Move to next page
        $args['page'] = (int) $args['page'] + 1;

        // Help PHP free memory between batches
        gc_collect_cycles();
    }

    echo "Discount removal process completed.\n";
    echo "   - Processed products: {$processed_products}\n";
    echo "   - Processed variations: {$processed_variations}\n";
}
function restore_product_price(WC_Product $product, array $rules = [])
{
    $dryRun = isset($rules["dry_run"]) && $rules["dry_run"] === true;

    $regular_price = $product->get_regular_price();
    // Capture the sale price before clearing it, for both previews and actual runs.
    $sale_price = $product->get_sale_price();

    if ($regular_price === '') {
        return;
    }

    if(!$dryRun){

        // Remove sale price
        $product->set_sale_price('');
        $product->set_date_on_sale_from(null);
        $product->set_date_on_sale_to(null);

        // Restore price from regular price
        $product->set_price($regular_price);

        $product->save();

    }

    $brand = '';
    $brand_product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    $terms = get_the_terms($brand_product_id, 'product_brand');
	
    if (!empty($terms) && !is_wp_error($terms)) {
	    $brand = implode(', ', wp_list_pluck($terms, 'name'));
	}     
    discount_output_csv_row([$product->get_id(), $regular_price, $sale_price, $product->get_name(), $brand]);
}

function validate_add_discount_rules($rules, $action = "add")
{
    foreach (['include', 'exclude'] as $group) {
        if (isset($rules[$group]['product_skus'])) {
            if (!is_array($rules[$group]['product_skus'])) return false;
            foreach ($rules[$group]['product_skus'] as $sku) {
                if (!is_string($sku) || trim($sku) === '') return false;
            }
        }
    }
    if ($action == "add") {
        if (!isset($rules["discount_type"]) || !in_array($rules["discount_type"], ["percentage", "fixed"])) return false;
        if (!isset($rules["discount_value"]) || !is_numeric($rules["discount_value"]) || $rules["discount_value"] <= 0) return false;
        if (!isset($rules["exclude_on_sale"]) || !is_bool($rules["exclude_on_sale"])) return false;
        if (!isset($rules["add_tag"]) || !is_string($rules["add_tag"])) return false;
        if (!validate_discount_schedule_rules($rules)) return false;
    }
    if (!isset($rules["apply_to_all"]) || !is_bool($rules["apply_to_all"])) return false;
    if (
        !isset($rules["exclude"])
        ||
        !isset($rules["exclude"]["product_ids"])
        ||
        !isset($rules["exclude"]["categories"])
        ||
        !isset($rules["exclude"]["tags"])
        ||
        !isset($rules["exclude"]["brands"])
        ||
        !isset($rules["exclude"]["attributes"])
        ||
        !isset($rules["include"])
        ||
        !isset($rules["include"]["product_ids"])
        ||
        !isset($rules["include"]["categories"])
        ||
        !isset($rules["include"]["tags"])
        ||
        !isset($rules["include"]["brands"])
        ||
        !isset($rules["include"]["attributes"])
    ) return false;
    foreach ($rules["exclude"] as $exclude) {
        if (!is_array($exclude)) return false;
    }
    foreach ($rules["include"] as $include) {
        if (!is_array($include)) return false;
    }
    if (count($rules["exclude"]["product_ids"]) > 0) {
        foreach ($rules["exclude"]["product_ids"] as $id) {
            if (!is_numeric($id)) return false;
        }
    }
    if (count($rules["include"]["product_ids"]) > 0) {
        foreach ($rules["include"]["product_ids"] as $id) {
            if (!is_numeric($id)) return false;
        }
    }
    return true;
}

function validate_discount_schedule_rules(array $rules): bool
{
    if (!isset($rules['schedule'])) {
        return true;
    }

    if (!is_array($rules['schedule'])) {
        return false;
    }

    $schedule = array_merge(
        [
            'enabled' => false,
            'start_date' => '',
            'end_date' => '',
        ],
        $rules['schedule']
    );

    if (!is_bool($schedule['enabled']) || !is_string($schedule['start_date']) || !is_string($schedule['end_date'])) {
        return false;
    }

    if (!$schedule['enabled']) {
        return true;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedule['start_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedule['end_date'])) {
        return false;
    }

    $start = strtotime($schedule['start_date']);
    $end = strtotime($schedule['end_date']);

    return $start !== false && $end !== false && $start <= $end;
}

function readSettings($action = "add"){
    $settings = [];
    $filePath = __DIR__ . "/" . $action . "-discount.json";
    $file = fopen($filePath, "r");
    $fileJSON = fread($file, filesize($filePath));
    $settings = json_decode($fileJSON, true);
    fclose($file);
    return (array) $settings;
}
