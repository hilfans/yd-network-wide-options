<?php
/**
 * Plugin Name:       YD Network-wide Options
 * Plugin URI:        https://www.yann.com/en/wp-plugins/yd-wpmu-sitewide-options
 * Description:       Makes selected plugin settings network-wide. Changes to a setting on your main site can be automatically replicated to all multisite blogs. Centralized management of your plugin options.
 * Version:           5.1.0
 * Author:            Yann Dubois
 * Author URI:        https://www.yann.com/
 * Text Domain:       yd-wpmu-sitewide-options
 * Domain Path:       /languages
 * Network:           true
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Requires MySQL:    8.0
 * Requires MariaDB:  10.6
 */

/**
 * @package YD_Network_Wide_Options
 * @author Yann Dubois
 * @version 5.1.0
 */

/**
 * @copyright 2010-2025 Yann Dubois ( email : yann _at_ abc.fr )
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class YD_Network_Wide_Options {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version = '5.1.0';

	/**
	 * The single instance of the class.
	 *
	 * @var YD_Network_Wide_Options
	 */
	private static $_instance = null;

	/**
	 * Plugin options.
	 *
	 * @var array
	 */
	private $options = [];

	/**
	 * Main YD_Network_Wide_Options Instance.
	 *
	 * Ensures only one instance of YD_Network_Wide_Options is loaded or can be loaded.
	 *
	 * @static
	 * @return YD_Network_Wide_Options - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->define_constants();
		$this->hooks();
		$this->options = get_network_option( 'yd_network_wide_options', $this->get_default_options() );
	}

	/**
	 * Define Constants.
	 */
	private function define_constants() {
		define( 'YD_NWO_PLUGIN_FILE', __FILE__ );
		define( 'YD_NWO_VERSION', $this->version );
	}

	/**
	 * Hook into actions and filters.
	 */
	private function hooks() {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'network_admin_menu', [ $this, 'add_network_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_and_build_fields' ] );
		add_action( 'admin_init', [ $this, 'maybe_migrate_options' ] );

		// Core functionality hooks
		add_action( 'wpmu_new_blog', [ $this, 'on_new_blog_creation' ], 10, 1 );
		add_action( 'plugins_loaded', [ $this, 'add_option_update_hooks' ] );
		add_action( 'wp_footer', [ $this, 'display_footer_link' ] );
		add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'yd-wpmu-sitewide-options', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Get default options.
	 *
	 * @return array
	 */
	private function get_default_options() {
		return [
			'plugin_version'   => $this->version,
			'selected_options' => [],
			'selected_tables'  => [],
			'replicate_data'   => 0,
			'disable_backlink' => 0,
			'autospreading'    => 1,
			'auto_new_blog'    => 1,
			'overwrite_options' => 1,
			'overwrite_data'   => 0,
			'flush_rewrites'   => 0,
			'master_blog_id'   => 1,
			'only_public'      => 0,
			'skip_archived'    => 1,
			'skip_mature'      => 1,
			'skip_spam'        => 1,
			'skip_deleted'     => 1,
			'to_skip'          => '',
		];
	}

	/**
	 * Check for old options and migrate them to the new system.
	 */
	public function maybe_migrate_options() {
		$old_options = get_network_option( 'widget_yd_wpmuso' );
		if ( false === $old_options ) {
			return;
		}

		// Old options found, let's migrate.
		$old_settings = isset( $old_options[0] ) && is_array( $old_options[0] ) ? $old_options[0] : [];
		if ( empty( $old_settings ) ) {
			delete_network_option( 'widget_yd_wpmuso' );
			return;
		}

		$new_options = $this->get_default_options();
		$new_options['plugin_version'] = $this->version;

		// Map old settings to new settings
		$map = [
			'disable_backlink' => 'disable_backlink',
			'autospreading'    => 'autospreading',
			'auto_new_blog'    => 'auto_new_blog',
			'over_write'       => 'overwrite_options',
			'overwrite_data'   => 'overwrite_data',
			'flush_rewrites'   => 'flush_rewrites',
			'master_blog_id'   => 'master_blog_id',
			'only_public'      => 'only_public',
			'skip_archived'    => 'skip_archived',
			'skip_mature'      => 'skip_mature',
			'skip_spam'        => 'skip_spam',
			'skip_deleted'     => 'skip_deleted',
			'to_skip'          => 'to_skip',
		];

		foreach ( $map as $old_key => $new_key ) {
			if ( isset( $old_settings[ $old_key ] ) ) {
				$new_options[ $new_key ] = $old_settings[ $old_key ];
			}
		}

		// Separate options and tables from the old 'selected_options'
		if ( ! empty( $old_settings['selected_options'] ) && is_array( $old_settings['selected_options'] ) ) {
			foreach ( $old_settings['selected_options'] as $selected ) {
				if ( strpos( $selected, 'ydtable_' ) === 0 ) {
					$table_name = str_replace( 'ydtable_', '', $selected );
					$new_options['selected_tables'][] = $table_name;
				} elseif ( strpos( $selected, 'yddata_' ) === 0 ) {
					// This indicates data replication for a table, which is now a separate option.
					$new_options['replicate_data'] = 1;
				} else {
					$new_options['selected_options'][] = $selected;
				}
			}
		}

		update_network_option( 'yd_network_wide_options', $new_options );
		delete_network_option( 'widget_yd_wpmuso' );

		// Redirect to the settings page with an "updated" message
		wp_safe_redirect( add_query_arg( 'updated', 'migrated', network_admin_url( 'settings.php?page=yd-network-wide-options' ) ) );
		exit;
	}

	/**
	 * Add the options page to the network admin menu.
	 */
	public function add_network_admin_menu() {
		add_submenu_page(
			'settings.php',
			__( 'YD Network-wide Options', 'yd-wpmu-sitewide-options' ),
			__( 'Network-wide Options', 'yd-wpmu-sitewide-options' ),
			'manage_network_options',
			'yd-network-wide-options',
			[ $this, 'create_admin_page' ]
		);
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public function register_and_build_fields() {
		register_setting(
			'yd_nwo_options', // Option group
			'yd_network_wide_options', // Option name
			[ $this, 'sanitize_options' ] // Sanitize callback
		);

		// Options Section
		add_settings_section(
			'yd_nwo_options_section',
			__( 'Options to Propagate', 'yd-wpmu-sitewide-options' ),
			'__return_false',
			'yd_nwo_options'
		);

		add_settings_field(
			'selected_options',
			__( 'Replicate these options:', 'yd-wpmu-sitewide-options' ),
			[ $this, 'render_options_field' ],
			'yd_nwo_options',
			'yd_nwo_options_section'
		);

		// Tables Section
		add_settings_section(
			'yd_nwo_tables_section',
			__( 'Database Tables to Propagate', 'yd-wpmu-sitewide-options' ),
			'__return_false',
			'yd_nwo_options'
		);

		add_settings_field(
			'selected_tables',
			__( 'Replicate these tables:', 'yd-wpmu-sitewide-options' ),
			[ $this, 'render_tables_field' ],
			'yd_nwo_options',
			'yd_nwo_tables_section'
		);

		// Global Settings Section
		add_settings_section(
			'yd_nwo_global_section',
			__( 'Global Settings', 'yd-wpmu-sitewide-options' ),
			'__return_false',
			'yd_nwo_options'
		);

		$global_fields = [
			'master_blog_id'    => __( 'Master Site ID', 'yd-wpmu-sitewide-options' ),
			'autospreading'     => __( 'Auto-apply future changes', 'yd-wpmu-sitewide-options' ),
			'auto_new_blog'     => __( 'Apply to new sites', 'yd-wpmu-sitewide-options' ),
			'overwrite_options' => __( 'Overwrite existing options on subsites', 'yd-wpmu-sitewide-options' ),
			'replicate_data'    => __( 'Replicate table data (not just structure)', 'yd-wpmu-sitewide-options' ),
			'overwrite_data'    => __( 'Overwrite existing data in tables', 'yd-wpmu-sitewide-options' ),
			'flush_rewrites'    => __( 'Flush rewrite rules on new sites', 'yd-wpmu-sitewide-options' ),
		];

		foreach ( $global_fields as $id => $title ) {
			add_settings_field(
				$id,
				$title,
				[ $this, 'render_global_field' ],
				'yd_nwo_options',
				'yd_nwo_global_section',
				[ 'id' => $id ]
			);
		}
		
		// Blog Filtering Section
		add_settings_section(
			'yd_nwo_filtering_section',
			__( 'Site Filtering', 'yd-wpmu-sitewide-options' ),
			'__return_false',
			'yd_nwo_options'
		);
		
		$filtering_fields = [
			'only_public'   => __( 'Only public sites', 'yd-wpmu-sitewide-options' ),
			'skip_archived' => __( 'Skip archived sites', 'yd-wpmu-sitewide-options' ),
			'skip_mature'   => __( 'Skip mature sites', 'yd-wpmu-sitewide-options' ),
			'skip_spam'     => __( 'Skip spam sites', 'yd-wpmu-sitewide-options' ),
			'skip_deleted'  => __( 'Skip deleted sites', 'yd-wpmu-sitewide-options' ),
			'to_skip'       => __( 'Site IDs to skip', 'yd-wpmu-sitewide-options' ),
		];
		
		foreach ( $filtering_fields as $id => $title ) {
			add_settings_field(
				$id,
				$title,
				[ $this, 'render_global_field' ],
				'yd_nwo_options',
				'yd_nwo_filtering_section',
				[ 'id' => $id ]
			);
		}
	}
	
	/**
	 * Render the settings page wrapper.
	 */
	public function create_admin_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'YD Network-wide Options', 'yd-wpmu-sitewide-options' ); ?></h1>
			<?php
			if ( isset( $_GET['updated'] ) && $_GET['updated'] === 'migrated' ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Old plugin settings successfully migrated!', 'yd-wpmu-sitewide-options' ) . '</p></div>';
			}
			?>
			<form action="edit.php?action=update_network_option" method="post">
				<?php
				settings_fields( 'yd_nwo_options' );
				do_settings_sections( 'yd_nwo_options' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the checkbox list for options.
	 */
	public function render_options_field() {
		$master_blog_id = absint( $this->options['master_blog_id'] );
		$all_options    = $this->get_replicable_options( $master_blog_id );

		if ( empty( $all_options ) ) {
			printf(
				/* translators: %d: master site ID */
				esc_html__( 'Could not find any options for the master site (ID: %d).', 'yd-wpmu-sitewide-options' ),
				esc_html( $master_blog_id )
			);
			return;
		}

		echo '<div style="max-height: 400px; overflow-y: scroll; border: 1px solid #ccd0d4; padding: 10px; background: #fff;">';
		echo '<table>';
		foreach ( $all_options as $option ) {
			$option_name  = esc_attr( $option->option_name );
			$checked      = in_array( $option_name, $this->options['selected_options'] ) ? 'checked' : '';
			$option_value = esc_html( wp_trim_words( maybe_serialize( $option->option_value ), 20 ) );
			
			echo '<tr>';
			echo '<td style="width: 250px; padding-right: 15px;"><label><input type="checkbox" name="yd_network_wide_options[selected_options][]" value="' . $option_name . '" ' . $checked . '> ' . $option_name . '</label></td>';
			echo '<td><small><code>' . $option_value . '</code></small></td>';
			echo '</tr>';
		}
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Render the checkbox list for tables.
	 */
	public function render_tables_field() {
		$master_blog_id = absint( $this->options['master_blog_id'] );
		$all_tables     = $this->get_replicable_tables( $master_blog_id );

		if ( empty( $all_tables ) ) {
			esc_html_e( 'No custom database tables found.', 'yd-wpmu-sitewide-options' );
			return;
		}

		echo '<div style="max-height: 400px; overflow-y: scroll; border: 1px solid #ccd0d4; padding: 10px; background: #fff;">';
		foreach ( $all_tables as $table ) {
			$table_name = esc_attr( $table );
			$checked    = in_array( $table_name, $this->options['selected_tables'] ) ? 'checked' : '';
			echo '<label style="display: block;"><input type="checkbox" name="yd_network_wide_options[selected_tables][]" value="' . $table_name . '" ' . $checked . '> ' . $table_name . '</label>';
		}
		echo '</div>';
	}

	/**
	 * Render a generic settings field (checkbox or text).
	 *
	 * @param array $args Field arguments.
	 */
	public function render_global_field( $args ) {
		$id = $args['id'];
		$value = $this->options[ $id ];

		if ( in_array( $id, [ 'master_blog_id', 'to_skip' ] ) ) {
			// Text field
			echo '<input type="text" name="yd_network_wide_options[' . esc_attr( $id ) . ']" value="' . esc_attr( $value ) . '" class="regular-text">';
			if ( $id === 'to_skip' ) {
				echo '<p class="description">' . esc_html__( 'Comma-separated list of site IDs to exclude.', 'yd-wpmu-sitewide-options' ) . '</p>';
			}
		} else {
			// Checkbox
			echo '<label><input type="checkbox" name="yd_network_wide_options[' . esc_attr( $id ) . ']" value="1" ' . checked( 1, $value, false ) . '></label>';
		}
	}

	/**
	 * Sanitize and validate options.
	 *
	 * @param array $input The input from the settings form.
	 * @return array The sanitized options.
	 */
	public function sanitize_options( $input ) {
		$new_input = $this->get_default_options();

		if ( isset( $input['master_blog_id'] ) ) {
			$new_input['master_blog_id'] = absint( $input['master_blog_id'] );
		}
		if ( isset( $input['to_skip'] ) ) {
			$new_input['to_skip'] = sanitize_text_field( $input['to_skip'] );
		}

		// Checkboxes
		$checkboxes = [
			'autospreading', 'auto_new_blog', 'overwrite_options', 'replicate_data',
			'overwrite_data', 'flush_rewrites', 'only_public', 'skip_archived',
			'skip_mature', 'skip_spam', 'skip_deleted', 'disable_backlink',
		];
		foreach ( $checkboxes as $cb ) {
			$new_input[ $cb ] = isset( $input[ $cb ] ) ? 1 : 0;
		}

		// Array of strings (options and tables)
		if ( ! empty( $input['selected_options'] ) && is_array( $input['selected_options'] ) ) {
			$new_input['selected_options'] = array_map( 'sanitize_text_field', $input['selected_options'] );
		}
		if ( ! empty( $input['selected_tables'] ) && is_array( $input['selected_tables'] ) ) {
			$new_input['selected_tables'] = array_map( 'sanitize_text_field', $input['selected_tables'] );
		}
		
		// After sanitizing, trigger the replication
		$this->replicate_all_settings( $new_input );

		return $new_input;
	}

	/**
	 * Get list of options from the master site.
	 *
	 * @param int $master_blog_id The ID of the master site.
	 * @return array
	 */
	private function get_replicable_options( $master_blog_id ) {
		global $wpdb;
		switch_to_blog( $master_blog_id );
		$query       = "SELECT option_name, option_value FROM $wpdb->options WHERE NOT option_name LIKE %s ORDER BY option_name";
		$optionslist = $wpdb->get_results( $wpdb->prepare( $query, '\_%' ) );
		restore_current_blog();
		return $optionslist;
	}

	/**
	 * Get list of custom tables from the master site.
	 *
	 * @param int $master_blog_id The ID of the master site.
	 * @return array
	 */
	private function get_replicable_tables( $master_blog_id ) {
		global $wpdb;
		switch_to_blog( $master_blog_id );
		$query       = "SHOW TABLES LIKE %s";
		$all_tables  = $wpdb->get_col( $wpdb->prepare( $query, $wpdb->prefix . '%' ) );
		$core_tables = [
			$wpdb->prefix . 'commentmeta',
			$wpdb->prefix . 'comments',
			$wpdb->prefix . 'links',
			$wpdb->prefix . 'options',
			$wpdb->prefix . 'postmeta',
			$wpdb->prefix . 'posts',
			$wpdb->prefix . 'term_relationships',
			$wpdb->prefix . 'term_taxonomy',
			$wpdb->prefix . 'termmeta',
			$wpdb->prefix . 'terms',
		];
		
		// Also filter out global tables
		$core_tables[] = $wpdb->blogs;
		$core_tables[] = $wpdb->blog_versions;
		$core_tables[] = $wpdb->registration_log;
		$core_tables[] = $wpdb->signups;
		$core_tables[] = $wpdb->site;
		$core_tables[] = $wpdb->sitemeta;
		$core_tables[] = $wpdb->users;
		$core_tables[] = $wpdb->usermeta;

		$custom_tables = array_diff( $all_tables, $core_tables );
		restore_current_blog();
		return $custom_tables;
	}
	
	/**
	 * Get a filtered list of sites to replicate to.
	 *
	 * @param array $options Plugin options.
	 * @return array
	 */
	private function get_target_sites( $options ) {
		$args = [
			'number' => 0, // Get all sites
		];
		if ( ! empty( $options['only_public'] ) ) {
			$args['public'] = 1;
		}
		if ( ! empty( $options['skip_archived'] ) ) {
			$args['archived'] = 0;
		}
		if ( ! empty( $options['skip_mature'] ) ) {
			$args['mature'] = 0;
		}
		if ( ! empty( $options['skip_spam'] ) ) {
			$args['spam'] = 0;
		}
		if ( ! empty( $options['skip_deleted'] ) ) {
			$args['deleted'] = 0;
		}
		if ( ! empty( $options['to_skip'] ) ) {
			$excluded_ids = array_map( 'absint', explode( ',', $options['to_skip'] ) );
			$args['site__not_in'] = $excluded_ids;
		}
		
		return get_sites( $args );
	}

	/**
	 * Replicate all selected settings to all target sites.
	 *
	 * @param array $options The plugin settings.
	 */
	private function replicate_all_settings( $options ) {
		$master_blog_id = absint( $options['master_blog_id'] );
		$sites          = $this->get_target_sites( $options );

		if ( empty( $sites ) ) {
			return;
		}

		// Replicate options
		if ( ! empty( $options['selected_options'] ) ) {
			foreach ( $options['selected_options'] as $option_name ) {
				$value = get_blog_option( $master_blog_id, $option_name );
				foreach ( $sites as $site ) {
					$site_id = $site->blog_id;
					if ( $site_id == $master_blog_id ) {
						continue;
					}
					if ( $options['overwrite_options'] || false === get_blog_option( $site_id, $option_name, false ) ) {
						update_blog_option( $site_id, $option_name, $value );
					}
				}
			}
		}

		// Replicate tables
		if ( ! empty( $options['selected_tables'] ) ) {
			foreach ( $options['selected_tables'] as $table_name ) {
				foreach ( $sites as $site ) {
					$site_id = $site->blog_id;
					if ( $site_id == $master_blog_id ) {
						continue;
					}
					$this->replicate_table_structure( $table_name, $site_id, $master_blog_id );
					if ( ! empty( $options['replicate_data'] ) ) {
						$this->replicate_table_data( $table_name, $site_id, $master_blog_id, ! empty( $options['overwrite_data'] ) );
					}
				}
			}
		}
	}

	/**
	 * Replicate a single table's structure.
	 *
	 * @param string $master_table_name Full table name from master site.
	 * @param int    $target_site_id    The ID of the site to replicate to.
	 * @param int    $master_site_id    The ID of the master site.
	 */
	private function replicate_table_structure( $master_table_name, $target_site_id, $master_site_id ) {
		global $wpdb;

		// Get master table structure
		$master_create_sql_row = $wpdb->get_row( "SHOW CREATE TABLE `{$master_table_name}`", ARRAY_A );
		if ( ! $master_create_sql_row || empty( $master_create_sql_row['Create Table'] ) ) {
			return;
		}
		$master_create_sql = $master_create_sql_row['Create Table'];

		// Get prefixes
		$master_prefix = $wpdb->get_blog_prefix( $master_site_id );
		$target_prefix = $wpdb->get_blog_prefix( $target_site_id );

		// Determine the non-prefixed part of the table name
		$base_table_name = preg_replace( "/^{$master_prefix}/", '', $master_table_name );
		$target_table_name = $target_prefix . $base_table_name;

		// Create the new SQL
		$target_create_sql = preg_replace( "/`{$master_table_name}`/", "`{$target_table_name}`", $master_create_sql, 1 );
		$target_create_sql = str_replace( 'CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $target_create_sql );

		$wpdb->query( $target_create_sql );
	}

	/**
	 * Replicate a single table's data.
	 *
	 * @param string $master_table_name Full table name from master site.
	 * @param int    $target_site_id    The ID of the site to replicate to.
	 * @param int    $master_site_id    The ID of the master site.
	 * @param bool   $overwrite         Whether to delete existing data.
	 */
	private function replicate_table_data( $master_table_name, $target_site_id, $master_site_id, $overwrite ) {
		global $wpdb;

		$master_prefix = $wpdb->get_blog_prefix( $master_site_id );
		$target_prefix = $wpdb->get_blog_prefix( $target_site_id );

		$base_table_name   = preg_replace( "/^{$master_prefix}/", '', $master_table_name );
		$target_table_name = $target_prefix . $base_table_name;
		
		// Check if target table exists
		if($wpdb->get_var("SHOW TABLES LIKE '{$target_table_name}'") != $target_table_name) {
			return; // Don't proceed if target table doesn't exist.
		}

		if ( $overwrite ) {
			$wpdb->query( "TRUNCATE TABLE `{$target_table_name}`" );
		}

		$master_data = $wpdb->get_results( "SELECT * FROM `{$master_table_name}`", ARRAY_A );

		if ( ! empty( $master_data ) ) {
			$columns = array_keys( $master_data[0] );
			$column_list = '`' . implode( '`, `', $columns ) . '`';
			
			$values_list = [];
			$placeholders = [];
			
			foreach($master_data as $row) {
				$row_placeholders = [];
				foreach($row as $value) {
					$values_list[] = $value;
					$row_placeholders[] = '%s';
				}
				$placeholders[] = '(' . implode(',', $row_placeholders) . ')';
			}
			
			$query = "INSERT INTO `{$target_table_name}` ({$column_list}) VALUES " . implode(', ', $placeholders);
			$wpdb->query( $wpdb->prepare($query, $values_list) );
		}
	}

	/**
	 * Action fired when a new blog is created.
	 *
	 * @param int $blog_id The ID of the newly created site.
	 */
	public function on_new_blog_creation( $blog_id ) {
		if ( empty( $this->options['auto_new_blog'] ) ) {
			return;
		}

		$master_blog_id = absint( $this->options['master_blog_id'] );
		if ( $blog_id == $master_blog_id ) {
			return;
		}
		
		// Replicate options
		if ( ! empty( $this->options['selected_options'] ) ) {
			foreach ( $this->options['selected_options'] as $option_name ) {
				$value = get_blog_option( $master_blog_id, $option_name );
				update_blog_option( $blog_id, $option_name, $value );
			}
		}

		// Replicate tables
		if ( ! empty( $this->options['selected_tables'] ) ) {
			foreach ( $this->options['selected_tables'] as $table_name ) {
				$this->replicate_table_structure( $table_name, $blog_id, $master_blog_id );
				if ( ! empty( $this->options['replicate_data'] ) ) {
					// Always overwrite for new blogs
					$this->replicate_table_data( $table_name, $blog_id, $master_blog_id, true );
				}
			}
		}

		// Flush rewrite rules
		if ( ! empty( $this->options['flush_rewrites'] ) ) {
			switch_to_blog( $blog_id );
			flush_rewrite_rules();
			restore_current_blog();
		}
	}

	/**
	 * Add hooks to watch for updates on selected options.
	 */
	public function add_option_update_hooks() {
		if ( empty( $this->options['autospreading'] ) || empty( $this->options['selected_options'] ) ) {
			return;
		}

		foreach ( $this->options['selected_options'] as $option_name ) {
			add_action( "update_option_{$option_name}", [ $this, 'on_tracked_option_update' ], 10, 3 );
		}
	}

	/**
	 * Action fired when a tracked option is updated on the master site.
	 *
	 * @param mixed  $old_value The old option value.
	 * @param mixed  $new_value The new option value.
	 * @param string $option_name The name of the option.
	 */
	public function on_tracked_option_update( $old_value, $new_value, $option_name ) {
		global $wpdb;
		// This hook fires for all sites. We only care if it's the master site.
		if ( get_current_blog_id() != absint( $this->options['master_blog_id'] ) ) {
			return;
		}

		$sites = $this->get_target_sites( $this->options );
		if ( empty( $sites ) ) {
			return;
		}

		foreach ( $sites as $site ) {
			$site_id = $site->blog_id;
			if ( $site_id == get_current_blog_id() ) {
				continue;
			}
			update_blog_option( $site_id, $option_name, $new_value );
		}
	}

	/**
	 * Display a link in the footer, if not disabled.
	 */
	public function display_footer_link() {
		if ( ! empty( $this->options['disable_backlink'] ) ) {
			return;
		}
		echo '<p style="text-align:center" class="yd_linkware"><small><a href="'
			. esc_url( __( 'http://www.yann.com/en/wp-plugins/yd-wpmu-sitewide-options', 'yd-wpmu-sitewide-options' ) )
			. '">' . esc_html__( 'Network-wide options by YD - Freelance Wordpress Developer', 'yd-wpmu-sitewide-options' )
			. '</a></small></p>';
	}
	
	/**
	 * Add links to the plugin's row in the plugin list table.
	 *
	 * @param array  $links The existing links.
	 * @param string $file The plugin file.
	 * @return array
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( YD_NWO_PLUGIN_FILE ) === $file ) {
			$settings_link = '<a href="' . network_admin_url( 'settings.php?page=yd-network-wide-options' ) . '">' . __( 'Settings', 'yd-wpmu-sitewide-options' ) . '</a>';
			$support_link  = '<a href="http://www.yann.com/en/wp-plugins/yd-wpmu-sitewide-options" target="_blank">' . __( 'Support', 'yd-wpmu-sitewide-options' ) . '</a>';
			array_unshift( $links, $settings_link, $support_link );
		}
		return $links;
	}
}

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function yd_nwo_run() {
	return YD_Network_Wide_Options::instance();
}
yd_nwo_run();

