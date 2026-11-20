# WooCommerce Discount Script

## Add Discount

This script applies discounts to WooCommerce products based on configurable rules. It supports percentage or fixed amount discounts, with options to include/exclude products by ID, category, tag, brand, or WooCommerce attribute. The script processes products in memory-safe batches and can optionally add a tag to discounted products.

## Remove Discount

This script removes applied discounts by restoring original prices and clearing sale prices. It can target all products currently on sale or specific products based on include/exclude rules.

# WooCommerce Discount Script (woocommerce-discount)

This repository contains **two CLI scripts** that you can run from the server terminal (SSH) to:

- Apply a discount to WooCommerce products (in safe batches)
- Remove discounts (restore prices)

It is designed for stores with **thousands of products**, and avoids memory exhaustion by:

- Querying products in **batches** (default: 200)
- Querying **IDs only** (not full product objects)
- Freeing memory aggressively between loops

---

## 1) Repository Structure

```
woocommerce-discount/
├─ add-discount.json
├─ add-discount.php
├─ assets/
├─ functions.php
├─ index.php
├─ README.md
├─ remove-discount.json
├─ remove-discount.php
└─ woocommerce-discount.php
```

`woocommerce-discount.php` turns the project into a WordPress plugin with an admin UI under:

```
WooCommerce > Discount Rules
```

To activate it as a plugin, place the whole `woocommerce-discount` folder in:

```
wp-content/plugins/woocommerce-discount/
```

The SSH scripts still work and continue to use the same JSON files.

### Admin rule selectors

Both discount tabs provide searchable, multiple-selection dropdowns for include/exclude categories, tags, brands, and global attribute terms. Options come from the database, including terms with no assigned products. Product IDs remain comma-, space-, or newline-separated text.

The dropdowns display term names and save the same slug arrays used by the CLI scripts. Previously saved terms that are no longer available remain selected and are labelled accordingly. Discount calculations, product matching, exclusions, scheduling, and dry-run processing are unchanged.

Brief help explains dry runs, starting from all products, and how include/exclude filters combine. The discount value displays `%` for percentage discounts and a currency-amount explanation for fixed discounts.

### Product SKUs and CSV import

Both tabs support Product IDs and Product SKUs in Include and Exclude rules. Enter one SKU per line; punctuation, spaces, and leading zeros are retained. IDs and SKUs form one combined product list, which is still narrowed by the selected category, brand, tag, and attribute filters. Exclusions take precedence. Starting from all products still ignores Include rules.

Use **Download sample CSV** and replace its example values with your products. The format is exactly `ID,SKU`; either cell may be blank. **Import CSV** appends values to that rule group's fields without saving settings or running discounts. Files are read in the browser (maximum 5 MB), and invalid CSV leaves the fields unchanged. Save or dry-run after reviewing the imported fields.

Duplicate IDs/SKUs are removed from each list. At run time, SKU matches are converted to product IDs and combined with entered IDs, so a product identified by both columns is processed once. Unknown SKUs are skipped with a message; an unmatched Include SKU never selects all products. SKUs target simple or parent variable products, matching the existing product scope; use a parent's SKU to process all its variations, not a variation SKU.

JSON rules may optionally include `"product_skus": ["WATCH-001", "00042"]` inside either rule group. Existing JSON files without this key continue to work.

---

## 2) Configuration Files

The scripts use JSON configuration files to define discount rules:

- `add-discount.json`: Configuration for applying discounts
- `remove-discount.json`: Configuration for removing discounts

Both files support a `dry_run` option (set to `true` for testing, `false` for production).

**Note:** Currently, both JSON files have `dry_run` set to `true`, so the scripts will simulate the process without making actual changes. Set to `false` to apply real discounts.

### Editing Configuration

Edit the JSON files to customize the discount rules. The structure matches the PHP arrays described below. Use a JSON validator to ensure syntax correctness.

