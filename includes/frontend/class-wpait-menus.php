<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_Menus {
    const MENU_ITEM_META_KEY = '_wpait_language_menu';

    public static function register() {
        add_action( 'load-nav-menus.php', array( __CLASS__, 'register_menu_meta_box' ) );
        add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'handle_menu_item_save' ), 10, 3 );
        add_filter( 'wp_setup_nav_menu_item', array( __CLASS__, 'setup_menu_item' ) );
        add_filter( 'walker_nav_menu_start_el', array( __CLASS__, 'render_language_menu_item' ), 10, 4 );
        add_filter( 'wp_nav_menu_args', array( __CLASS__, 'assign_menu_for_language' ) );
    }

    public static function register_menu_meta_box() {
        add_meta_box(
            'wpait-language-menu',
            __( 'WP AI Translator', 'wp-ai-translator' ),
            array( __CLASS__, 'render_menu_meta_box' ),
            'nav-menus',
            'side',
            'default'
        );
    }

    public static function render_menu_meta_box() {
        $item              = new stdClass();
        $item->db_id        = 0;
        $item->object_id    = 0;
        $item->ID           = self::get_placeholder_id();
        $item->object       = 'wpait_language_menu';
        $item->menu_item_parent = 0;
        $item->type         = 'custom';
        $item->title        = __( 'Language Selector', 'wp-ai-translator' );
        $item->url          = '#wpait-language-menu';
        $item->target       = '';
        $item->attr_title   = '';
        $item->description  = '';
        $item->classes      = array();
        $item->xfn          = '';

        $walker = new Walker_Nav_Menu_Checklist();
        echo '<div id="wpait-language-menu-options" class="wpait-language-menu-options">';
        echo '<p>' . esc_html__( 'Add the language selector to your menu.', 'wp-ai-translator' ) . '</p>';
        echo '<ul class="categorychecklist form-no-clear">';
        echo walk_nav_menu_tree( array( $item ), 0, (object) array( 'walker' => $walker ) );
        echo '</ul>';
        echo '<p class="button-controls">';
        echo '<span class="add-to-menu">';
        echo '<button type="submit" class="button-secondary submit-add-to-menu right" value="' . esc_attr__( 'Add to Menu', 'wp-ai-translator' ) . '" name="add-wpait-language-menu" id="submit-wpait-language-menu">' . esc_html__( 'Add to Menu', 'wp-ai-translator' ) . '</button>';
        echo '<span class="spinner"></span>';
        echo '</span>';
        echo '</p>';
        echo '</div>';
    }

    private static function get_placeholder_id() {
        if ( ! isset( $GLOBALS['_nav_menu_placeholder'] ) ) {
            $GLOBALS['_nav_menu_placeholder'] = 0;
        }

        $GLOBALS['_nav_menu_placeholder'] = ( 0 > $GLOBALS['_nav_menu_placeholder'] )
            ? $GLOBALS['_nav_menu_placeholder'] - 1
            : -1;

        return $GLOBALS['_nav_menu_placeholder'];
    }

    public static function handle_menu_item_save( $menu_id, $menu_item_db_id, $args ) {
        if ( empty( $_POST['menu-item-object'][ $menu_item_db_id ] ) ) {
            return;
        }

        $object = sanitize_text_field( wp_unslash( $_POST['menu-item-object'][ $menu_item_db_id ] ) );
        if ( 'wpait_language_menu' !== $object ) {
            delete_post_meta( $menu_item_db_id, self::MENU_ITEM_META_KEY );
            return;
        }

        update_post_meta( $menu_item_db_id, self::MENU_ITEM_META_KEY, 1 );
    }

    public static function setup_menu_item( $item ) {
        $is_language_menu = get_post_meta( $item->ID, self::MENU_ITEM_META_KEY, true );
        if ( $is_language_menu ) {
            $item->type_label = __( 'Language Selector', 'wp-ai-translator' );
            $item->url        = '#wpait-language-menu';
        }

        return $item;
    }

    public static function render_language_menu_item( $item_output, $item, $depth, $args ) {
        $is_language_menu = get_post_meta( $item->ID, self::MENU_ITEM_META_KEY, true );
        if ( ! $is_language_menu ) {
            return $item_output;
        }

        $menu_markup = WPAIT_Language_Widget::render_menu_shortcode( array() );
        if ( '' === $menu_markup ) {
            return $item_output;
        }

        return $menu_markup;
    }

    public static function assign_menu_for_language( $args ) {
        if ( is_admin() ) {
            return $args;
        }

        if ( ! empty( $args['menu'] ) ) {
            return $args;
        }

        $settings = WPAIT_Settings::get_settings();
        $language = self::get_current_language( $settings );
        $menu_id  = isset( $settings['menu_assignments'][ $language ] ) ? (int) $settings['menu_assignments'][ $language ] : 0;

        if ( ! $menu_id ) {
            return $args;
        }

        $args['menu'] = $menu_id;

        return $args;
    }

    private static function get_current_language( $settings ) {
        $post_id  = get_queried_object_id();
        $language = $post_id ? get_post_meta( $post_id, '_wpait_language', true ) : '';

        if ( ! $language ) {
            $language = $settings['default_language'];
        }

        return $language;
    }
}
