<?php
/**
 * This file contains a controller-object to handle external files operations.
 *
 * @package external-files-in-media-library
 */

namespace ExternalFilesInMediaLibrary\ExternalFiles;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ExternalFilesInMediaLibrary\Plugin\Helper;
use ExternalFilesInMediaLibrary\Plugin\Log;
use WP_Post;
use WP_Query;

/**
 * Controller for external files-tasks.
 *
 * @noinspection PhpUnused
 */
class Files {
	/**
	 * Instance of actual object.
	 *
	 * @var Files|null
	 */
	private static ?Files $instance = null;

	/**
	 * Log-object.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * The login.
	 *
	 * @var string
	 */
	private string $login = '';

	/**
	 * The password.
	 *
	 * @var string
	 */
	private string $password = '';

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {
		// get log-object.
		$this->log = Log::get_instance();
	}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() { }

	/**
	 * Return instance of this object as singleton.
	 *
	 * @return Files
	 */
	public static function get_instance(): Files {
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
		// misc.
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_media_box' ), 20, 1 );

		// main handling of external files in media tasks.
		add_filter( 'attachment_link', array( $this, 'get_attachment_link' ), 10, 2 );
		add_filter( 'wp_get_attachment_url', array( $this, 'get_attachment_url' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'change_media_row_actions' ), 20, 2 );
		add_filter( 'get_attached_file', array( $this, 'get_attached_file' ), 10, 2 );
		add_filter( 'image_downsize', array( $this, 'image_downsize' ), 10, 3 );
		add_action( 'import_end', array( $this, 'import_end' ), 10, 0 );
		add_filter( 'redirect_canonical', array( $this, 'disable_attachment_page' ), 10, 0 );
		add_filter( 'template_redirect', array( $this, 'disable_attachment_page' ), 10, 0 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'wp_calculate_image_srcset' ), 10, 5 );
		add_filter( 'wp_import_post_meta', array( $this, 'set_import_marker_for_attachments' ), 10, 2 );
		add_filter( 'wp_get_attachment_metadata', array( $this, 'wp_get_attachment_metadata' ), 10, 2 );
		add_action( 'delete_attachment', array( $this, 'log_url_deletion' ), 10, 1 );
		add_action( 'delete_attachment', array( $this, 'delete_file_from_cache' ), 10, 1 );

		// add ajax hooks.
		add_action( 'wp_ajax_eml_check_availability', array( $this, 'check_file_availability_via_ajax' ), 10, 0 );
		add_action( 'wp_ajax_eml_switch_hosting', array( $this, 'switch_hosting_via_ajax' ), 10, 0 );

		// use our own hooks.
		add_filter( 'eml_file_import_title', array( $this, 'optimize_file_title' ) );
		add_action( 'eml_check_files', array( $this, 'check_files' ), 10, 0 );
		add_filter( 'eml_file_import_title', array( $this, 'set_file_title' ), 10, 3 );

