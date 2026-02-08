<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_Queue {
    const OPTION_KEY = 'wpait_queue';
    const LOG_PAGE_SLUG = 'wpait-logs';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'wpait_process_queue', array( __CLASS__, 'process_queue' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_wpait_clear_queue', array( __CLASS__, 'ajax_clear_queue' ) );
        add_action( 'wp_ajax_wpait_get_queue_logs', array( __CLASS__, 'ajax_get_queue_logs' ) );
    }

    public static function add_menu() {
        add_submenu_page(
            'wpait',
            __( 'Logs / History', 'wp-ai-translator' ),
            __( 'Logs / History', 'wp-ai-translator' ),
            'manage_options',
            self::LOG_PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function get_queue() {
        $queue = get_option( self::OPTION_KEY, array() );
        return self::prune_queue( $queue );
    }

    public static function update_queue( array $queue ) {
        update_option( self::OPTION_KEY, array_values( $queue ), false );
    }

    public static function enqueue( array $job ) {
        $queue   = self::get_queue();
        $job['id'] = uniqid( 'wpait_', true );
        if ( empty( $job['log'] ) ) {
            $job['log'] = array();
        }
        if ( ! empty( $job['message'] ) ) {
            self::append_log_entry( $job, $job['status'] ?? 'pending', $job['message'] );
        }
        $queue[] = $job;
        self::update_queue( $queue );
        return $job['id'];
    }

    public static function process_queue() {
        $queue = self::get_queue();
        foreach ( $queue as $index => $job ) {
            if ( 'pending' !== $job['status'] ) {
                continue;
            }
            $queue[ $index ]['status'] = 'running';
            self::append_log_entry( $queue[ $index ], 'running', __( 'Translation started.', 'wp-ai-translator' ) );
            self::update_queue( $queue );

            $result = WPAIT_Translator::translate_post( $job['post_id'], $job['target_language'] );
            if ( is_wp_error( $result ) ) {
                $queue[ $index ]['status']  = 'failed';
                $queue[ $index ]['message'] = $result->get_error_message();
                self::append_log_entry( $queue[ $index ], 'failed', $queue[ $index ]['message'] );
            } else {
                $queue[ $index ]['status']     = 'completed';
                $queue[ $index ]['message']    = __( 'Translation completed.', 'wp-ai-translator' );
                $queue[ $index ]['new_post_id'] = $result;
                self::append_log_entry( $queue[ $index ], 'completed', $queue[ $index ]['message'] );
            }
            self::update_queue( $queue );
            break;
        }
    }

    public static function enqueue_assets( $hook ) {
        if ( 'wp-ai-translator_page_' . self::LOG_PAGE_SLUG !== $hook ) {
            return;
        }
        wp_enqueue_style( 'wpait-admin', WPAIT_PLUGIN_URL . 'assets/admin.css', array(), WPAIT_VERSION );
        wp_enqueue_script( 'wpait-logs', WPAIT_PLUGIN_URL . 'assets/logs.js', array( 'jquery' ), WPAIT_VERSION, true );
        wp_localize_script(
            'wpait-logs',
            'wpaitLogs',
            array(
                'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( 'wpait_logs' ),
                'emptyQueueText'  => __( 'No translation history yet.', 'wp-ai-translator' ),
                'viewLabel'       => __( 'View progress', 'wp-ai-translator' ),
                'clearLabel'      => __( 'Clear history', 'wp-ai-translator' ),
                'modalTitle'      => __( 'Translation Progress', 'wp-ai-translator' ),
                'statusLabel'     => __( 'Status', 'wp-ai-translator' ),
                'timestampLabel'  => __( 'Timestamp', 'wp-ai-translator' ),
                'messageLabel'    => __( 'Message', 'wp-ai-translator' ),
            )
        );
    }

    public static function ajax_clear_queue() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'wpait_logs' ) && ! wp_verify_nonce( $nonce, 'wpait_pages' ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'wp-ai-translator' ) ) );
        }
        if ( ! self::current_user_can_manage_queue() ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-ai-translator' ) ) );
        }
        self::update_queue( array() );
        wp_send_json_success( array( 'queue' => array() ) );
    }

    public static function ajax_get_queue_logs() {
        check_ajax_referer( 'wpait_logs', 'nonce' );
        if ( ! self::current_user_can_manage_queue() ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-ai-translator' ) ) );
        }
        wp_send_json_success(
            array(
                'queue' => self::get_queue_payload( true ),
            )
        );
    }

    public static function get_queue_payload( $include_logs = false ) {
        $queue = self::get_queue();
        $items = array();
        foreach ( $queue as $job ) {
            $items[] = array(
                'id'         => $job['id'] ?? '',
                'post_title' => get_the_title( $job['post_id'] ),
                'language'   => strtoupper( $job['target_language'] ),
                'status'     => ucfirst( $job['status'] ),
                'message'    => $job['message'] ?? '',
                'log'        => $include_logs ? ( $job['log'] ?? array() ) : array(),
            );
        }
        return $items;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Logs / History', 'wp-ai-translator' ) . '</h1>';
        echo '<p>' . esc_html__( 'Track translation progress in real time. Click a record to view detailed history.', 'wp-ai-translator' ) . '</p>';
        echo '<div class="wpait-logs-actions">';
        echo '<button type="button" class="button" id="wpait-clear-history">' . esc_html__( 'Clear history', 'wp-ai-translator' ) . '</button>';
        echo '</div>';
        echo '<table class="widefat striped wpait-logs-table">';
        echo '<thead><tr><th>' . esc_html__( 'Post', 'wp-ai-translator' ) . '</th><th>' . esc_html__( 'Language', 'wp-ai-translator' ) . '</th><th>' . esc_html__( 'Status', 'wp-ai-translator' ) . '</th><th>' . esc_html__( 'Message', 'wp-ai-translator' ) . '</th><th>' . esc_html__( 'Progress', 'wp-ai-translator' ) . '</th></tr></thead>';
        echo '<tbody id="wpait-logs-body"></tbody>';
        echo '</table>';
        echo '<div class="wpait-modal" id="wpait-log-modal" aria-hidden="true">';
        echo '<div class="wpait-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wpait-log-title">';
        echo '<div class="wpait-modal__header">';
        echo '<h2 id="wpait-log-title">' . esc_html__( 'Translation Progress', 'wp-ai-translator' ) . '</h2>';
        echo '<button type="button" class="wpait-modal__close" aria-label="' . esc_attr__( 'Close', 'wp-ai-translator' ) . '">×</button>';
        echo '</div>';
        echo '<div class="wpait-modal__body">';
        echo '<div class="wpait-log-meta" id="wpait-log-meta"></div>';
        echo '<div class="wpait-modal__progress-table">';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Timestamp', 'wp-ai-translator' ) . '</th><th>' . esc_html__( 'Status', 'wp-ai-translator' ) . '</th><th>' . esc_html__( 'Message', 'wp-ai-translator' ) . '</th></tr></thead><tbody id="wpait-log-body"></tbody></table>';
        echo '</div>';
        echo '</div>';
        echo '<div class="wpait-modal__footer">';
        echo '<button type="button" class="button button-secondary wpait-modal__cancel">' . esc_html__( 'Close', 'wp-ai-translator' ) . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    private static function current_user_can_manage_queue() {
        return current_user_can( 'manage_options' ) || current_user_can( 'edit_pages' );
    }

    private static function append_log_entry( array &$job, $status, $message ) {
        if ( empty( $job['log'] ) ) {
            $job['log'] = array();
        }
        $job['log'][] = array(
            'timestamp' => current_time( 'mysql' ),
            'status'    => ucfirst( $status ),
            'message'   => $message,
        );
    }

    private static function prune_queue( $queue ) {
        $queue = is_array( $queue ) ? $queue : array();
        $updated = false;
        $filtered = array();
        foreach ( $queue as $job ) {
            $post = get_post( $job['post_id'] ?? 0 );
            if ( ! $post ) {
                $updated = true;
                continue;
            }
            if ( isset( $job['new_post_id'] ) && 'completed' === ( $job['status'] ?? '' ) ) {
                $translated_post = get_post( $job['new_post_id'] );
                if ( ! $translated_post ) {
                    $updated = true;
                    continue;
                }
            }
            $filtered[] = $job;
        }
        if ( $updated ) {
            self::update_queue( $filtered );
        }
        return $filtered;
    }
}
