<?php

// +----------------------------------------------------------------------+
// | Copyright Incsub (http://incsub.com/)                                |
// +----------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License, version 2, as  |
// | published by the Free Software Foundation.                           |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to the Free Software          |
// | Foundation, Inc., 51 Franklin St, Fifth Floor, Boston,               |
// | MA 02110-1301 USA                                                    |
// +----------------------------------------------------------------------+

/**
 * System module.
 *
 * @category Autoblog
 * @package Module
 *
 * @since 4.0.0
 */
class Autoblog_Module_System extends Autoblog_Module {

	const NAME = __CLASS__;

	/**
	 * Constructor.
	 *
	 * @since 4.0.0
	 *
	 * @access public
	 * @param Autoblog_Plugin $plugin The plugin instance.
	 */
	public function __construct( Autoblog_Plugin $plugin ) {
		parent::__construct( $plugin );

		// upgrade the plugin
		$this->_upgrade();

		// load text domain
		$this->_add_action( 'plugins_loaded', 'load_textdomain' );

		// load network wide and blog wide addons
		$this->load_network_addons();
		$this->load_addons();

		// setup cron stuff
		$this->_add_action( 'wp_loaded', 'check_schedules' );
		register_activation_hook( AUTOBLOG_BASEFILE, array( $this, 'register_schedules' ) );
	}

	/**
	 * Checks whether we need to recheck scheduled events or not.
	 *
	 * @since 4.0.0
	 * @action wp_loaded
	 *
	 * @access public
	 */
	public function check_schedules() {
		if ( Autoblog_Plugin::use_cron() ) {
			$transient = 'autoblog-feeds-launching';
			if ( get_transient( $transient ) === false ) {
				$this->register_schedules();
				set_transient( $transient, 1, HOUR_IN_SECONDS );
			}
		} else {
			$this->_launch_pageload_schedules();
		}
	}

	/**
	 * Launched schedules on page load.
	 *
	 * @since 4.0.0
	 *
	 * @access private
	 */
	private function _launch_pageload_schedules() {
		$feeds = (array)$this->_wpdb->get_results( sprintf(
			'SELECT feed_id, nextcheck FROM %s WHERE site_id = %d AND blog_id = %d AND nextcheck > 0 ORDER BY nextcheck LIMIT 1',
			AUTOBLOG_TABLE_FEEDS,
			!empty( $this->_wpdb->siteid ) ? $this->_wpdb->siteid : 1,
			get_current_blog_id()
		) );

		$current_time = current_time( 'timestamp', 1 );
		foreach ( $feeds as $feed ) {
			if ( $feed->nextcheck < $current_time ) {
				$args = array( absint( $feed->feed_id ) );
				do_action( Autoblog_Plugin::SCHEDULE_PROCESS, $args );
			}
		}
	}

	/**
	 * Registers scheduled events.
	 *
	 * @since 4.0.0
	 *
	 * @access public
	 */
	public function register_schedules() {
		if ( !Autoblog_Plugin::use_cron() ) {
			return;
		}

		$feeds = (array)$this->_wpdb->get_results( sprintf(
			'SELECT feed_id, nextcheck FROM %s WHERE site_id = %d AND blog_id = %d AND nextcheck > 0 ORDER BY nextcheck',
			AUTOBLOG_TABLE_FEEDS,
			!empty( $this->_wpdb->siteid ) ? $this->_wpdb->siteid : 1,
			get_current_blog_id()
		) );

		$current_time = current_time( 'timestamp' );
		foreach ( $feeds as $feed ) {
			$args = array( absint( $feed->feed_id ) );
			$next_job = wp_next_scheduled( Autoblog_Plugin::SCHEDULE_PROCESS, $args );
			if ( !$next_job ) {
				$nextrun = $feed->nextcheck < $current_time ? $current_time : $feed->nextcheck;
				wp_schedule_single_event( $nextrun, Autoblog_Plugin::SCHEDULE_PROCESS, $args );
			}
		}
	}

