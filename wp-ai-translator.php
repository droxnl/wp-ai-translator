<?php
/**
 * Plugin Name: WP AI Translator
 * Description: WP Bakery / Salient compatible translation plugin with AI that translates page context and creates language-specific duplicates.
 * Version: 0.1.0
 * Author: OpenAI
 * Text Domain: wp-ai-translator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPAIT_VERSION', '0.1.0' );
define( 'WPAIT_PLUGIN_FILE', __FILE__ );
define( 'WPAIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

autoload_wpait();

function autoload_wpait() {
    require_once WPAIT_PLUGIN_DIR . 'includes/admin/class-wpait-settings.php';
    require_once WPAIT_PLUGIN_DIR . 'includes/admin/class-wpait-pages.php';
    require_once WPAIT_PLUGIN_DIR . 'includes/admin/class-wpait-queue.php';
    require_once WPAIT_PLUGIN_DIR . 'includes/api/class-wpait-openai.php';
    require_once WPAIT_PLUGIN_DIR . 'includes/frontend/class-wpait-language-widget.php';
    require_once WPAIT_PLUGIN_DIR . 'includes/translation/class-wpait-translator.php';
}

function wpait_bootstrap() {
    WPAIT_Settings::register();
    WPAIT_Pages::register();
    WPAIT_Queue::register();
    add_action( 'widgets_init', 'wpait_register_language_widget' );
}
add_action( 'plugins_loaded', 'wpait_bootstrap' );

function wpait_register_language_widget() {
    register_widget( 'WPAIT_Language_Widget' );
}

function wpait_activate() {
    if ( ! wp_next_scheduled( 'wpait_process_queue' ) ) {
        wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wpait_minutely', 'wpait_process_queue' );
    }
}
register_activation_hook( __FILE__, 'wpait_activate' );

function wpait_deactivate() {
    wp_clear_scheduled_hook( 'wpait_process_queue' );
}
register_deactivation_hook( __FILE__, 'wpait_deactivate' );

function wpait_cron_schedules( $schedules ) {
    if ( ! isset( $schedules['wpait_minutely'] ) ) {
        $schedules['wpait_minutely'] = array(
            'interval' => MINUTE_IN_SECONDS,
            'display'  => __( 'Every Minute', 'wp-ai-translator' ),
        );
    }
    return $schedules;
}
add_filter( 'cron_schedules', 'wpait_cron_schedules' );
