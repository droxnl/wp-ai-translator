<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_Queue {
    const OPTION_KEY = 'wpait_queue';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'wpait_process_queue', array( __CLASS__, 'process_queue' ) );
    }

    public static function add_menu() {
        add_submenu_page(
            'tools.php',
            __( 'AI Translation Queue', 'wp-ai-translator' ),
            __( 'AI Translation Queue', 'wp-ai-translator' ),
            'manage_options',
            'wpait-queue',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function get_queue() {
        return get_option( self::OPTION_KEY, array() );
    }

    public static function update_queue( array $queue ) {
        update_option( self::OPTION_KEY, array_values( $queue ), false );
    }

    public static function enqueue( array $job ) {
        $queue   = self::get_queue();
        $job['id'] = uniqid( 'wpait_', true );
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
            self::update_queue( $queue );

            $result = WPAIT_Translator::translate_post( $job['post_id'], $job['target_language'] );
            if ( is_wp_error( $result ) ) {
                $queue[ $index ]['status']  = 'failed';
                $queue[ $index ]['message'] = $result->get_error_message();
            } else {
                $queue[ $index ]['status']     = 'completed';
                $queue[ $index ]['message']    = __( 'Translation completed.', 'wp-ai-translator' );
                $queue[ $index ]['new_post_id'] = $result;
            }
            self::update_queue( $queue );
            break;
        }
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $queue = self::get_queue();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'AI Translation Queue', 'wp-ai-translator' ) . '</h1>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . esc_html__( 'Post', 'wp-ai-translator' ) . '</th><th>' . esc_html__( 'Language', 'wp-ai-translator' ) . '</th><th>' . esc_html__( 'Status', 'wp-ai-translator' ) . '</th><th>' . esc_html__( 'Message', 'wp-ai-translator' ) . '</th></tr></thead>';
        echo '<tbody>';
        if ( empty( $queue ) ) {
            echo '<tr><td colspan="4">' . esc_html__( 'No queued jobs.', 'wp-ai-translator' ) . '</td></tr>';
        } else {
            foreach ( $queue as $job ) {
                $post_title = get_the_title( $job['post_id'] );
                echo '<tr>';
                echo '<td>' . esc_html( $post_title ) . '</td>';
                echo '<td>' . esc_html( strtoupper( $job['target_language'] ) ) . '</td>';
                echo '<td>' . esc_html( ucfirst( $job['status'] ) ) . '</td>';
                echo '<td>' . esc_html( $job['message'] ?? '' ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
    }
}