		// add admin actions.
		add_action( 'admin_action_eml_reset_thumbnails', array( $this, 'reset_thumbnails_by_request' ) );
	}

	/**
	 * Return the URL of an external file.
	 *
	 * @param string $url               The URL which is requested.
	 * @param int    $attachment_id     The attachment-ID which is requested.
	 *
	 * @return string
	 */
	public function get_attachment_url( string $url, int $attachment_id ): string {
		$external_file_obj = $this->get_file( $attachment_id );

		// bail if file is not a URL-file.
		if ( false === $external_file_obj ) {
			return $url;
		}

		// return the original URL if this URL-file is not valid or not available.
		if ( false === $external_file_obj->is_valid() || false === $external_file_obj->get_availability() ) {
			return $url;
		}

		// use local URL if URL-file is locally saved.
		if ( false !== $external_file_obj->is_locally_saved() ) {
			$uploads = wp_get_upload_dir();
			if ( false === $uploads['error'] ) {
				return trailingslashit( $uploads['baseurl'] ) . $external_file_obj->get_attachment_url();
			}
		}

		// return the extern URL.
		return $external_file_obj->get_url();
	}

	/**
	 * Disable attachment-page-links for external files, if this is enabled.
	 *
	 * @param string $url               The URL which is requested.
	 * @param ?int   $attachment_id     The attachment-ID which is requested.
	 *
	 * @return string
	 */
	public function get_attachment_link( string $url, ?int $attachment_id ): string {
		$false = false;
		/**
		 * Filter if attachment link should not be changed.
		 *
		 * @since 2.0.0 Available since 2.0.0.
		 *
		 * @param bool $false True if URL should not be changed.
		 * @param string $url The given URL.
		 * @param ?int $attachment_id The ID of the attachment.
		 *
		 * @noinspection PhpConditionAlreadyCheckedInspection
		 */
		if ( false !== apply_filters( 'eml_attachment_link', $false, $url, $attachment_id ) ) {
			return $url;
		}

		// get the external file object.
		$external_file_obj = $this->get_file( absint( $attachment_id ) );

		// bail if file is not a URL-file.
		if ( false === $external_file_obj ) {
			return $url;
		}

		// bail if file is not valid.
		if ( ! $external_file_obj->is_valid() ) {
			return $url;
		}

		// bail if attachment pages are not disabled.
		if ( 0 === absint( get_option( 'eml_disable_attachment_pages', 0 ) ) ) {
			return $url;
		}

		// return the external URL.
		return $external_file_obj->get_url( is_admin() );
	}

	/**
	 * Get all external files in media library as external_file-object-array.
	 *
	 * @return array
	 */
	public function get_files_in_media_library(): array {
		$query  = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'meta_query'     => array(
				array(
					'key'     => EML_POST_META_URL,
					'compare' => 'EXISTS',
				),
			),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		$result = new WP_Query( $query );

		// bail on no results.
		if ( 0 === $result->post_count ) {
			return array();
		}

		// collect the results.
		$results = array();

		// loop through them.
		foreach ( $result->get_posts() as $attachment_id ) {
			// get the object of the external file.
			$external_file_obj = $this->get_file( $attachment_id );

			// bail if object could not be loaded.
			if ( ! $external_file_obj ) {
				continue;
			}

			// add object to the list.
			$results[] = $external_file_obj;
		}

		// bail if list is empty.
		if ( empty( $results ) ) {
			return array();
		}

		// return the resulting list.
		return $results;
	}

	/**
	 * Add a URL in media library.
	 *
	 * This is the main function for any import.
	 *
	 * If URL is a directory we try to import all files from this directory.
	 * If URL is a single file, it will be imported.
	 *
	 * @param string $url The URL to add.
	 *
	 * @return bool true if anything from the URL has been added successfully.
	 */
	public function add_from_url( string $url ): bool {
		$false = false;
		/**
		 * Filter the given URL against custom blacklists.
		 *
		 * @since 2.0.0 Available since 2.0.0.
		 * @param bool $false Return true if blacklist matches.
		 * @param string $url The given URL.
		 *
		 * @noinspection PhpConditionAlreadyCheckedInspection
		 */
		if ( apply_filters( 'eml_blacklist', $false, $url ) ) {
			return false;
		}

		/**
		 * Get the handler for this URL depending on its protocol.
		 */
		$protocol_handler_obj = Protocols::get_instance()->get_protocol_object_for_url( $url );

		/**
		 * Do nothing if URL is using a not supported tcp protocol.
		 */
		if ( ! $protocol_handler_obj ) {
			/* translators: %1$s will be replaced by the file-URL */
			Log::get_instance()->create( sprintf( __( 'Given URL %1$s is using a not supported TCP protocol. You will not be able to use this URL for external files in media library.', 'external-files-in-media-library' ), esc_html( $url ) ), esc_html( $url ), 'error', 0 );
			return false;
		}

		/**
		 * Add the given credentials, even if none are set.
		 */
		$protocol_handler_obj->set_login( $this->get_login() );
		$protocol_handler_obj->set_password( $this->get_password() );

		/**
		 * Get information about files under the given URL.
		 */
		$files = $protocol_handler_obj->get_external_infos();

		/**
		 * Do nothing if check of URL resulted in empty file list.
		 */
		if ( empty( $files ) ) {
			/* translators: %1$s will be replaced by the file-URL */
			Log::get_instance()->create( sprintf( __( 'No files found under given URL %1$s.', 'external-files-in-media-library' ), esc_html( $url ) ), esc_html( $url ), 'error', 0 );
			return false;
		}

		/**
		 * Get user the attachment would be assigned to.
		 */
		$user_id = Helper::get_current_user_id();

		/**
		 * Filter the user_id for a single file during import.
		 *
		 * @since 1.1.0 Available since 1.1.0
		 *
		 * @param int $user_id The title generated by importer.
		 * @param string $url The requested external URL.
		 */
		$user_id = apply_filters( 'eml_file_import_user', $user_id, $url );

		/**
		 * Loop through the results and save each in the media library.
		 */
		foreach ( $files as $file_data ) {
			/**
			 * Run additional tasks before new external file will be added.
			 *
			 * @since 2.0.0 Available since 2.0.0.
			 * @param array $file_data The array with the file data.
			 */
			do_action( 'eml_before_file_save', $file_data );

			// bail if file is given, but has an error.
			if ( ! empty( $file_data['tmp-file'] ) && is_wp_error( $file_data['tmp-file'] ) ) {
				/* translators: %1$s will be replaced by the file-URL */
				Log::get_instance()->create( sprintf( __( 'Given string %1$s results in error during request: <pre>%2$s</pre>', 'external-files-in-media-library' ), esc_url( $url ), wp_json_encode( $file_data['tmp-file'] ) ), esc_url( $url ), 'error', 0 );
				continue;
			}

			/**
			 * Filter the title for a single file during import.
			 *
			 * @since 1.1.0 Available since 1.1.0
			 *
			 * @param string $title     The title generated by importer.
			 * @param string $url       The requested external URL.
			 * @param array  $file_data List of file settings detected by importer.
			 */
			$title = apply_filters( 'eml_file_import_title', $file_data['title'], $file_data['url'], $file_data );

			/**
			 * Prepare attachment-post-settings.
			 */
			$post_array = array(
				'post_author' => $user_id,
				'post_name'   => $title,
			);

			/**
			 * Filter the attachment settings
			 *
			 * @since 2.0.0 Available since 2.0.0
			 *
			 * @param string $post_array     The attachment settings.
			 * @param string $url       The requested external URL.
			 * @param array  $file_data List of file settings detected by importer.
			 */
			$post_array = apply_filters( 'eml_file_import_attachment', $post_array, $file_data['url'], $file_data );

			/**
			 * Save this file local if it is required.
			 */
			if ( false !== $file_data['local'] ) {
				// import file as image via WP-own functions.
				$array = array(
					'name'     => $title,
					'type'     => $file_data['mime-type'],
					'tmp_name' => $file_data['tmp-file'],
					'error'    => 0,
					'size'     => $file_data['filesize'],
				);

				$attachment_id = media_handle_sideload( $array, 0, null, $post_array );
			} else {
				/**
				 * For all other files: simply create the attachment.
				 */
				$attachment_id = wp_insert_attachment( $post_array, $file_data['url'] );
			}

			// bail on any error.
			if ( is_wp_error( $attachment_id ) ) {
				/* translators: %1$s will be replaced by the file-URL, %2$s will be replaced by a WP-error-message */
				$this->log->create( sprintf( __( 'URL %1$s could not be saved because of this error: %2$s', 'external-files-in-media-library' ), $file_data['url'], $attachment_id->errors['upload_error'][0] ), $file_data['url'], 'error', 0 );
				continue;
			}

			// get external file object to update its settings.
			$external_file_obj = $this->get_file( $attachment_id );

			// bail if object could not be loaded.
			if ( ! $external_file_obj ) {
				/* translators: %1$s will be replaced by the file-URL */
				$this->log->create( sprintf( __( 'External file object for URL %1$s could not be loaded.', 'external-files-in-media-library' ), $file_data['url'] ), $file_data['url'], 'error', 0 );
				continue;
			}

			// mark this attachment as one of our own plugin through setting the URL.
			$external_file_obj->set_url( $file_data['url'] );

			// set title.
			$external_file_obj->set_title( $title );

			// set mime-type.
			$external_file_obj->set_mime_type( $file_data['mime-type'] );

			// set availability-status (true for 'is available', false if not).
			$external_file_obj->set_availability( true );

			// set filesize.
			$external_file_obj->set_filesize( $file_data['filesize'] );

			// mark if this file is an external file locally saved.
			$external_file_obj->set_is_local_saved( $file_data['local'] );

			// save the credentials on the object, if set.
			$external_file_obj->set_login( $this->get_login() );
			$external_file_obj->set_password( $this->get_password() );

			// set meta-data for images if mode is enabled for this.
			if ( false === $file_data['local'] && ! empty( $file_data['tmp-file'] ) ) {
				// TODO implement file-specific metadata.

				// update meta data for images.
				if ( $external_file_obj->is_image() ) {
					// create the image meta data.
					$image_meta = wp_create_image_subsizes( $file_data['tmp-file'], $attachment_id );

					// set file to our url.
					$image_meta['file'] = $file_data['url'];

					// change file name for each size, if given.
					if ( ! empty( $image_meta['sizes'] ) ) {
						foreach ( $image_meta['sizes'] as $size_name => $size_data ) {
							$image_meta['sizes'][ $size_name ]['file'] = Helper::generate_sizes_filename( $file_data['title'], $size_data['width'], $size_data['height'] );
						}
					}

					// save the resulting image-data.
					wp_update_attachment_metadata( $attachment_id, $image_meta );
				}

				// update meta data for videos.
				if ( $external_file_obj->is_video() ) {
					// collect meta data.
					$video_meta = array(
						'filesize' => $file_data['filesize'],
					);

					// save the resulting image-data.
					wp_update_attachment_metadata( $attachment_id, $video_meta );
				}

				// add file to local cache if it is an image.
				$external_file_obj->add_to_cache();
			}

			// log that URL has been added as file in media library.
			/* translators: %1$s will be replaced by the file-URL */
			$this->log->create( sprintf( __( 'URL %1$s successfully added in media library.', 'external-files-in-media-library' ), $file_data['url'] ), $file_data['url'], 'success', 0 );

			/**
			 * Run additional tasks after new external file has been added.
			 *
			 * @since 2.0.0 Available since 2.0.0.
			 * @param File $external_file_obj The object of the external file.
			 * @param array $file_data The array with the file data.
			 */
			do_action( 'eml_after_file_save', $external_file_obj, $file_data );
		}

		// return ok.
		return true;
	}

	/**
	 * Log deletion of external urls in media library.
	 *
	 * @param int $attachment_id  The attachment_id which will be deleted.
	 *
	 * @return void
	 */
	public function log_url_deletion( int $attachment_id ): void {
		// get the external file object.
		$external_file = $this->get_file( $attachment_id );

		// bail if it is not an external file.
		if ( ! $external_file || false === $external_file->is_valid() ) {
			return;
		}

		// log deletion.
		/* translators: %1$s will be replaced by the file-URL */
		Log::get_instance()->create( sprintf( __( 'URL %1$s has been deleted from media library.', 'external-files-in-media-library' ), esc_url( $external_file->get_url() ) ), $external_file->get_url(), 'success', 1 );
	}

	/**
	 * Return external_file object of single attachment by given ID without checking its availability.
	 *
	 * @param int $attachment_id    The attachment_id where we want to call the File-object.
	 *
	 * @return false|File
	 */
	public function get_file( int $attachment_id ): false|File {
		// bail if file is not an attachment.
		if ( false !== is_attachment( $attachment_id ) ) {
			return false;
		}

		// return the external file object.
		return new File( $attachment_id );
	}

	/**
	 * Delete the given external-file-object with all its data from media library.
	 *
	 * @param File $external_file_obj  The File which will be deleted.
	 *
	 * @return void
	 */
	public function delete_file( File $external_file_obj ): void {
		// delete thumbs.
		$external_file_obj->delete_thumbs();

		// delete the file entry itself.
		wp_delete_attachment( $external_file_obj->get_id(), true );
	}

	/**
	 * Check all external files regarding their availability.
	 *
	 * @return void
	 */
	public function check_files(): void {
		// get all files.
		$files = $this->get_files_in_media_library();

		// bail if no files are found.
		if ( empty( $files ) ) {
			return;
		}

		// loop through the files and check each.
		foreach ( $files as $external_file_obj ) {
			// bail if obj is not an external file object.
			if ( ! $external_file_obj instanceof File ) {
				continue;
			}

			// get the protocol handler for this URL.
			$protocol_handler = Protocols::get_instance()->get_protocol_object_for_external_file( $external_file_obj );

			// bail if handler is false.
			if ( ! $protocol_handler ) {
				continue;
			}

			// get and save its availability.
			$external_file_obj->set_availability( $protocol_handler->check_availability( $external_file_obj->get_url() ) );
		}
	}

	/**
	 * Get all imported external files.
	 *
	 * @return array
	 */
	public function get_imported_external_files(): array {
		$query  = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => EML_POST_META_URL,
					'compare' => 'EXISTS',
				),
				array(
					'key'   => EML_POST_IMPORT_MARKER,
					'value' => 1,
				),
			),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		$result = new WP_Query( $query );

		// bail if result is 0.
		if ( 0 === $result->post_count ) {
			return array();
		}

		// get the list.
		$results = array();

		// loop through the results.
		foreach ( $result->get_posts() as $attachment_id ) {
			// get the external file object.
			$external_file_obj = $this->get_file( $attachment_id );

			// bail if object could not be loaded.
			if ( ! $external_file_obj ) {
				continue;
			}

			// add to the list.
			$results[] = $external_file_obj;
		}

		// return resulting list.
		return $results;
	}

	/**
	 * Get file-object by a given URL.
	 *
	 * @param string $url The URL we use to search.
	 *
	 * @return false|File
	 */
	public function get_file_by_url( string $url ): false|File {
		// bail if URL is empty.
		if ( empty( $url ) ) {
			return false;
		}

		// query for the post with the given URL.
		$query  = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'meta_query'     => array(
				array(
					'key'     => EML_POST_META_URL,
					'value'   => $url,
					'compare' => '=',
				),
			),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		);
		$result = new WP_Query( $query );

		// bail if more or less than 1 is found.
		if ( 1 !== $result->post_count ) {
			return false;
		}

		// get the external file object for the match.
		$external_file_obj = $this->get_file( $result->posts[0] );

		// bail if the external file object could not be created or is not valid.
		if ( ! ( $external_file_obj && $external_file_obj->is_valid() ) ) {
			return false;
		}

		// return the object.
		return $external_file_obj;
	}

	/**
	 * Get file-object by its title.
	 *
	 * @param string $title The title we use to search.
	 *
	 * @return bool|File
	 */
	public function get_file_by_title( string $title ): bool|File {
		// bail if no title is given.
		if ( empty( $title ) ) {
			return false;
		}

		// query for attachments of this plugin with this title.
		$query  = array(
			'title'          => $title,
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'meta_query'     => array(
				array(
					'key'     => EML_POST_META_URL,
					'compare' => 'EXISTS',
				),
			),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		$result = new WP_Query( $query );

		// bail on no results.
		if ( 1 !== $result->post_count ) {
			return false;
		}

		// get the external file object.
		$external_file_obj = $this->get_file( $result->posts[0] );

		// bail if object could not be loaded or is not valid.
		if ( ! ( $external_file_obj && $external_file_obj->is_valid() ) ) {
			return false;
		}

		// return the object.
		return $external_file_obj;
	}

	/**
	 * If file is deleted, delete also its proxy-cache, if set.
	 *
	 * @param int $attachment_id The ID of the attachment.
	 * @return void
	 */
	public function delete_file_from_cache( int $attachment_id ): void {
		// get the external file object.
		$external_file = $this->get_file( $attachment_id );

		// bail if it is not an external file.
		if ( ! $external_file || false === $external_file->is_valid() ) {
			return;
		}

		// call cache file deletion.
		$external_file->delete_cache();
	}

	/**
	 * Return the login.
	 *
	 * @return string
	 */
	private function get_login(): string {
		return $this->login;
	}

	/**
	 * Set the login.
	 *
	 * @param string $login The login.
	 *
	 * @return void
	 */
	public function set_login( string $login ): void {
		$this->login = $login;
	}

	/**
	 * Return the password.
	 *
	 * @return string
	 */
	private function get_password(): string {
		return $this->password;
	}

	/**
	 * Set the password.
	 *
	 * @param string $password The password.
	 *
	 * @return void
	 */
	public function set_password( string $password ): void {
		$this->password = $password;
	}

	/**
	 * Add meta box for external fields on media edit screen.
	 *
	 * @param WP_Post $post The requested post as object.
	 *
	 * @return void
	 */
	public function add_media_box( WP_Post $post ): void {
		// get file by its ID.
		$external_file_obj = $this->get_file( $post->ID );

		// bail if the file is not an external file-URL.
		if ( ! $external_file_obj ) {
			return;
		}

		// bail if file is not valid.
		if ( ! $external_file_obj->is_valid() ) {
			return;
		}

		// add the box.
		add_meta_box( 'attachment_external_file', __( 'External file', 'external-files-in-media-library' ), array( $this, 'add_media_box_with_file_info' ), 'attachment', 'side', 'low' );
	}

	/**
	 * Create the content of the meta-box on media-edit-page.
	 *
	 * @param WP_Post $post The requested post as object.
	 *
	 * @return void
	 */
	public function add_media_box_with_file_info( WP_Post $post ): void {
		// get file by its ID.
		$external_file_obj = $this->get_file( $post->ID );

		// bail if this is not an external file.
		if ( ! ( false !== $external_file_obj && false !== $external_file_obj->is_valid() ) ) {
			return;
		}

		// get protocol handler for this URL.
		$protocol_handler = Protocols::get_instance()->get_protocol_object_for_external_file( $external_file_obj );

		// bail if no protocol handler could be loaded.
		if ( ! $protocol_handler ) {
			return;
		}

		// get URL for show depending on used protocol.
		$url_to_show = $protocol_handler->get_link();

		// get the unproxied file URL.
		$url = $external_file_obj->get_url( true );

		// output.
		?>
		<div class="misc-pub-external-file">
		<p>
			<?php echo esc_html__( 'External URL of this file:', 'external-files-in-media-library' ); ?><br><a href="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( $url_to_show ); ?></a>
		</p>
		</div>
		<ul class="misc-pub-external-file">
		<li>
			<?php
			if ( $external_file_obj->get_availability() ) {
				?>
				<span id="eml_url_file_state"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html__( 'File-URL is available.', 'external-files-in-media-library' ); ?></span>
				<?php
			} else {
				$log_url = Helper::get_log_url();
				?>
				<span id="eml_url_file_state"><span class="dashicons dashicons-no-alt"></span>
					<?php
					/* translators: %1$s will be replaced by the URL for the logs */
					printf( esc_html__( 'File-URL is NOT available! Check <a href="%1$s">the log</a> for details.', 'external-files-in-media-library' ), esc_url( $log_url ) );
					?>
					</span>
				<?php
			}
			if ( $protocol_handler->can_check_availability() ) {
				?>
				<a class="button dashicons dashicons-image-rotate" href="#" id="eml_recheck_availability" title="<?php echo esc_html__( 'Recheck availability', 'external-files-in-media-library' ); ?>"></a>
				<?php
			}
			?>
		</li>
		<li><span class="dashicons dashicons-yes-alt"></span>
		<?php
		if ( false !== $external_file_obj->is_locally_saved() ) {
			echo '<span class="eml-hosting-state">' . esc_html__( 'File is local hosted.', 'external-files-in-media-library' ) . '</span>';
			if ( $external_file_obj->is_image() && $protocol_handler->can_change_hosting() ) {
				?>
					<a href="#" class="button dashicons dashicons-controls-repeat eml-change-host" title="<?php echo esc_html__( 'Switch to extern', 'external-files-in-media-library' ); ?>">&nbsp;</a>
					<?php
			}
		} else {
			echo '<span class="eml-hosting-state">' . esc_html__( 'File is extern hosted.', 'external-files-in-media-library' ) . '</span>';
			if ( $external_file_obj->is_image() && $protocol_handler->can_change_hosting() ) {
				?>
					<a href="#" class="button dashicons dashicons-controls-repeat eml-change-host" title="<?php echo esc_html__( 'Switch to local', 'external-files-in-media-library' ); ?>">&nbsp;</a>
					<?php
			}
		}
		?>
		</li>
		<?php
		if ( ( $external_file_obj->is_image() && get_option( 'eml_proxy' ) ) || ( $external_file_obj->is_video() && get_option( 'eml_video_proxy' ) ) ) {
			?>
			<li>
				<?php
				if ( false !== $external_file_obj->is_cached() ) {
					echo '<span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'File is delivered through proxied cache.', 'external-files-in-media-library' );
				} else {
					echo '<span class="dashicons dashicons-no-alt"></span> ' . esc_html__( 'File is not cached in proxy.', 'external-files-in-media-library' );
				}
				?>
			</li>
			<?php
		}
		if ( $external_file_obj->has_credentials() ) {
			?>
			<li><span class="dashicons dashicons-lock"></span> <?php echo esc_html__( 'File is protected with login and password.', 'external-files-in-media-library' ); ?></li>
			<?php
		}
		?>
		<li><span class="dashicons dashicons-list-view"></span> <a href="<?php echo esc_url( Helper::get_log_url( $url ) ); ?>"><?php echo esc_html__( 'Show log entries', 'external-files-in-media-library' ); ?></a></li>
		<?php
		if ( $external_file_obj->is_image() ) {
			?>
			<li><span class="dashicons dashicons-images-alt"></span> <a href="<?php echo esc_url( $this->get_thumbnail_reset_url( $external_file_obj ) ); ?>"><?php echo esc_html__( 'Reset thumbnails', 'external-files-in-media-library' ); ?></a></li>
			<?php
		}
		?>
		</ul>
		<?php
	}

	/**
	 * Check file availability via AJAX request.
	 *
	 * @return       void
	 */
	public function check_file_availability_via_ajax(): void {
		// check nonce.
		check_ajax_referer( 'eml-availability-check-nonce', 'nonce' );

		// create error-result.
		$result = array(
			'state'   => 'error',
			'message' => __( 'No ID given.', 'external-files-in-media-library' ),
		);

		// get ID.
		$attachment_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		// bail if no file is given.
		if ( 0 === $attachment_id ) {
			// send response as JSON.
			wp_send_json( $result );
		}

		// get the single external file-object.
		$external_file_obj = $this->get_file( $attachment_id );

		// bail if file could not be loaded.
		if ( ! $external_file_obj ) {
			// send response as JSON.
			wp_send_json( $result );
		}

		// get protocol handler for this url.
		$protocol_handler = Protocols::get_instance()->get_protocol_object_for_external_file( $external_file_obj );

		// bail if protocol handler could not be loaded.
		if ( ! $protocol_handler ) {
			// send response as JSON.
			wp_send_json( $result );
		}

		// check and save its availability.
		$external_file_obj->set_availability( $protocol_handler->check_availability( $external_file_obj->get_url() ) );

		// return result depending on availability-value.
		if ( $external_file_obj->get_availability() ) {
			$result = array(
				'state'   => 'success',
				'message' => __( 'File-URL is available.', 'external-files-in-media-library' ),
			);

			// send response as JSON.
			wp_send_json( $result );
		}

		// return error if file is not available.
		$result = array(
			'state'   => 'error',
			/* translators: %1$s will be replaced by the URL for the logs */
			'message' => sprintf( __( 'URL-File is NOT available! Check <a href="%1$s">the log</a> for details.', 'external-files-in-media-library' ), Helper::get_log_url() ),
		);

		// send response as JSON.
		wp_send_json( $result );
	}

	/**
	 * URL-decode the file-title if it is used in admin (via AJAX).
	 *
	 * @param string $title The title to optimize.
	 *
	 * @return string
	 */
	public function optimize_file_title( string $title ): string {
		return urldecode( $title );
	}

	/**
	 * Switch the hosting of a single file from local to extern or extern to local.
	 *
	 * @return       void
	 */
	public function switch_hosting_via_ajax(): void {
		// check nonce.
		check_ajax_referer( 'eml-switch-hosting-nonce', 'nonce' );

		// create error-result.
		$result = array(
			'state'   => 'error',
			'message' => __( 'No ID given.', 'external-files-in-media-library' ),
		);

		// get ID.
		$attachment_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		// bail if id is not given.
		if ( 0 === $attachment_id ) {
			wp_send_json( $result );
		}

		// get the file.
		$external_file_obj = $this->get_file( $attachment_id );

		// bail if object could not be loaded.
		if ( ! $external_file_obj ) {
			wp_send_json( $result );
		}

		// get the external URL.
		$url = $external_file_obj->get_url( true );

		// bail if file is not an external file.
		if ( ! $external_file_obj->is_valid() ) {
			$result = array(
				'state'   => 'error',
				'message' => __( 'Given file is not an external file.', 'external-files-in-media-library' ),
			);
			wp_send_json( $result );
		}

		/**
		 * Switch from local to external.
		 */
		if ( $external_file_obj->is_locally_saved() ) {
			// switch to external and show error if it runs in an error.
			if ( ! $external_file_obj->switch_to_external() ) {
				wp_send_json( $result );
			}

			// create return message.
			$result = array(
				'state'   => 'success',
				'message' => __( 'File is extern hosted.', 'external-files-in-media-library' ),
			);
		} else {
			/**
			 * Switch from external to local.
			 */

			// switch to local and show error if it runs in an error.
			if ( ! $external_file_obj->switch_to_local() ) {
				wp_send_json( $result );
			}

			// create return message.
			$result = array(
				'state'   => 'success',
				'message' => __( 'File is local hosted.', 'external-files-in-media-library' ),
			);
		}

		// log this event.
		/* translators: %1$s will be replaced by the file URL. */
		Log::get_instance()->create( sprintf( __( 'File %1$s has been switched the hosting.', 'external-files-in-media-library' ), $url ), $url, 'success', 0 );

		// send response as JSON.
		wp_send_json( $result );
	}

	/**
	 * Change media row actions for URL-files.
	 *
	 * @param array   $actions List of action.
	 * @param WP_Post $post The Post.
	 *
	 * @return array
	 */
	public function change_media_row_actions( array $actions, WP_Post $post ): array {
		// get the external file object.
		$external_file_obj = $this->get_file( $post->ID );

		// bail if file is not an external file.
		if ( ! $external_file_obj ) {
			return $actions;
		}

		// bail if file is not valid.
		if ( ! $external_file_obj->is_valid() ) {
			return $actions;
		}

		// if file is not available, show hint as action.
		if ( false === $external_file_obj->get_availability() ) {
			// remove actions if file is not available.
			unset( $actions['edit'] );
			unset( $actions['copy'] );
			unset( $actions['download'] );

			// add custom hint.
			$actions['eml-hint'] = '<a href="' . esc_url( Helper::get_config_url() ) . '">' . __( 'Mime-Type not allowed', 'external-files-in-media-library' ) . '</a>';
		}

		// return resulting list of actions.
		return $actions;
	}

	/**
	 * Prevent output as file if availability is not given.
	 *
	 * @param string $file The file.
	 * @param int    $post_id The post-ID.
	 *
	 * @return string
	 */
	public function get_attached_file( string $file, int $post_id ): string {
		// get the external file object.
		$external_file_obj = $this->get_file( $post_id );

		// bail if file is not an external file.
		if ( ! $external_file_obj ) {
			return $file;
		}

		// bail if file is not valid.
		if ( ! $external_file_obj->is_valid() ) {
			return $file;
		}

		// return nothing to prevent output as file is not valid.
		if ( false === $external_file_obj->get_availability() ) {
			return '';
		}

		// return normal file-name.
		return $file;
	}

	/**
	 * Prevent image downsizing for external hosted images.
	 *
	 * @param array|bool   $result        The resulting array with image-data.
	 * @param int|string   $attachment_id The attachment ID.
	 * @param array|string $size               The requested size.
	 *
	 * @return bool|array
	 */
	public function image_downsize( array|bool $result, int|string $attachment_id, array|string $size ): bool|array {
		// get the external file object.
		$external_file_obj = $this->get_file( absint( $attachment_id ) );

		// bail if file is not an external file.
		if ( ! $external_file_obj ) {
			return $result;
		}

		// bail if file is not valid.
		if ( ! $external_file_obj->is_valid() ) {
			return $result;
		}

		// check if the file is an external file, an image and if it is really external hosted.
		if (
			false === $external_file_obj->is_locally_saved()
			&& $external_file_obj->is_image()
		) {
			// if requested size is a string, get its sizes.
			if ( is_string( $size ) ) {
				$size = array(
					absint( get_option( $size . '_size_w' ) ),
					absint( get_option( $size . '_size_h' ) ),
				);
			}

			// get image data.
			$image_data = wp_get_attachment_metadata( $attachment_id );

			// bail if both sizes are 0.
			if ( 0 === $size[0] && 0 === $size[1] ) {
				// set return-array so that WP won't generate an image for it.
				return array(
					$external_file_obj->get_url(),
					$image_data['width'] ? $image_data['width'] : 0,
					$image_data['height'] ? $image_data['height'] : 0,
					false,
				);
			}

			// use already existing thumb.
			if ( ! empty( $image_data['sizes'][ $size[0] . 'x' . $size[1] ] ) && file_exists( $image_data['sizes'][ $size[0] . 'x' . $size[1] ]['file'] ) ) {
				// return the thumb.
				return array(
					trailingslashit( get_home_url() ) . Proxy::get_instance()->get_slug() . '/' . $image_data['sizes'][ $size[0] . 'x' . $size[1] ]['file'],
					$size[0],
					$size[1],
					false,
				);
			}

			// get image editor as object.
			$image_editor = wp_get_image_editor( $external_file_obj->get_cache_file() );

			// on error return the original image.
			if ( is_wp_error( $image_editor ) ) {
				// set return-array so that WP won't generate an image for it.
				return array(
					$external_file_obj->get_url(),
					$image_data['width'] ? $image_data['width'] : 0,
					$image_data['height'] ? $image_data['height'] : 0,
					false,
				);
			}

			/**
			 * Generate the requested thumb and save it in metadata for the image.
			 */

			// generate the filename for the thumb.
			$generated_filename = Helper::generate_sizes_filename( basename( $external_file_obj->get_cache_file() ), $size[0], $size[1] );

			// public filename.
			$public_filename = Helper::generate_sizes_filename( basename( $external_file_obj->get_url() ), $size[0], $size[1] );

			// resize the image.
			$image_editor->resize( $size[0], $size[1], true );

			// save the resized image and get its data.
			$new_image_data = $image_editor->save( Proxy::get_instance()->get_cache_directory() . $generated_filename );

			// remove the path from the resized image data.
			unset( $new_image_data['path'] );

			// replace the filename in the resized image data with the public filename we use in our proxy.
			$new_image_data['file'] = $public_filename;

			// update the meta data.
			$image_data['sizes'][ $size[0] . 'x' . $size[1] ] = $new_image_data;
			wp_update_attachment_metadata( $attachment_id, $image_data );

			// return the thumb.
			return array(
				trailingslashit( get_home_url() ) . Proxy::get_instance()->get_slug() . '/' . $public_filename,
				$size[0],
				$size[1],
				false,
			);
		}

		// return result.
		return $result;
	}

	/**
	 * Set URL of all imported external files and remove the import-marker.
	 *
	 * @return void
	 */
	public function import_end(): void {
		// loop through all imported external files and update their wp_attached_file-setting.
		foreach ( $this->get_imported_external_files() as $external_file ) {
			update_post_meta( $external_file->get_id(), '_wp_attached_file', $external_file->get_url() );
		}

		// get all imported external files attachments.
		$query  = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'meta_query'     => array(
				array(
					'key'   => EML_POST_IMPORT_MARKER,
					'value' => 1,
				),
			),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		$result = new WP_Query( $query );

		// bail if no results found.
		if ( 0 === $result->post_count ) {
			return;
		}

		// delete the import marker for each of these files.
		foreach ( $result->get_posts() as $attachment_id ) {
			delete_post_meta( $attachment_id, EML_POST_IMPORT_MARKER );
		}
	}

	/**
	 * Disable attachment-pages for external files.
	 *
	 * @return void
	 */
	public function disable_attachment_page(): void {
		// bail if this is not an attachment page.
		if ( ! is_attachment() ) {
			return;
		}

		// bail if setting is disabled.
		if ( 1 !== absint( get_option( 'eml_disable_attachment_pages', 0 ) ) ) {
			return;
		}

		// get the external files.
		$external_file_obj = $this->get_file( get_the_ID() );

		// bail if file could not be loaded.
		if ( ! $external_file_obj ) {
			return;
		}

		// bail if file is not valid.
		if ( ! $external_file_obj->is_valid() ) {
			return;
		}

		// return 404 page.
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
	}

	/**
	 * Change the URL in srcset-attribute for each attachment.
	 *
	 * @param array  $sources Array with srcset-data if the image.
	 * @param array  $size_array Array with sizes for images.
	 * @param string $image_src The src of the image.
	 * @param array  $image_meta The image meta-data.
	 * @param int    $attachment_id The attachment-ID.
	 *
	 * @return array
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function wp_calculate_image_srcset( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {
		// get the external file object.
		$external_file_obj = $this->get_file( $attachment_id );

		// bail if this is not an external file.
		if ( ! $external_file_obj || ! $external_file_obj->is_valid() ) {
			return $sources;
		}

		// bail with empty array if this is an image which is external hosted.
		if (
			false === $external_file_obj->is_locally_saved()
			&& $external_file_obj->is_image()
		) {
			// return empty array as we can not optimize external images.
			return array();
		}

		// return resulting array.
		return $sources;
	}

	/**
	 * Force permalink-URL for file-attribute in meta-data for external url-files
	 * to change the link-target if attachment-pages are disabled via attachment_link-hook.
	 *
	 * @param array $data The image-data.
	 * @param int   $attachment_id The attachment-ID.
	 *
	 * @return array
	 */
	public function wp_get_attachment_metadata( array $data, int $attachment_id ): array {
		// get the external file object.
		$external_file_obj = $this->get_file( $attachment_id );

		// bail if file could not be loaded.
		if ( ! $external_file_obj ) {
			return $data;
		}

		// bail if file is not valid.
		if ( ! $external_file_obj->is_valid() ) {
			return $data;
		}

		// set permalink as file.
		$data['file'] = get_permalink( $attachment_id );

		// return resulting data array.
		return $data;
	}

	/**
	 * Set the import-marker for all attachments.
	 *
	 * @param array $post_meta The attachment-meta.
	 * @param int   $post_id The attachment-ID.
	 *
	 * @return array
	 */
	public function set_import_marker_for_attachments( array $post_meta, int $post_id ): array {
		// bail if this is not an attachment.
		if ( 'attachment' !== get_post_type( $post_id ) ) {
			return $post_meta;
		}

		// update the meta query.
		$post_meta[] = array(
			'key'   => 'eml_imported',
			'value' => 1,
		);

		// return resulting meta query.
		return $post_meta;
	}

	/**
	 * Set the file title.
	 *
	 * @param string $title The title.
	 * @param string $url   The used URL.
	 * @param array  $file_data The file data.
	 *
	 * @return string
	 */
	public function set_file_title( string $title, string $url, array $file_data ): string {
		// bail if title is set.
		if ( ! empty( $title ) ) {
			return $title;
		}

		// get URL data.
		$url_info = wp_parse_url( $url );

		// bail if url_info is empty.
		if ( empty( $url_info ) ) {
			return $title;
		}

		// get all possible mime-types our plugin supports.
		$mime_types = Helper::get_possible_mime_types();

		// get basename of path, if available.
		$title = basename( $url_info['path'] );

		// add file extension if we support the mime-type and if the title does not have any atm.
		if ( empty( pathinfo( $title, PATHINFO_EXTENSION ) ) && ! empty( $mime_types[ $file_data['mime-type'] ] ) ) {
			$title .= '.' . $mime_types[ $file_data['mime-type'] ]['ext'];
		}

		// return resulting list of file data.
		return $title;
	}

	/**
	 * Return the thumbnail reset URL for single external file.
	 *
	 * @param File $external_file_obj The external file object.
	 *
	 * @return string
	 */
	private function get_thumbnail_reset_url( File $external_file_obj ): string {
		return add_query_arg(
			array(
				'action' => 'eml_reset_thumbnails',
				'post'   => $external_file_obj->get_id(),
				'nonce'  => wp_create_nonce( 'eml-reset-thumbnails' ),
			),
			get_admin_url() . 'admin.php'
		);
	}

	/**
	 * Reset thumbnails if single file by request.
	 *
	 * @return void
	 * @noinspection PhpNoReturnAttributeCanBeAddedInspection
	 */
	public function reset_thumbnails_by_request(): void {
		// check referer.
		check_admin_referer( 'eml-reset-thumbnails', 'nonce' );

		// get the file id.
		$post_id = absint( filter_input( INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT ) );

		// bail if post id is not given.
		if ( 0 === $post_id ) {
			wp_safe_redirect( wp_get_referer() );
			exit;
		}

		// get the external file object.
		$external_file_obj = $this->get_file( $post_id );

		// bail if object could not be loaded.
		if ( ! $external_file_obj ) {
			wp_safe_redirect( wp_get_referer() );
			exit;
		}

		// delete the thumbs of this file.
		$external_file_obj->delete_thumbs();

		// generate the thumbs.

		// redirect user.
		wp_safe_redirect( wp_get_referer() );
		exit;
	}
}