Example `add-discount.json`:
```json
{
    "dry_run": false,
    "discount_type": "percentage",
    "discount_value": 10,
    "apply_to_all": true,
    "exclude_on_sale": false,
    "exclude": {
        "product_ids": [],
        "categories": [],
        "tags": [],
        "brands": []
    },
    "include": {
        "product_ids": [],
        "categories": [],
        "tags": [],
        "brands": []
    },
    "schedule": {
        "enabled": false,
        "start_date": "",
        "end_date": ""
    },
    "add_tag": "discounted"
}
```

---

## 2) Where to Upload This Repository

Upload the whole folder **inside your WordPress installation**, for example:

```
/var/www/html/wordpress-root/woocommerce-discount/
```

Your folder must be located in a place where this path exists:

```
.../wp-load.php
```

Because the scripts bootstrap WordPress using:

```php
$wp_load = dirname(__FILE__, 2) . "/wp-load.php";
```

Meaning:

- `woocommerce-discount` must be inside a directory that is **2 levels below** the WordPress root.

Example that works:

```
wordpress/
├─ wp-load.php
└─ woocommerce-discount/
    ├─ add-discount.json
    ├─ add-discount.php
    ├─ functions.php
    ├─ index.php
    ├─ README.md
    ├─ remove-discount.json
    └─ remove-discount.php
```

---

## 3) How to Run (SSH / Terminal)

### Requirements

- PHP CLI enabled on the server
- WordPress is installed
- WooCommerce plugin is active

### Run: Apply Discount

From your WordPress root:

```bash
cd /var/www/html/wordpress-root/woocommerce-discount
php add-discount.php
```

### Run: Remove Discount

```bash
cd /var/www/html/wordpress-root/woocommerce-discount
php remove-discount.php
```

---

## 4) What the Scripts Do (Step-by-Step)

### 4.1 Bootstrap WordPress

Both scripts:

1. Locate `wp-load.php`
2. Load WordPress
3. Load WooCommerce
4. Load `functions.php`

If anything is missing, the script stops immediately.

---

### 4.2 Build a WooCommerce Query (Memory Safe)

The scripts use:

```php
wc_get_products($args)
```

But the query is optimized to avoid memory issues:

- `return => 'ids'` (**important**)
- `limit => 200`
- `page => 1,2,3...`

So each batch loads only product IDs, and each product is processed individually.

---

### 4.3 Apply or Remove the Discount

- For **simple products**: the script updates the product itself.
- For **variable products**: the script updates **each variation**.

After each update, the script clears WooCommerce transients:

```php
wc_delete_product_transients($product_id);
```

And triggers garbage collection between batches:

```php
gc_collect_cycles();
```

---

## 5) Add Discount Script (`add-discount.php`)

### Purpose

Applies a discount to products by setting:

- `sale_price`
- `price`

And optionally adds a tag to the product.

---

### Discount Rules (add-discount.json)

```json
{
    "dry_run": false,
    "discount_type": "percentage",
    "discount_value": 10,
    "apply_to_all": true,
    "exclude_on_sale": false,
    "exclude": {
        "product_ids": [],
        "categories": [],
        "tags": [],
        "brands": [],
        "attributes": {}
    },
    "include": {
        "product_ids": [],
        "categories": ["watches"],
        "tags": ["CoupleWatches"],
        "brands": ["casio"],
        "attributes": {
            "pa_gender": ["women"]
        }
    },
    "schedule": {
        "enabled": false,
        "start_date": "",
        "end_date": ""
    },
    "add_tag": "valentines-day"
}
```

---

### Configuration Reference (Detailed)

#### `dry_run`
- Type: `bool`
- Default: `false`

If `true`, the script will simulate the discount application/removal without making actual changes to products. Useful for testing configurations.

#### `discount_type`
- Type: `string`
- Allowed values: `percentage` or `fixed`

Controls how the sale price is calculated.

---

#### `discount_value`
- Type: `int|float`
- Must be `> 0`

- If `percentage`: `10` means **10% off**.
- If `fixed`: `10` means **10 currency units off**.

---

#### `apply_to_all`
- Type: `bool`

If `true`:

- The script targets **all products**
- Then applies the `exclude` rules

If `false`:

- The script targets only products defined in `include`
- Then applies the `exclude` rules to remove products from that included set

This lets you build rules such as:

