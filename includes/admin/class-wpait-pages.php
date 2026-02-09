<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_Pages {
    const SALIENT_POST_TYPE = 'salient_g_sections';

    public static function register() {
        foreach ( self::get_supported_post_types() as $post_type ) {
            add_filter( "manage_edit-{$post_type}_columns", array( __CLASS__, 'add_columns' ) );
            add_action( "manage_{$post_type}_posts_custom_column", array( __CLASS__, 'render_columns' ), 10, 2 );
            add_filter( "views_edit-{$post_type}", array( __CLASS__, 'add_language_views' ) );
        }
        add_action( 'restrict_manage_posts', array( __CLASS__, 'add_language_filter' ) );
        add_filter( 'parse_query', array( __CLASS__, 'filter_by_language' ) );
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
            $settings  = WPAIT_Settings::get_settings();
            $languages = WPAIT_Settings::get_available_languages();
            $code      = $language ? $language : $settings['default_language'];
            $label     = isset( $languages[ $code ] ) ? $languages[ $code ]['label'] : strtoupper( $code );
            $flag      = isset( $languages[ $code ]['flag'] ) ? $languages[ $code ]['flag'] : '';
            $flag_url  = $flag ? esc_url( WPAIT_PLUGIN_URL . 'assets/flags/' . $flag ) : '';

            echo '<span class="wpait-language-cell">';
            if ( $flag_url ) {
                printf(
                    '<span class="wpait-language-cell__flag"><img src="%1$s" alt="%2$s" /></span>',
                    $flag_url,
                    esc_attr( $label )
                );
            }
            echo esc_html( sprintf( '%1$s (%2$s)', $label, strtoupper( $code ) ) );
            echo '</span>';
        }

        if ( 'wpait_translations' === $column ) {
            $group = get_post_meta( $post_id, '_wpait_translation_group', true );
            if ( ! $group ) {
                echo esc_html__( 'No translations', 'wp-ai-translator' );
                return;
            }
            $translations = get_posts(
                array(
                    'post_type'   => get_post_type( $post_id ),
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
        if ( ! self::is_supported_post_type( $post_type ) ) {
            return;
        }
        $label = self::get_post_type_label( $post_type, 'name' );
        echo '<button type="button" class="button wpait-translate-selected" id="wpait-translate-selected">' . esc_html( sprintf( __( 'Translate selected %s', 'wp-ai-translator' ), $label ) ) . '</button>';
    }

    public static function add_language_views( $views ) {
        $screen = get_current_screen();
        if ( ! $screen || ! self::is_supported_post_type( $screen->post_type ) ) {
            return $views;
        }

        $settings  = WPAIT_Settings::get_settings();
        $languages = WPAIT_Settings::get_available_languages();
        $current   = isset( $_GET['wpait_language'] ) ? sanitize_text_field( wp_unslash( $_GET['wpait_language'] ) ) : '';
        $counts    = self::get_language_counts( $settings['languages'], $settings['default_language'], $screen->post_type );
        $base_url  = remove_query_arg( array( 'wpait_language', 'paged' ) );

        $language_views = array();
        $language_views['wpait_language_all'] = sprintf(
            '<a href="%1$s" class="%2$s">%3$s</a>',
            esc_url( $base_url ),
            esc_attr( $current ? '' : 'current' ),
            esc_html( sprintf( __( 'All Languages (%d)', 'wp-ai-translator' ), $counts['all'] ) )
        );

        foreach ( $settings['languages'] as $language ) {
            if ( ! isset( $languages[ $language ] ) ) {
                continue;
            }
            $label = $languages[ $language ]['label'];
            $count = $counts['languages'][ $language ] ?? 0;
            $language_views[ 'wpait_language_' . $language ] = sprintf(
                '<a href="%1$s" class="%2$s">%3$s</a>',
                esc_url( add_query_arg( 'wpait_language', $language, $base_url ) ),
                esc_attr( $current === $language ? 'current' : '' ),
                esc_html( sprintf( '%1$s (%2$d)', $label, $count ) )
            );
        }

        return array_merge( $views, $language_views );
    }

    public static function filter_by_language( $query ) {
        if ( ! is_admin() || 'edit.php' !== $GLOBALS['pagenow'] ) {
            return $query;
        }
        if ( empty( $_GET['post_type'] ) || ! self::is_supported_post_type( $_GET['post_type'] ) ) {
            return $query;
        }
        if ( empty( $_GET['wpait_language'] ) ) {
            return $query;
        }
        $language = sanitize_text_field( wp_unslash( $_GET['wpait_language'] ) );
        $settings = WPAIT_Settings::get_settings();
        if ( $language === $settings['default_language'] ) {
            $query->set(
                'meta_query',
                array(
                    array(
                        'key'     => '_wpait_language',
                        'compare' => 'NOT EXISTS',
                    ),
                )
            );
            return $query;
        }
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

    public static function add_row_action( $actions, $post ) {
        if ( ! self::is_supported_post_type( $post->post_type ) ) {
            return $actions;
        }
        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'wpait_action' => 'translate',
                    'post_id'      => $post->ID,
                ),
                admin_url( 'edit.php?post_type=' . $post->post_type )
            ),
            'wpait_translate_post'
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
        check_admin_referer( 'wpait_translate_post' );
        $post_id  = absint( $_GET['post_id'] );
        $post_type = get_post_type( $post_id );
        if ( ! $post_type || ! self::is_supported_post_type( $post_type ) ) {
            return;
        }
        if ( ! self::current_user_can_translate( $post_type ) ) {
            return;
        }
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

        wp_safe_redirect( admin_url( 'edit.php?post_type=' . $post_type . '&wpait_queued=1' ) );
        exit;
    }

    public static function enqueue_assets( $hook ) {
        if ( 'edit.php' !== $hook ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! self::is_supported_post_type( $screen->post_type ) ) {
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
        $post_type_label = self::get_post_type_label( $screen->post_type, 'name' );
        $post_type_singular = self::get_post_type_label( $screen->post_type, 'singular_name' );
        wp_localize_script(
            'wpait-pages',
            'wpaitPages',
            array(
                'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
                'nonce'          => wp_create_nonce( 'wpait_pages' ),
                'languages'      => $available,
                'defaultLanguage' => $settings['default_language'],
                'postType'       => $screen->post_type,
                'postTypeLabel'  => $post_type_label,
                'postTypeSingularLabel' => $post_type_singular,
                'emptyQueueText' => __( 'No queued jobs yet.', 'wp-ai-translator' ),
                'emptySelectionText' => sprintf(
                    __( 'Select at least one %s.', 'wp-ai-translator' ),
                    strtolower( $post_type_singular )
                ),
                'clearedText'    => __( 'Translation history cleared.', 'wp-ai-translator' ),
            )
        );
    }

    public static function render_modal() {
        if ( 'edit.php' !== $GLOBALS['pagenow'] ) {
            return;
        }
        if ( empty( $_GET['post_type'] ) || ! self::is_supported_post_type( $_GET['post_type'] ) ) {
            return;
        }
        $post_type = sanitize_text_field( wp_unslash( $_GET['post_type'] ) );
        $label = self::get_post_type_label( $post_type, 'name' );
        $singular = self::get_post_type_label( $post_type, 'singular_name' );
        ?>
        <div class="wpait-modal" id="wpait-translate-modal" aria-hidden="true">
            <div class="wpait-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wpait-translate-title">
                <div class="wpait-modal__header">
                    <h2 id="wpait-translate-title"><?php echo esc_html( sprintf( __( 'Translate Selected %s', 'wp-ai-translator' ), $label ) ); ?></h2>
                    <button type="button" class="wpait-modal__close" aria-label="<?php esc_attr_e( 'Close', 'wp-ai-translator' ); ?>">×</button>
                </div>
                <div class="wpait-modal__body">
                    <p class="wpait-modal__intro"><?php echo esc_html( sprintf( __( 'Choose the languages you want to translate these %s into.', 'wp-ai-translator' ), strtolower( $label ) ) ); ?></p>
                    <div class="wpait-modal__languages" id="wpait-language-options"></div>
                    <div class="wpait-modal__progress">
                        <h3><?php esc_html_e( 'Translation Progress', 'wp-ai-translator' ); ?></h3>
                        <div class="wpait-modal__progress-table">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html( $singular ); ?></th>
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
                    <button type="button" class="button wpait-modal__clear"><?php esc_html_e( 'Clear History', 'wp-ai-translator' ); ?></button>
                    <button type="button" class="button button-secondary wpait-modal__cancel"><?php esc_html_e( 'Cancel', 'wp-ai-translator' ); ?></button>
                    <button type="button" class="button button-primary wpait-modal__confirm"><?php esc_html_e( 'Start Translation', 'wp-ai-translator' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    public static function ajax_enqueue_translations() {
        check_ajax_referer( 'wpait_pages', 'nonce' );
        $post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'page';
        if ( ! self::is_supported_post_type( $post_type ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post type.', 'wp-ai-translator' ) ) );
        }
        if ( ! self::current_user_can_translate( $post_type ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-ai-translator' ) ) );
        }
        $post_ids  = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) $_POST['post_ids'] ) : array();
        $languages = isset( $_POST['languages'] ) ? array_map( 'sanitize_text_field', (array) $_POST['languages'] ) : array();
        $post_ids  = array_filter(
            array_unique( $post_ids ),
            function ( $post_id ) use ( $post_type ) {
                return $post_id && get_post_type( $post_id ) === $post_type;
            }
        );
        $settings  = WPAIT_Settings::get_settings();
        $allowed   = array_diff( (array) $settings['languages'], array( $settings['default_language'] ) );
        $languages = array_values( array_intersect( $languages, $allowed ) );
        $singular = self::get_post_type_label( $post_type, 'singular_name' );

        if ( empty( $post_ids ) || empty( $languages ) ) {
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        __( 'Select at least one %s and language.', 'wp-ai-translator' ),
                        strtolower( $singular )
                    ),
                )
            );
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
                'queue'  => WPAIT_Queue::get_queue_payload(),
            )
        );
    }

    public static function ajax_get_queue() {
        check_ajax_referer( 'wpait_pages', 'nonce' );
        $post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'page';
        if ( ! self::is_supported_post_type( $post_type ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post type.', 'wp-ai-translator' ) ) );
        }
        if ( ! self::current_user_can_translate( $post_type ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-ai-translator' ) ) );
        }
        wp_send_json_success(
            array(
                'queue' => WPAIT_Queue::get_queue_payload(),
            )
        );
    }

    private static function get_language_counts( $languages, $default_language, $post_type ) {
        $counts = array(
            'all'       => 0,
            'languages' => array(),
        );
        $total_counts = (array) wp_count_posts( $post_type );
        $counts['all'] = array_sum( array_map( 'intval', $total_counts ) );

        foreach ( (array) $languages as $language ) {
            $query_args = array(
                'post_type'      => $post_type,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => 1,
                'no_found_rows'  => false,
                'meta_query'     => array(
                    array(
                        'key'   => '_wpait_language',
                        'value' => $language,
                    ),
                ),
            );

            if ( $language === $default_language ) {
                $query_args['meta_query'][0] = array(
                    'key'     => '_wpait_language',
                    'compare' => 'NOT EXISTS',
                );
            }

            $query = new WP_Query( $query_args );
            $counts['languages'][ $language ] = (int) $query->found_posts;
        }

        return $counts;
    }

    private static function get_supported_post_types() {
        return array( 'page', self::SALIENT_POST_TYPE );
    }

    private static function is_supported_post_type( $post_type ) {
        return in_array( $post_type, self::get_supported_post_types(), true );
    }

    private static function get_post_type_label( $post_type, $label_key = 'name' ) {
        $post_type_object = get_post_type_object( $post_type );
        if ( $post_type_object && isset( $post_type_object->labels ) && isset( $post_type_object->labels->{$label_key} ) ) {
            return $post_type_object->labels->{$label_key};
        }
        if ( 'singular_name' === $label_key ) {
            return ucfirst( $post_type );
        }
        return ucfirst( $post_type );
    }

    private static function current_user_can_translate( $post_type ) {
        $post_type_object = get_post_type_object( $post_type );
        $capability = $post_type_object && isset( $post_type_object->cap->edit_posts )
            ? $post_type_object->cap->edit_posts
            : 'edit_posts';

        return current_user_can( $capability );
    }
}
