<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_Pages {
    public static function register() {
        add_filter( 'manage_edit-page_columns', array( __CLASS__, 'add_columns' ) );
        add_action( 'manage_page_posts_custom_column', array( __CLASS__, 'render_columns' ), 10, 2 );
        add_action( 'restrict_manage_posts', array( __CLASS__, 'add_language_filter' ) );
        add_filter( 'parse_query', array( __CLASS__, 'filter_by_language' ) );
        add_filter( 'bulk_actions-edit-page', array( __CLASS__, 'register_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-edit-page', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
        add_filter( 'post_row_actions', array( __CLASS__, 'add_row_action' ), 10, 2 );
        add_action( 'admin_init', array( __CLASS__, 'handle_row_action' ) );
    }

    public static function add_columns( $columns ) {
        $columns['wpait_language']     = __( 'Language', 'wp-ai-translator' );
        $columns['wpait_translations'] = __( 'Translations', 'wp-ai-translator' );
        return $columns;
    }

    public static function render_columns( $column, $post_id ) {
        if ( 'wpait_language' === $column ) {
            $language = get_post_meta( $post_id, '_wpait_language', true );
            echo $language ? esc_html( strtoupper( $language ) ) : esc_html__( 'Default', 'wp-ai-translator' );
        }

        if ( 'wpait_translations' === $column ) {
            $group = get_post_meta( $post_id, '_wpait_translation_group', true );
            if ( ! $group ) {
                echo esc_html__( 'No translations', 'wp-ai-translator' );
                return;
            }
            $translations = get_posts(
                array(
                    'post_type'   => 'page',
                    'meta_key'    => '_wpait_translation_group',
                    'meta_value'  => $group,
                    'numberposts' => -1,
                    'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
                )
            );
            $labels = array();
            foreach ( $translations as $translation ) {
                $lang = get_post_meta( $translation->ID, '_wpait_language', true );
                if ( $lang ) {
                    $labels[] = strtoupper( $lang );
                }
            }
            echo $labels ? esc_html( implode( ', ', $labels ) ) : esc_html__( 'No translations', 'wp-ai-translator' );
        }
    }

    public static function add_language_filter( $post_type ) {
        if ( 'page' !== $post_type ) {
            return;
        }
        $settings  = WPAIT_Settings::get_settings();
        $languages = WPAIT_Settings::get_available_languages();
        $current   = isset( $_GET['wpait_language'] ) ? sanitize_text_field( wp_unslash( $_GET['wpait_language'] ) ) : '';

        echo '<select name="wpait_language">';
        echo '<option value="">' . esc_html__( 'All Languages', 'wp-ai-translator' ) . '</option>';
        foreach ( $settings['languages'] as $language ) {
            if ( ! isset( $languages[ $language ] ) ) {
                continue;
            }
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr( $language ),
                selected( $current, $language, false ),
                esc_html( $languages[ $language ]['label'] )
            );
        }
        echo '</select>';
    }

    public static function filter_by_language( $query ) {
        if ( ! is_admin() || 'edit.php' !== $GLOBALS['pagenow'] ) {
            return $query;
        }
        if ( empty( $_GET['post_type'] ) || 'page' !== $_GET['post_type'] ) {
            return $query;
        }
        if ( empty( $_GET['wpait_language'] ) ) {
            return $query;
        }
        $language = sanitize_text_field( wp_unslash( $_GET['wpait_language'] ) );
        $query->set(
            'meta_query',
            array(
                array(
                    'key'   => '_wpait_language',
                    'value' => $language,
                ),
            )
        );
        return $query;
    }

    public static function register_bulk_actions( $actions ) {
        $actions['wpait_translate'] = __( 'Duplicate & Translate', 'wp-ai-translator' );
        return $actions;
    }

    public static function handle_bulk_actions( $redirect_to, $doaction, $post_ids ) {
        if ( 'wpait_translate' !== $doaction ) {
            return $redirect_to;
        }
        $settings  = WPAIT_Settings::get_settings();
        $languages = array_diff( (array) $settings['languages'], array( 'en' ) );
        $queued    = 0;

        foreach ( $post_ids as $post_id ) {
            foreach ( $languages as $language ) {
                if ( WPAIT_Translator::has_translation( $post_id, $language ) ) {
                    continue;
                }
                WPAIT_Queue::enqueue(
                    array(
                        'post_id'         => $post_id,
                        'target_language' => $language,
                        'status'          => 'pending',
                        'message'         => __( 'Queued for translation.', 'wp-ai-translator' ),
                    )
                );
                $queued++;
            }
        }

        return add_query_arg( 'wpait_queued', $queued, $redirect_to );
    }

    public static function add_row_action( $actions, $post ) {
        if ( 'page' !== $post->post_type ) {
            return $actions;
        }
        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'wpait_action' => 'translate',
                    'post_id'      => $post->ID,
                ),
                admin_url( 'edit.php?post_type=page' )
            ),
            'wpait_translate_page'
        );
        $actions['wpait_translate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Translate', 'wp-ai-translator' ) . '</a>';
        return $actions;
    }

    public static function handle_row_action() {
        if ( ! isset( $_GET['wpait_action'], $_GET['post_id'] ) ) {
            return;
        }
        if ( 'translate' !== $_GET['wpait_action'] ) {
            return;
        }
        check_admin_referer( 'wpait_translate_page' );
        $post_id  = absint( $_GET['post_id'] );
        $settings = WPAIT_Settings::get_settings();
        $languages = array_diff( (array) $settings['languages'], array( 'en' ) );

        foreach ( $languages as $language ) {
            if ( WPAIT_Translator::has_translation( $post_id, $language ) ) {
                continue;
            }
            WPAIT_Queue::enqueue(
                array(
                    'post_id'         => $post_id,
                    'target_language' => $language,
                    'status'          => 'pending',
                    'message'         => __( 'Queued for translation.', 'wp-ai-translator' ),
                )
            );
        }

        wp_safe_redirect( admin_url( 'edit.php?post_type=page&wpait_queued=1' ) );
        exit;
    }
}