- Include category `watches`
- Include attribute `pa_gender = men`
- Exclude brands `casio` and `g-shock`
- Add tag `fathers-day`

---

#### `exclude_on_sale`
- Type: `bool`

If `true`, the script will exclude products that are already on sale.

> Note: This is only applied when `apply_to_all = false`, or when `apply_to_all = true` with `action = add`.

---

#### `schedule`
- Type: `object`
- Applies to add-discount rules only

If `enabled` is `true`, the script saves WooCommerce sale start/end dates on every matched product or variation.

```json
"schedule": {
  "enabled": true,
  "start_date": "2026-06-20",
  "end_date": "2026-06-23"
}
```

- `start_date`: sale starts at the beginning of this date.
- `end_date`: sale ends at the end of this date.
- Dates must use `YYYY-MM-DD`.
- If the schedule starts in the future, the sale price is stored but the current price remains the regular price until WooCommerce activates the scheduled sale.
- If `enabled` is `false`, sale date fields are cleared when applying the discount.
- When schedule dates are enabled, the run output and downloadable CSV include `StartDate` and `EndDate` columns in `Y-m-d` format.

---

#### `exclude`
- Type: `array`

This defines what should be excluded.

- `product_ids`: array of WooCommerce product IDs
- `categories`: array of category slugs
- `tags`: array of tag slugs
- `brands`: array of brand slugs (**taxonomy: `product_brand`**)
- `attributes`: object where each key is a WooCommerce attribute taxonomy and each value is an array of term slugs

Example:

```json
"exclude": {
  "product_ids": [12, 55],
  "categories": ["sale"],
  "tags": ["black-friday"],
  "brands": ["casio"],
  "attributes": {
    "pa_gender": ["men"]
  }
}
```

Attribute keys may be written as either `gender` or `pa_gender`; the script normalizes `gender` to `pa_gender`.

The `exclude` rules are applied in both modes:

- `apply_to_all = true`: start from all products, then subtract exclusions
- `apply_to_all = false`: start from included products, then subtract exclusions

---

#### `include`
- Type: `array`

This defines what should be included when `apply_to_all = false`.

- `product_ids`: array of WooCommerce product IDs
- `categories`: array of category slugs
- `tags`: array of tag slugs
- `brands`: array of brand slugs
- `attributes`: object where each key is a WooCommerce attribute taxonomy and each value is an array of term slugs

Example:

```json
"include": {
  "product_ids": [99, 100],
  "categories": ["watches"],
  "tags": ["couplewatches"],
  "brands": ["casio"],
  "attributes": {
    "pa_gender": ["women"]
  }
}
```

### Example: Father's Day Watches for Men, Excluding Brands

```json
{
  "dry_run": false,
  "discount_type": "percentage",
  "discount_value": 20,
  "apply_to_all": false,
  "exclude_on_sale": true,
  "schedule": {
    "enabled": false,
    "start_date": "",
    "end_date": ""
  },
  "exclude": {
    "product_ids": [],
    "categories": [],
    "tags": [],
    "brands": ["casio", "g-shock"],
    "attributes": {}
  },
  "include": {
    "product_ids": [],
    "categories": ["watches"],
    "tags": [],
    "brands": [],
    "attributes": {
      "pa_gender": ["men"]
    }
  },
  "add_tag": "fathers-day"
}
```

---

#### `add_tag`
- Type: `string`

If set, the script will add this product tag to discounted products.

**Important behavior:**

- If the product is a variation, the tag is added to the **parent variable product**.

---

### Example: Apply 15% to Everything Except Some Categories

