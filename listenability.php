<?php
/**
 * Plugin Name: Listenability
 * Description: Create a text-to-speech podcast of your posts, with the content from embedded URLs supplied by Readability's Parser API. Re-incarnation of SoundGecko.
 * Plugin URI: https://github.com/westonruter/wp-listenability
 * Version: 0.1.1
 * Author: Weston Ruter
 * Author URI: https://weston.ruter.net/
 * License: GPLv2+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: listenability
 * Domain Path: /languages
 *
 * Copyright (c) 2015 Weston Ruter (https://weston.ruter.net/)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */

if ( version_compare( phpversion(), '5.3', '>=' ) ) {
	require_once __DIR__ . '/php/class-plugin-base.php';
	require_once __DIR__ . '/php/class-plugin.php';
	$class_name = '\Listenability\Plugin';
	$GLOBALS['listenability_plugin'] = new $class_name();
	register_activation_hook( __FILE__, array( $class_name, 'activate' ) );
	register_deactivation_hook( __FILE__, array( $class_name, 'deactivate' ) );
} else {
	function listenability_php_version_error() {
		printf( '<div class="error"><p>%s</p></div>', esc_html__( 'Listenability plugin error: Your version of PHP is too old to run this plugin. You must be running PHP 5.3 or higher.', 'listenability' ) );
	}
	if ( defined( 'WP_CLI' ) ) {
		WP_CLI::warning( __( 'Listenability plugin error: Your PHP version is too old. You must have 5.3 or higher.', 'listenability' ) );
	} else {
		add_action( 'admin_notices', 'listenability_php_version_error' );
	}
}
