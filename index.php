<?php

/*
Plugin Name: Purple DS HUB - NoSQL
Plugin URI:  https://sprylab.com
Description: NoSQL sync plugin for Purple DS HUB.
Version:     0.9
Author:      sprylab technologies
Author URI:  https://sprylab.com
License:     GPL V3.
License URI: http://www.gnu.org/licenses/
Text Domain: purpledshub-hype
Domain Path: /languages
 */

// exit.
defined( 'ABSPATH' ) || exit;


require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/inc/autoloader-class.php';
\PurpleRedis\Inc\Autoloader::run();

/**
 * Adds the debug function
 *
 * @param mixed $var The value to be printed.
 */
function debug( $var ) {
	error_log( print_r( $var, true ) );
}

$init_connection = new \PurpleRedis\Inc\Init_Connection();

wp_enqueue_script(
	'purple-gutenbergid-script',
	plugins_url( 'inc/purpleId.js', __FILE__ ),
	array(),
	filemtime( plugin_dir_path( __FILE__ ) . 'inc/purpleId.js' ),
	true
);