```json
{
  "dry_run": false,
  "discount_type": "percentage",
  "discount_value": 15,
  "apply_to_all": true,
  "exclude_on_sale": true,
  "exclude": {
    "product_ids": [],
    "categories": ["clearance"],
    "tags": [],
    "brands": []
  },
  "include": {
    "product_ids": [],
    "categories": [],
    "tags": [],
    "brands": []
  },
  "add_tag": "discounted"
}
```
    "categories"  => ["clearance"],
    "tags"        => [],
    "brands"      => [],
  ],
  "include"         => [
    "product_ids" => [],
    "categories"  => [],
    "tags"        => [],
    "brands"      => [],
  ],
  "add_tag"         => "discounted"
];
```

---

## 6) Remove Discount Script (`remove-discount.php`)

### Purpose

This script removes the discount by:

- Clearing `sale_price`
- Clearing sale start/end dates
- Restoring `price` back to `regular_price`

---

### Rules (remove-discount.json)

```json
{
    "dry_run": false,
    "apply_to_all": true,
    "exclude": {
        "product_ids": [],
        "categories": [],
        "tags": [],
        "brands": [],
        "attributes": {}
    },
    "include": {
        "product_ids": [],
        "categories": [],
        "tags": [],
        "brands": [],
        "attributes": {}
    }
}
```

---

### How Removal Works

There are two modes:

#### Mode A: `apply_to_all = true`

The script will:

1. Load products with sale price data or sale schedule data. This includes currently active sales and future scheduled sales.

```php
get_discounted_product_ids_for_removal();
```

2. Remove discounts and sale start/end dates from them.

You can still exclude some products using:

- `exclude.product_ids`
- `exclude.categories`
- `exclude.tags`
- `exclude.brands`
- `exclude.attributes`

---

#### Mode B: `apply_to_all = false`

The script will remove discounts only from products matched by the `include` rules, after subtracting any matching `exclude` rules.

---

## 7) Batch Size & Performance

By default, the scripts run in batches of:

- `200` products per query

This value is set inside:

```php
build_wc_product_query_args($rules, 200, 1, $action)
```

You can safely change `200` to:

- `100` if the server is small
- `300` or `500` if the server is strong

---

## 8) Important Notes & Limitations

### 8.1 This Script Modifies Real Prices

This is not a dynamic discount rule.

It directly updates WooCommerce product prices by saving:

- `sale_price`
- `price`

So it is a **permanent change** until removed.

---

### 8.2 Variable Products

- Variable products themselves do not get a sale price.
- The script updates each variation.

---

### 8.3 Brand Taxonomy

The script assumes brands are stored in:

- Taxonomy: `product_brand`

If your site uses another taxonomy (example: `pa_brand`), update it inside:

```php
'taxonomy' => 'product_brand'
```

---

### 8.4 Cache

The script clears transients per product.

If you use a persistent object cache (Redis/Memcached), you may still want to clear cache after the run.

---

## 9) Safety Recommendations (Strongly Suggested)

Before running on production:

1. Take a database backup
2. Test on staging first
3. Start with a small include rule (few products)

---

## 10) Troubleshooting

### WooCommerce not active

If you see:

```
❌ WooCommerce is not active.
```

Make sure:

- WooCommerce plugin is enabled
- You are running the script inside the correct WordPress installation

---

### wp-load.php not found

If you see:

```
❌ wp-load.php not found.
```

Then the folder is not located correctly.

The scripts expect this structure:

```
wordpress/
├─ wp-load.php
└─ woocommerce-discount/
```

---

## 11) Output Example

The admin page displays a results panel above the settings, with a dry-run/live-run label, summary counts, and a scrollable product price table. Removal results show the sale price captured before removal beside the regular price, plus the discount amount and percentage. Empty runs and validation messages are displayed separately. The Download CSV button remains available; names and brands containing commas, quotes, or line breaks are quoted correctly.

The script prints a CSV header and processes each product, outputting details:

For add-discount.php:
```
ID,RegularPrice,SalePrice,Name,Brand
123,100.00,90.00,Product Name,Casio
124,200.00,180.00,Another Product,
...
```

For remove-discount.php:
```
ID,RegularPrice,SalePrice,Name,Brand
123,100.00,90.00,Product Name,Casio
124,200.00,180.00,Another Product,
...
```

At the end, it shows summary:
```
Discount process completed.
   - Processed products: 150
   - Processed variations: 50
```

---

## 12) Quick Start

1. Upload the folder inside WordPress:

```
woocommerce-discount/
```

2. Edit the rules in:

- `add-discount.php` (`$discount_rules`)
- `remove-discount.php` (`$rules`)

3. Run:

```bash
php add-discount.php
```

Or remove:

```bash
php remove-discount.php
```
