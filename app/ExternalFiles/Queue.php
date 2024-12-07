<?php
/**
 * This file contains a controller-object to handle the queue for external files.
 *
 * @package external-files-in-media-library
 */

namespace ExternalFilesInMediaLibrary\ExternalFiles;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ExternalFilesInMediaLibrary\Plugin\Crypt;
use ExternalFilesInMediaLibrary\Plugin\Helper;
use ExternalFilesInMediaLibrary\Plugin\Log;
use ExternalFilesInMediaLibrary\Plugin\Settings\Fields\Number;
use ExternalFilesInMediaLibrary\Plugin\Settings\Fields\Select;
use ExternalFilesInMediaLibrary\Plugin\Settings\Settings;
use ExternalFilesInMediaLibrary\Plugin\Transients;

/**
 * Controller for queue tasks.
 *
 * @noinspection PhpUnused
 */
class Queue {
	/**
	 * Instance of actual object.
	 *
	 * @var Queue|null
	 */
	private static ?Queue $instance = null;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return instance of this object as singleton.
	 *
	 * @return Queue
	 */
	public static function get_instance(): Queue {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// add settings.
		add_action( 'init', array( $this, 'init_queue' ), 20 );

		// misc.
		add_action( 'admin_action_eml_queue_process', array( $this, 'process_queue_by_request' ) );
		add_action( 'admin_action_eml_queue_clear', array( $this, 'clear_by_request' ) );
		add_action( 'admin_action_eml_queue_clear_errors', array( $this, 'delete_errors_by_request' ) );
		add_action( 'admin_action_eml_queue_delete_entry', array( $this, 'delete_entry_by_request' ) );
		add_action( 'admin_action_eml_queue_process_entry', array( $this, 'process_queue_entry_by_request' ) );
	}

	/**
	 * Initialize the settings for this part of the plugin.
	 *
	 * @return void
	 */
	public function init_queue(): void {
		// get the settings object.
		$settings_obj = Settings::get_instance();

		// add interval setting on main tab.
		$general_tab_main = $settings_obj->get_section( 'settings_section_main' );

		// create interval setting.
		$setting = $settings_obj->add_setting( 'eml_queue_interval' );
		$setting->set_section( $general_tab_main );
		$setting->set_type( 'string' );
		$setting->set_default( 'hourly' );
		$field = new Select();
		$field->set_title( __( 'Set interval for queue processing', 'external-files-in-media-library' ) );
		$field->set_description( __( 'Defines the time interval in which the queue for new URLs will be processed.', 'external-files-in-media-library' ) );
		$field->set_options( Helper::get_intervals() );
		$field->set_sanitize_callback( array( $this, 'sanitize_interval_setting' ) );
		$setting->set_save_callback( array( $this, 'update_interval_setting' ) );
		$setting->set_field( $field );

		// add setting for limit.
		$setting = $settings_obj->add_setting( 'eml_queue_limit' );
		$setting->set_section( $general_tab_main );
		$setting->set_type( 'integer' );
		$setting->set_default( 10 );
		$field = new Number();
		$field->set_title( __( 'To process per queue cycle', 'external-files-in-media-library' ) );
		$field->set_description( __( 'Set the limit of URLs which will be process per cycle.', 'external-files-in-media-library' ) );
		$setting->set_field( $field );

		// add tab for queue table.
		$queue_table_tab = $settings_obj->add_tab( 'eml_queue_table' );
		$queue_table_tab->set_title( __( 'Queue', 'external-files-in-media-library' ) );
		$queue_table_tab->set_callback( array( $this, 'show_queue' ) );
	}

	/**
	 * Sanitize the interval setting.
	 *
	 * @param string|null $value The given value.
	 *
	 * @return string
	 */
	public function sanitize_interval_setting( null|string $value ): string {
		// get option.
		$option = str_replace( 'sanitize_option_', '', current_filter() );

		// bail if value is empty.
		if ( empty( $value ) ) {
			add_settings_error( $option, $option, __( 'An interval has to be set.', 'external-files-in-media-library' ) );
			return '';
		}

		// bail if value is 'eml_disable_check'.
		if ( 'eml_disable_check' === $value ) {
			return $value;
		}

		// check if the given interval exists.
		$intervals = wp_get_schedules();
		if ( empty( $intervals[ $value ] ) ) {
			/* translators: %1$s will be replaced by the name of the used interval */
			add_settings_error( $option, $option, sprintf( __( 'The given interval %1$s does not exists.', 'external-files-in-media-library' ), esc_html( $value ) ) );
		}

		// return the value.
		return $value;
	}

