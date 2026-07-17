# Nexter SEO — Developer Hook Reference

Every custom filter and action exposed by the Nexter **Content SEO** module, grouped by area.
All hooks use the `nexter_content_seo_` prefix.

> **Naming note:** an earlier QA report referenced `nxt_seo_schema_output`, `nxt_seo_robots_meta`,
> and `nxt_seo_sitemap_urls`. Those names do **not** exist in the code. The real equivalents are
> `nexter_content_seo_schema_graph`, `nexter_content_seo_robots_directives`, and
> `nexter_content_seo_sitemap_excluded_post_types` (see below).

Legend: **F** = filter (`apply_filters`), **A** = action (`do_action`).

---

## Schema (`class-seo-schema.php`)

| Hook | Type | Default | Purpose |
|------|------|---------|---------|
| `nexter_content_seo_print_schema` | F(bool) | `true` | Return `false` to suppress all JSON-LD `@graph` output on the current request. |
| `nexter_content_seo_skip_schema_on_noindex` | F(bool) | `true` | Whether to skip schema on noindex pages. Return `false` to still emit schema there. |
| `nexter_content_seo_schema_cache` | F(bool) | `true` | Return `false` to disable the per-request schema object cache. |
| `nexter_content_seo_schema_graph` | F(array `$nodes`) | rendered nodes | Final filter over the full `@graph` array right before output — add/remove/modify nodes. |
| `nexter_content_seo_schema_replacements` | F(array `$r`) | replacement map | Add or override `%token%` → value pairs used when rendering schema field templates. |
| `nexter_content_seo_schema_variables` | F(array `$all`) | variable list | The list of template variables surfaced to the schema builder UI. |
| `nexter_content_seo_schema_rules_selections` | F(array `$options`) | options | Adjust the show-on / not-show-on rule selection data for schema rows. |
| `nexter_content_seo_auto_website_schema` | F(bool) | `true` | Return `false` to stop auto-injecting the site-identity `WebSite` node. |
| `nexter_content_seo_defer_product_schema_to_woo` | F(bool) | `class_exists('WooCommerce')` | Whether to defer Product schema to WooCommerce's own output on product pages. |
| `nexter_content_seo_search_action_target_url` | F(string) | `home_url('/') . '?s={search_term_string}'` | The sitelinks-searchbox target URL used by the `WebSite` `SearchAction`. |
| `nexter_content_seo_article_schema_type_options` | F(array `$groups`) | subtype groups | Article `@type` options shown in the schema builder (Article/NewsArticle/BlogPosting). |
| `nexter_content_seo_organization_schema_type_options` | F(array `$groups`) | subtype groups | Organization `@type` subtype options. |
| `nexter_content_seo_webpage_schema_type_options` | F(array `$groups`) | subtype groups | WebPage `@type` subtype options (CollectionPage, AboutPage, …). |

## Robots (`class-seo-robots.php`)

| Hook | Type | Default | Purpose |
|------|------|---------|---------|
| `nexter_content_seo_robots_directives` | F(array `$directives`) | resolved directives | The robots directives (`noindex`, `nofollow`, `noarchive`, …) for the current request. |
| `nexter_content_seo_noindex_wc_transactional` | F(bool `$is_wc_page`) | auto-detected | Whether the current WooCommerce cart/checkout/account page is treated as transactional (noindex). |

## Description & Title (`class-seo-description.php`, `class-seo-title.php`)

| Hook | Type | Default | Purpose |
|------|------|---------|---------|
| `nexter_content_seo_output_description_meta` | F(bool) | `true` | Return `false` to suppress the `<meta name="description">` output. |
| `nexter_content_seo_defer_to_other_seo` | F(bool `$active`) | auto-detected | Whether to defer meta output to another active SEO plugin (Yoast, RankMath, …). |
| `nexter_content_seo_dedupe_head_description` | F(bool) | `true` | Return `false` to disable buffering `<head>` to remove duplicate description metas. |
| `nexter_content_seo_builder_description` | F(string `$desc`, WP_Post `$post`) | `''` | Supply a description from a page builder (e.g. the built-in Elementor `_elementor_data` parser). |
| `nexter_content_seo_max_description_length` | F(int) | `320` | Max meta-description length (word-boundary truncated). Return `0` to disable the cap. |
| `nexter_content_seo_max_title_length` | F(int) | `120` | Max `<title>` length (word-boundary truncated). Return `0` to disable the cap. |

