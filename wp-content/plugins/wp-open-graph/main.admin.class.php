<?php

/**
 * Class NY_OG_Main_Admin
 */
// If this file is called directly, abort.
if ( !defined( 'ABSPATH' ) ) {
    die();
}

class NY_OG_Main_Admin {

    static $options;

    static public function init() {
        self::$options = get_option( 'wpog_options' );

        if ( !is_array( self::$options ) ) {
            self::$options = array(
                'blog_title'       => get_bloginfo( 'name' ),
                'home_title'       => get_bloginfo( 'name' ),
                'blog_type'        => 'blog',
                'home_type'        => 'website',
                'blog_description' => get_bloginfo( 'description' ),
                'home_description' => get_bloginfo( 'description' ),
            );
        }

        add_action( 'admin_menu', array( __CLASS__, 'wpog_add_pages' ) );
    }

    public function option( $name ) {
        return isset( self::$options[$name] ) ? sanitize_text_field( self::$options[$name] ) : null;
    }

    public function set_options( $options ) {
        self::$options = array_merge( self::$options, $options );

        update_option( 'wpog_options', self::$options );
    }

    public function wpog_add_pages() {
        add_options_page( __( 'WP Open Graph' ), __( 'WP Open Graph' ), 'manage_options', 'wp-open-graph', array(
            __CLASS__,
            'main_settings',
        ) );
    }

    public function main_settings() {
        if ( isset( $_POST['wpog_options'] ) ) {
            check_admin_referer( 'graf' );
            NY_OG_Main_Admin::set_options( $_POST['wpog_options'] );
        }

        require_once 'main.admin.form.php';
    }

}
