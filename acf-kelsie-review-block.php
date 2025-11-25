<?php
/**
 * Plugin Name: Kelsie ACF Reviews Block
 * Description: ACF block for displaying Reviews repeater content with optional Rank Math schema integration.
 * Version:     1.1.8
 * Author:      Kelsie Cakes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** ---------------------------
 *  CONFIG (edit in one place)
 * --------------------------- */
define( 'KELSIE_BLOCK_DIR', __DIR__ . '/blocks/kelsie-review' );
define( 'KELSIE_BLOCK_NAME', 'kelsiecakes/review-list' );    // block.json "name"

define( 'KELSIE_REVIEW_REPEATER', 'client_testimonials' );      // repeater
define( 'KELSIE_REVIEW_BODY', 'review_body' );                  // sub field (Text Area)
define( 'KELSIE_REVIEWER_NAME', 'reviewer_name' );              // sub field (Text)
define( 'KELSIE_REVIEW_ID', 'review_id' );                      // sub field (Text/ID, schema only)
define( 'KELSIE_REVIEW_SAMEAS', 'review_original_location' );   // sub field (URL, schema only)
define( 'KELSIE_REVIEW_RATING', 'rating_number' );              // sub field (Number, schema only)
define( 'KELSIE_REVIEW_TITLE', 'review_title' );                // sub field (Text, optional frontend)



define( 'KELSIE_OPTIONS_ID', 'option' );          // ACF Options Page id
define( 'KELSIE_SCHEMA_KEY', 'kelsie_reviews' );  // array key in Rank Math graph

add_action( 'admin_init', function () {
    if ( ! class_exists( 'ACF' ) && current_user_can( 'activate_plugins' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Kelsie ACF Reviews Block:</strong> ACF is inactive. The block will show a placeholder until ACF is active.</p></div>';
        } );
    }
} );

function kelsie_register_review_block() {
    if ( ! function_exists( 'acf_register_block_type' ) ) {
        return;
    }

    $block_json_path = KELSIE_BLOCK_DIR . '/block.json';
    $acf_version     = function_exists( 'acf_version' ) ? acf_version() : '';

    if ( $acf_version && version_compare( $acf_version, '6.1', '>=' ) ) {
        acf_register_block_type( $block_json_path );
        return;
    }

    if ( ! file_exists( $block_json_path ) ) {
        return;
    }

    $block_settings = json_decode( file_get_contents( $block_json_path ), true );

    if ( ! is_array( $block_settings ) ) {
        return;
    }

    $render_template = '';

    if ( ! empty( $block_settings['acf']['renderTemplate'] ) ) {
        $render_template = KELSIE_BLOCK_DIR . '/' . ltrim( $block_settings['acf']['renderTemplate'], '/' );
    }

    $compat_settings = array_filter(
        [
            'name'            => ! empty( $block_settings['name'] ) ? $block_settings['name'] : KELSIE_BLOCK_NAME,
            'title'           => $block_settings['title'] ?? '',
            'description'     => $block_settings['description'] ?? '',
            'category'        => $block_settings['category'] ?? '',
            'icon'            => $block_settings['icon'] ?? '',
            'keywords'        => $block_settings['keywords'] ?? [],
            'supports'        => $block_settings['supports'] ?? [],
            'style'           => $block_settings['style'] ?? '',
            'editor_style'    => $block_settings['editorStyle'] ?? '',
            'mode'            => $block_settings['acf']['mode'] ?? 'preview',
            'render_template' => $render_template ?: null,
            'example'         => $block_settings['example'] ?? [],
            'api_version'     => $block_settings['apiVersion'] ?? 2,
        ],
        static function ( $value ) {
            return null !== $value && '' !== $value;
        }
    );

    acf_register_block_type( $compat_settings );
}

add_action( 'acf/init', 'kelsie_register_review_block', 5 );

add_action( 'init', function () {
    // Styles referenced by block.json
    $style_path        = plugin_dir_path( __FILE__ ) . 'assets/style.css';
    $editor_style_path = plugin_dir_path( __FILE__ ) . 'assets/editor.css';

    wp_register_style(
        'kelsie-review-block',
        plugins_url( 'assets/style.css', __FILE__ ),
        [],
        file_exists( $style_path ) ? filemtime( $style_path ) : null
    );

    wp_register_style(
        'kelsie-review-block-editor',
        plugins_url( 'assets/editor.css', __FILE__ ),
        [],
        file_exists( $editor_style_path ) ? filemtime( $editor_style_path ) : null
    );
});

/**
 * Build the base URL for schema anchors.
 *
 * @param int|string $context_id Post ID or 'option'.
 *
 * @return string
 */
function kelsie_get_review_base_url( $context_id ) {
    $base_url = '';

    if ( $context_id && 'option' !== $context_id ) {
        $base_url = get_permalink( $context_id );
    }

    if ( ! $base_url ) {
        $base_url = home_url( '/' );
    }

    return $base_url;
}

/**
 * Build the itemReviewed object for JSON-LD output.
 *
 * Prefers business option fields when available, falling back to site info.
 *
 * @param int|string $context_id Post ID or 'option'.
 *
 * @return array
 */
