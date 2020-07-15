<?php
/**
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl.txt
 * Copyright 2012-2020 Jean-Sebastien Morisset (https://surniaulula.com/)
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'These aren\'t the droids you\'re looking for.' );
}

if ( ! class_exists( 'SucomNotice' ) ) {

	class SucomNotice {

		private $p;
		private $lca          = 'sucom';
		private $uca          = 'SUCOM';
		private $text_domain  = 'sucom';
		private $dismiss_name = 'sucom_dismissed';
		private $default_ttl  = 600;
		private $label_transl = false;
		private $doing_dev    = false;
		private $use_cache    = true;	// Read/save minimized CSS from/to transient cache.
		private $tb_notices   = false;
		private $has_shown    = false;
		private $all_types    = array( 'nag', 'err', 'warn', 'inf', 'upd' );	// Sort by importance (most to least).
		private $notice_info  = array();
		private $notice_cache = array();
		private $cache_loaded = array();

		public $enabled = true;

		public function __construct( $plugin = null, $lca = null, $text_domain = null, $label_transl = false ) {

			static $do_once = null;	// Just in case.

			if ( true === $do_once ) {
				return;
			}

			$do_once = true;

			if ( ! class_exists( 'SucomUtil' ) ) {	// Just in case.
				require_once trailingslashit( dirname( __FILE__ ) ) . 'util.php';
			}

			$this->set_config( $plugin, $lca, $text_domain, $label_transl );

			$this->add_wp_hooks();
		}

		/**
		 * Set property values for text domain, notice label, etc.
		 */
		private function set_config( $plugin = null, $lca = null, $text_domain = null, $label_transl = false ) {

			if ( $plugin !== null ) {

				$this->p =& $plugin;

				if ( ! empty( $this->p->debug->enabled ) ) {
					$this->p->debug->mark();
				}
			}

			/**
			 * Set the lower and upper case acronyms.
			 */
			if ( $lca !== null ) {
				$this->lca = $lca;
			} elseif ( ! empty( $this->p->lca ) ) {
				$this->lca = $this->p->lca;
			}

			$this->uca = strtoupper( $this->lca );

			/**
			 * Set the text domain.
			 */
			if ( $text_domain !== null ) {
				$this->text_domain = $text_domain;
			} elseif ( ! empty( $this->p->cf[ 'plugin' ][ $this->lca ][ 'text_domain' ] ) ) {
				$this->text_domain = $this->p->cf[ 'plugin' ][ $this->lca ][ 'text_domain' ];
			}

			/**
			 * Set the dismiss key name.
			 */
			if ( defined( $this->uca . '_DISMISS_NAME' ) ) {
				$this->dismiss_name = constant( $this->uca . '_DISMISS_NAME' );
			} else {
				$this->dismiss_name = $this->lca . '_dismissed';
			}

			/**
			 * Set the translated notice label.
			 */
			if ( false !== $label_transl ) {
				$this->label_transl = $label_transl;
			} elseif ( ! empty( $this->p->cf[ 'menu' ][ 'title' ] ) ) {
				$this->label_transl = sprintf( __( '%s Notice', $this->text_domain ),
					_x( $this->p->cf[ 'menu' ][ 'title' ], 'menu title', $this->text_domain ) );
			} else {
				$this->label_transl = __( 'Notice', $this->text_domain );
			}

			/**
			 * Determine if the DEV constant is defined.
			 */
			$this->doing_dev = SucomUtil::get_const( $this->uca . '_DEV' );
			$this->use_cache = $this->doing_dev ? false : true;	// Read/save minimized CSS from/to transient cache.

			/**
			 * Set the notification system.
			 */
			if ( ! is_admin_bar_showing() ) {	// Just in case.

				$this->tb_notices = false;

			} if ( empty( $this->p->options[ 'plugin_notice_system' ] ) ) {	// Just in case.

				$this->tb_notices = true;

			} elseif ( 'toolbar_notices' === $this->p->options[ 'plugin_notice_system' ] ) {

				$this->tb_notices = true;
			} else {
				$this->tb_notices = false;
			}

			if ( defined( $this->uca . '_TOOLBAR_NOTICES' ) ) {

				if ( ! empty( $this->p->debug->enabled ) ) {
					$this->p->debug->log( $this->uca . '_TOOLBAR_NOTICES is defined' );
				}

				$this->tb_notices = constant( $this->uca . '_TOOLBAR_NOTICES' );
			}

			if ( true === $this->tb_notices ) {

				if ( ! empty( $this->p->debug->enabled ) ) {
					$this->p->debug->log( 'tb_notices is true' );
				}

				$this->tb_notices = array( 'err', 'warn', 'inf' );
			}

			if ( empty( $this->tb_notices ) || ! is_array( $this->tb_notices ) ) {

				if ( ! empty( $this->p->debug->enabled ) ) {
					$this->p->debug->log( 'tb_notices is empty or not an array' );
				}

				$this->tb_notices = false;
			}
		}

		/**
		 * Add WordPress action and filters hooks.
		 */
		private function add_wp_hooks() {

			if ( is_admin() ) {

				add_action( 'wp_ajax_' . $this->lca . '_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
				add_action( 'wp_ajax_' . $this->lca . '_get_notices_json', array( $this, 'ajax_get_notices_json' ) );
				add_action( 'in_admin_header', array( $this, 'admin_header_notices' ), PHP_INT_MAX );
				add_action( 'admin_footer', array( $this, 'admin_footer_script' ) );
				add_action( 'shutdown', array( $this, 'shutdown_notice_cache' ) );
			}
		}

		public function nag( $msg_text, $user_id = null, $notice_key = false, $dismiss_time = false, $payload = array() ) {

			/**
			 * Do not show a dismiss button by default.
			 *
			 * if ( ! isset( $payload[ 'dismiss_diff' ] ) ) {
			 * 	$payload[ 'dismiss_diff' ] = false;
			 * }
			 */

			$this->log( 'nag', $msg_text, $user_id, $notice_key, $dismiss_time, $payload );
		}

		public function err( $msg_text, $user_id = null, $notice_key = false, $dismiss_time = false, $payload = array() ) {

			$this->log( 'err', $msg_text, $user_id, $notice_key, $dismiss_time, $payload );
		}

		public function warn( $msg_text, $user_id = null, $notice_key = false, $dismiss_time = false, $payload = array() ) {

			$this->log( 'warn', $msg_text, $user_id, $notice_key, $dismiss_time, $payload );
		}

		public function inf( $msg_text, $user_id = null, $notice_key = false, $dismiss_time = false, $payload = array() ) {

			$this->log( 'inf', $msg_text, $user_id, $notice_key, $dismiss_time, $payload );
		}

		public function upd( $msg_text, $user_id = null, $notice_key = false, $dismiss_time = false, $payload = array() ) {

			$this->log( 'upd', $msg_text, $user_id, $notice_key, $dismiss_time, $payload );
		}

		private function log( $msg_type, $msg_text, $user_id = null, $notice_key = false, $dismiss_time = false, $payload = array() ) {

			if ( empty( $msg_type ) || empty( $msg_text ) ) {
				return false;
			}

			$current_user_id = get_current_user_id();	// Always returns an integer.

			$user_id = is_numeric( $user_id ) ? (int) $user_id : $current_user_id;	// User ID can be true, false, null, or a number.

			if ( empty( $user_id ) ) {	// User ID is 0 (cron user, for example).
				return false;
			}

			$payload[ 'notice_label' ] = isset( $payload[ 'notice_label' ] ) ? $payload[ 'notice_label' ] : $this->label_transl;

			$payload[ 'notice_key' ] = empty( $notice_key ) ? false : sanitize_key( $notice_key );

			$payload[ 'notice_time' ] = time();

			/**
			 * 0 disables notice expiration.
			 */
			$payload[ 'notice_ttl' ]  = isset( $payload[ 'notice_ttl' ] ) ? (int) $payload[ 'notice_ttl' ] : $this->default_ttl;

			$payload[ 'dismiss_time' ] = false;

			$payload[ 'dismiss_diff' ] = isset( $payload[ 'dismiss_diff' ] ) ? $payload[ 'dismiss_diff' ] : null;

			/**
			 * Add dismiss text for dismiss button and notice message.
			 */
			if ( $this->can_dismiss() ) {

				$payload[ 'dismiss_time' ] = $dismiss_time;	// Maybe true, false, 0, or seconds greater than 0.

				if ( null === $payload[ 'dismiss_diff' ] ) {	// Has not been provided, so set a default value.

					$dismiss_suffix_msg = false;

					if ( true === $payload[ 'dismiss_time' ] ) {	// True.

						$payload[ 'dismiss_diff' ] = __( 'Forever', $this->text_domain );

						$dismiss_suffix_msg = __( 'This notice can be dismissed permanently.', $this->text_domain );

					} elseif ( empty( $payload[ 'dismiss_time' ] ) ) {	// False or 0 seconds.

						$payload[ 'dismiss_time' ] = false;

						$payload[ 'dismiss_diff' ] = false;

					} elseif ( is_numeric( $payload[ 'dismiss_time' ] ) ) {	// Seconds greater than 0.

						$payload[ 'dismiss_diff' ] = human_time_diff( 0, $payload[ 'dismiss_time' ] );

						$dismiss_suffix_msg = __( 'This notice can be dismissed for %s.', $this->text_domain );
					}

					if ( ! empty( $payload[ 'dismiss_diff' ] ) && $dismiss_suffix_msg ) {

						$msg_text = trim( $msg_text );

						$msg_close_div = '';

						if ( substr( $msg_text, -6 ) === '</div>' ) {
							$msg_text = substr( $msg_text, 0, -6 );
							$msg_close_div = '</div>';
						}

						$msg_add_p = substr( $msg_text, -4 ) === '</p>' ? true : false;

						$msg_text .= $msg_add_p || $msg_close_div ? '<p>' : ' ';
						$msg_text .= sprintf( $dismiss_suffix_msg, $payload[ 'dismiss_diff' ] );
						$msg_text .= $msg_add_p || $msg_close_div ? '</p>' : '';
						$msg_text .= $msg_close_div;
					}
				}
			}

			/**
			 * Maybe add a reference URL at the end.
			 */
			$msg_text .= $this->get_ref_url_html();

			$payload[ 'msg_text' ] = preg_replace( '/<!--spoken-->(.*?)<!--\/spoken-->/Us', ' ', $msg_text );

			$payload[ 'msg_spoken' ] = preg_replace( '/<!--not-spoken-->(.*?)<!--\/not-spoken-->/Us', ' ', $msg_text );
			$payload[ 'msg_spoken' ] = SucomUtil::decode_html( SucomUtil::strip_html( $payload[ 'msg_spoken' ] ) );

			$msg_key = empty( $payload[ 'notice_key' ] ) ? sanitize_key( $payload[ 'msg_spoken' ] ) : $payload[ 'notice_key' ];

			$this->notice_cache[ $user_id ][ $msg_type ][ $msg_key ] = $payload;

			/**
			 * Update the notice transient now if we're adding a notice for a different user ID, otherwise wait for the
			 * shutdown_notice_cache() method to execute.
			 */
			if ( $user_id !== $current_user_id ) {

				$this->update_notice_transient( $user_id );
			}
		}

		/**
		 * Clear a single notice key from the notice cache.
		 */
		public function clear_key( $notice_key, $user_id = null ) {

			$this->clear( '', '', $notice_key, $user_id );
		}

		/**
		 * Clear a message type, message text, notice key from the notice cache, or clear all notices.
		 */
		public function clear( $msg_type = '', $msg_text = '', $notice_key = false, $user_id = null ) {

			$current_user_id = get_current_user_id();	// Always returns an integer.

			if ( is_array( $user_id ) ) {

				$trunc_user_ids = $user_id;

			} else {

				$user_id = is_numeric( $user_id ) ? (int) $user_id : $current_user_id;	// User ID can be true, false, null, or a number.

				if ( empty( $user_id ) ) {	// User ID is 0 (cron user, for example).
					return false;
				}

				$trunc_user_ids = array( $user_id );
			}

			unset( $user_id );	// A reminder that we are re-using this variable name below.

			$trunc_types = empty( $msg_type ) ? $this->all_types : array( (string) $msg_type );

			foreach ( $trunc_user_ids as $user_id ) {

				$this->maybe_load_notice_cache( $user_id );

				foreach ( $trunc_types as $msg_type ) {

					/**
					 * Clear notice for a specific notice key.
					 */
					if ( ! empty( $notice_key ) ) {

						foreach ( $this->notice_cache[ $user_id ][ $msg_type ] as $msg_key => $payload ) {

							if ( ! empty( $payload[ 'notice_key' ] ) && $payload[ 'notice_key' ] === $notice_key ) {

								unset( $this->notice_cache[ $user_id ][ $msg_type ][ $msg_key ] );
							}
						}

					/**
					 * Clear a specific message text.
					 */
					} elseif ( ! empty( $msg_text ) ) {

						foreach ( $this->notice_cache[ $user_id ][ $msg_type ] as $msg_key => $payload ) {

							if ( ! empty( $payload[ 'msg_text' ] ) && $payload[ 'msg_text' ] === $msg_text ) {

								unset( $this->notice_cache[ $user_id ][ $msg_type ][ $msg_key ] );
							}
						}

					/**
					 * Clear all notices for a message type.
					 */
					} else {
						$this->notice_cache[ $user_id ][ $msg_type ] = array();
					}
				}
			}
		}

		/**
		 * Set reference values for admin notices.
		 */
		public function set_ref( $url = null, $mod = false, $context_transl = null ) {

			$this->notice_info[] = array(
				'url'            => $url,
				'mod'            => $mod,
				'context_transl' => $context_transl,
			);

			return $url;
		}

		/**
		 * Restore previous reference values for admin notices.
		 */
		public function unset_ref( $url = null ) {

			if ( null === $url || $this->is_ref_url( $url ) ) {

				array_pop( $this->notice_info );

				return true;

			}

			return false;
		}

		public function get_ref( $ref_key = false, $text_prefix = '', $text_suffix = '' ) {

			$refs = end( $this->notice_info );	// Get the last reference added.

			if ( 'edit' === $ref_key ) {

				$link = '';

				if ( ! empty( $refs[ 'mod' ] ) ) {

					if ( ! empty( $refs[ 'mod' ][ 'id' ] ) && is_numeric( $refs[ 'mod' ][ 'id' ] ) ) {

						if ( $refs[ 'mod' ][ 'is_post' ] ) {

							$link = get_edit_post_link( $refs[ 'mod' ][ 'id' ], $display = false );

						} elseif ( $refs[ 'mod' ][ 'is_user' ] ) {

							$link = get_edit_user_link( $refs[ 'mod' ][ 'id' ] );

						} elseif ( $refs[ 'mod' ][ 'is_term' ] ) {

							$link = get_edit_term_link( $refs[ 'mod' ][ 'id' ], $refs[ 'mod' ][ 'tax_slug' ] );
						}
					}
				}

				return empty( $link ) ? '' : $text_prefix . $link . $text_suffix;

			} elseif ( false !== $ref_key ) {

				if ( isset( $refs[ $ref_key ] ) ) {
					return $text_prefix . $refs[ $ref_key ] . $text_suffix;
				}

				return null;

			}

			return $refs;
		}

		public function get_ref_url_html() {

			$ref_html = '';

			if ( $url = $this->get_ref( $ref_key = 'url' ) ) {

				/**
				 * Show a shorter relative URL, if possible.
				 */
				$pretty_url = strtolower( str_replace( home_url(), '', $url ) );

				$context_transl = $this->get_ref( $ref_key = 'context_transl' );

				$context_transl = empty( $context_transl ) ?
					'<a href="' . $url . '">' . $pretty_url . '</a>' :
					'<a href="' . $url . '">' . $context_transl . '</a>';

				/**
				 * Returns an empty string or a clickable (Edit) link.
				 */
				$edit_html = $this->get_ref(
					$ref_key     = 'edit',
					$text_prefix = ' (<a href="',
					$text_suffix = '">' . __( 'Edit', $this->text_domain ) . '</a>)'
				);

				$ref_html .= ' <p class="reference-message">' .
					sprintf( __( 'Reference: %s', $this->text_domain ),
						$context_transl . $edit_html ) . '</p>';
			}

			return $ref_html;
		}

		public function is_ref_url( $url = null ) {

			if ( null === $url || $url === $this->get_ref( $ref_key = 'url' ) ) {
				return true;
			} else {
				return false;
			}
		}

		public function is_admin_pre_notices( $notice_key = false, $user_id = null ) {

			if ( is_admin() ) {

				if ( ! empty( $notice_key ) ) {

					/**
					 * If notice is dismissed, say that we've already shown the notices.
					 */
					if ( $this->is_dismissed( $notice_key, $user_id ) ) {

						if ( ! empty( $this->p->debug->enabled ) ) {
							$this->p->debug->log( 'returning false: ' . $notice_key . ' is dismissed' );
						}

						return false;
					}
				}

				if ( $this->has_shown ) {

					if ( ! empty( $this->p->debug->enabled ) ) {
						$this->p->debug->log( 'returning false: notices have been shown' );
					}

					return false;
				}

			} else {

				if ( ! empty( $this->p->debug->enabled ) ) {
					$this->p->debug->log( 'returning false: is not admin' );
				}

				return false;

			}

			return true;
		}

		public function force_expire( $notice_key = false, $user_id = null ) {

			$this->is_dismissed( $notice_key, $user_id, $force_expire = true );
		}

		public function is_dismissed( $notice_key = false, $user_id = null, $force_expire = false ) {

			if ( empty( $notice_key ) || ! $this->can_dismiss() ) {	// Just in case.
				return false;
			}

			$current_user_id = get_current_user_id();	// Always returns an integer.

			$user_id = is_numeric( $user_id ) ? (int) $user_id : $current_user_id;	// User ID can be true, false, null, or a number.

			if ( empty( $user_id ) ) {	// User ID is 0 (cron user, for example).
				return false;
			}

			$user_dismissed = get_user_option( $this->dismiss_name, $user_id );

			if ( ! is_array( $user_dismissed ) ) {
				return false;
			}

			if ( isset( $user_dismissed[ $notice_key ] ) ) {	// Notice has been dismissed.

				$current_time = time();

				$dismiss_time = $user_dismissed[ $notice_key ];

				if ( ! $force_expire && ( empty( $dismiss_time ) || $dismiss_time > $current_time ) ) {

					return true;

				} else {	// Dismiss time has expired.

					unset( $user_dismissed[ $notice_key ] );

					if ( empty( $user_dismissed ) ) {
						delete_user_option( $user_id, $this->dismiss_name );
					} else {
						update_user_option( $user_id, $this->dismiss_name, $user_dismissed );
					}
			
					return false;
				}
			}

			return false;
		}

		public function can_dismiss() {

			global $wp_version;

			if ( version_compare( $wp_version, '4.2', '>=' ) ) {
				return true;
			}

			return false;
		}

		public function admin_header_notices() {

			add_action( 'all_admin_notices', array( $this, 'show_admin_notices' ), -10 );
		}

		public function show_admin_notices() {

			if ( ! empty( $this->p->debug->enabled ) ) {
				$this->p->debug->mark();
			}

			$notice_types = $this->all_types;

			/**
			 * If toolbar notices are being used, exclude these from being shown. The default toolbar notices array is
			 * err, warn, and inf.
			 */
			if ( ! empty( $this->tb_notices ) && is_array( $this->tb_notices ) ) {

				if ( ! empty( $this->p->debug->enabled ) ) {
					$this->p->debug->log_arr( 'tb_notices', $this->tb_notices );
				}

				$notice_types = array_diff( $notice_types, $this->tb_notices );

			} else {

				if ( ! empty( $this->p->debug->enabled ) ) {
					$this->p->debug->log( 'toolbar notices are disabled' );
				}
			}

			if ( ! empty( $this->p->debug->enabled ) ) {
				$this->p->debug->log_arr( 'notice_types', $notice_types );
			}

			if ( empty( $notice_types ) ) {	// Just in case.
				return;
			}

			echo "\n" . '<!-- ' . $this->lca . ' admin notices begin -->' . "\n";

			echo '<div id="' . sanitize_html_class( $this->lca . '-admin-notices-begin' ) . '"></div>' . "\n";

			echo $this->get_notice_style();

			/**
			 * Exit early if this is a block editor page. The notices will be retrieved using an ajax call on page load
			 * and post save.
			 */
			if ( SucomUtilWP::doing_block_editor() ) {

				if ( ! empty( $this->p->debug->enabled ) ) {
					$this->p->debug->log( 'exiting early: doing block editor' );
				}

				return;
			}

			if ( ! empty( $this->p->debug->enabled ) ) {
				$this->p->debug->log( 'doing block editor is false' );
			}

			$nag_html         = '';
			$msg_html         = '';
			$user_id          = get_current_user_id();	// Always returns an integer.
			$user_dismissed   = $user_id ? get_user_option( $this->dismiss_name, $user_id ) : false;
			$update_user_meta = false;

			$this->has_shown = true;

			$this->maybe_load_notice_cache( $user_id );

			$this->maybe_add_update_errors( $user_id );

			/**
			 * Loop through all the msg types and show them all.
			 */
			foreach ( $notice_types as $msg_type ) {

				if ( ! isset( $this->notice_cache[ $user_id ][ $msg_type ] ) ) {	// Just in case.
					continue;
				}

				foreach ( $this->notice_cache[ $user_id ][ $msg_type ] as $msg_key => $payload ) {

					unset( $this->notice_cache[ $user_id ][ $msg_type ][ $msg_key ] );	// Don't show it twice.

					if ( empty( $payload[ 'msg_text' ] ) ) {	// Nothing to show.
						continue;
					}

					/**
					 * Make sure the notice has not exceeded its TTL.
					 *
					 * A 'notice_ttl' value of 0 disables the notice message expiration.
					 */
					if ( ! empty( $payload[ 'notice_time' ] ) && ! empty( $payload[ 'notice_ttl' ] ) ) {

						if ( time() > $payload[ 'notice_time' ] + $payload[ 'notice_ttl' ] ) {

							continue;
						}
					}

					if ( ! empty( $payload[ 'dismiss_time' ] ) ) {	// True or seconds greater than 0.

						/**
						 * Check for automatically hidden errors and/or warnings.
						 */
						if ( ! empty( $payload[ 'notice_key' ] ) && isset( $user_dismissed[ $payload[ 'notice_key' ] ] ) ) {

							$current_time = time();

							$dismiss_time = $user_dismissed[ $payload[ 'notice_key' ] ];	// Get time for key.

							if ( empty( $dismiss_time ) || $dismiss_time > $current_time ) {	// 0 or time in future.

								$payload[ 'hidden' ] = true;

							} else {	// Dismiss has expired.

								$update_user_meta = true;	// Update the user meta when done.

								unset( $user_dismissed[ $payload[ 'notice_key' ] ] );
							}
						}
					}

					/**
					 * Only show a single nag message at a time.
					 */
					if ( 'nag' === $msg_type ) {

						if ( empty( $nag_html ) ) {
							$nag_html .= $this->get_notice_html( $msg_type, $payload );
						}

					} else {
						$msg_html .= $this->get_notice_html( $msg_type, $payload );
					}
				}
			}

			/**
			 * Don't save unless we've changed something.
			 */
			if ( ! empty( $user_id ) ) {	// Just in case.

				if ( true === $update_user_meta ) {

					if ( empty( $user_dismissed ) ) {
						delete_user_option( $user_id, $this->dismiss_name );
					} else {
						update_user_option( $user_id, $this->dismiss_name, $user_dismissed );
					}
				}
			}

			if ( ! empty( $nag_html ) ) {

				echo $this->get_nag_style();

				echo $nag_html . "\n";
			}

			echo $msg_html . "\n";

			echo '<!-- ' . $this->lca . ' admin notices end -->' . "\n";
		}

		public function admin_footer_script() {

			echo $this->get_notice_script();
		}

		public function ajax_dismiss_notice() {

			if ( ! SucomUtil::get_const( 'DOING_AJAX' ) ) {	// Just in case.
				return;
			}

			$user_id      = get_current_user_id();	// Always returns an integer.
			$dismiss_info = array();

			if ( empty( $user_id ) || ! current_user_can( 'edit_user', $user_id ) ) {
				die( -1 );
			}

			check_ajax_referer( __FILE__, 'dismiss_nonce', true );

			/**
			 * Quick sanitation of input values.
			 */
			foreach ( array( 'notice_key', 'dismiss_time' ) as $key ) {
				$dismiss_info[ $key ] = sanitize_text_field( filter_input( INPUT_POST, $key ) );
			}

			if ( empty( $dismiss_info[ 'notice_key' ] ) ) {	// Just in case.
				die( -1 );
			}

			$user_dismissed = get_user_option( $this->dismiss_name, $user_id );

			if ( ! is_array( $user_dismissed ) ) {
				$user_dismissed = array();
			}

			if ( empty( $dismiss_info[ 'dismiss_time' ] ) || ! is_numeric( $dismiss_info[ 'dismiss_time' ] ) ) {
				$user_dismissed[ $dismiss_info[ 'notice_key' ] ] = 0;
			} else {
				$user_dismissed[ $dismiss_info[ 'notice_key' ] ] = time() + $dismiss_info[ 'dismiss_time' ];
			}

			update_user_option( $user_id, $this->dismiss_name, $user_dismissed );

			die( '1' );
		}

		public function ajax_get_notices_json() {

			if ( ! SucomUtil::get_const( 'DOING_AJAX' ) ) {
				return;
			} elseif ( SucomUtil::get_const( 'DOING_AUTOSAVE' ) ) {
				die( -1 );
			}

			$notice_types = $this->all_types;

			if ( ! empty( $_REQUEST[ '_notice_types' ] ) ) {

				if ( is_array( $_REQUEST[ '_notice_types' ] ) ) {
					$notice_types = $_REQUEST[ '_notice_types' ];
				} else {
					$notice_types = explode( ',', $_REQUEST[ '_notice_types' ] );
				}
			}

			if ( ! empty( $_REQUEST[ '_exclude_types' ] ) ) {

				if ( is_array( $_REQUEST[ '_exclude_types' ] ) ) {
					$exclude_types = $_REQUEST[ '_exclude_types' ];
				} else {
					$exclude_types = explode( ',', $_REQUEST[ '_exclude_types' ] );
				}

				$notice_types = array_diff( $notice_types, $exclude_types );
			}

			if ( empty( $notice_types ) ) {	// Just in case.
				die( -1 );
			}

			check_ajax_referer( WPSSO_NONCE_NAME, '_ajax_nonce', true );

			$user_id          = get_current_user_id();	// Always returns an integer.
			$user_dismissed   = $user_id ? get_user_option( $this->dismiss_name, $user_id ) : false;
			$update_user_meta = false;
			$json_notices     = array();
			$ajax_context     = empty( $_REQUEST[ 'context' ] ) ? '' : $_REQUEST[ 'context' ];	// 'block_editor' or 'toolbar_notices'

			$this->has_shown = true;

			$this->maybe_load_notice_cache( $user_id );

			$this->maybe_add_update_errors( $user_id );

			/**
			 * Loop through all the msg types and show them all.
			 */
			foreach ( $notice_types as $msg_type ) {

				if ( ! isset( $this->notice_cache[ $user_id ][ $msg_type ] ) ) {	// Just in case.

					continue;
				}

				foreach ( $this->notice_cache[ $user_id ][ $msg_type ] as $msg_key => $payload ) {

					unset( $this->notice_cache[ $user_id ][ $msg_type ][ $msg_key ] );	// Don't show it twice.

					if ( empty( $payload[ 'msg_text' ] ) ) {	// Nothing to show.

						continue;
					}

					/**
					 * Make sure the notice has not exceeded its TTL.
					 *
					 * A 'notice_ttl' value of 0 disables the notice message expiration.
					 */
					if ( ! empty( $payload[ 'notice_time' ] ) && ! empty( $payload[ 'notice_ttl' ] ) ) {

						if ( time() > $payload[ 'notice_time' ] + $payload[ 'notice_ttl' ] ) {

							continue;
						}
					}

					if ( ! empty( $payload[ 'dismiss_time' ] ) ) {	// True or seconds greater than 0.

						/**
						 * Check for automatically hidden errors and/or warnings.
						 */
						if ( ! empty( $payload[ 'notice_key' ] ) && isset( $user_dismissed[ $payload[ 'notice_key' ] ] ) ) {

							$current_time = time();

							$dismiss_time = $user_dismissed[ $payload[ 'notice_key' ] ];	// Get time for key.

							if ( empty( $dismiss_time ) || $dismiss_time > $current_time ) {	// 0 or time in future.

								$payload[ 'hidden' ] = true;

							} else {	// Dismiss has expired.

								$update_user_meta = true;	// Update the user meta when done.

								unset( $user_dismissed[ $payload[ 'notice_key' ] ] );
							}
						}
					}

					$payload[ 'msg_html' ] = $this->get_notice_html( $msg_type, $payload, true );	// $notice_alt is true.

					$json_notices[ $msg_type ][ $msg_key ] = $payload;
				}
			}

			/**
			 * Don't save unless we've changed something.
			 */
			if ( ! empty( $user_id ) ) {	// Just in case.

				if ( true === $update_user_meta ) {

					if ( empty( $user_dismissed ) ) {
						delete_user_option( $user_id, $this->dismiss_name );
					} else {
						update_user_option( $user_id, $this->dismiss_name, $user_dismissed );
					}
				}
			}

			$json_encoded = SucomUtil::json_encode_array( $json_notices );

			die( $json_encoded );
		}

		public function get_notice_system() {

			return $this->tb_notices;
		}

		private function get_notice_html( $msg_type, array $payload, $notice_alt = false ) {

			$charset = get_bloginfo( 'charset' );

			$notice_class = $notice_alt ? 'notice notice-alt' : 'notice';

			switch ( $msg_type ) {

				case 'nag':

					$payload[ 'notice_label' ] = '';	// No label for nag notices.

					$msg_type = 'nag';
					$wp_class = 'update-nag';

					break;

				case 'err':
				case 'error':

					$msg_type = 'err';
					$wp_class = $notice_class . ' notice-error error';

					break;

				case 'warn':
				case 'warning':

					$msg_type = 'warn';
					$wp_class = $notice_class . ' notice-warning';

					break;

				case 'inf':
				case 'info':

					$msg_type = 'inf';
					$wp_class = $notice_class . ' notice-info';

					break;

				case 'upd':
				case 'updated':

					$msg_type = 'upd';
					$wp_class = $notice_class . ' notice-success updated';

					break;

				default:	// Unknown $msg_type.

					$msg_type = 'unknown';
					$wp_class = $notice_class;

					break;
			}

			$css_id_attr = empty( $payload[ 'notice_key' ] ) ? '' : ' id="' . $msg_type . '-' . $payload[ 'notice_key' ] . '"';

			$is_dismissible = empty( $payload[ 'dismiss_time' ] ) ? false : true;

			$data_attr = '';

			if ( $is_dismissible ) {

				$data_attr .= ' data-notice-key="' . ( isset( $payload[ 'notice_key' ] ) ?
					esc_attr( $payload[ 'notice_key' ] ) : '' ). '"';

				$data_attr .= ' data-dismiss-time="' . ( isset( $payload[ 'dismiss_time' ] ) &&
					is_numeric( $payload[ 'dismiss_time' ] ) ?
						esc_attr( $payload[ 'dismiss_time' ] ) : 0 ) . '"';

				$data_attr .= ' data-dismiss-nonce="' . wp_create_nonce( __FILE__ ) . '"';
			}

			$style_attr = ' style="' . 
				( empty( $payload[ 'style' ] ) ? '' : $payload[ 'style' ] ) .
				( empty( $payload[ 'hidden' ] ) ? 'display:block;' : 'display:none;' ) . '"';

			$msg_html = '<div class="' . $this->lca . '-notice ' . 
				( $is_dismissible ? $this->lca . '-dismissible ' : '' ) .
				$wp_class . '"' . $css_id_attr . $style_attr . $data_attr . '>';	// Display block or none.

			/**
			 * Float the dismiss button on the right, so the button must be added first.
			 */
			if ( ! empty( $payload[ 'dismiss_diff' ] ) && $is_dismissible ) {

				$msg_html .= '<button class="notice-dismiss" type="button">' .
					'<span class="notice-dismiss-text">' . $payload[ 'dismiss_diff' ] . '</span>' .
						'</button><!-- .notice-dismiss -->';
			}

			/**
			 * The notice label can be false, an empty string, or translated string.
			 */
			if ( ! empty( $payload[ 'notice_label' ] ) ) {

				$msg_html .= '<div class="notice-label">' . $payload[ 'notice_label' ] . '</div><!-- .notice-label -->';
			}

			/**
			 * Check to see if there's a section that should be shown only once.
			 */
			if ( preg_match( '/<!-- show-once -->.*<!-- \/show-once -->/Us', $payload[ 'msg_text' ], $matches ) ) {

				static $show_once = array();

				$match_md5 = md5( $matches[ 0 ] );

				if ( isset( $show_once[ $match_md5 ] ) ) {
					$payload[ 'msg_text' ] = str_replace( $matches[ 0 ], '', $payload[ 'msg_text' ] );
				} else {
					$show_once[ $match_md5 ] = true;
				}
			}


			$msg_html .= '<div class="notice-message">' . $payload[ 'msg_text' ] . '</div><!-- .notice-message -->';

			$msg_html .= '</div><!-- .' . $this->lca . '-notice -->' . "\n";

			return $msg_html;
		}

		/**
		 * Called by the WordPress 'shutdown' action. Save notices for all user IDs in the notice cache.
		 */
		public function shutdown_notice_cache() {

			foreach ( $this->notice_cache as $user_id => $user_notices ) {

				$this->update_notice_transient( $user_id );
			}
		}

		private function maybe_add_update_errors( $user_id ) {

			if ( ! class_exists( 'SucomUpdate' ) ) {
				return;
			}

			if ( empty( $this->p->cf[ 'plugin' ] ) ) {
				return;
			}

			foreach ( $this->p->cf[ 'plugin' ] as $ext => $info ) {

				if ( SucomUpdate::is_configured( $ext ) ) {	// Since WPSSO UM v1.0.

					$uerr = SucomUpdate::get_umsg( $ext );	// Since WPSSO UM v1.0.

					if ( ! empty( $uerr ) ) {

						$msg_text   = preg_replace( '/<!--spoken-->(.*?)<!--\/spoken-->/Us', ' ', $uerr );
						$msg_spoken = preg_replace( '/<!--not-spoken-->(.*?)<!--\/not-spoken-->/Us', ' ', $uerr );
						$msg_spoken = SucomUtil::decode_html( SucomUtil::strip_html( $msg_spoken ) );
						$msg_key    = sanitize_key( $msg_spoken );

						$this->notice_cache[ $user_id ][ 'err' ][ $msg_key ] = array(
							'msg_text'   => $msg_text,
							'msg_spoken' => $msg_spoken,
						);
					}
				}
			}
		}

		private function update_notice_transient( $user_id ) {

			$result = false;

			if ( empty( $user_id ) ) {	// User ID is 0 (cron user, for example).
				return $result;
			}

			$this->maybe_load_notice_cache( $user_id );

			$cache_md5_pre  = $this->lca . '_!_';	// Protect transient from being cleared.
			$cache_exp_secs = DAY_IN_SECONDS;
			$cache_salt     = 'sucom_notice_transient(user_id:' . $user_id . ')';
			$cache_id       = $cache_md5_pre . md5( $cache_salt );

			$have_notices = false;

			foreach ( $this->all_types as $msg_type ) {

				if ( ! empty( $this->notice_cache[ $user_id ][ $msg_type ] ) ) {

					$have_notices = true;

					break;
				}
			}

			if ( $have_notices ) {
				$result = set_transient( $cache_id, $this->notice_cache[ $user_id ], $cache_exp_secs );
			} else {
				delete_transient( $cache_id );
			}

			unset( $this->notice_cache[ $user_id ] );

			return $result;
		}

		private function maybe_load_notice_cache( $user_id = null ) {

			$current_user_id = get_current_user_id();	// Always returns an integer.

			$user_id = is_numeric( $user_id ) ? (int) $user_id : $current_user_id;	// User ID can be true, false, null, or a number.

			if ( $user_id === $current_user_id ) {

				if ( isset( $this->cache_loaded[ $user_id ] ) ) {

					return false;	// Nothing to do.
				}
			}

			$transient_cache = $this->get_notice_transient( $user_id );	// Returns an empty array.

			$this->cache_loaded[ $user_id ] = true;

			if ( empty( $this->notice_cache[ $user_id ] ) ) {	// Set the notice cache from the transient notices.

				$this->notice_cache[ $user_id ] = $transient_cache;

			} elseif ( ! empty( $transient_cache ) ) {	// Merge notice cache with transient notices (without overwriting).

				foreach ( $this->all_types as $msg_type ) {

					if ( ! empty( $transient_cache[ $msg_type ] ) ) {

						foreach ( $transient_cache[ $msg_type ] as $msg_key => $payload ) {

							if ( ! isset( $this->notice_cache[ $user_id ][ $msg_type ][ $msg_key ] ) ) {

								$this->notice_cache[ $user_id ][ $msg_type ][ $msg_key ] = $payload;
							}
							
						}
					}
				}
			}

			foreach ( $this->all_types as $msg_type ) {

				if ( ! isset( $this->notice_cache[ $user_id ][ $msg_type ] ) ) {

					$this->notice_cache[ $user_id ][ $msg_type ] = array();
				}
			}

			return true;
		}

		private function get_notice_transient( $user_id ) {

			if ( empty( $user_id ) ) {	// User ID is 0 (cron user, for example).
				return array();
			}

			$cache_md5_pre = $this->lca . '_!_';	// Protect transient from being cleared.
			$cache_salt    = 'sucom_notice_transient(user_id:' . $user_id . ')';
			$cache_id      = $cache_md5_pre . md5( $cache_salt );

			$transient_cache = get_transient( $cache_id );

			if ( ! is_array( $transient_cache ) ) {
				$transient_cache = array();
			}

			return $transient_cache;
		}

		private function get_notice_style() {

			global $wp_version;

			$cache_md5_pre  = $this->lca . '_';
			$cache_exp_secs = DAY_IN_SECONDS;
			$cache_salt     = __METHOD__ . '(wp_version:' . $wp_version . ')';
			$cache_id       = $cache_md5_pre . md5( $cache_salt );

			if ( $this->use_cache ) {
				if ( $custom_style_css = get_transient( $cache_id ) ) {	// Not empty.
					return '<style type="text/css">' . $custom_style_css . '</style>';
				}
			}

			$custom_style_css = '';	// Start with an empty string.

			/**
			 * Unhide the WordPress admin toolbar if there are notices, including when using the fullscreen editor.
			 */
			$custom_style_css .= '
				body.wp-admin.has-toolbar-notices #wpadminbar {
					display:block;
				}
				body.wp-admin.is-fullscreen-mode.has-toolbar-notices .block-editor__container {
					min-height:calc(100vh - 32px);
				}
			';

			if ( version_compare( $wp_version, '5.3.2', '>' ) ) {

				$custom_style_css .= '
					body.wp-admin.is-fullscreen-mode.has-toolbar-notices .block-editor__container .block-editor-editor-skeleton,
					body.wp-admin.is-fullscreen-mode.has-toolbar-notices .block-editor__container .block-editor-editor-skeleton .editor-post-publish-panel {
						top:32px;
					}
				';

			} else {

				$custom_style_css .= '
					body.wp-admin.is-fullscreen-mode.has-toolbar-notices .block-editor__container .edit-post-layout > .edit-post-header {
						top:32px;
					}
					body.wp-admin.is-fullscreen-mode.has-toolbar-notices .block-editor__container .edit-post-layout > .edit-post-layout__content {
						top:88px;
					}
					body.wp-admin.is-fullscreen-mode.has-toolbar-notices .block-editor__container .edit-post-layout > div > .edit-post-sidebar {
						top:88px;
					}
				';
			}

			$custom_style_css .= '
				@keyframes blinker {
					25% { opacity: 0; }
					75% { opacity: 1; }
				}
				.components-notice-list .' . $this->lca . '-notice {
					margin:0;
					min-height:0;
					-webkit-box-shadow:none;
					-moz-box-shadow:none;
					box-shadow:none;
				}
				.components-notice-list .is-dismissible .' . $this->lca . '-notice {
					padding-right:30px;
				}
				.components-notice-list .' . $this->lca . '-notice *,
				#wpadminbar .' . $this->lca . '-notice *,
				.' . $this->lca . '-notice * {
					line-height:1.4em;
				}
				.components-notice-list .' . $this->lca . '-notice .notice-label,
				.components-notice-list .' . $this->lca . '-notice .notice-message,
				.components-notice-list .' . $this->lca . '-notice .notice-dismiss {
					padding:8px;
					margin:0;
					border:0;
					background:inherit;
				}
				#wpadminbar #wp-toolbar .has-toolbar-notices .ab-item:hover,
				#wpadminbar #wp-toolbar .has-toolbar-notices.hover .ab-item { 
					color:inherit;
					background:inherit;
				}
				#wpadminbar #wp-toolbar .has-toolbar-notices #' . $this->lca . '-toolbar-notices-icon.ab-icon::before { 
					color:#fff;
					background-color:inherit;
				}
				#wpadminbar #wp-toolbar .has-toolbar-notices #' . $this->lca . '-toolbar-notices-count {
					color:#fff;
					background-color:inherit;
				}
				#wpadminbar #wp-toolbar .has-toolbar-notices.toolbar-notices-error {
					background-color:#dc3232;	/* Red. */
				}
				#wpadminbar #wp-toolbar .has-toolbar-notices.toolbar-notices-warning {
					background-color:#ffb900;	/* Yellow. */
				}
				#wpadminbar #wp-toolbar .has-toolbar-notices.toolbar-notices-info {
					background-color:#00a0d2;	/* Blue. */
				}
				#wpadminbar .has-toolbar-notices.toolbar-notices-success {
					background-color:#46b450;	/* Green. */
				}
				#wpadminbar .has-toolbar-notices #wp-admin-bar-' . $this->lca . '-toolbar-notices-default { 
					padding:0;
				}
				#wpadminbar .has-toolbar-notices #wp-admin-bar-' . $this->lca . '-toolbar-notices-container { 
					min-width:70vw;			/* 70% of the viewing window width. */
					max-height:90vh;		/* 90% of the viewing window height. */
					overflow-y:scroll;
				}
				#wpadminbar .' . $this->lca . '-notice,
				#wpadminbar .' . $this->lca . '-notice.error,
				#wpadminbar .' . $this->lca . '-notice.updated,
				.' . $this->lca . '-notice,
				.' . $this->lca . '-notice.error,
				.' . $this->lca . '-notice.updated {
					clear:both;
					padding:0;
					-webkit-box-shadow:none;
					-moz-box-shadow:none;
					box-shadow:none;
				}
				#wpadminbar .' . $this->lca . '-notice,
				#wpadminbar .' . $this->lca . '-notice.error,
				#wpadminbar .' . $this->lca . '-notice.updated {
					background:inherit;
					border-bottom:none;
					border-right:none;
				}
				#wpadminbar .' . $this->lca . '-notice > div,
				#wpadminbar .' . $this->lca . '-notice.error > div,
				#wpadminbar .' . $this->lca . '-notice.updated > div {
					min-height:50px;
				}
				#wpadminbar div.' . $this->lca . '-notice.notice-copy {
					font-size:0.9em;
					line-height:1;
					text-align:center;
					min-height:auto;
				}
				#wpadminbar div.' . $this->lca . '-notice.notice-copy > div {
					min-height:auto;
				}
				#wpadminbar div.' . $this->lca . '-notice.notice-copy div.notice-message {
					display:inline-block;
					padding:5px 20px;
				}
				#wpadminbar div.' . $this->lca . '-notice.notice-copy div.notice-message a {
					font-size:0.9em;
					font-weight:200;
					letter-spacing:0.2px;
				}
				#wpadminbar div.' . $this->lca . '-notice a,
				.' . $this->lca . '-notice a {
					display:inline;
					text-decoration:underline;
					padding:0;
				}
				#wpadminbar div.' . $this->lca . '-notice .notice-label,
				#wpadminbar div.' . $this->lca . '-notice .notice-message,
				#wpadminbar div.' . $this->lca . '-notice .notice-dismiss {
					position:relative;
					display:table-cell;
					padding:20px;
					margin:0;
					border:none;
					vertical-align:top;
					background:inherit;
				}
				.' . $this->lca . '-notice div.notice-actions {
					text-align:center;
					margin:20px 0 15px 0;
				}
				.' . $this->lca . '-notice div.notice-single-button {
					display:inline-block;
					vertical-align:top;
					margin:5px;
				}
				.' . $this->lca . '-notice .notice-label,
				.' . $this->lca . '-notice .notice-message,
				.' . $this->lca . '-notice .notice-dismiss {
					position:relative;
					display:table-cell;
					padding:15px 20px;
					margin:0;
					border:none;
					vertical-align:top;
				}
				.components-notice-list .' . $this->lca . '-notice .notice-dismiss,
				#wpadminbar .' . $this->lca . '-notice .notice-dismiss,
				.' . $this->lca . '-notice .notice-dismiss {
					clear:both;	/* Clear the "Screen Options" tab in nags. */
					display:block;
					float:right;
					top:0;
					right:0;
					padding-left:0;
					padding-bottom:15px;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-label,
				.' . $this->lca . '-notice .notice-label {
					font-weight:600;
					color:#444;			/* Default text color. */
					background-color:#fcfcfc;	/* Default background color. */
					white-space:nowrap;
				}
				#wpadminbar .' . $this->lca . '-notice.notice-error .notice-label,
				.' . $this->lca . '-notice.notice-error .notice-label {
					background-color: #fbeaea;
				}
				#wpadminbar .' . $this->lca . '-notice.notice-warning .notice-label,
				.' . $this->lca . '-notice.notice-warning .notice-label {
					background-color: #fff8e5;
				}
				#wpadminbar .' . $this->lca . '-notice.notice-info .notice-label,
				.' . $this->lca . '-notice.notice-info .notice-label {
					background-color: #e5f5fa;
				}
				#wpadminbar .' . $this->lca . '-notice.notice-success .notice-label,
				.' . $this->lca . '-notice.notice-success .notice-label {
					background-color: #ecf7ed;
				}
				.' . $this->lca . '-notice.notice-success .notice-label::before,
				.' . $this->lca . '-notice.notice-info .notice-label::before,
				.' . $this->lca . '-notice.notice-warning .notice-label::before,
				.' . $this->lca . '-notice.notice-error .notice-label::before {
					font-family:"Dashicons";
					font-size:1.2em;
					vertical-align:bottom;
					margin-right:6px;
				}
				.' . $this->lca . '-notice.notice-error .notice-label::before {
					content:"\f488";	/* megaphone */
				}
				.' . $this->lca . '-notice.notice-warning .notice-label::before {
					content:"\f227";	/* flag */
				}
				.' . $this->lca . '-notice.notice-info .notice-label::before {
					content:"\f537";	/* sticky */
				}
				.' . $this->lca . '-notice.notice-success .notice-label::before {
					content:"\f147";	/* yes */
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message h2,
				.' . $this->lca . '-notice .notice-message h2 {
					font-size:1.2em;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message h3,
				.' . $this->lca . '-notice .notice-message h3 {
					font-size:1.1em;
					margin-top:1.2em;
					margin-bottom:0.8em;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message code,
				.' . $this->lca . '-notice .notice-message code {
					font-family:"Courier", monospace;
					font-size:1em;
					vertical-align:middle;
					padding:0 2px;
					margin:0;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message a,
				.' . $this->lca . '-notice .notice-message a {
					display:inline;
					text-decoration:underline;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message a code,
				.' . $this->lca . '-notice .notice-message a code {
					padding:0;
					vertical-align:middle;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message p,
				#wpadminbar .' . $this->lca . '-notice .notice-message pre,
				.' . $this->lca . '-notice .notice-message p,
				.' . $this->lca . '-notice .notice-message pre {
					margin:0.8em 0 0 0;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message .top,
				.' . $this->lca . '-notice .notice-message .top {
					margin-top:0;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message p.reference-message,
				.' . $this->lca . '-notice .notice-message p.reference-message {
					font-size:0.9em;
					margin:10px 0 0 0;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message p.reference-message a {
					font-size:0.9em;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message p.smaller-message,
				#wpadminbar .' . $this->lca . '-notice .notice-message p.smaller-message a,
				.' . $this->lca . '-notice .notice-message p.smaller-message,
				.' . $this->lca . '-notice .notice-message p.smaller-message a {
					font-size:0.9em;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message ul,
				.' . $this->lca . '-notice .notice-message ul {
					margin:1em 0 1em 3em;
					list-style:disc outside none;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message ol,
				.' . $this->lca . '-notice .notice-message ol {
					margin:1em 0 1em 3em;
					list-style:decimal outside none;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message li,
				.' . $this->lca . '-notice .notice-message li {
					text-align:left;
					margin:5px 0 5px 0;
					padding-left:0.8em;
					list-style:inherit;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-message b,
				#wpadminbar .' . $this->lca . '-notice .notice-message b a,
				#wpadminbar .' . $this->lca . '-notice .notice-message strong,
				#wpadminbar .' . $this->lca . '-notice .notice-message strong a,
				.' . $this->lca . '-notice .notice-message b,
				.' . $this->lca . '-notice .notice-message b a,
				.' . $this->lca . '-notice .notice-message strong,
				.' . $this->lca . '-notice .notice-message strong a {
					font-weight:600;
				}
				#wpadminbar .' . $this->lca . '-notice .notice-dismiss .notice-dismiss-text,
				.' . $this->lca . '-notice .notice-dismiss .notice-dismiss-text {
					display:inline-block;
					font-size:12px;
					padding:2px;
					vertical-align:top;
					white-space:nowrap;
				}
				.' . $this->lca . '-notice .notice-message .button-highlight {
					border-color:#0074a2;
					background-color:#daeefc;
				}
				.' . $this->lca . '-notice .notice-message .button-highlight:hover {
					background-color:#c8e6fb;
				}
				.' . $this->lca . '-notice .notice-dismiss::before {
					display:inline-block;
					padding:2px;
				}
			';

			if ( $this->use_cache ) {

				if ( method_exists( 'SucomUtil', 'minify_css' ) ) {
					$custom_style_css = SucomUtil::minify_css( $custom_style_css, $this->lca );
				}

				set_transient( $cache_id, $custom_style_css, $cache_exp_secs );
			}

			return '<style type="text/css">' . $custom_style_css . '</style>';
		}

		private function get_nag_style() {

			global $wp_version;

			$cache_md5_pre  = $this->lca . '_';
			$cache_exp_secs = DAY_IN_SECONDS;
			$cache_salt     = __METHOD__ . '(wp_version:' . $wp_version . ')';
			$cache_id       = $cache_md5_pre . md5( $cache_salt );

			if ( $this->use_cache ) {
				if ( $custom_style_css = get_transient( $cache_id ) ) {	// Not empty.
					return '<style type="text/css">' . $custom_style_css . '</style>';
				}
			}

			$custom_style_css = '';	// Start with an empty string.

			if ( isset( $this->p->cf[ 'notice' ] ) ) {
				foreach ( $this->p->cf[ 'notice' ] as $css_class => $css_props ) {
					foreach ( $css_props as $prop_name => $prop_value ) {
						$custom_style_css .= '.' . $this->lca . '-notice.' . $css_class . '{' . $prop_name . ':' . $prop_value . ';}' . "\n";
					}
				}
			}

			$custom_style_css .= '
				.' . $this->lca . '-notice.update-nag .notice-message {
					padding:15px 30px;
				}
				.' . $this->lca . '-notice.update-nag p,
				.' . $this->lca . '-notice.update-nag ul,
				.' . $this->lca . '-notice.update-nag ol {
					margin:15px 0;
				}
				.' . $this->lca . '-notice.update-nag ul li {
					list-style-type:square;
				}
				.' . $this->lca . '-notice.update-nag ol li {
					list-style-type:decimal;
				}
				.' . $this->lca . '-notice.update-nag li {
					margin:5px 0 5px 60px;
				}
			';
			
			if ( $this->use_cache ) {

				if ( method_exists( 'SucomUtil', 'minify_css' ) ) {
					$custom_style_css = SucomUtil::minify_css( $custom_style_css, $this->lca );
				}

				set_transient( $cache_id, $custom_style_css, $cache_exp_secs );
			}

			return '<style type="text/css">' . $custom_style_css . '</style>';
		}

		private function get_notice_script() {

			return '
<script type="text/javascript">

	jQuery( document ).on( "click", "div.' . $this->lca . '-dismissible > button.notice-dismiss, div.' . $this->lca . '-dismissible .dismiss-on-click", function() {

		var notice = jQuery( this ).closest( ".' . $this->lca . '-dismissible" );

		var dismiss_msg = jQuery( this ).data( "dismiss-msg" );

		var ajaxDismissData = {
			action: "' . $this->lca . '_dismiss_notice",
			notice_key: notice.data( "notice-key" ),
			dismiss_time: notice.data( "dismiss-time" ),
			dismiss_nonce: notice.data( "dismiss-nonce" ),
		}

		if ( notice.data( "notice-key" ) ) {
			jQuery.post( ajaxurl, ajaxDismissData );
		}

		if ( dismiss_msg ) {

			notice.children( "button.notice-dismiss" ).hide();

			jQuery( this ).closest( "div.notice-message" ).html( dismiss_msg );

		} else {
			notice.hide();
		}
	} ); 

</script>' . "\n";
		}
	}
}
