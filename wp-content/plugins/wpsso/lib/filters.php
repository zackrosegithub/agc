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

if ( ! class_exists( 'WpssoFilters' ) ) {

	class WpssoFilters {

		private $p;

		public function __construct( &$plugin ) {

			/**
			 * Just in case - prevent filters from being hooked and executed more than once.
			 */
			static $do_once = null;

			if ( true === $do_once ) {
				return;	// Stop here.
			}

			$do_once = true;

			$this->p =& $plugin;

			if ( $this->p->debug->enabled ) {
				$this->p->debug->mark();
			}

			if ( ! empty( $this->p->avail[ 'media' ][ 'wp-retina-2x' ] ) ) {

				add_filter( 'option_wr2x_ignore_sizes', array( $this, 'update_wr2x_ignore_sizes' ), 10, 1 );
			}

			if ( is_admin() ) {

				/**
				 * Cleanup incorrect Yoast SEO notifications.
				 */
				if ( ! empty( $this->p->avail[ 'seo' ][ 'wpseo' ] ) ) {

					add_action( 'admin_init', array( $this, 'cleanup_wpseo_notifications' ), 15 );
				}

				if ( class_exists( 'GFForms' ) ) {

					add_action( 'gform_noconflict_styles', array( $this, 'update_gform_noconflict_styles' ) );

					add_action( 'gform_noconflict_scripts', array( $this, 'update_gform_noconflict_scripts' ) );
				}

				if ( class_exists( 'GravityView_Plugin' ) ) {

					add_action( 'gravityview_noconflict_styles', array( $this, 'update_gform_noconflict_styles' ) );

					add_action( 'gravityview_noconflict_scripts', array( $this, 'update_gform_noconflict_scripts' ) );
				}

			} else {

				/**
				 * Disable JetPack open graph meta tags.
				 */
				if ( ! empty( $this->p->avail[ 'util' ][ 'jetpack' ] ) ) {

					add_filter( 'jetpack_enable_opengraph', '__return_false', 1000 );

					add_filter( 'jetpack_enable_open_graph', '__return_false', 1000 );

					add_filter( 'jetpack_disable_twitter_cards', '__return_true', 1000 );
				}

				/**
				 * Disable Yoast SEO social meta tags and Schema markup.
				 */
				if ( ! empty( $this->p->avail[ 'seo' ][ 'wpseo' ] ) ) {

					/**
					 * Since Yoast SEO v14.0.
					 *
					 * Disable Yoast SEO social meta tags and Schema markup.
					 */
					if ( method_exists( 'Yoast\WP\SEO\Integrations\Front_End_Integration', 'get_presenters' ) ) {

						add_filter( 'wpseo_frontend_presenters', array( $this, 'cleanup_wpseo_frontend_presenters' ), 10000 );

					} else {

						add_action( 'template_redirect', array( $this, 'cleanup_wpseo_filters' ), 10000 );

						add_action( 'amp_post_template_head', array( $this, 'cleanup_wpseo_filters' ), -10000 );
					}
				}

				/**
				 * Disable Rank Math social meta tags.
				 */
				if ( ! empty( $this->p->avail[ 'seo' ][ 'rankmath' ] ) ) {

					add_action( 'rank_math/head', array( $this, 'cleanup_rankmath_filters' ), -10000 );
				}

				/**
				 * Prevent SNAP from adding meta tags for the Facebook user agent.
				 */
				if ( function_exists( 'nxs_initSNAP' ) ) {

					add_action( 'wp_head', array( __CLASS__, 'remove_snap_og_meta_tags_holder' ), -1000 );
				}

				/**
				 * Honor the FORCE_SSL constant on the front-end with a 301 redirect.
				 */
				if ( SucomUtil::get_const( 'FORCE_SSL' ) ) {

					add_action( 'wp_loaded', array( __CLASS__, 'force_ssl_redirect' ), -1000 );
				}
			}
		}

		public function update_wr2x_ignore_sizes( $mixed ) {

			global $_wp_additional_image_sizes;

			/**
			 * Maybe remove old image size names.
			 */
			if ( is_array( $mixed ) ) {

				foreach ( $mixed as $size_name => $disabled ) {

					if ( false !== strpos( $size_name, $this->p->lca . '-' ) ) {
						unset( $mixed[ $size_name ] );
					}
				}

			} else {

				$mixed = array();
			}

			/**
			 * Disable all current WPSSO image size names.
			 */
			foreach ( $_wp_additional_image_sizes as $size_name => $size_info ) {

				if ( false !== strpos( $size_name, $this->p->lca . '-' ) ) {
					$mixed[ $size_name ] = 1;
				}
			}

			return $mixed;
		}

		public function update_gform_noconflict_styles( $styles ) {

			return array_merge( $styles, array(
				'jquery-ui.js',
				'jquery-qtip.js',
				'sucom-admin-page',
				'sucom-settings-table',
				'sucom-metabox-tabs',
				'wp-color-picker',
			) );
		}

		public function update_gform_noconflict_scripts( $scripts ) {

			return array_merge( $scripts, array(
				'jquery-ui-datepicker',
				'jquery-qtip',
				'sucom-metabox',
				'sucom-tooltips',
				'wp-color-picker',
				'sucom-admin-media',
			) );
		}

		/**
		 * Cleanup incorrect Yoast SEO notifications.
		 */
		public function cleanup_wpseo_notifications() {

			if ( $this->p->debug->enabled ) {
				$this->p->debug->mark();
			}

			/**
			 * Yoast SEO only checks for a conflict with WPSSO if the Open Graph option is enabled.
			 */
			if ( method_exists( 'WPSEO_Options', 'get' ) ) {

				if ( ! WPSEO_Options::get( 'opengraph' ) ) {
					return;
				}
			}

			if ( class_exists( 'Yoast_Notification_Center' ) ) {

				$info = $this->p->cf[ 'plugin' ][ $this->p->lca ];
				$name = $this->p->cf[ 'plugin' ][ $this->p->lca ][ 'name' ];

				/**
				 * Since WordPress SEO v4.0.
				 */
				if ( method_exists( 'Yoast_Notification_Center', 'get_notification_by_id' ) ) {

					$notif_id     = 'wpseo-conflict-' . md5( $info[ 'base' ] );
					$notif_msg    = '<style>#' . $notif_id . '{display:none;}</style>';	// Hide our empty notification. ;-)
					$notif_center = Yoast_Notification_Center::get();
					$notif_obj    = $notif_center->get_notification_by_id( $notif_id );

					if ( empty( $notif_obj ) ) {
						return;
					}

					/**
					 * Note that Yoast_Notification::render() wraps the notification message with
					 * '<div class="yoast-alert"></div>'.
					 */
					if ( method_exists( 'Yoast_Notification', 'render' ) ) {
						$notif_html = $notif_obj->render();
					} else {
						$notif_html = $notif_obj->message;
					}

					if ( strpos( $notif_html, $notif_msg ) === false ) {

						update_user_meta( get_current_user_id(), $notif_obj->get_dismissal_key(), 'seen' );

						$notif_obj = new Yoast_Notification( $notif_msg, array( 'id' => $notif_id ) );

						$notif_center->add_notification( $notif_obj );
					}

				} elseif ( defined( 'Yoast_Notification_Center::TRANSIENT_KEY' ) ) {

					if ( false !== ( $wpseo_notif = get_transient( Yoast_Notification_Center::TRANSIENT_KEY ) ) ) {

						$wpseo_notif = json_decode( $wpseo_notif, $assoc = false );

						if ( ! empty( $wpseo_notif ) ) {

							foreach ( $wpseo_notif as $num => $notif_msgs ) {

								if ( isset( $notif_msgs->options->type ) && $notif_msgs->options->type == 'error' ) {

									if ( false !== strpos( $notif_msgs->message, $name ) ) {

										unset( $wpseo_notif[ $num ] );

										set_transient( Yoast_Notification_Center::TRANSIENT_KEY, json_encode( $wpseo_notif ) );
									}
								}
							}
                                        	}
					}
				}
			}
		}

		/**
		 * Since Yoast SEO v14.0.
		 *
		 * Disable Yoast SEO social meta tags and Schema markup.
		 */
		public function cleanup_wpseo_frontend_presenters( $presenters ) {

			if ( $this->p->debug->enabled ) {
				$this->p->debug->mark();
			}

			foreach ( $presenters as $num => $obj ) {

				$class_name = get_class( $obj );

				if ( preg_match( '/(Open_Graph|Twitter|Schema)/', $class_name ) ) {
			
					if ( $this->p->debug->enabled ) {
						$this->p->debug->log( 'removing presenter: ' . $class_name );
					}

					unset( $presenters[ $num ] );

				} else {

					if ( $this->p->debug->enabled ) {
						$this->p->debug->log( 'skipping presenter: ' . $class_name );
					}
				}
			}

			return $presenters;
		}

		/**
		 * Deprecated since 2020/04/28 by Yoast SEO v14.0.
		 *
		 * Disable Yoast SEO social meta tags and Schema markup.
		 */
		public function cleanup_wpseo_filters() {

			if ( $this->p->debug->enabled ) {
				$this->p->debug->mark();
			}


			if ( isset( $GLOBALS[ 'wpseo_og' ] ) && is_object( $GLOBALS[ 'wpseo_og' ] ) ) {

				if ( false !== ( $prio = has_action( 'wpseo_head', array( $GLOBALS[ 'wpseo_og' ], 'opengraph' ) ) ) ) {

					if ( $this->p->debug->enabled ) {
						$this->p->debug->log( 'removing wpseo_head action for opengraph' );
					}

					$ret = remove_action( 'wpseo_head', array( $GLOBALS[ 'wpseo_og' ], 'opengraph' ), $prio );
				}
			}

			if ( class_exists( 'WPSEO_Twitter' ) ) {

				if ( false !== ( $prio = has_action( 'wpseo_head', array( 'WPSEO_Twitter', 'get_instance' ) ) ) ) {

					if ( $this->p->debug->enabled ) {
						$this->p->debug->log( 'removing wpseo_head action for twitter' );
					}

					$ret = remove_action( 'wpseo_head', array( 'WPSEO_Twitter', 'get_instance' ), $prio );
				}
			}

			if ( isset( WPSEO_Frontend::$instance ) ) {

				if ( false !== ( $prio = has_action( 'wpseo_head', array( WPSEO_Frontend::$instance, 'publisher' ) ) ) ) {

					if ( $this->p->debug->enabled ) {
						$this->p->debug->log( 'removing wpseo_head action for publisher' );
					}

					$ret = remove_action( 'wpseo_head', array( WPSEO_Frontend::$instance, 'publisher' ), $prio );
				}
			}

			/**
			 * Disable Yoast SEO JSON-LD.
			 */
			if ( $this->p->debug->enabled ) {
				$this->p->debug->log( 'disabling wpseo_json_ld_output filters' );
			}

			add_filter( 'wpseo_json_ld_output', '__return_false', PHP_INT_MAX );

			add_filter( 'wpseo_schema_graph_pieces', '__return_empty_array', PHP_INT_MAX );
		}

		/**
		 * Disable Rank Math social meta tags and Schema markup.
		 */
		public function cleanup_rankmath_filters() {

			if ( $this->p->debug->enabled ) {
				$this->p->debug->mark();
			}

			remove_all_actions( 'rank_math/opengraph/facebook' );
			remove_all_actions( 'rank_math/opengraph/twitter' );
			remove_all_actions( 'rank_math/json_ld' );
		}

		/**
		 * Prevent SNAP from adding meta tags for the Facebook user agent.
		 */
		public static function remove_snap_og_meta_tags_holder() {

			remove_action( 'wp_head', 'nxs_addOGTagsPreHolder', 150 );
		}

		/**
		 * Redirect from HTTP to HTTPS if the current webpage URL is not HTTPS. A 301 redirect is considered a best
		 * practice when moving from HTTP to HTTPS. See https://en.wikipedia.org/wiki/HTTP_301 for more info.
		 */
		public static function force_ssl_redirect() {

			/**
			 * Check for web server variables in case WP is being used from the command line.
			 */
			if ( isset( $_SERVER[ 'HTTP_HOST' ] ) && isset( $_SERVER[ 'REQUEST_URI' ] ) ) {

				if ( ! SucomUtil::is_https() ) {

					wp_redirect( 'https://' . $_SERVER[ 'HTTP_HOST' ] . $_SERVER[ 'REQUEST_URI' ], 301 );

					exit();
				}
			}
		}
	}
}
