<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_Settings {
    const OPTION_KEY = 'wpait_settings';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_post_wpait_clone_menus', array( __CLASS__, 'handle_clone_menus' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function enqueue_assets( $hook ) {
        if ( 'settings_page_wpait-settings' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'wpait-admin', WPAIT_PLUGIN_URL . 'assets/admin.css', array(), WPAIT_VERSION );
    }

    public static function add_menu() {
        add_options_page(
            __( 'WP AI Translator', 'wp-ai-translator' ),
            __( 'WP AI Translator', 'wp-ai-translator' ),
            'manage_options',
            'wpait-settings',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function register_settings() {
        register_setting( 'wpait_settings', self::OPTION_KEY );

        add_settings_section(
            'wpait_api_section',
            __( 'API Settings', 'wp-ai-translator' ),
            '__return_false',
            'wpait-settings'
        );

        add_settings_field(
            'api_key',
            __( 'ChatGPT API Key', 'wp-ai-translator' ),
            array( __CLASS__, 'render_api_key' ),
            'wpait-settings',
            'wpait_api_section'
        );

        add_settings_field(
            'model',
            __( 'Model', 'wp-ai-translator' ),
            array( __CLASS__, 'render_model' ),
            'wpait-settings',
            'wpait_api_section'
        );

        add_settings_section(
            'wpait_language_section',
            __( 'Languages', 'wp-ai-translator' ),
            '__return_false',
            'wpait-settings'
        );

        add_settings_field(
            'languages',
            __( 'Available Languages', 'wp-ai-translator' ),
            array( __CLASS__, 'render_languages' ),
            'wpait-settings',
            'wpait_language_section'
        );
    }

    public static function get_settings() {
        $defaults = array(
            'api_key'   => '',
            'model'     => 'gpt-4o-mini',
            'languages' => array( 'en', 'nl' ),
        );
        $settings = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( $settings, $defaults );
    }

    public static function render_api_key() {
        $settings = self::get_settings();
        printf(
            '<input type="password" class="regular-text" name="%1$s[api_key]" value="%2$s" autocomplete="off" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $settings['api_key'] )
        );
    }

    public static function render_model() {
        $settings = self::get_settings();
        $models   = array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini' );
        echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[model]">';
        foreach ( $models as $model ) {
            printf(
                '<option value="%1$s" %2$s>%1$s</option>',
                esc_attr( $model ),
                selected( $settings['model'], $model, false )
            );
        }
        echo '</select>';
    }

    public static function render_languages() {
        $settings  = self::get_settings();
        $languages = self::get_available_languages();
        echo '<div class="wpait-language-grid">';
        foreach ( $languages as $code => $language ) {
            $checked = in_array( $code, (array) $settings['languages'], true );
            $flag    = esc_url( WPAIT_PLUGIN_URL . 'assets/flags/' . $language['flag'] );
            printf(
                '<label class="wpait-language-card"><input type="checkbox" name="%1$s[languages][]" value="%2$s" %3$s /><span class="wpait-language-flag"><img src="%4$s" alt="%5$s" /></span><span class="wpait-language-name">%5$s</span></label>',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $code ),
                checked( $checked, true, false ),
                $flag,
                esc_html( $language['label'] )
            );
        }
        echo '</div>';
    }

    public static function get_available_languages() {
        return array(
            'en' => array(
                'label' => __( 'English', 'wp-ai-translator' ),
                'flag'  => 'en.svg',
            ),
            'nl' => array(
                'label' => __( 'Dutch', 'wp-ai-translator' ),
                'flag'  => 'nl.svg',
            ),
            'fr' => array(
                'label' => __( 'French', 'wp-ai-translator' ),
                'flag'  => 'fr.svg',
            ),
            'de' => array(
                'label' => __( 'German', 'wp-ai-translator' ),
                'flag'  => 'de.svg',
            ),
            'es' => array(
                'label' => __( 'Spanish', 'wp-ai-translator' ),
                'flag'  => 'es.svg',
            ),
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'WP AI Translator Settings', 'wp-ai-translator' ) . '</h1>';
        if ( isset( $_GET['wpait_menus'] ) && 'cloned' === $_GET['wpait_menus'] ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Menus cloned successfully.', 'wp-ai-translator' ) . '</p></div>';
        }
        echo '<form method="post" action="options.php">';
        settings_fields( 'wpait_settings' );
        do_settings_sections( 'wpait-settings' );
        submit_button();
        echo '</form>';

        echo '<hr />';
        echo '<h2>' . esc_html__( 'Menu Cloning', 'wp-ai-translator' ) . '</h2>';
        echo '<p>' . esc_html__( 'Clone existing menus for each selected language.', 'wp-ai-translator' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="wpait_clone_menus" />';
        wp_nonce_field( 'wpait_clone_menus', 'wpait_clone_menus_nonce' );
        submit_button( __( 'Clone Menus', 'wp-ai-translator' ), 'secondary' );
        echo '</form>';
        echo '</div>';
    }

    public static function handle_clone_menus() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wp-ai-translator' ) );
        }
        check_admin_referer( 'wpait_clone_menus', 'wpait_clone_menus_nonce' );
        $settings  = self::get_settings();
        $languages = array_diff( $settings['languages'], array( 'en' ) );
        $menus     = wp_get_nav_menus();
        $created   = array();

        foreach ( $menus as $menu ) {
            foreach ( $languages as $language ) {
                $new_menu_name = $menu->name . ' (' . strtoupper( $language ) . ')';
                if ( term_exists( $new_menu_name, 'nav_menu' ) ) {
                    continue;
                }
                $new_menu_id = wp_create_nav_menu( $new_menu_name );
                if ( is_wp_error( $new_menu_id ) ) {
                    continue;
                }
                $items = wp_get_nav_menu_items( $menu->term_id );
                if ( $items ) {
                    foreach ( $items as $item ) {
                        wp_update_nav_menu_item(
                            $new_menu_id,
                            0,
                            array(
                                'menu-item-title'     => $item->title,
                                'menu-item-object'    => $item->object,
                                'menu-item-object-id' => $item->object_id,
                                'menu-item-type'      => $item->type,
                                'menu-item-status'    => 'publish',
                                'menu-item-url'       => $item->url,
                            )
                        );
                    }
                }
                $created[] = $new_menu_id;
            }
        }

        update_option( 'wpait_menu_clones', $created, false );
        wp_safe_redirect( admin_url( 'options-general.php?page=wpait-settings&wpait_menus=cloned' ) );
        exit;
    }
}
