<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIT_OpenAI {
    public static function translate( $content, $target_language ) {
        $settings = WPAIT_Settings::get_settings();
        if ( empty( $settings['api_key'] ) ) {
            return new WP_Error( 'wpait_no_key', __( 'Missing API key.', 'wp-ai-translator' ) );
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $settings['api_key'],
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'model'    => $settings['model'],
                        'messages' => array(
                            array(
                                'role'    => 'system',
                                'content' => 'You are a translation assistant that preserves WordPress shortcodes and only translates human-readable text.',
                            ),
                            array(
                                'role'    => 'user',
                                'content' => sprintf( 'Translate the following content to %s:', $target_language ) . "\n\n" . $content,
                            ),
                        ),
                    )
                ),
                'timeout' => 60,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'wpait_bad_response', __( 'Unexpected API response.', 'wp-ai-translator' ) );
        }

        return trim( $body['choices'][0]['message']['content'] );
    }
}
