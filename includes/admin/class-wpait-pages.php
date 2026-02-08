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
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_footer', array( __CLASS__, 'render_modal' ) );
        add_action( 'wp_ajax_wpait_enqueue_translations', array( __CLASS__, 'ajax_enqueue_translations' ) );
        add_action( 'wp_ajax_wpait_get_queue', array( __CLASS__, 'ajax_get_queue' ) );
    }

    public static function add_columns( $columns ) {
        $columns['wpait_language']     = __( 'Language', 'wp-ai-translator' );
        $columns['wpait_translations'] = __( 'Translations', 'wp-ai-translator' );
        return $columns;
    }

    public static function render_columns( $column, $post_id ) {
        if ( 'wpait_language' === $column ) {
            $language = get_post_meta( $post_id, '_wpait_language', true );
            if ( $language ) {
                echo esc_html( strtoupper( $language ) );
            } else {
                $settings  = WPAIT_Settings::get_settings();
                $languages = WPAIT_Settings::get_available_languages();
                $default   = $settings['default_language'];
                $label     = isset( $languages[ $default ] ) ? $languages[ $default ]['label'] : strtoupper( $default );
                echo esc_html( sprintf( '%1$s (%2$s)', $label, strtoupper( $default ) ) );
            }
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
        $languages = array_diff( (array) $settings['languages'], array( $settings['default_language'] ) );
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
        $languages = array_diff( (array) $settings['languages'], array( $settings['default_language'] ) );

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

    public static function enqueue_assets( $hook ) {
        if ( 'edit.php' !== $hook ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || 'page' !== $screen->post_type ) {
            return;
        }
        wp_enqueue_style( 'wpait-pages', WPAIT_PLUGIN_URL . 'assets/pages.css', array(), WPAIT_VERSION );
        wp_enqueue_script( 'wpait-pages', WPAIT_PLUGIN_URL . 'assets/pages.js', array( 'jquery' ), WPAIT_VERSION, true );
        $settings  = WPAIT_Settings::get_settings();
        $languages = WPAIT_Settings::get_available_languages();
        $available = array();
        foreach ( $settings['languages'] as $language ) {
            if ( $settings['default_language'] === $language ) {
                continue;
            }
            if ( isset( $languages[ $language ] ) ) {
                $available[] = array(
                    'code'  => $language,
                    'label' => $languages[ $language ]['label'],
                );
            }
        }
        wp_localize_script(
            'wpait-pages',
            'wpaitPages',
            array(
                'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
                'nonce'          => wp_create_nonce( 'wpait_pages' ),
                'languages'      => $available,
                'defaultLanguage' => $settings['default_language'],
                'emptyQueueText' => __( 'No queued jobs yet.', 'wp-ai-translator' ),
                'emptySelectionText' => __( 'Select at least one page.', 'wp-ai-translator' ),
            )
        );
    }

    public static function render_modal() {
        if ( 'edit.php' !== $GLOBALS['pagenow'] ) {
            return;
        }
        if ( empty( $_GET['post_type'] ) || 'page' !== $_GET['post_type'] ) {
            return;
        }
        ?>
        <div class="wpait-modal" id="wpait-translate-modal" aria-hidden="true">
            <div class="wpait-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wpait-translate-title">
                <div class="wpait-modal__header">
                    <h2 id="wpait-translate-title"><?php esc_html_e( 'Translate Selected Pages', 'wp-ai-translator' ); ?></h2>
                    <button type="button" class="wpait-modal__close" aria-label="<?php esc_attr_e( 'Close', 'wp-ai-translator' ); ?>">×</button>
                </div>
                <div class="wpait-modal__body">
                    <p class="wpait-modal__intro"><?php esc_html_e( 'Choose the languages you want to translate these pages into.', 'wp-ai-translator' ); ?></p>
                    <div class="wpait-modal__languages" id="wpait-language-options"></div>
                    <div class="wpait-modal__progress">
                        <h3><?php esc_html_e( 'Translation Progress', 'wp-ai-translator' ); ?></h3>
                        <div class="wpait-modal__progress-table">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Page', 'wp-ai-translator' ); ?></th>
                                        <th><?php esc_html_e( 'Language', 'wp-ai-translator' ); ?></th>
                                        <th><?php esc_html_e( 'Status', 'wp-ai-translator' ); ?></th>
                                        <th><?php esc_html_e( 'Message', 'wp-ai-translator' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="wpait-queue-body"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="wpait-modal__notice" id="wpait-modal-notice" aria-live="polite"></div>
                </div>
                <div class="wpait-modal__footer">
                    <button type="button" class="button button-secondary wpait-modal__cancel"><?php esc_html_e( 'Cancel', 'wp-ai-translator' ); ?></button>
                    <button type="button" class="button button-primary wpait-modal__confirm"><?php esc_html_e( 'Start Translation', 'wp-ai-translator' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    public static function ajax_enqueue_translations() {
        check_ajax_referer( 'wpait_pages', 'nonce' );
        if ( ! current_user_can( 'edit_pages' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-ai-translator' ) ) );
        }
        $post_ids  = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) $_POST['post_ids'] ) : array();
        $languages = isset( $_POST['languages'] ) ? array_map( 'sanitize_text_field', (array) $_POST['languages'] ) : array();
        $post_ids  = array_filter( array_unique( $post_ids ) );
        $settings  = WPAIT_Settings::get_settings();
        $allowed   = array_diff( (array) $settings['languages'], array( $settings['default_language'] ) );
        $languages = array_values( array_intersect( $languages, $allowed ) );

        if ( empty( $post_ids ) || empty( $languages ) ) {
            wp_send_json_error( array( 'message' => __( 'Select at least one page and language.', 'wp-ai-translator' ) ) );
        }

        $queued = 0;
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

        wp_send_json_success(
            array(
                'queued' => $queued,
                'queue'  => self::get_queue_payload(),
            )
        );
    }

    public static function ajax_get_queue() {
        check_ajax_referer( 'wpait_pages', 'nonce' );
        if ( ! current_user_can( 'edit_pages' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-ai-translator' ) ) );
        }
        wp_send_json_success(
            array(
                'queue' => self::get_queue_payload(),
            )
        );
    }

    private static function get_queue_payload() {
        $queue = WPAIT_Queue::get_queue();
        $items = array();
        foreach ( $queue as $job ) {
            $items[] = array(
                'post_title' => get_the_title( $job['post_id'] ),
                'language'   => strtoupper( $job['target_language'] ),
                'status'     => ucfirst( $job['status'] ),
                'message'    => $job['message'] ?? '',
            );
        }
        return $items;
    }
}
