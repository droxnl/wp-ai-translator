<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_Menus {
    const MENU_ITEM_META_KEY = '_wpait_language_menu';
    const MENU_ITEM_CLASS    = 'wp-ai-menu-switch';

    public static function register() {
        add_action( 'load-nav-menus.php', array( __CLASS__, 'register_menu_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'handle_menu_item_save' ), 10, 3 );
        add_filter( 'wp_setup_nav_menu_item', array( __CLASS__, 'setup_menu_item' ) );
        add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'inject_language_menu_items' ), 10, 2 );
        add_filter( 'nav_menu_item_title', array( __CLASS__, 'filter_language_menu_title' ), 10, 4 );
        add_filter( 'nav_menu_link_attributes', array( __CLASS__, 'filter_language_menu_link_attributes' ), 10, 3 );
        add_filter( 'wp_nav_menu_args', array( __CLASS__, 'assign_menu_for_language' ) );
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( 'nav-menus.php' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'wpait-menus',
            WPAIT_PLUGIN_URL . 'assets/menus.js',
            array( 'jquery', 'nav-menu' ),
            WPAIT_VERSION,
            true
        );
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
        $item->object       = 'custom';
        $item->menu_item_parent = 0;
        $item->type         = 'custom';
        $item->title        = __( 'Language Selector', 'wp-ai-translator' );
        $item->url          = '#';
        $item->target       = '';
        $item->attr_title   = '';
        $item->description  = '';
        $item->classes      = array( self::MENU_ITEM_CLASS );
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
        $classes = '';
        if ( isset( $_POST['menu-item-classes'][ $menu_item_db_id ] ) ) {
            $classes = sanitize_text_field( wp_unslash( $_POST['menu-item-classes'][ $menu_item_db_id ] ) );
        }

        if ( 'wpait_language_menu' !== $object && ! self::has_language_menu_class( $classes ) ) {
            delete_post_meta( $menu_item_db_id, self::MENU_ITEM_META_KEY );
            return;
        }

        update_post_meta( $menu_item_db_id, self::MENU_ITEM_META_KEY, 1 );
    }

    public static function setup_menu_item( $item ) {
        $is_language_menu = self::is_language_menu_parent_item( $item );
        if ( $is_language_menu ) {
            $item->type_label = __( 'Language Selector', 'wp-ai-translator' );
            $item->url        = '#';
            if ( ! in_array( self::MENU_ITEM_CLASS, (array) $item->classes, true ) ) {
                $item->classes[] = self::MENU_ITEM_CLASS;
            }
        }

        return $item;
    }

    public static function inject_language_menu_items( $items, $args ) {
        if ( is_admin() ) {
            return $items;
        }

        $settings        = WPAIT_Settings::get_settings();
        $current         = self::get_current_language( $settings );
        $language_items  = self::build_menu_language_items( $settings, $current );

        if ( empty( $language_items ) ) {
            return $items;
        }

        $new_items = array();

        foreach ( $items as $item ) {
            $new_items[] = $item;

            if ( ! self::is_language_menu_parent_item( $item ) ) {
                continue;
            }

            $current_data = isset( $language_items[ $current ] ) ? $language_items[ $current ] : null;
            if ( $current_data ) {
                $item->wpait_language_code  = $current;
                $item->wpait_language_label = $current_data['label'];
                $item->wpait_language_flag  = $current_data['flag'];
            }

            $children = array();
            $order    = isset( $item->menu_order ) ? (int) $item->menu_order : 0;
            foreach ( $language_items as $code => $data ) {
                if ( $code === $current ) {
                    continue;
                }

                $order++;
                $children[] = self::build_child_menu_item( $item, $code, $data, $order );
            }

            if ( ! empty( $children ) ) {
                $item->classes[] = 'menu-item-has-children';
                $new_items       = array_merge( $new_items, $children );
            }
        }

        return $new_items;
    }

    public static function filter_language_menu_title( $title, $item, $args, $depth ) {
        if ( ! empty( $item->wpait_language_code ) ) {
            $label = ! empty( $item->wpait_language_label ) ? $item->wpait_language_label : $title;
            $flag  = ! empty( $item->wpait_language_flag )
                ? '<span class="wpait-language-menu__flag"><img src="' . esc_url( $item->wpait_language_flag ) . '" alt="' . esc_attr( $label ) . '" /></span>'
                : '';

            if ( $flag ) {
                $is_child = in_array( 'wpait-language-menu__child', (array) $item->classes, true );
                if ( $is_child ) {
                    $title = $flag . '<span class="wpait-language-menu__label">' . esc_html( $label ) . '</span>';
                } else {
                    $title = $flag . '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
                }
            }
        }

        return $title;
    }

    public static function filter_language_menu_link_attributes( $atts, $item, $args ) {
        if ( self::is_language_menu_parent_item( $item ) ) {
            $atts['href'] = '#';
        }

        return $atts;
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

    private static function is_language_menu_parent_item( $item ) {
        $is_language_menu = get_post_meta( $item->ID, self::MENU_ITEM_META_KEY, true );
        if ( $is_language_menu ) {
            return true;
        }

        return self::has_language_menu_class( isset( $item->classes ) ? $item->classes : array() );
    }

    private static function has_language_menu_class( $classes ) {
        if ( empty( $classes ) ) {
            return false;
        }

        if ( is_string( $classes ) ) {
            $classes = preg_split( '/\s+/', $classes );
        }

        return in_array( self::MENU_ITEM_CLASS, (array) $classes, true );
    }

    private static function build_menu_language_items( $settings, $current ) {
        $available = WPAIT_Settings::get_available_languages();
        $items     = array();

        foreach ( (array) $settings['languages'] as $language_code ) {
            if ( ! isset( $available[ $language_code ] ) ) {
                continue;
            }

            $items[ $language_code ] = array(
                'label' => $available[ $language_code ]['label'],
                'url'   => home_url( '/' . $language_code . '/' ),
                'flag'  => isset( $available[ $language_code ]['flag'] )
                    ? WPAIT_PLUGIN_URL . 'assets/flags/' . $available[ $language_code ]['flag']
                    : '',
            );
        }

        if ( ! isset( $items[ $current ] ) && ! empty( $items ) ) {
            $first = array_key_first( $items );
            if ( $first ) {
                $current = $first;
            }
        }

        return $items;
    }

    private static function build_child_menu_item( $parent_item, $language_code, $data, $menu_order = 0 ) {
        $item_id = self::get_placeholder_id();

        $item                       = new stdClass();
        $item->ID                   = $item_id;
        $item->db_id                = 0;
        $item->menu_item_parent     = $parent_item->ID;
        $item->object_id            = 0;
        $item->object               = 'custom';
        $item->type                 = 'custom';
        $item->title                = $data['label'];
        $item->url                  = $data['url'];
        $item->target               = '';
        $item->attr_title           = '';
        $item->description          = '';
        $item->classes              = array(
            'menu-item',
            'menu-item-type-custom',
            'menu-item-object-custom',
            'wpait-language-menu__child',
        );
        $item->xfn                  = '';
        $item->menu_order           = $menu_order;
        $item->status               = 'publish';
        $item->wpait_language_code  = $language_code;
        $item->wpait_language_label = $data['label'];
        $item->wpait_language_flag  = $data['flag'];

        return $item;
    }
}
