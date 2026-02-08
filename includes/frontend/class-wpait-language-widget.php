<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_Language_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'wpait_language_widget',
            __( 'WP AI Translator Language Switcher', 'wp-ai-translator' ),
            array(
                'description' => __( 'Dropdown language selector for active site languages.', 'wp-ai-translator' ),
            )
        );
    }

    public function widget( $args, $instance ) {
        $settings = WPAIT_Settings::get_settings();
        $title    = ! empty( $instance['title'] ) ? $instance['title'] : '';

        echo $args['before_widget'];

        if ( $title ) {
            echo $args['before_title'] . apply_filters( 'widget_title', $title ) . $args['after_title'];
        }

        $options = $this->build_language_items( $settings );
        $current = $this->get_current_language( $settings );

        if ( ! empty( $options ) ) {
            printf(
                '<select class="wpait-language-selector" aria-label="%1$s" onchange="if(this.value){window.location.href=this.value;}">',
                esc_attr__( 'Select language', 'wp-ai-translator' )
            );

            foreach ( $options as $language_code => $data ) {
                printf(
                    '<option value="%1$s" %2$s %3$s>%4$s</option>',
                    esc_url( $data['url'] ),
                    selected( true, ( $data['current'] || $current === $language_code ), false ),
                    disabled( ! $data['enabled'], true, false ),
                    esc_html( $data['label'] )
                );
            }

            echo '</select>';
        }

        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $title = isset( $instance['title'] ) ? $instance['title'] : __( 'Language', 'wp-ai-translator' );
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
                <?php esc_html_e( 'Title:', 'wp-ai-translator' ); ?>
            </label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text"
                   value="<?php echo esc_attr( $title ); ?>" />
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        $instance          = array();
        $instance['title'] = sanitize_text_field( $new_instance['title'] ?? '' );

        return $instance;
    }

    public static function render_menu_shortcode( $atts ) {
        $settings = WPAIT_Settings::get_settings();
        $widget   = new self();
        $items    = $widget->build_language_items( $settings );
        $current  = $widget->get_current_language( $settings );

        if ( empty( $items ) ) {
            return '';
        }

        $output = '<div class="wpait-language-menu" aria-label="' . esc_attr__( 'Language selector', 'wp-ai-translator' ) . '">';
        foreach ( $items as $language_code => $data ) {
            $classes = array( 'wpait-language-menu__item' );
            $attrs   = '';
            $url     = $data['enabled'] ? $data['url'] : '#';

            if ( $data['current'] || $language_code === $current ) {
                $classes[] = 'is-current';
                $attrs    .= ' aria-current="page"';
            }
            if ( ! $data['enabled'] ) {
                $classes[] = 'is-disabled';
                $attrs    .= ' aria-disabled="true" tabindex="-1"';
            }

            $output .= sprintf(
                '<a class="%1$s" href="%2$s"%3$s>%4$s<span class="wpait-language-menu__label">%5$s</span></a>',
                esc_attr( implode( ' ', $classes ) ),
                esc_url( $url ),
                $attrs,
                $data['flag'] ? '<span class="wpait-language-menu__flag"><img src="' . esc_url( $data['flag'] ) . '" alt="' . esc_attr( $data['label'] ) . '" /></span>' : '',
                esc_html( $data['label'] )
            );
        }
        $output .= '</div>';

        return $output;
    }

    private function build_language_items( $settings ) {
        $available = WPAIT_Settings::get_available_languages();
        $options   = array();
        $group     = $this->get_translation_group();
        $post_type = $this->get_post_type();

        foreach ( (array) $settings['languages'] as $language_code ) {
            if ( ! isset( $available[ $language_code ] ) ) {
                continue;
            }

            $url     = $this->resolve_language_url( $language_code, $settings, $group, $post_type );
            $enabled = ! empty( $url );

            $flag = isset( $available[ $language_code ]['flag'] ) ? WPAIT_PLUGIN_URL . 'assets/flags/' . $available[ $language_code ]['flag'] : '';

            $options[ $language_code ] = array(
                'label'   => $available[ $language_code ]['label'],
                'url'     => $url ? $url : '#',
                'enabled' => $enabled,
                'flag'    => $flag,
                'current' => false,
            );
        }

        $options = apply_filters(
            'wpait_language_menu_items',
            $options,
            $settings,
            array(
                'group'     => $group,
                'post_type' => $post_type,
                'post_id'   => $this->get_post_id(),
            )
        );

        foreach ( (array) $options as $code => $data ) {
            $normalized = wp_parse_args(
                $data,
                array(
                    'label'   => '',
                    'url'     => '#',
                    'enabled' => true,
                    'flag'    => '',
                    'current' => false,
                )
            );

            if ( '' === $normalized['label'] ) {
                unset( $options[ $code ] );
                continue;
            }

            $options[ $code ] = $normalized;
        }

        return $options;
    }

    private function get_current_language( $settings ) {
        $post_id  = $this->get_post_id();
        $language = $post_id ? get_post_meta( $post_id, '_wpait_language', true ) : '';

        if ( ! $language ) {
            $language = $settings['default_language'];
        }

        return $language;
    }

    private function get_translation_group() {
        $post_id = $this->get_post_id();
        if ( ! $post_id ) {
            return '';
        }

        return get_post_meta( $post_id, '_wpait_translation_group', true );
    }

    private function get_post_id() {
        $post_id = get_queried_object_id();
        return $post_id ? $post_id : 0;
    }

    private function get_post_type() {
        $post_id = $this->get_post_id();
        return $post_id ? get_post_type( $post_id ) : 'page';
    }

    private function resolve_language_url( $language, $settings, $group, $post_type ) {
        $post_id = $this->get_post_id();

        if ( ! $post_id ) {
            return home_url( '/' );
        }

        $current_language = get_post_meta( $post_id, '_wpait_language', true );

        if ( $language === $current_language || ( ! $current_language && $language === $settings['default_language'] ) ) {
            return get_permalink( $post_id );
        }

        if ( ! $group ) {
            return '';
        }

        if ( $language === $settings['default_language'] ) {
            $default_posts = get_posts(
                array(
                    'post_type'   => $post_type,
                    'numberposts' => 1,
                    'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
                    'meta_query'  => array(
                        array(
                            'key'   => '_wpait_translation_group',
                            'value' => $group,
                        ),
                        array(
                            'key'     => '_wpait_language',
                            'compare' => 'NOT EXISTS',
                        ),
                    ),
                )
            );

            if ( ! empty( $default_posts ) ) {
                return get_permalink( $default_posts[0]->ID );
            }
        }

        $translations = get_posts(
            array(
                'post_type'   => $post_type,
                'numberposts' => 1,
                'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
                'meta_query'  => array(
                    array(
                        'key'   => '_wpait_translation_group',
                        'value' => $group,
                    ),
                    array(
                        'key'   => '_wpait_language',
                        'value' => $language,
                    ),
                ),
            )
        );

        if ( empty( $translations ) ) {
            return '';
        }

        return get_permalink( $translations[0]->ID );
    }
}