## Social — Open Graph & Twitter (`class-seo-social-meta.php`)

| Hook | Type | Default | Purpose |
|------|------|---------|---------|
| `nexter_content_seo_og_type` | F(string `$type`) | auto | The `og:type` value for the current request (`website`/`article`/…). |
| `nexter_content_seo_downgrade_small_twitter_card` | F(bool, string `$img`) | `true` | Whether to downgrade `summary_large_image` → `summary` when the image is below the minimum. |
| `nexter_content_seo_large_card_min_width` | F(int) | `300` | Minimum image width for a Twitter `summary_large_image` card. |
| `nexter_content_seo_large_card_min_height` | F(int) | `157` | Minimum image height for a Twitter `summary_large_image` card. |
| `nexter_content_seo_large_card_assume_when_unknown` | F(bool, string `$url`) | `false` | Whether to keep a large card when image dimensions can't be determined. |
| `nexter_content_seo_og_probe_remote_image` | F(bool, string `$url`) | `true` | Whether to fetch a remote/off-site image to measure `og:image:width/height`. |
| `nexter_content_seo_og_remote_probe_timeout` | F(int) | `5` | Timeout (seconds) for the remote OG-image dimension probe. |
| `nexter_content_seo_og_image_dim_ttl` | F(int) | `DAY_IN_SECONDS` | Transient TTL for cached OG-image dimensions. |

## Image SEO (`class-seo-image.php`)

| Hook | Type | Default | Purpose |
|------|------|---------|---------|
| `nexter_content_seo_image_content_processing_enabled` | F(bool `$from_option`) | option value | Master switch for rewriting `<img>` tags in content. |
| `nexter_content_seo_image_seo_enable_title` | F(bool) | `false` | Whether to also add a `title` attribute to images. |
| `nexter_content_seo_image_seo_alt_template` | F(string) | `'%filename%'` | Template for generated `alt` text. |
| `nexter_content_seo_image_seo_title_template` | F(string) | `'%title%'` | Template for generated `title` text. |
| `nexter_content_seo_image_seo_alt_is_descriptive` | F(bool, string `$alt`, string `$filename`, object `$context`) | heuristic | Whether a generated `alt` is descriptive enough to inject (filters out `IMG_1234`, hashes, dimensions). |
| `nexter_content_seo_image_seo_enhancements` | F(array `$enhancements`, array `$attributes`, string `$src`, object `$context`) | needed attrs | The attribute → template map applied to an image. |
| `nexter_content_seo_image_seo_resolved_text` | F(string `$resolved`, string `$template`, object `$context`, string `$filename`) | resolved | Final resolved alt/title text after token substitution. |
| `nexter_content_seo_image_seo_variable_map` | F(array `$vars`, object `$context`, string `$filename`) | var map | Token → value map for image alt/title templates. |
| `nexter_content_seo_image_seo_bool_keys` | F(array) | bool key list | Which image-optimize option keys are treated as booleans on save. |

## Sitemaps (`class-seo-sitemap.php`)

| Hook | Type | Default | Purpose |
|------|------|---------|---------|
| `nexter_content_seo_sitemap_excluded_post_types` | F(array `$excluded`, array `$options`) | configured | Post types excluded from the XML sitemap. |
| `nexter_content_seo_sitemap_links_per_page` | F(int) | `1000` | URLs per sitemap file (clamped to the 50,000 protocol ceiling). |
| `nexter_content_seo_sitemap_cache_ttl` | F(int) | `12 * HOUR_IN_SECONDS` | Cache TTL for generated sitemap output. |
| `nexter_content_seo_sitemap_stylesheet_url` | F(string) | `home_url('/sitemap.xsl')` | URL of the human-readable sitemap XSLT stylesheet. |

