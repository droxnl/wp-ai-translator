<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_Translator {
    public static function has_translation( $post_id, $language ) {
        $group = get_post_meta( $post_id, '_wpait_translation_group', true );
        if ( ! $group ) {
            return false;
        }
        $translations = get_posts(
            array(
                'post_type'   => 'page',
                'meta_key'    => '_wpait_translation_group',
                'meta_value'  => $group,
                'numberposts' => 1,
                'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
                'meta_query'  => array(
                    array(
                        'key'   => '_wpait_language',
                        'value' => $language,
                    ),
                ),
            )
        );
        return ! empty( $translations );
    }

    public static function translate_post( $post_id, $target_language ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'wpait_missing_post', __( 'Post not found.', 'wp-ai-translator' ) );
        }

        $group = get_post_meta( $post_id, '_wpait_translation_group', true );
        if ( ! $group ) {
            $group = uniqid( 'wpait_group_', true );
            update_post_meta( $post_id, '_wpait_translation_group', $group );
        }

        $translated_content = self::translate_content( $post->post_content, $target_language );
        if ( is_wp_error( $translated_content ) ) {
            return $translated_content;
        }

        $new_post_id = wp_insert_post(
            array(
                'post_title'   => $post->post_title . ' (' . strtoupper( $target_language ) . ')',
                'post_content' => $translated_content,
                'post_status'  => 'draft',
                'post_type'    => $post->post_type,
            )
        );

        if ( is_wp_error( $new_post_id ) ) {
            return $new_post_id;
        }

        update_post_meta( $new_post_id, '_wpait_translation_group', $group );
        update_post_meta( $new_post_id, '_wpait_language', $target_language );

        return $new_post_id;
    }

    public static function translate_content( $content, $target_language ) {
        $parts = self::split_content_preserving_shortcodes( $content );
        $translated = '';

        foreach ( $parts as $part ) {
            if ( $part['type'] === 'shortcode' ) {
                $translated .= $part['value'];
                continue;
            }
            $text = trim( $part['value'] );
            if ( '' === $text ) {
                $translated .= $part['value'];
                continue;
            }
            $response = WPAIT_OpenAI::translate( $text, $target_language );
            if ( is_wp_error( $response ) ) {
                return $response;
            }
            $translated .= str_replace( $text, $response, $part['value'] );
        }

        return $translated;
    }

    private static function split_content_preserving_shortcodes( $content ) {
        $pattern = '/(\[[^\]]+\])/';
        $tokens  = preg_split( $pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
        $parts   = array();

        foreach ( $tokens as $token ) {
            if ( preg_match( $pattern, $token ) ) {
                $parts[] = array(
                    'type'  => 'shortcode',
                    'value' => $token,
                );
            } else {
                $parts[] = array(
                    'type'  => 'text',
                    'value' => $token,
                );
            }
        }

        return $parts;
    }
}
