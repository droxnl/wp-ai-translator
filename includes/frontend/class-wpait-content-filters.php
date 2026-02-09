<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_Content_Filters {
    const SALIENT_POST_TYPE = 'salient_g_sections';

    public static function register() {
        add_action( 'pre_get_posts', array( __CLASS__, 'filter_salient_sections' ) );
    }

    public static function filter_salient_sections( $query ) {
        if ( is_admin() || ! ( $query instanceof WP_Query ) ) {
            return;
        }

        $post_type = $query->get( 'post_type' );
        if ( empty( $post_type ) ) {
            return;
        }

        if ( is_array( $post_type ) ) {
            if ( ! in_array( self::SALIENT_POST_TYPE, $post_type, true ) ) {
                return;
            }
        } elseif ( self::SALIENT_POST_TYPE !== $post_type ) {
            return;
        }

        if ( self::meta_query_has_language( $query ) ) {
            return;
        }

        $settings  = WPAIT_Settings::get_settings();
        $language  = self::get_active_language( $settings );
        $meta_query = (array) $query->get( 'meta_query' );
        $meta_query[] = self::build_language_meta_query( $language, $settings['default_language'] );
        $query->set( 'meta_query', $meta_query );
    }

    private static function get_active_language( $settings ) {
        $post_id  = get_queried_object_id();
        $language = $post_id ? get_post_meta( $post_id, '_wpait_language', true ) : '';

        if ( ! $language ) {
            $language = $settings['default_language'];
        }

        return $language;
    }

    private static function build_language_meta_query( $language, $default_language ) {
        if ( $language === $default_language ) {
            return array(
                'key'     => '_wpait_language',
                'compare' => 'NOT EXISTS',
            );
        }

        return array(
            'key'   => '_wpait_language',
            'value' => $language,
        );
    }

    private static function meta_query_has_language( $query ) {
        $meta_query = (array) $query->get( 'meta_query' );
        foreach ( $meta_query as $clause ) {
            if ( ! is_array( $clause ) ) {
                continue;
            }
            if ( isset( $clause['key'] ) && '_wpait_language' === $clause['key'] ) {
                return true;
            }
        }

        return false;
    }
}
