<?php
/**
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl.txt
 * Copyright 2012-2020 Jean-Sebastien Morisset (https://surniaulula.com/)
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'These aren\'t the droids you\'re looking for.' );
}

if ( ! class_exists( 'SucomNoNotice' ) ) {

	class SucomNoNotice {

		public $enabled = false;

		public function __construct() {}

		public function nag() {}
		public function err() {}
		public function warn() {}
		public function inf() {}
		public function upd() {}
		public function log() {}

		public function clear_key() {}
		public function clear() {}

		public function set_ref() { return ''; }
		public function unset_ref() { return false; }
		public function get_ref() { return null; }
		public function get_ref_url_html() { return ''; }

		public function is_ref_url() { return false; }
		public function is_admin_pre_notices() { return false; }
		public function is_dismissed() { return false; }
		public function can_dismiss() { return false; }

		public function admin_header_notices() {}
		public function show_admin_notices() {}
		public function admin_footer_script() {}
		public function ajax_dismiss_notice() { die( -1 ); }
		public function ajax_get_notices_json() { die( -1 ); }
		public function get_notice_system() { return false; }
		public function shutdown_notice_cache() {}
	}
}