	/**
	 * Performs upgrade plugin evnironment to up to date version.
	 *
	 * @since 4.0.0
	 *
	 * @access private
	 */
	private function _upgrade() {
		$filter = 'autoblog_database_upgrade';
		$option = 'autoblog_database_version';

		// fetch current database version
		$db_version = get_site_option( $option );
		if ( $db_version === false ) {
			$db_version = '0.0.0';
			update_site_option( $option, $db_version );
		}

		// check if current version is equal to database version, then there is nothing to upgrade
		if ( version_compare( $db_version, Autoblog_Plugin::VERSION, '=' ) ) {
			return;
		}

		// add upgrade functions
		$this->_add_filter( $filter, 'setup_database', 1 );
		$this->_add_filter( $filter, 'upgrade_to_4_0_0', 10 );

		// upgrade database version to current plugin version
		$db_version = apply_filters( $filter, $db_version );
		$db_version = version_compare( $db_version, Autoblog_Plugin::VERSION, '>=' )
			? $db_version
			: Autoblog_Plugin::VERSION;

		update_site_option( $option, $db_version );
	}

	/**
	 * Setups database table.
	 *
	 * @since 4.0.0
	 *
	 * @access public
	 * @param string $current_version The current plugin version.
	 * @return string Unchanged version.
	 */
	public function setup_database( $current_version ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = '';

		if ( !empty( $this->_wpdb->charset ) ) {
			$charset_collate = 'DEFAULT CHARACTER SET ' . $this->_wpdb->charset;
		}

		if ( !empty( $this->_wpdb->collate ) ) {
			$charset_collate .= ' COLLATE ' . $this->_wpdb->collate;
		}

		dbDelta( array(
			// feeds
			sprintf(
				'CREATE TABLE %s (
				  feed_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				  site_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				  blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				  feed_meta TEXT,
				  active INT DEFAULT NULL,
				  nextcheck BIGINT UNSIGNED DEFAULT NULL,
				  lastupdated BIGINT UNSIGNED DEFAULT NULL,
				  PRIMARY KEY  (feed_id),
				  KEY site_id (site_id),
				  KEY blog_id (blog_id),
				  KEY nextcheck (nextcheck)
				) %s;',
				AUTOBLOG_TABLE_FEEDS,
				$charset_collate
			),

			// logs
			sprintf(
				'CREATE TABLE %s (
				  log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				  feed_id BIGINT UNSIGNED NOT NULL,
				  cron_id BIGINT UNSIGNED NOT NULL,
				  log_at BIGINT UNSIGNED NOT NULL,
				  log_type TINYINT UNSIGNED NOT NULL,
				  log_info TEXT,
				  PRIMARY KEY  (log_id),
				  KEY feed_id (feed_id),
				  KEY cron_id (cron_id),
				  KEY feed_log_type (feed_id, log_type)
				) %s;',
				AUTOBLOG_TABLE_LOGS,
				$charset_collate
			),
		) );

		return $current_version;
	}

	/**
	 * Upgrades the plugin to the version 4.0.0
	 *
	 * @since 4.0.0
	 *
	 * @access public
	 * @param string $current_version The current plugin version.
	 * @return string Unchanged version.
	 */
	public function upgrade_to_4_0_0( $current_version ) {
		$this_version = '4.0.0';
		if ( version_compare( $current_version, $this_version, '>=' ) ) {
			return $current_version;
		}

		// remove deprecated options
		delete_site_option( 'autoblog_installed' );
		delete_option( 'autoblog_installed' );

		// update options
		if ( is_multisite() && function_exists( 'get_blog_option' ) ) {
			update_site_option( 'autoblog_networkactivated_addons', get_blog_option( 1, 'autoblog_networkactivated_addons' ) );
			delete_blog_option( 1, 'autoblog_networkactivated_addons' );
		}

		// remove deprecated logs
		$this->_wpdb->query( "DELETE FROM {$this->_wpdb->options} WHERE option_name LIKE 'autoblog_log_%'" );
		if ( is_multisite() ) {
			$this->_wpdb->query( "DELETE FROM {$this->_wpdb->sitemeta} WHERE site_id = {$this->_wpdb->siteid} AND meta_key LIKE 'autoblog_log_%'" );
		}

		// update feeds table
		$this->_wpdb->update( AUTOBLOG_TABLE_FEEDS, array( 'site_id' => 1 ), array( 'site_id' => 0 ), array( '%d' ), array( '%d' ) );
		$this->_wpdb->update( AUTOBLOG_TABLE_FEEDS, array( 'blog_id' => 1 ), array( 'blog_id' => 0 ), array( '%d' ), array( '%d' ) );

		// remove deprecated scheduled event
		$next_schedule = wp_next_scheduled( 'autoblog_process_all_feeds_for_cron' );
		if ( $next_schedule ) {
			wp_unschedule_event( $next_schedule, 'autoblog_process_all_feeds_for_cron' );
		}

		return $this_version;
	}

	/**
	 * Loads text domain.
	 *
	 * @since 4.0.0
	 * @action plugins_loaded
	 *
	 * @access public
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'autoblogtext', false, dirname( plugin_basename( AUTOBLOG_BASEFILE ) ) . '/autoblogincludes/languages/' );
	}

	/**
	 * Loads autoblog addons.
	 *
	 * @since 4.0.0
	 *
	 * @access public
	 * @param array $feed The feed data.
	 */
	public function load_addons( $feed = array() ) {
		$directory = AUTOBLOG_ABSPATH . 'autoblogincludes/addons/';
		if ( !is_dir( $directory ) ) {
			return;
		}

		if ( ( $dh = opendir( $directory ) ) ) {
			$auto_plugins = array();
			while ( ( $plugin = readdir( $dh ) ) !== false ) {
				if ( substr( $plugin, -4 ) == '.php' ) {
					$auto_plugins[] = $plugin;
				}
			}
			closedir( $dh );
			sort( $auto_plugins );

			$switched = false;
			if ( is_array( $feed ) && !empty( $feed['blog_id'] ) && $feed['blog_id'] != get_current_blog_id() && function_exists( 'switch_to_blog' ) ) {
				$switched = true;
				switch_to_blog( $feed['blog_id'] );
			}

			$plugins = (array)get_option( 'autoblog_activated_addons', array() );
			$auto_plugins = apply_filters( 'autoblog_available_addons', $auto_plugins );

			foreach ( $auto_plugins as $auto_plugin ) {
				if ( in_array( $auto_plugin, $plugins ) ) {
					include_once $directory . $auto_plugin;
				}
			}

			if ( $switched && function_exists( 'restore_current_blog' ) ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Loads network wide autoblog addons.
	 *
	 * @since 4.0.0
	 *
	 * @access public
	 */
	public function load_network_addons() {
		$directory = AUTOBLOG_ABSPATH . 'autoblogincludes/addons/';
		if ( !is_multisite() || !is_dir( $directory ) ) {
			return;
		}

		if ( ( $dh = opendir( $directory ) ) ) {
			$auto_plugins = array();
			while ( ( $plugin = readdir( $dh ) ) !== false ) {
				if ( substr( $plugin, -4 ) == '.php' ) {
					$auto_plugins[] = $plugin;
				}
			}

			closedir( $dh );
			sort( $auto_plugins );

			$plugins = (array)get_site_option( 'autoblog_networkactivated_addons', array() );
			$auto_plugins = apply_filters( 'autoblog_available_addons', $auto_plugins );

			foreach ( $auto_plugins as $auto_plugin ) {
				if ( in_array( $auto_plugin, $plugins ) ) {
					include_once $directory . $auto_plugin;
				}
			}
		}
	}

}