## Instant Indexing — IndexNow (`class-seo-indexing.php`)

| Hook | Type | Default | Purpose |
|------|------|---------|---------|
| `nexter_content_seo_output_verification_meta` | F(bool) | `true` | Return `false` to suppress site-verification `<meta>` tags. |
| `nexter_content_seo_indexnow_rl_window` | F(int) | `5 * MINUTE_IN_SECONDS` | Rate-limit sliding window for bulk IndexNow submissions. |
| `nexter_content_seo_indexnow_rl_max_requests` | F(int) | `10` | Max bulk submissions per user per window. |
| `nexter_content_seo_indexnow_rl_max_urls` | F(int) | `1000` | Max URLs per user per window. |

## LLMs.txt (`class-seo-llms.php`)

| Hook | Type | Default | Purpose |
|------|------|---------|---------|
| `nexter_content_seo_llms_txt_body` | F(string `$body`, array `$options`) | generated | The full rendered `llms.txt` body before output. |

## Redirects (`class-redirection.php`)

| Hook | Type | Signature | Purpose |
|------|------|-----------|---------|
| `nexter_content_seo_before_redirect` | **A** | `($rule, $to, $status, $path)` | Fires immediately before a configured redirect (or 410/451 Gone; `$to` is `''` for Gone). |
| `nexter_content_seo_max_redirect_rules` | F(int) | `2000` | Maximum number of stored redirect rules. |

## Options / Import-Export (`class-content-seo.php`)

| Hook | Type | Signature / Default | Purpose |
|------|------|---------------------|---------|
| `nexter_content_seo_default_options` | F(array) | `default_options()` | Default options for a fresh install. |
| `nexter_content_seo_migrate_options` | F(array `$opts`, int `$from`, int `$to`) | — | Hook into the options data-version migration. |
| `nexter_content_seo_export_include_secrets` | F(bool) | `false` | Whether the settings export bundle includes credentials/secrets. |

---

## Usage examples

**Add a custom node to the JSON-LD `@graph`:**
```php
add_filter( 'nexter_content_seo_schema_graph', function ( $nodes ) {
    $nodes[] = array(
        '@type' => 'Organization',
        'name'  => 'My Brand',
        'url'   => home_url( '/' ),
    );
    return $nodes;
} );
```

**Raise the meta-description cap (or remove it):**
```php
add_filter( 'nexter_content_seo_max_description_length', function () {
    return 500; // return 0 to disable truncation entirely
} );
```

**Exclude a post type from the sitemap:**
```php
add_filter( 'nexter_content_seo_sitemap_excluded_post_types', function ( $excluded ) {
    $excluded[] = 'my_cpt';
    return $excluded;
} );
```

**Run code just before a redirect fires (e.g. logging):**
```php
add_action( 'nexter_content_seo_before_redirect', function ( $rule, $to, $status, $path ) {
    error_log( "Nexter SEO redirect: {$path} -> {$to} ({$status})" );
}, 10, 4 );
```

**Force the Twitter card to always stay large, even for unmeasurable images:**
```php
add_filter( 'nexter_content_seo_large_card_assume_when_unknown', '__return_true' );
```

**Supply a description for a custom page builder:**
```php
add_filter( 'nexter_content_seo_builder_description', function ( $desc, $post ) {
    if ( '' !== $desc ) {
        return $desc; // respect an earlier source
    }
    return my_builder_extract_text( $post->ID );
}, 10, 2 );
```

---

*Every hook above is also documented inline at its `apply_filters()` / `do_action()` call site with a
WP-core-style docblock (`@param` descriptions). This file is the consolidated external reference.*
