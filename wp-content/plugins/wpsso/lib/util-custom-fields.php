<?php
/**
 * IMPORTANT: READ THE LICENSE AGREEMENT CAREFULLY. BY INSTALLING, COPYING, RUNNING, OR OTHERWISE USING THE WPSSO CORE PREMIUM
 * APPLICATION, YOU AGREE  TO BE BOUND BY THE TERMS OF ITS LICENSE AGREEMENT. IF YOU DO NOT AGREE TO THE TERMS OF ITS LICENSE
 * AGREEMENT, DO NOT INSTALL, RUN, COPY, OR OTHERWISE USE THE WPSSO CORE PREMIUM APPLICATION.
 * 
 * License URI: https://wpsso.com/wp-content/plugins/wpsso/license/premium.txt
 * 
 * Copyright 2012-2020 Jean-Sebastien Morisset (https://wpsso.com/)
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'These aren\'t the droids you\'re looking for.' );
}

if ( ! defined( 'WPSSO_PLUGINDIR' ) ) {
	die( 'Do. Or do not. There is no try.' );
}

if ( ! class_exists( 'WpssoUtilCustomFields' ) ) {

	class WpssoUtilCustomFields {

		private $p;

		/**
		 * Instantiated by WpssoUtil->__construct().
		 */
		public function __construct( &$plugin, &$util ) {

			$this->p =& $plugin;

			if ( $this->p->debug->enabled ) {
				$this->p->debug->mark();
			}

			$util->add_plugin_filters( $this, array(
				'import_custom_fields' => 3,
			), $prio = 1000 );
		}

		/**
		 * The 'import_custom_fields' filter is executed before the 'wpsso_get_post_options' filter, so values retrieved from
		 * custom fields may get overwritten by later filters.
		 *
		 * For example, the WooCommerce integration module hooks the 'wpsso_get_post_options' filter and provides
		 * information about the main / simple product, including any product attributes, and disables these options in the
		 * Document SSO metabox.
		 */
		public function filter_import_custom_fields( array $md_opts, $wp_meta = false, array $cf_meta_keys = array() ) {

			if ( $this->p->debug->enabled ) {
				$this->p->debug->mark();
			}

			/**
			 * No meta to read if $wp_meta is empty or not an array.
			 */
			if ( empty( $wp_meta ) || ! is_array( $wp_meta ) ) {

				if ( $this->p->debug->enabled ) {
					$this->p->debug->log( 'wp_meta provided is empty or not an array' );
				}

				return $md_opts;
			}

			if ( ! empty( $cf_meta_keys ) ) {
				if ( $this->p->debug->enabled ) {
					$this->p->debug->log_arr( '$cf_meta_keys', $cf_meta_keys );
				}
			}

			$charset = get_bloginfo( 'charset' );	// Required for html_entity_decode().

			static $local_cache = null;

			if ( null === $local_cache ) {
				$local_cache = (array) apply_filters( $this->p->lca . '_cf_md_index', $this->p->cf[ 'opt' ][ 'cf_md_index' ] );
			}

			foreach ( $local_cache as $cf_key => $md_key ) {

				/**
				 * Make sure we have an associated option key to save the metadata value - it may have been removed
				 * by a 'wpsso_cf_md_index' filter hook.
				 */
				if ( empty( $md_key ) ) {

					if ( $this->p->debug->enabled ) {
						$this->p->debug->log( 'custom field ' . $cf_key . ' key is disabled' );
					}

					continue;

				/**
				 * Check to see if we have an alternate $wp_meta_key value (for WooCommerce variations).
				 */
				} elseif ( isset( $cf_meta_keys[ $cf_key ] ) ) {

					if ( empty( $cf_meta_keys[ $cf_key ] ) ) {	// Just in case.

						if ( $this->p->debug->enabled ) {
							$this->p->debug->log( 'meta keys ' . $cf_key . ' value is empty' );
						}

						continue;
					}

					$wp_meta_key = $cf_meta_keys[ $cf_key ];	// Example: 'hwp_var_gtin'.

				/**
				 * Make sure the filtered custom field key is known.
				 */
				} else {
				
					if ( empty( $this->p->options[ $cf_key ] ) ) {

						if ( $this->p->debug->enabled ) {
							$this->p->debug->log( 'custom field ' . $cf_key . ' option is empty' );
						}

						continue;
					}

					$wp_meta_key = $this->p->options[ $cf_key ];	// Example: '_format_video_url'.
				}

				if ( $this->p->debug->enabled ) {
					$this->p->debug->log( 'using custom field ' . $cf_key . ' meta key ' . $wp_meta_key );
				}

				/**
				 * WordPress offers metadata as an array, so make sure we have at least one element in the array.
				 */
				if ( ! isset( $wp_meta[ $wp_meta_key ][ 0 ] ) ) {

					if ( $this->p->debug->enabled ) {
						$this->p->debug->log( 'no ' . $wp_meta_key . ' meta key element 0 in wp_meta' );
					}

					continue;
				}

				if ( $this->p->debug->enabled ) {
					$this->p->debug->log( $wp_meta_key . ' meta key found for ' . $md_key . ' option' );
				}

				/**
				 * $mixed can be an array or something else, so handle both.
				 */
				$mixed = maybe_unserialize( $wp_meta[ $wp_meta_key ][ 0 ] );

				$values = array();

				/**
				 * If an array, then decode each array element.
				 */
				if ( is_array( $mixed ) ) {

					if ( $this->p->debug->enabled ) {
						$this->p->debug->log( $wp_meta_key . ' is array of ' . count( $mixed ) . ' values (decoding each value)' );
					}

					foreach ( $mixed as $val ) {

						if ( is_array( $val ) ) {
							$val = SucomUtil::array_implode( $val );
						}

						$values[] = trim( html_entity_decode( SucomUtil::decode_utf8( $val ), ENT_QUOTES, $charset ) );
					}

				} else {

					if ( $this->p->debug->enabled ) {
						$this->p->debug->log( 'decoding ' . $wp_meta_key . ' as string of ' . strlen( $mixed ) . ' chars' );
					}

					$values[] = trim( html_entity_decode( SucomUtil::decode_utf8( $mixed ), ENT_QUOTES, $charset ) );
				}

				/**
				 * Check if the value should be split into multiple numeric options. If not, then just get the
				 * first value from the $values array.
				 */
				if ( empty( $this->p->cf[ 'opt' ][ 'cf_md_multi' ][ $md_key ] ) ) {

					/**
					 * Get first element of $values array.
					 */
					$md_opts[ $md_key ] = reset( $values );

					$md_opts[ $md_key . ':is' ] = 'disabled';

					if ( $this->p->debug->enabled ) {
						$this->p->debug->log( 'option ' . $md_key . ' = ' . print_r( $md_opts[ $md_key ], true ) );
					}

					/**
					 * If this is an '_img_url' option, add the image size and unset the '_img_id' option.
					 */
					$this->p->util->maybe_add_img_url_size( $md_opts, $md_key );

				} else {

					if ( ! is_array( $mixed ) ) {

						/**
						 * Explode the first element into an array.
						 */
						$values = array_map( 'trim', explode( PHP_EOL, reset( $values ) ) );

						if ( $this->p->debug->enabled ) {
							$this->p->debug->log( 'exploded ' . $wp_meta_key . ' into array of ' . count( $values ) . ' elements' );
						}
					}

					/**
					 * Remove any old values from the options array.
					 */
					$md_opts = SucomUtil::preg_grep_keys( '/^' . $md_key . '_[0-9]+$/', $md_opts, $invert = true );

					/**
					 * Renumber the options starting from 0.
					 */
					foreach ( $values as $num => $val ) {

						$md_opts[ $md_key . '_' . $num ] = $val;

						$md_opts[ $md_key . '_' . $num . ':is' ] = 'disabled';
					}
				}
			}

			return $md_opts;
		}
	}
}
