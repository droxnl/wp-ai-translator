<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_Settings {
    const OPTION_API_KEY      = 'wpait_api_settings';
    const OPTION_LANGUAGE_KEY = 'wpait_language_settings';
    const OPTION_MENU_KEY     = 'wpait_menu_settings';

    private static $menu_hooks = array();

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_post_wpait_clone_menus', array( __CLASS__, 'handle_clone_menus' ) );
        add_action( 'admin_post_wpait_add_language_menu_item', array( __CLASS__, 'handle_add_language_menu_item' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function enqueue_assets( $hook ) {
        if ( empty( self::$menu_hooks ) || ! in_array( $hook, self::$menu_hooks, true ) ) {
            return;
        }
        wp_enqueue_style( 'wpait-admin', WPAIT_PLUGIN_URL . 'assets/admin.css', array(), WPAIT_VERSION );
    }

    public static function add_menu() {
        $main_hook = add_menu_page(
            __( 'WP AI Translator', 'wp-ai-translator' ),
            __( 'WP AI Translator', 'wp-ai-translator' ),
            'manage_options',
            'wpait',
            array( __CLASS__, 'render_ai_settings_page' ),
            'dashicons-translation',
            60
        );
        self::$menu_hooks[] = $main_hook;

        $ai_hook = add_submenu_page(
            'wpait',
            __( 'AI Settings', 'wp-ai-translator' ),
            __( 'AI Settings', 'wp-ai-translator' ),
            'manage_options',
            'wpait-ai-settings',
            array( __CLASS__, 'render_ai_settings_page' )
        );
        self::$menu_hooks[] = $ai_hook;

        $languages_hook = add_submenu_page(
            'wpait',
            __( 'Languages', 'wp-ai-translator' ),
            __( 'Languages', 'wp-ai-translator' ),
            'manage_options',
            'wpait-languages',
            array( __CLASS__, 'render_languages_page' )
        );
        self::$menu_hooks[] = $languages_hook;

        $menus_hook = add_submenu_page(
            'wpait',
            __( 'Menus', 'wp-ai-translator' ),
            __( 'Menus', 'wp-ai-translator' ),
            'manage_options',
            'wpait-menus',
            array( __CLASS__, 'render_menus_page' )
        );
        self::$menu_hooks[] = $menus_hook;
    }

    public static function register_settings() {
        register_setting( 'wpait_ai_settings', self::OPTION_API_KEY );
        register_setting( 'wpait_language_settings', self::OPTION_LANGUAGE_KEY, array( __CLASS__, 'sanitize_language_settings' ) );
        register_setting( 'wpait_menu_settings', self::OPTION_MENU_KEY );

        add_settings_section(
            'wpait_api_section',
            __( 'API Settings', 'wp-ai-translator' ),
            '__return_false',
            'wpait-ai-settings'
        );

        add_settings_field(
            'api_key',
            __( 'ChatGPT API Key', 'wp-ai-translator' ),
            array( __CLASS__, 'render_api_key' ),
            'wpait-ai-settings',
            'wpait_api_section'
        );

        add_settings_field(
            'model',
            __( 'Model', 'wp-ai-translator' ),
            array( __CLASS__, 'render_model' ),
            'wpait-ai-settings',
            'wpait_api_section'
        );

        add_settings_section(
            'wpait_language_section',
            __( 'Languages', 'wp-ai-translator' ),
            '__return_false',
            'wpait-languages'
        );

        add_settings_field(
            'default_language',
            __( 'Default Language', 'wp-ai-translator' ),
            array( __CLASS__, 'render_default_language' ),
            'wpait-languages',
            'wpait_language_section'
        );

        add_settings_field(
            'languages',
            __( 'Available Languages', 'wp-ai-translator' ),
            array( __CLASS__, 'render_languages' ),
            'wpait-languages',
            'wpait_language_section'
        );

        add_settings_section(
            'wpait_menu_section',
            __( 'Menu Assignments', 'wp-ai-translator' ),
            '__return_false',
            'wpait-menus'
        );

        add_settings_field(
            'menu_assignments',
            __( 'Menu Assignments', 'wp-ai-translator' ),
            array( __CLASS__, 'render_menu_assignments' ),
            'wpait-menus',
            'wpait_menu_section'
        );
    }

    public static function get_settings() {
        $defaults = array(
            'api_key'   => '',
            'model'     => 'gpt-4o-mini',
            'languages' => array( 'en', 'nl' ),
            'default_language' => 'en',
            'menu_assignments' => array(),
        );
        $api_settings      = get_option( self::OPTION_API_KEY, array() );
        $language_settings = get_option( self::OPTION_LANGUAGE_KEY, array() );
        $menu_settings     = get_option( self::OPTION_MENU_KEY, array() );

        $settings = array_merge( $defaults, $api_settings, $language_settings, $menu_settings );
        $available = self::get_available_languages();
        if ( empty( $settings['default_language'] ) || ! isset( $available[ $settings['default_language'] ] ) ) {
            $settings['default_language'] = $defaults['default_language'];
        }
        if ( ! in_array( $settings['default_language'], (array) $settings['languages'], true ) ) {
            $settings['languages'][] = $settings['default_language'];
        }

        if ( empty( $settings['menu_assignments'] ) || ! is_array( $settings['menu_assignments'] ) ) {
            $settings['menu_assignments'] = array();
        }
        return $settings;
    }

    public static function sanitize_language_settings( $settings ) {
        $settings = is_array( $settings ) ? $settings : array();
        $available = self::get_available_languages();
        $languages = isset( $settings['languages'] ) ? (array) $settings['languages'] : array();
        $languages = array_values( array_intersect( $languages, array_keys( $available ) ) );
        $default_language = isset( $settings['default_language'] ) ? sanitize_text_field( $settings['default_language'] ) : 'en';

        if ( ! isset( $available[ $default_language ] ) ) {
            $default_language = 'en';
        }
        if ( ! in_array( $default_language, $languages, true ) ) {
            $languages[] = $default_language;
        }

        $settings['default_language'] = $default_language;
        $settings['languages']        = $languages;

        return $settings;
    }

    public static function render_api_key() {
        $settings = self::get_settings();
        printf(
            '<input type="password" class="regular-text" name="%1$s[api_key]" value="%2$s" autocomplete="off" />',
            esc_attr( self::OPTION_API_KEY ),
            esc_attr( $settings['api_key'] )
        );
    }

    public static function render_model() {
        $settings = self::get_settings();
        $models   = array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini' );
        echo '<select name="' . esc_attr( self::OPTION_API_KEY ) . '[model]">';
        foreach ( $models as $model ) {
            printf(
                '<option value="%1$s" %2$s>%1$s</option>',
                esc_attr( $model ),
                selected( $settings['model'], $model, false )
            );
        }
        echo '</select>';
    }

    public static function render_default_language() {
        $settings  = self::get_settings();
        $languages = self::get_available_languages();
        echo '<select name="' . esc_attr( self::OPTION_LANGUAGE_KEY ) . '[default_language]">';
        foreach ( $languages as $code => $language ) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr( $code ),
                selected( $settings['default_language'], $code, false ),
                esc_html( $language['label'] )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Used as the source language for pages without a specific language set.', 'wp-ai-translator' ) . '</p>';
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
                esc_attr( self::OPTION_LANGUAGE_KEY ),
                esc_attr( $code ),
                checked( $checked, true, false ),
                $flag,
                esc_html( $language['label'] )
            );
        }
        echo '</div>';
    }

    public static function render_menu_assignments() {
        $settings  = self::get_settings();
        $available = self::get_available_languages();
        $menus     = wp_get_nav_menus();

        if ( empty( $menus ) ) {
            echo '<p class="description">' . esc_html__( 'Create a menu first to assign it to a language.', 'wp-ai-translator' ) . '</p>';
            return;
        }

        echo '<div class="wpait-menu-assignments">';
        foreach ( (array) $settings['languages'] as $code ) {
            if ( ! isset( $available[ $code ] ) ) {
                continue;
            }

            $language = $available[ $code ];
            $selected = isset( $settings['menu_assignments'][ $code ] ) ? (int) $settings['menu_assignments'][ $code ] : 0;
            echo '<p>';
            printf(
                '<label for="wpait-menu-assignment-%1$s">%2$s</label>',
                esc_attr( $code ),
                esc_html( $language['label'] )
            );
            echo '<br />';
            printf(
                '<select id="wpait-menu-assignment-%1$s" name="%2$s[menu_assignments][%1$s]">',
                esc_attr( $code ),
                esc_attr( self::OPTION_MENU_KEY )
            );
            echo '<option value="0">' . esc_html__( 'Default menu', 'wp-ai-translator' ) . '</option>';
            foreach ( $menus as $menu ) {
                printf(
                    '<option value="%1$d" %2$s>%3$s</option>',
                    (int) $menu->term_id,
                    selected( $selected, (int) $menu->term_id, false ),
                    esc_html( $menu->name )
                );
            }
            echo '</select>';
            echo '</p>';
        }
        echo '<p class="description">' . esc_html__( 'Choose a menu to use when viewing each language.', 'wp-ai-translator' ) . '</p>';
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
            'ru' => array(
                'label' => __( 'Russian', 'wp-ai-translator' ),
                'flag'  => 'ru.svg',
            ),
        );
    }

    public static function render_ai_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'AI Settings', 'wp-ai-translator' ) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'wpait_ai_settings' );
        do_settings_sections( 'wpait-ai-settings' );
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public static function render_languages_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Languages', 'wp-ai-translator' ) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'wpait_language_settings' );
        do_settings_sections( 'wpait-languages' );
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public static function render_menus_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Menus', 'wp-ai-translator' ) . '</h1>';
        if ( isset( $_GET['wpait_menus'] ) && 'cloned' === $_GET['wpait_menus'] ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Menus cloned successfully.', 'wp-ai-translator' ) . '</p></div>';
        }
        if ( isset( $_GET['wpait_menu_item'] ) && 'added' === $_GET['wpait_menu_item'] ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Language selector added to the default menu.', 'wp-ai-translator' ) . '</p></div>';
        }
        if ( isset( $_GET['wpait_menu_item'] ) && 'exists' === $_GET['wpait_menu_item'] ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'Language selector already exists in the default menu.', 'wp-ai-translator' ) . '</p></div>';
        }
        if ( isset( $_GET['wpait_menu_item'] ) && 'missing' === $_GET['wpait_menu_item'] ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Create a menu first before adding the language selector.', 'wp-ai-translator' ) . '</p></div>';
        }
        echo '<form method="post" action="options.php">';
        settings_fields( 'wpait_menu_settings' );
        do_settings_sections( 'wpait-menus' );
        submit_button();
        echo '</form>';

        echo '<hr />';
        echo '<h2>' . esc_html__( 'Language Selector', 'wp-ai-translator' ) . '</h2>';
        echo '<p>' . esc_html__( 'Add the language selector to the default menu as a custom link.', 'wp-ai-translator' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="wpait_add_language_menu_item" />';
        wp_nonce_field( 'wpait_add_language_menu_item', 'wpait_add_language_menu_item_nonce' );
        submit_button( __( 'Add Language Selector to Default Menu', 'wp-ai-translator' ), 'secondary' );
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
        $languages = array_diff( $settings['languages'], array( $settings['default_language'] ) );
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
        wp_safe_redirect( admin_url( 'admin.php?page=wpait-menus&wpait_menus=cloned' ) );
        exit;
    }

    public static function handle_add_language_menu_item() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wp-ai-translator' ) );
        }

        check_admin_referer( 'wpait_add_language_menu_item', 'wpait_add_language_menu_item_nonce' );

        $settings     = self::get_settings();
        $menu_id      = 0;
        $default_lang = isset( $settings['default_language'] ) ? $settings['default_language'] : '';

        if ( $default_lang && ! empty( $settings['menu_assignments'][ $default_lang ] ) ) {
            $menu_id = (int) $settings['menu_assignments'][ $default_lang ];
        }

        if ( ! $menu_id ) {
            $menus = wp_get_nav_menus();
            if ( ! empty( $menus ) ) {
                $menu_id = (int) $menus[0]->term_id;
            }
        }

        if ( ! $menu_id ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wpait-menus&wpait_menu_item=missing' ) );
            exit;
        }

        $items = wp_get_nav_menu_items( $menu_id );
        if ( $items ) {
            foreach ( $items as $item ) {
                if ( in_array( WPAIT_Menus::MENU_ITEM_CLASS, (array) $item->classes, true ) ) {
                    wp_safe_redirect( admin_url( 'admin.php?page=wpait-menus&wpait_menu_item=exists' ) );
                    exit;
                }
            }
        }

        $menu_item_id = wp_update_nav_menu_item(
            $menu_id,
            0,
            array(
                'menu-item-title'   => __( 'Language Selector', 'wp-ai-translator' ),
                'menu-item-url'     => '#',
                'menu-item-status'  => 'publish',
                'menu-item-type'    => 'custom',
                'menu-item-classes' => WPAIT_Menus::MENU_ITEM_CLASS,
            )
        );

        if ( $menu_item_id && ! is_wp_error( $menu_item_id ) ) {
            update_post_meta( $menu_item_id, WPAIT_Menus::MENU_ITEM_META_KEY, 1 );
            wp_safe_redirect( admin_url( 'admin.php?page=wpait-menus&wpait_menu_item=added' ) );
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wpait-menus&wpait_menu_item=missing' ) );
        exit;
    }
}
