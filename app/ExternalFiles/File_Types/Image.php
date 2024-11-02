<?php
/**
 * File to handle Image files.
 *
 * @package external-files-in-media-library
 */

namespace ExternalFilesInMediaLibrary\ExternalFiles\File_Types;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ExternalFilesInMediaLibrary\ExternalFiles\File_Types_Base;

/**
 * Object to handle images.
 */
class Image extends File_Types_Base {
	/**
	 * Define mime types this object is used for.
	 *
	 * @var array|string[]
	 */
	protected array $mime_types = array(
		'image/jpeg',
		'image/jpg',
		'image/png',
		'image/gif',
	);

	/**
	 * Output of proxied file.
	 *
	 * @return void
	 * @noinspection PhpNoReturnAttributeCanBeAddedInspection
	 */
	public function get_proxied_file(): void {
		// get the file object.
		$external_file_obj = $this->get_file();

		// get the cached file.
		$cached_file = $external_file_obj->get_cache_file( $this->get_size() );

		// get WP Filesystem-handler.
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;

		// return header.
		header( 'Content-Type: ' . $external_file_obj->get_mime_type() );
		header( 'Content-Disposition: inline; filename="' . basename( $external_file_obj->get_url() ) . '"' );
		header( 'Content-Length: ' . wp_filesize( $cached_file ) );

		// return file content via WP filesystem.
		echo $wp_filesystem->get_contents( $cached_file ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
