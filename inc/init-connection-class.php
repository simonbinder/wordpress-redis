<?php

namespace PurpleRedis\Inc;

use PurpleDsHub\Inc\Interfaces\Hooks_Interface;
use PurpleDsHub\Inc\Utilities\General_Utilities;
use \PurpleDsHub\Inc\Utilities\Torque_Urls;

if ( ! class_exists( 'Init_Connection' ) ) {
	class Init_Connection {

		/**
		 * Component's handle.
		 */
		const HANDLE = 'init-connection';

		/**
		 */
		private $connection;

		public function __construct() {
			if ( is_null( $this->connection ) ) {
				// connect to Redis.
				$redis = new \Redis();
				$redis->connect( '127.0.0.1' );

				$this->connection = $redis;
				debug_log( 'new connection' );

				$update_post = new Update_Post( $this->connection );
				$update_post->init_hooks();
			}
		}
	}
}