	/**
	 * Update the schedule if interval has been changed.
	 *
	 * @param string|null $value The given value for the interval.
	 *
	 * @return string
	 */
	public function update_interval_setting( string|null $value ): string {
		// get queue-schedule-object.
		$queue_schedule = new \ExternalFilesInMediaLibrary\Plugin\Schedules\Queue();

		// if new value is 'eml_disable_check' remove the schedule.
		if ( 'eml_disable_check' === $value ) {
			// log event.
			Log::get_instance()->create( __( 'Queue schedule has been disabled.', 'external-files-in-media-library' ), '', 'info', 2 );

			// remove schedule.
			$queue_schedule->delete();
		} else {
			// log event.
			Log::get_instance()->create( __( 'Queue schedule interval has changed.', 'external-files-in-media-library' ), '', 'info', 2 );

			// set the new interval.
			$queue_schedule->set_interval( $value );

			// reset the schedule.
			$queue_schedule->reset();
		}

		// return the new value to save it via WP.
		return $value;
	}

	/**
	 * Install necessary DB-table.
	 *
	 * @return void
	 */
	public function install(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		// table for the queue.
		$sql = 'CREATE TABLE ' . $wpdb->prefix . "eml_queue (
		    `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `time` datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            `url` text DEFAULT '' NOT NULL,
            `login` text DEFAULT '' NOT NULL,
            `password` text DEFAULT '' NOT NULL,
            `state` text DEFAULT '' NOT NULL,
            UNIQUE KEY id (id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Add list of URLs to the queue.
	 *
	 * @param array  $urls List of URLs.
	 * @param string $login The login to use.
	 * @param string $password The password to use.
	 *
	 * @return void
	 */
	public function add_urls( array $urls, string $login = '', string $password = '' ): void {
		global $wpdb;

		// bail if list is empty.
		if ( empty( $urls ) ) {
			return;
		}

		// add the files to the queue table.
		foreach ( $urls as $url ) {
			// bail if file-string is empty.
			if ( empty( $url ) ) {
				continue;
			}

			// bail if given URL is already in queue.
			if ( ! empty( $this->get_url( $url ) ) ) {
				Log::get_instance()->create( __( 'URL is already in queue.', 'external-files-in-media-library' ), $url, 'info', 0 );
				continue;
			}

			// add the URL of this file to the list.
			$result = $wpdb->insert(
				$wpdb->prefix . 'eml_queue',
				array(
					'time'     => gmdate( 'Y-m-d H:i:s' ),
					'url'      => $url,
					'login'    => ! empty( $login ) ? Crypt::get_instance()->encrypt( $login ) : '',
					'password' => ! empty( $password ) ? Crypt::get_instance()->encrypt( $password ) : '',
					'state'    => 'new',
				)
			);

			// use error-handling.
			$this->db_error_handling( $result, $url );
		}
	}

	/**
	 * Process the queue.
	 *
	 * Import all URLs from queue in media library which have the state "new".
	 *
	 * @return void
	 */
	public function process_queue(): void {
		// get the queue.
		$urls_to_import = $this->get_urls();

		// show progress.
		/* translators: %1$d will be replaced by a number. */
		$progress = Helper::is_cli() ? \WP_CLI\Utils\make_progress_bar( sprintf( _n( 'Processing the import of %1$d URL from queue.', 'Processing the import of %1$d URLs from queue.', count( $urls_to_import ), 'external-files-in-media-library' ), count( $urls_to_import ) ), count( $urls_to_import ) ) : '';

		// log event.
		/* translators: %1$d will be replaced by a number. */
		Log::get_instance()->create( sprintf( _n( 'Processing the import of %1$d URL from queue.', 'Processing the import of %1$d URLs from queue.', count( $urls_to_import ), 'external-files-in-media-library' ), count( $urls_to_import ) ), '', 'info', 2 );

		/**
		 * Filter the list of URLs from queue before they are processed.
		 *
		 * @since 2.0.0 Available since 2.0.0.
		 * @param array $urls_to_import List of URLs to import from queue.
		 */
		$urls_to_import = apply_filters( 'eml_queue_urls', $urls_to_import );

		/**
		 * Run action before queue is processed.
		 *
		 * @since 2.0.0 Available since 2.0.0.
		 * @param array $urls_to_import List of URLs to import from queue which will be processed.
		 */
		do_action( 'eml_queue_before_process', $urls_to_import );

		// loop through the queue.
		foreach ( $urls_to_import as $url_data ) {
			// bail if given url_data is not an array.
			if ( ! is_array( $url_data ) ) {
				continue;
			}

			$this->process_entry( $url_data['id'] );

			// show progress.
			$progress ? $progress->tick() : '';
		}

		// show end of process.
		$progress ? $progress->finish() : '';

		/**
		 * Run action after queue is processed.
		 *
		 * @since 2.0.0 Available since 2.0.0.
		 * @param array $urls_to_import List of URLs to import from queue which has been processed.
		 */
		do_action( 'eml_queue_after_process', $urls_to_import );

		// log event.
		/* translators: %1$d will be replaced by a number. */
		Log::get_instance()->create( sprintf( _n( 'Processing the import of %1$d URL from queue ended.', 'Processing the import of %1$d URLs from queue ended.', count( $urls_to_import ), 'external-files-in-media-library' ), count( $urls_to_import ) ), '', 'info', 2 );
	}

	/**
	 * Process single entry.
	 *
	 * @param int $id The ID to use.
	 *
	 * @return void
	 */
	private function process_entry( int $id ): void {
		// get the entry.
		$url_data = $this->get_url_by_id( $id );

		// bail if no data could be loaded.
		if ( empty( $url_data ) ) {
			return;
		}

		// get the files object.
		$files_obj = Files::get_instance();

		// set the login.
		$files_obj->set_login( Crypt::get_instance()->decrypt( $url_data['login'] ) );

		// set the password.
		$files_obj->set_password( Crypt::get_instance()->decrypt( $url_data['password'] ) );

		// import the URL.
		if ( $files_obj->add_url( $url_data['url'] ) ) {
			// remove URL from queue.
			$this->remove_url( absint( $url_data['id'] ) );
		} else {
			// mark URL with state "error" to prevent usage.
			$this->set_url_state( absint( $url_data['id'] ), 'error' );
		}
	}

	/**
	 * Process the queue by request.
	 *
	 * @return void
	 * @noinspection PhpNoReturnAttributeCanBeAddedInspection
	 */
	public function process_queue_by_request(): void {
		// check the nonce.
		check_admin_referer( 'eml-queue-process', 'nonce' );

		// process the queue.
		$this->process_queue();

		// show ok message.
		$transients_obj = Transients::get_instance();
		$transient_obj  = $transients_obj->add();
		$transient_obj->set_name( 'eml_queue_processed' );
		$transient_obj->set_message( __( 'The queue has been processed.', 'external-files-in-media-library' ) );
		$transient_obj->set_type( 'success' );
		$transient_obj->save();

		// forward user.
		wp_safe_redirect( wp_get_referer() );
		exit;
	}

	/**
	 * Process single entry of queue by request.
	 *
	 * @return void
	 * @noinspection PhpNoReturnAttributeCanBeAddedInspection
	 */
	public function process_queue_entry_by_request(): void {
		// check the nonce.
		check_admin_referer( 'eml-queue-process-entry', 'nonce' );

		// get the ID from request.
		$id = absint( filter_input( INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT ) );

		// delete the entry if id is given.
		if ( $id > 0 ) {
			// process it.
			$this->process_entry( $id );

			// show ok message.
			$transients_obj = Transients::get_instance();
			$transient_obj  = $transients_obj->add();
			$transient_obj->set_name( 'eml_queue_processed' );
			$transient_obj->set_message( '<strong>' . __( 'The entry has been processed.', 'external-files-in-media-library' ) . '</strong>' );
			$transient_obj->set_type( 'success' );
			$transient_obj->save();
		}

		// forward user.
		wp_safe_redirect( wp_get_referer() );
		exit;
	}

	/**
	 * Clear the queue.
	 *
	 * @return void
	 */
	public function clear(): void {
		global $wpdb;
		$wpdb->query( sprintf( 'TRUNCATE TABLE %s', $wpdb->prefix . 'eml_queue' ) );
	}

	/**
	 * Clear the queue by request.
	 *
	 * @return void
	 * @noinspection PhpNoReturnAttributeCanBeAddedInspection
	 */
	public function clear_by_request(): void {
		check_admin_referer( 'eml-queue-clear', 'nonce' );

		// clear the queue.
		$this->clear();

		// show ok message.
		$transients_obj = Transients::get_instance();
		$transient_obj  = $transients_obj->add();
		$transient_obj->set_name( 'eml_queue_cleared' );
		$transient_obj->set_message( __( 'The queue has been cleared.', 'external-files-in-media-library' ) );
		$transient_obj->set_type( 'success' );
		$transient_obj->save();

		// forward user.
		wp_safe_redirect( wp_get_referer() );
		exit;
	}

	/**
	 * Delete all error entries by request.
	 *
	 * @return void
	 * @noinspection PhpNoReturnAttributeCanBeAddedInspection
	 */
	public function delete_errors_by_request(): void {
		check_admin_referer( 'eml-queue-clear-errors', 'nonce' );

		// get the error entries.
		foreach ( $this->get_urls( 'error' ) as $url_data ) {
			// delete them.
			$this->remove_url( $url_data['id'] );
		}

		// show ok message.
		$transients_obj = Transients::get_instance();
		$transient_obj  = $transients_obj->add();
		$transient_obj->set_name( 'eml_queue_cleared' );
		$transient_obj->set_message( __( 'Error entries from queue has been deleted.', 'external-files-in-media-library' ) );
		$transient_obj->set_type( 'success' );
		$transient_obj->save();

		// forward user.
		wp_safe_redirect( wp_get_referer() );
		exit;
	}

	/**
	 * Return list of URLs from queue table.
	 *
	 * @param string $state The requested state (new or error).
	 * @param bool   $unlimited Whether to call unlimited list (true) or not (false).
	 *
	 * @return array
	 */
	public function get_urls( string $state = 'new', bool $unlimited = false ): array {
		global $wpdb;

		if ( empty( $state ) ) {
			// run query to unlimited list.
			if ( $unlimited ) {
				// return all URLs for requested state.
				return $wpdb->get_results( $wpdb->prepare( 'SELECT `id`, `time` as `date`, `url`, `login`, `password`, `state` FROM ' . $wpdb->prefix . 'eml_queue WHERE 1 = %s ORDER BY `time` DESC', array( 1 ) ), ARRAY_A );
			}

			// return limited URLs for requested state.
			return $wpdb->get_results( $wpdb->prepare( 'SELECT `id`, `time` as `date`, `url`, `login`, `password`, `state` FROM ' . $wpdb->prefix . 'eml_queue WHERE 1 = %s ORDER BY `time` DESC LIMIT %d', array( 1, absint( get_option( 'eml_queue_limit' ) ) ) ), ARRAY_A );
		}

		// run query to unlimited list.
		if ( $unlimited ) {
			// return all URLs for requested state.
			return $wpdb->get_results( $wpdb->prepare( 'SELECT `id`, `time` as `date`, `url`, `login`, `password`, `state` FROM ' . $wpdb->prefix . 'eml_queue WHERE 1 = %s AND `state` = %s ORDER BY `time` DESC', array( 1, $state ) ), ARRAY_A );
		}

		// return limited URLs for requested state.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT `id`, `time` as `date`, `url`, `login`, `password`, `state` FROM ' . $wpdb->prefix . 'eml_queue WHERE 1 = %s AND `state` = %s ORDER BY `time` DESC LIMIT %d', array( 1, $state, absint( get_option( 'eml_queue_limit' ) ) ) ), ARRAY_A );
	}

	/**
	 * Return data of single URL in queue.
	 *
	 * @param string $url The requested URL.
	 *
	 * @return array
	 */
	private function get_url( string $url ): array {
		global $wpdb;

		// get the data of the single URL.
		$result = $wpdb->get_row( $wpdb->prepare( 'SELECT `id`, `url`, `login`, `password` FROM ' . $wpdb->prefix . 'eml_queue WHERE 1 = %s AND `url` = %s', array( 1, $url ) ), ARRAY_A );

		// if query resulted not in an array return an empty array.
		if ( ! is_array( $result ) ) {
			return array();
		}

		// return resulting array with the data.
		return $result;
	}

	/**
	 * Return data of single ID in queue.
	 *
	 * @param int $id The ID.
	 *
	 * @return array
	 */
	private function get_url_by_id( int $id ): array {
		global $wpdb;

		// get the data of the single URL.
		$result = $wpdb->get_row( $wpdb->prepare( 'SELECT `id`, `url`, `login`, `password` FROM ' . $wpdb->prefix . 'eml_queue WHERE 1 = %s AND `id` = %d', array( 1, $id ) ), ARRAY_A );

		// if query resulted not in an array return an empty array.
		if ( ! is_array( $result ) ) {
			return array();
		}

		// return resulting array with the data.
		return $result;
	}

	/**
	 * Set entry in queue to given state.
	 *
	 * @param int    $id The ID to use.
	 * @param string $state The state to set.
	 *
	 * @return void
	 */
	private function set_url_state( int $id, string $state ): void {
		global $wpdb;

		// get the URL from queue for given ID.
		$url      = '';
		$url_data = $this->get_url( $url );
		if ( ! empty( $url_data ) ) {
			$url = $url_data['url'];
		}

		// update the entry.
		$result = $wpdb->update(
			$wpdb->prefix . 'eml_queue',
			array(
				'state' => $state,
			),
			array(
				'id' => $id,
			)
		);

		// use error-handling.
		$this->db_error_handling( $result, $url );
	}

	/**
	 * Remove given URL from queue.
	 *
	 * @param int $id The id to use.
	 *
	 * @return void
	 */
	private function remove_url( int $id ): void {
		global $wpdb;

		// get the URL from queue for given ID.
		$url      = '';
		$url_data = $this->get_url( $url );
		if ( ! empty( $url_data ) ) {
			$url = $url_data['url'];
		}

		// update the entry.
		$result = $wpdb->delete(
			$wpdb->prefix . 'eml_queue',
			array(
				'id' => $id,
			)
		);

		// use error-handling.
		$this->db_error_handling( $result, $url );
	}

	/**
	 * Handling of any DB-errors in this object.
	 *
	 * @param \mysqli_result|bool|int|null $result The result.
	 * @param string                       $url The URL.
	 *
	 * @return void
	 */
	private function db_error_handling( \mysqli_result|bool|int|null $result, string $url ): void {
		global $wpdb;

		// bail if result is not false.
		if ( false !== $result ) {
			return;
		}

		// log this event.
		/* translators: %1$s is replaced by an error-code. */
		Log::get_instance()->create( sprintf( __( 'Database-Error: %1$s', 'external-files-in-media-library' ), '<code>' . wp_json_encode( $wpdb->last_error ) . '</code>' ), $url, 'error', 0 );
	}

	/**
	 * Show queue table in backend.
	 *
	 * @return void
	 */
	public function show_queue(): void {
		// if WP_List_Table is not loaded automatically, we need to load it.
		if ( ! class_exists( 'WP_List_Table' ) ) {
			include_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		$queue = new \ExternalFilesInMediaLibrary\Plugin\Tables\Queue();
		$queue->prepare_items();
		?>
		<div class="wrap eml-queue-table">
			<h2><?php echo esc_html__( 'Queue', 'external-files-in-media-library' ); ?></h2>
			<?php
			$queue->views();
			$queue->display();
			?>
		</div>
		<?php
	}

	/**
	 * Uninstall the queue table.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		global $wpdb;
		$wpdb->query( sprintf( 'DROP TABLE IF EXISTS %s', $wpdb->prefix . 'eml_queue' ) );
	}

	/**
	 * Delete entry by request.
	 *
	 * @return void
	 * @noinspection PhpNoReturnAttributeCanBeAddedInspection
	 */
	public function delete_entry_by_request(): void {
		check_admin_referer( 'eml-queue-delete-entry', 'nonce' );

		// get the ID from request.
		$id = absint( filter_input( INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT ) );

		// delete the entry if id is given.
		if ( $id > 0 ) {
			$this->remove_url( $id );

			// show ok message.
			$transient_obj = Transients::get_instance()->add();
			$transient_obj->set_type( 'success' );
			$transient_obj->set_name( 'eml_queue_entry_deleted' );
			$transient_obj->set_message( '<strong>' . __( 'The queue entry has been deleted.', 'external-files-in-media-library' ) . '</strong>' );
			$transient_obj->save();
		}

		// redirect the user.
		wp_safe_redirect( wp_get_referer() );
		exit;
	}
}