function kelsie_get_item_reviewed_schema( $context_id ) {
    $base_url = kelsie_get_review_base_url( $context_id );

    $site_name = wp_strip_all_tags( get_bloginfo( 'name' ) );
    $site_url  = home_url( '/' );

    $business_name = '';
    $business_url  = '';
    $address       = [];

    if ( function_exists( 'get_field' ) ) {
        $business_name = get_field( 'business_name', KELSIE_OPTIONS_ID );
        $business_url  = get_field( 'business_url', KELSIE_OPTIONS_ID );
        $address       = [
            'streetAddress'   => get_field( 'business_street', KELSIE_OPTIONS_ID ),
            'addressLocality' => get_field( 'business_city', KELSIE_OPTIONS_ID ),
            'addressRegion'   => get_field( 'business_region', KELSIE_OPTIONS_ID ),
            'postalCode'      => get_field( 'business_postcode', KELSIE_OPTIONS_ID ),
            'addressCountry'  => get_field( 'business_country', KELSIE_OPTIONS_ID ),
        ];
    }

    $name = $business_name ? wp_strip_all_tags( $business_name ) : $site_name;
    $url  = $business_url ? esc_url_raw( $business_url ) : $site_url;

    $clean_address = array_filter(
        array_map(
            static function ( $value ) {
                return is_string( $value ) ? trim( $value ) : '';
            },
            $address
        ),
        static function ( $value ) {
            return '' !== $value;
        }
    );

    $schema = [
        '@type' => ! empty( $clean_address ) ? 'LocalBusiness' : 'Organization',
        '@id'   => esc_url_raw( untrailingslashit( $base_url ) . '#item' ),
        'name'  => $name,
        'url'   => $url,
    ];

    if ( 'LocalBusiness' === $schema['@type'] && ! empty( $clean_address ) ) {
        $schema['address'] = array_merge( [ '@type' => 'PostalAddress' ], $clean_address );
    }

    return $schema;
}


/** ---------------------------
 *  Rank Math integration (optional)
 * --------------------------- */
add_action( 'plugins_loaded', function () {
    if ( ! defined( 'RANK_MATH_VERSION' ) ) {
        return;
    }

    add_filter( 'rank_math/json_ld', function ( $data, $jsonld ) {
        if ( ! is_singular() ) {
            return $data;
        }

        global $post;
        if ( ! $post || ! function_exists( 'has_block' ) || ! has_block( KELSIE_BLOCK_NAME, $post ) ) {
            return $data;
        }
        if ( ! function_exists( 'have_rows' ) ) {
            return $data; // ACF off
        }

        // Prefer per-post rows; fall back to Options Page.
        $source = null;
        if ( have_rows( KELSIE_REVIEW_REPEATER, $post->ID ) ) {
            $source = [ KELSIE_REVIEW_REPEATER, $post->ID ];
        } elseif ( have_rows( KELSIE_REVIEW_REPEATER, KELSIE_OPTIONS_ID ) ) {
            $source = [ KELSIE_REVIEW_REPEATER, KELSIE_OPTIONS_ID ];
        } else {
            return $data;
        }

        $item_reviewed = kelsie_get_item_reviewed_schema( $post->ID );
        $base_url      = kelsie_get_review_base_url( $post->ID );

        $reviews = [];

        while ( have_rows( $source[0], $source[1] ) ) {
            the_row();
            $body     = trim( wp_strip_all_tags( wpautop( get_sub_field( KELSIE_REVIEW_BODY ) ) ) );
            $reviewer = trim( wp_strip_all_tags( get_sub_field( KELSIE_REVIEWER_NAME ) ) );
            if ( ! $body || ! $reviewer ) {
                continue;
            }

            $title     = trim( wp_strip_all_tags( get_sub_field( KELSIE_REVIEW_TITLE ) ) );
            $rating    = get_sub_field( KELSIE_REVIEW_RATING );
            $same_as   = esc_url_raw( get_sub_field( KELSIE_REVIEW_SAMEAS ) );
            $review_id = get_sub_field( KELSIE_REVIEW_ID );

            $review = [
                '@type'        => 'Review',
                'reviewBody'   => $body,
                'author'       => [
                    '@type' => 'Person',
                    'name'  => $reviewer,
                ],
                'itemReviewed' => $item_reviewed,
            ];

            if ( $title ) {
                $review['name'] = $title;
            }

            $rating_value = is_numeric( $rating ) ? (float) $rating : null;
            if ( null !== $rating_value ) {
                $rating_value            = max( 0, min( 5, $rating_value ) );
                $review['reviewRating'] = [
                    '@type'       => 'Rating',
                    'ratingValue' => $rating_value,
                    'bestRating'  => 5,
                    'worstRating' => 0,
                ];
            }

            if ( $same_as ) {
                $review['sameAs'] = $same_as;
            }

            if ( $review_id ) {
                $review['@id'] = esc_url_raw( untrailingslashit( $base_url ) . '#review-' . sanitize_title( $review_id ) );
            }

            $reviews[] = $review;
        }

        if ( ! empty( $reviews ) ) {
            foreach ( array_values( $reviews ) as $index => $review ) {
                $data[ KELSIE_SCHEMA_KEY . '_' . ( $index + 1 ) ] = $review; // append, don’t overwrite
            }
        }

        return $data;
    }, 20, 2 );
} );
