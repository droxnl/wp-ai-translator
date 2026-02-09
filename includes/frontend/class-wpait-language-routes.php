<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_Language_Routes {
    const QUERY_VAR_LANGUAGE = 'wpait_language';
    const QUERY_VAR_SLUG     = 'wpait_slug';
    const QUERY_VAR_HOME     = 'wpait_home';

    public static function register() {
        add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
        add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'filter_language_requests' ) );
        add_filter( 'page_link', array( __CLASS__, 'filter_page_link' ), 10, 2 );
    }

    public static function register_rewrites() {
        $settings  = WPAIT_Settings::get_settings();
        $languages = array_diff( (array) $settings['languages'], array( $settings['default_language'] ) );

        if ( empty( $languages ) ) {
            return;
        }

        $language_pattern = implode( '|', array_map( 'preg_quote', $languages ) );

        add_rewrite_rule(
            '^(' . $language_pattern . ')/?$',
            'index.php?post_type=page&' . self::QUERY_VAR_LANGUAGE . '=$matches[1]&' . self::QUERY_VAR_HOME . '=1',
            'top'
        );

        add_rewrite_rule(
            '^(' . $language_pattern . ')/(.+?)/?$',
            'index.php?post_type=page&' . self::QUERY_VAR_LANGUAGE . '=$matches[1]&' . self::QUERY_VAR_SLUG . '=$matches[2]',
            'top'
        );
    }

    public static function register_query_vars( $vars ) {
        $vars[] = self::QUERY_VAR_LANGUAGE;
        $vars[] = self::QUERY_VAR_SLUG;
        $vars[] = self::QUERY_VAR_HOME;
        return $vars;
    }

    public static function filter_language_requests( $query ) {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $language = $query->get( self::QUERY_VAR_LANGUAGE );
        if ( empty( $language ) ) {
            return;
        }

        $settings = WPAIT_Settings::get_settings();
        if ( $language === $settings['default_language'] ) {
            return;
        }

        $query->set( 'post_type', 'page' );

        if ( $query->get( self::QUERY_VAR_HOME ) ) {
            $front_page_id = (int) get_option( 'page_on_front' );
            if ( $front_page_id ) {
                $group = get_post_meta( $front_page_id, '_wpait_translation_group', true );
                if ( $group ) {
                    $translation = get_posts(
                        array(
                            'post_type'   => 'page',
                            'numberposts' => 1,
                            'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
                            'meta_query'  => array(
                                array(
                                    'key'   => '_wpait_translation_group',
                                    'value' => $group,
                                ),
                                array(
                                    'key'   => '_wpait_language',
                                    'value' => $language,
                                ),
                            ),
                        )
                    );

                    if ( ! empty( $translation ) ) {
                        $query->set( 'page_id', $translation[0]->ID );
                        return;
                    }
                }
            }
        }

        $slug = $query->get( self::QUERY_VAR_SLUG );
        if ( empty( $slug ) ) {
            return;
        }

        $meta_query   = (array) $query->get( 'meta_query' );
        $meta_query[] = array(
            'key'   => '_wpait_language',
            'value' => sanitize_text_field( $language ),
        );
        $meta_query[] = array(
            'key'   => '_wpait_base_slug',
            'value' => sanitize_text_field( $slug ),
        );

        $query->set( 'meta_query', $meta_query );
    }

    public static function filter_page_link( $permalink, $post_id ) {
        $settings = WPAIT_Settings::get_settings();
        $language = get_post_meta( $post_id, '_wpait_language', true );

        if ( empty( $language ) || $language === $settings['default_language'] ) {
            return $permalink;
        }

        $base_slug = get_post_meta( $post_id, '_wpait_base_slug', true );
        if ( empty( $base_slug ) ) {
            $post      = get_post( $post_id );
            $base_slug = $post ? $post->post_name : '';
            if ( $base_slug ) {
                $base_slug = preg_replace( '/^' . preg_quote( $language, '/' ) . '[-_]/i', '', $base_slug );
            }
        }

        $front_page_id = (int) get_option( 'page_on_front' );
        if ( $front_page_id ) {
            $front_group = get_post_meta( $front_page_id, '_wpait_translation_group', true );
            $group       = get_post_meta( $post_id, '_wpait_translation_group', true );
            if ( $front_group && $group && $front_group === $group ) {
                return home_url( user_trailingslashit( $language ) );
            }
        }

        if ( empty( $base_slug ) ) {
            return $permalink;
        }

        $path = $language . '/' . ltrim( $base_slug, '/' );
        return home_url( user_trailingslashit( $path ) );
    }
}
