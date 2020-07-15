<?php
/**
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl.txt
 * Copyright 2012-2020 Jean-Sebastien Morisset (https://wpsso.com/)
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'These aren\'t the droids you\'re looking for.' );
}

if ( ! defined( 'WPSSO_PLUGINDIR' ) ) {
	die( 'Do. Or do not. There is no try.' );
}

if ( ! class_exists( 'WpssoRegister' ) ) {

	class WpssoRegister {

		private $p;

		public function __construct( &$plugin ) {

			$this->p =& $plugin;

			register_activation_hook( WPSSO_FILEPATH, array( $this, 'network_activate' ) );

			register_deactivation_hook( WPSSO_FILEPATH, array( $this, 'network_deactivate' ) );

			if ( is_multisite() ) {

				add_action( 'wpmu_new_blog', array( $this, 'wpmu_new_blog' ), 10, 6 );

				add_action( 'wpmu_activate_blog', array( $this, 'wpmu_activate_blog' ), 10, 5 );
			}
		}

		/**
		 * Fires immediately after a new site is created.
		 */
		public function wpmu_new_blog( $blog_id, $user_id, $domain, $path, $site_id, $meta ) {

			switch_to_blog( $blog_id );

			$this->activate_plugin();

			restore_current_blog();
		}

		/**
		 * Fires immediately after a site is activated (not called when users and sites are created by a Super Admin).
		 */
		public function wpmu_activate_blog( $blog_id, $user_id, $password, $signup_title, $meta ) {

			switch_to_blog( $blog_id );

			$this->activate_plugin();

			restore_current_blog();
		}

		public function network_activate( $sitewide ) {

			self::do_multisite( $sitewide, array( $this, 'activate_plugin' ) );
		}

		public function network_deactivate( $sitewide ) {

			self::do_multisite( $sitewide, array( $this, 'deactivate_plugin' ) );
		}

		/**
		 * uninstall.php defines constants before calling network_uninstall().
		 */
		public static function network_uninstall() {

			$sitewide = true;

			/**
			 * Uninstall from the individual blogs first.
			 */
			self::do_multisite( $sitewide, array( __CLASS__, 'uninstall_plugin' ) );

			$opts = get_site_option( WPSSO_SITE_OPTIONS_NAME, array() );

			if ( ! empty( $opts[ 'plugin_clean_on_uninstall' ] ) ) {

				delete_site_option( WPSSO_SITE_OPTIONS_NAME );
			}
		}

		private static function do_multisite( $sitewide, $method, $args = array() ) {

			if ( is_multisite() && $sitewide ) {

				global $wpdb;

				$db_query = 'SELECT blog_id FROM ' . $wpdb->blogs;
				$blog_ids = $wpdb->get_col( $db_query );

				foreach ( $blog_ids as $blog_id ) {

					switch_to_blog( $blog_id );

					call_user_func_array( $method, array( $args ) );
				}

				restore_current_blog();

			} else {
				call_user_func_array( $method, array( $args ) );
			}
		}

		private function activate_plugin() {

			Wpsso::init_textdomain();

			$this->check_required( WpssoConfig::$cf );

			$this->p->set_config( $activate = true );  // Apply filters and define the $cf[ '*' ] array.

			$this->p->set_options( $activate = true ); // Read / create options and site_options.

			$this->p->set_objects( $activate = true ); // Load all the class objects.

			/**
			 * Returns the event timestamp, or false if the event has not been registered.
			 */
			$new_install = WpssoUtilReg::get_ext_event_time( 'wpsso', 'install' ) ? false : true;

			/**
			 * Add the "person" role for all WpssoUser::get_public_ids(). 
			 */
			if ( $new_install ) {
				$this->p->user->schedule_add_person_role();
			}

			/**
			 * Register plugin install, activation, update times.
			 */
			$version = WpssoConfig::$cf[ 'plugin' ][ 'wpsso' ][ 'version' ];

			WpssoUtilReg::update_ext_version( 'wpsso', $version );

			/**
			 * Clear all caches on activate.
			 */
			if ( ! empty( $this->p->options[ 'plugin_clear_on_activate' ] ) ) {

				$short = WpssoConfig::$cf[ 'plugin' ][ 'wpsso' ][ 'short' ];

				$settings_page_link = $this->p->util->get_admin_url( 'advanced#sucom-tabset_plugin-tab_cache',
					_x( 'Clear All Caches on Activate', 'option label', 'wpsso' ) );

				$this->p->notice->upd( '<strong>' . sprintf( __( 'The %s plugin has been activated.', 'wpsso' ), $short ) . '</strong> ' .
					sprintf( __( 'A background task will begin shortly to clear all caches (%s is enabled).',
						'wpsso' ), $settings_page_link ) );

				$this->p->util->cache->schedule_clear( $user_id = get_current_user_id(), $clear_other = true );
			}

			/**
			 * End of plugin activation.
			 */
			if ( $this->p->debug->enabled ) {
				$this->p->debug->log( 'done plugin activation' );
			}
		}

		private function deactivate_plugin() {

			/**
			 * Clear all caches on deactivate.
			 *
			 * Do not call the WpssoUtilCache->schedule_clear() method since WPSSO will be deactivated before the scheduled task can begin.
			 *
			 * If 'plugin_clear_on_deactivate' is empty, then at least clear the disk cache.
			 */
			if ( ! empty( $this->p->options[ 'plugin_clear_on_deactivate' ] ) ) {

				$this->p->util->cache->clear( $user_id = 0, $clear_other = true, $clear_short = true, $refresh = false );

			} else {

				$cache_dir = constant( 'WPSSO_CACHEDIR' );

				if ( $dh = @opendir( $cache_dir ) ) {

					while ( $file_name = @readdir( $dh ) ) {

						$cache_file = $cache_dir . $file_name;

						if ( ! preg_match( '/^(\..*|index\.php)$/', $file_name ) && is_file( $cache_file ) ) {
							@unlink( $cache_file );
						}
					}

					closedir( $dh );
				}
			}

			if ( class_exists( 'WpssoAdmin' ) ) {	// Just in case.
				WpssoAdmin::reset_admin_check_options();
			}
		}

		/**
		 * uninstall.php defines constants before calling network_uninstall(), which calls do_multisite(), and then calls
		 * uninstall_plugin().
		 */
		private static function uninstall_plugin() {

			$blog_id = get_current_blog_id();

			$opts = get_option( WPSSO_OPTIONS_NAME, array() );

			if ( ! empty( $opts[ 'plugin_clean_on_uninstall' ] ) ) {

				delete_option( WPSSO_REG_TS_NAME );

				delete_option( WPSSO_OPTIONS_NAME );

				/**
				 * Delete post settings and meta.
				 */
				delete_metadata( $meta_type = 'post', $object_id = null, WPSSO_META_NAME, $meta_value = null, $delete_all = true );

				delete_metadata( $meta_type = 'post', $object_id = null, WPSSO_META_ATTACHED_NAME, $meta_value = null, $delete_all = true );

				delete_post_meta_by_key( '_wpsso_wpproductreview' );	// Re-created automatically.

				delete_post_meta_by_key( '_wpsso_wprecipemaker' );	// Re-created automatically.

				delete_post_meta_by_key( '_wpsso_wpultimaterecipe' );	// Re-created automatically.

				/**
				 * Delete term settings and meta.
				 */
				foreach ( WpssoTerm::get_public_ids() as $id ) {

					WpssoTerm::delete_term_meta( $id, WPSSO_META_NAME );

					WpssoTerm::delete_term_meta( $id, WPSSO_META_ATTACHED_NAME );
				}

				/**
				 * Delete user settings and meta.
				 */
				delete_metadata( $meta_type = 'user', $object_id = null, WPSSO_META_NAME, $meta_value = null, $delete_all = true );

				delete_metadata( $meta_type = 'user', $object_id = null, WPSSO_META_ATTACHED_NAME, $meta_value = null, $delete_all = true );

				delete_metadata( $meta_type = 'user', $object_id = null, WPSSO_PREF_NAME, $meta_value = null, $delete_all = true );

				while ( $blog_user_ids = SucomUtil::get_user_ids( $blog_id, '', 1000 ) ) {	// Get a maximum of 1000 user IDs at a time.

					foreach ( $blog_user_ids as $id ) {

						delete_user_option( $id, WPSSO_DISMISS_NAME );
	
						WpssoUser::delete_metabox_prefs( $id );

						WpssoUser::remove_role_by_id( $id, $role = 'person' );
					}
				}

				remove_role( 'person' );
			}

			/**
			 * Delete plugin transients.
			 */
			global $wpdb;

			$prefix   = '_transient_';	// Clear all transients, even if no timeout value.
			$db_query = 'SELECT option_name FROM ' . $wpdb->options . ' WHERE option_name LIKE \'' . $prefix . 'wpsso_%\';';
			$expired  = $wpdb->get_col( $db_query ); 

			foreach( $expired as $option_name ) { 

				$transient_name = str_replace( $prefix, '', $option_name );

				if ( ! empty( $transient_name ) ) {
					delete_transient( $transient_name );
				}
			}
		}

		private static function check_required( $cf ) {

			$plugin_name    = $cf[ 'plugin' ][ 'wpsso' ][ 'name' ];
			$plugin_short   = $cf[ 'plugin' ][ 'wpsso' ][ 'short' ];
			$plugin_version = $cf[ 'plugin' ][ 'wpsso' ][ 'version' ];

			foreach ( array( 'wp', 'php' ) as $key ) {

				if ( empty( $cf[ $key ][ 'min_version' ] ) ) {
					return;
				}

				switch ( $key ) {

					case 'wp':

						global $wp_version;

						$app_version = $wp_version;

						break;

					case 'php':

						$app_version = phpversion();

						break;
				}

				$app_label   = $cf[ $key ][ 'label' ];
				$min_version = $cf[ $key ][ 'min_version' ];
				$version_url = $cf[ $key ][ 'version_url' ];

				if ( version_compare( $app_version, $min_version, '>=' ) ) {
					continue;
				}

				if ( ! function_exists( 'deactivate_plugins' ) ) {

					require_once trailingslashit( ABSPATH ) . 'wp-admin/includes/plugin.php';
				}

				deactivate_plugins( WPSSO_PLUGINBASE, $silent = true );

				if ( method_exists( 'SucomUtil', 'safe_error_log' ) ) {

					$error_pre = sprintf( __( '%s warning:', 'wpsso' ), __METHOD__ );

					$error_msg = sprintf( __( '%1$s requires %2$s version %3$s or higher and has been deactivated.',
						'wpsso' ), $plugin_name, $app_label, $min_version );

					SucomUtil::safe_error_log( $error_pre . ' ' . $error_msg );
				}

				wp_die( 
					'<p>' . sprintf( __( 'You are using %1$s version %2$s &mdash; <a href="%3$s">this %1$s version is outdated, unsupported, possibly insecure</a>, and may lack important updates and features.',
						'wpsso' ), $app_label, $app_version, $version_url ) . '</p>' . 
					'<p>' . sprintf( __( '%1$s requires %2$s version %3$s or higher and has been deactivated.',
						'wpsso' ), $plugin_name, $app_label, $min_version ) . '</p>' . 
					'<p>' . sprintf( __( 'Please upgrade %1$s before trying to re-activate the %2$s plugin.',
						'wpsso' ), $app_label, $plugin_name ) . '</p>'
				);
			}
		}
	}
}
