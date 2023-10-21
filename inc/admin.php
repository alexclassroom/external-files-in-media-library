<?php
/**
 * File for admin-related handlings.
 *
 * @package external-files-in-media-library
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use threadi\eml\Controller\external_files;
use threadi\eml\helper;
use threadi\eml\Model\External_File;
use threadi\eml\Transients;
use threadi\eml\View\logs;

/**
 * Add setting in admin-init.
 *
 * @return void
 */
function eml_admin_menu_init(): void {
	global $wp_roles;

	/**
	 * General Section.
	 */
	add_settings_section(
		'settings_section_main',
		__( 'General Settings', 'external-files-in-media-library' ),
		'__return_true',
		'eml_settings_page'
	);

	// set description for disabling the attachment pages.
	$description = __( 'Each file in media library has a attachment page which could be called in frontend. With this option you can disable this attachment page for files with URLs.', 'external-files-in-media-library' );
	if ( method_exists( 'WPSEO_Options', 'get' ) ) {
		$description = __( 'This is handled by Yoast SEO.', 'external-files-in-media-library' );
	}

	// Disable the attachment page.
	add_settings_field(
		'eml_disable_attachment_pages',
		__( 'Disable the attachment page for URL-files', 'external-files-in-media-library' ),
		'eml_admin_checkbox_field',
		'eml_settings_page',
		'settings_section_main',
		array(
			'label_for'   => 'eml_disable_attachment_pages',
			'fieldId'     => 'eml_disable_attachment_pages',
			/* translators: %1$s is replaced with "string" */
			'description' => $description,
			'readonly'    => false !== method_exists( 'WPSEO_Options', 'get' ),
		)
	);
	register_setting( 'eml_settings_group', 'eml_disable_attachment_pages', array( 'sanitize_callback' => 'eml_admin_validate_checkbox' ) );

	// interval-setting for automatic file-check.
	$values = array(
		'eml_disable_check' => __( 'Disable the check', 'external-files-in-media-library' ),
	);
	foreach ( wp_get_schedules() as $name => $interval ) {
		$values[ $name ] = $interval['display'];
	}
	add_settings_field(
		'eml_check_interval',
		__( 'Set interval for file-check', 'external-files-in-media-library' ),
		'eml_admin_select_field',
		'eml_settings_page',
		'settings_section_main',
		array(
			'label_for'   => 'eml_check_interval',
			'fieldId'     => 'eml_check_interval',
			'description' => __( 'Defines the time interval in which files with URLs are automatically checked for its availability.', 'external-files-in-media-library' ),
			'values'      => $values,
		)
	);
	register_setting( 'eml_settings_group', 'eml_check_interval', array( 'sanitize_callback' => 'eml_admin_validate_interval_select' ) );

	// get possible mime types.
	$mime_types = array();
	foreach ( External_Files::get_instance()->get_possible_mime_types() as $mime_type => $settings ) {
		$mime_types[ $mime_type ] = $settings['label'];
	}

	// select allowed mime-types.
	add_settings_field(
		'eml_allowed_mime_types',
		__( 'Select allowed mime-types', 'external-files-in-media-library' ),
		'eml_admin_multiselect_field',
		'eml_settings_page',
		'settings_section_main',
		array(
			'label_for'   => 'eml_allowed_mime_types',
			'fieldId'     => 'eml_allowed_mime_types',
			'values'      => $mime_types,
			'description' => __( 'Choose the mime-types you wish to allow as external URL. If you change this setting, already used external files will not change their accessibility in frontend.', 'external-files-in-media-library' ),
		)
	);
	register_setting( 'eml_settings_group', 'eml_allowed_mime_types', array( 'sanitize_callback' => 'eml_admin_validate_allowed_mime_types' ) );

	// Log-mode.
	add_settings_field(
		'eml_log_mode',
		__( 'Log-mode', 'external-files-in-media-library' ),
		'eml_admin_select_field',
		'eml_settings_page',
		'settings_section_main',
		array(
			'label_for' => 'eml_log_mode',
			'fieldId'   => 'eml_log_mode',
			'values'    => array(
				'0' => __( 'normal', 'external-files-in-media-library' ),
				'1' => __( 'log warnings', 'external-files-in-media-library' ),
				'2' => __( 'log all', 'external-files-in-media-library' ),
			),
		)
	);
	register_setting( 'eml_settings_group', 'eml_log_mode' );

	// Delete all data on deinstallation.
	add_settings_field(
		'eml_delete_on_deinstallation',
		__( 'Delete all data on deinstallation', 'external-files-in-media-library' ),
		'eml_admin_checkbox_field',
		'eml_settings_page',
		'settings_section_main',
		array(
			'label_for'   => 'eml_delete_on_deinstallation',
			'fieldId'     => 'eml_delete_on_deinstallation',
			'description' => __( 'If this option is enabled all URL-files will be deleted during deinstallation of this plugin.', 'external-files-in-media-library' ),
		)
	);
	register_setting( 'eml_settings_group', 'eml_delete_on_deinstallation', array( 'sanitize_callback' => 'eml_admin_validate_checkbox' ) );

	/**
	 * Files Section.
	 */
	add_settings_section(
		'settings_section_add_files',
		__( 'Adding files', 'external-files-in-media-library' ),
		'__return_true',
		'eml_settings_page'
	);

	// get user roles.
	$user_roles = array();
	if ( ! empty( $wp_roles->roles ) ) {
		foreach ( $wp_roles->roles as $slug => $role ) {
			$user_roles[ $slug ] = $role['name'];
		}
	}

	// Set roles to allow adding external URLs.
	add_settings_field(
		'eml_allowed_roles',
		__( 'Select user roles', 'external-files-in-media-library' ),
		'eml_admin_multiselect_field',
		'eml_settings_page',
		'settings_section_add_files',
		array(
			'label_for'   => 'eml_allowed_roles',
			'fieldId'     => 'eml_allowed_roles',
			'values'      => $user_roles,
			'description' => __( 'Select roles which should be allowed to add external files.', 'external-files-in-media-library' ),
		)
	);
	register_setting( 'eml_settings_group', 'eml_allowed_roles', array( 'sanitize_callback' => 'eml_admin_set_capability' ) );

	$users = array();
	foreach ( get_users() as $user ) {
		$users[ $user->ID ] = $user->display_name;
	}

	// User new files should be assigned to.
	add_settings_field(
		'eml_user_assign',
		__( 'User new files should be assigned to', 'external-files-in-media-library' ),
		'eml_admin_select_field',
		'eml_settings_page',
		'settings_section_add_files',
		array(
			'label_for'   => 'eml_user_assign',
			'fieldId'     => 'eml_user_assign',
			'description' => __( 'This is only a fallback if the actual user is not available (e.g. via CLI-import). New files are normally assigned to the user who add them.', 'external-files-in-media-library' ),
			'values'      => $users,
		)
	);
	register_setting( 'eml_settings_group', 'eml_user_assign' );

	/**
	 * Images Section.
	 */
	add_settings_section(
		'settings_section_images',
		__( 'Images Settings', 'external-files-in-media-library' ),
		'__return_true',
		'eml_settings_page'
	);

	// Image-mode.
	add_settings_field(
		'eml_images_mode',
		__( 'Mode for image handling', 'external-files-in-media-library' ),
		'eml_admin_select_field',
		'eml_settings_page',
		'settings_section_images',
		array(
			'label_for'   => 'eml_images_mode',
			'fieldId'     => 'eml_images_mode',
			'description' => __( 'Defines how external images are handled.', 'external-files-in-media-library' ),
			'values'      => array(
				'external' => __( 'host them extern', 'external-files-in-media-library' ),
				'local'    => __( 'download and host them local', 'external-files-in-media-library' ),
			),
		)
	);
	register_setting( 'eml_settings_group', 'eml_images_mode' );

	// Enable proxy in frontend.
	add_settings_field(
		'eml_proxy',
		__( 'Enable proxy for images', 'external-files-in-media-library' ),
		'eml_admin_checkbox_field',
		'eml_settings_page',
		'settings_section_images',
		array(
			'label_for'   => 'eml_proxy',
			'fieldId'     => 'eml_proxy',
			'description' => __( 'This option is only available if images are hosted external. If this option is disabled, external images will be embedded with their external URL. To prevent privacy protection issue you could enable this option to load the images locally.', 'external-files-in-media-library' ),
			'readonly'    => 'external' !== get_option( 'eml_images_mode', '' ),
		)
	);
	register_setting( 'eml_settings_group', 'eml_proxy', array( 'sanitize_callback' => 'eml_admin_validate_checkbox' ) );

	// Max age for cached files.
	add_settings_field(
		'eml_proxy_max_age',
		__( 'Max age for cached images in proxy in hours', 'external-files-in-media-library' ),
		'eml_admin_number_field',
		'eml_settings_page',
		'settings_section_images',
		array(
			'label_for'   => 'eml_proxy_max_age',
			'fieldId'     => 'eml_proxy_max_age',
			'description' => __( 'Defines how long images, which are loaded via our own proxy, are saved locally. After this time their cache will be renewed.', 'external-files-in-media-library' ),
			'readonly'    => 'external' !== get_option( 'eml_images_mode', '' ),
		)
	);
	register_setting( 'eml_settings_group', 'eml_proxy_max_age', array( 'sanitize_callback' => 'eml_admin_validate_number' ) );
}
add_action( 'admin_init', 'eml_admin_menu_init' );

/**
 * Add settings-page in admin-menu.
 *
 * @return void
 */
function eml_admin_menu_menu(): void {
	add_options_page(
		__( 'Settings for External files in Media Library', 'external-files-in-media-library' ),
		__( 'External files in Medias Library', 'external-files-in-media-library' ),
		'manage_options',
		'eml_settings',
		'eml_admin_settings'
	);
}
add_action( 'admin_menu', 'eml_admin_menu_menu' );

/**
 * Add CSS- and JS-files for backend.
 *
 * @return void
 */
function eml_admin_add_styles_and_js_admin(): void {
	// backend-JS.
	wp_enqueue_script(
		'eml-admin',
		plugins_url( '/admin/js.js', EML_PLUGIN ),
		array( 'jquery' ),
		filemtime( helper::get_plugin_dir() . '/admin/js.js' ),
		true
	);

	// admin-specific styles.
	wp_enqueue_style(
		'eml-admin',
		plugins_url( '/admin/style.css', EML_PLUGIN ),
		array(),
		filemtime( helper::get_plugin_dir() . '/admin/style.css' ),
	);

	// add php-vars to our js-script.
	wp_localize_script(
		'eml-admin',
		'emlJsVars',
		array(
			'ajax_url'           => admin_url( 'admin-ajax.php' ),
			'urls_nonce'         => wp_create_nonce( 'eml-urls-upload-nonce' ),
			'availability_nonce' => wp_create_nonce( 'eml-availability-check-nonce' ),
			'dismiss_nonce'      => wp_create_nonce( 'eml-dismiss-nonce' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'eml_admin_add_styles_and_js_admin', PHP_INT_MAX );

/**
 * Output form to enter multiple urls for external files.
 *
 * @return void
 */
function eml_admin_add_multi_form(): void {
	// bail if user has not the capability for it.
	if ( false === current_user_can( EML_CAP_NAME ) ) {
		return;
	}

	// get actual screen.
	$current_screen = get_current_screen();

	// on "add"-screen show our custom form-field to add external files.
	if ( 'add' === $current_screen->action ) {
		?>
			<div class="eml_add_external_files_wrapper">
				<label for="external_files">
					<?php
					echo esc_html( _n( 'Add external URL', 'Add external URLs', 2, 'external-files-in-media-library' ) );

					// add link to settings for admin.
					if ( current_user_can( 'manage_options' ) ) {
						?>
							<a href="<?php echo esc_url( helper::get_config_url() ); ?>" class="eml_settings_link"><span class="dashicons dashicons-admin-generic"></span></a>
						<?php
					}
					?>
				</label>
				<textarea id="external_files" name="external_files" class="eml_add_external_files" placeholder="<?php esc_html_e( 'Enter one URL per line for files you want to insert in your library', 'external-files-in-media-library' ); ?>"></textarea>
				<button class="button eml_add_external_upload"><?php echo esc_html( _n( 'Add this URL', 'Add this URLs', 2, 'external-files-in-media-library' ) ); ?></button>
			</div>
		<?php
	} else {
		$url = 'media-new.php';
		?>
			<div class="eml_add_external_files_wrapper">
				<p>
					<?php
						/* translators: %1$s will be replaced with the URL for add new media */
						echo wp_kses_post( sprintf( __( 'Add external files <a href="%1$s">here</a>.', 'external-files-in-media-library' ), esc_url( $url ) ) );
					?>
				</p>
			</div>
		<?php
	}
}
add_action( 'post-plupload-upload-ui', 'eml_admin_add_multi_form', 10, 0 );

/**
 * Output form to enter multiple urls for external files.
 *
 * @return void
 */
function eml_admin_add_single_form(): void {
	// bail if user has not the capability for it.
	if ( false === current_user_can( EML_CAP_NAME ) ) {
		return;
	}

	// show our custom form to add single external file.
	?>
	<div class="eml_add_external_files_wrapper">
		<label for="external_files">
			<?php
				echo esc_html( _n( 'Add external URL', 'Add external URL', 1, 'external-files-in-media-library' ) );

				// add link to settings for admin.
			if ( current_user_can( 'manage_options' ) ) {
				?>
					<a href="<?php echo esc_url( helper::get_config_url() ); ?>" class="eml_settings_link"><span class="dashicons dashicons-admin-generic"></span></a>
				<?php
			}
			?>
		</label>
		<input id="external_files" name="external_files" class="eml_add_external_files" type="url" placeholder="<?php echo esc_attr__( 'Enter an URL for a file you want to insert in your library', 'external-files-in-media-library' ); ?>">
		<button class="button eml_add_external_upload"><?php echo esc_html( _n( 'Add this URL', 'Add this URL', 1, 'external-files-in-media-library' ) ); ?></button>
	</div>
	<?php
}
add_action( 'post-html-upload-ui', 'eml_admin_add_single_form', 10, 0 );

/**
 * Process ajax-request for insert multiple urls to media library.
 *
 * @return       void
 * @noinspection PhpNoReturnAttributeCanBeAddedInspection
 */
function eml_admin_add_urls_via_ajax(): void {
	// check nonce.
	check_ajax_referer( 'eml-urls-upload-nonce', 'nonce' );

	// check capability.
	if ( false === current_user_can( EML_CAP_NAME ) ) {
		wp_die( '0', 400 );
	}

	// create error-result.
	$result = array(
		'state'   => 'error',
		'message' => __( 'No URLs given to import.', 'external-files-in-media-library' ),
	);

	// get files-object.
	$files_obj = external_files::get_instance();

	// get the urls from request.
	$urls      = isset( $_REQUEST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['urls'] ) ) : '';
	$url_array = explode( "\n", $urls );

	if ( ! empty( $url_array ) ) {
		// loop through them to add them to media library.
		$errors = array();
		$files  = array();
		foreach ( $url_array as $url ) {
			if ( ! empty( $url ) ) {
				if ( ! $files_obj->add_file( $url ) ) {
					$errors[] = $url;
				} else {
					// get file-object for list.
					$file = $files_obj->get_file_by_url( $url );
					if ( $file instanceof External_File && $file->is_valid() ) {
						$files[] = $file;
					}
				}
			}
		}

		// return ok-message if no error occurred.
		if ( empty( $errors ) ) {
			if ( 1 === count( $files ) ) {
				$result = array(
					'state'   => 'success',
					/* translators: %1$s will be replaced by the edit-URL of the saved file. */
					'message' => '<p>' . sprintf( __( 'The given URL <a href="%1$s">has been saved</a> in media library.', 'external-files-in-media-library' ), esc_url( $files[0]->get_edit_url() ) ) . '</p>',
				);
			} else {
				$list = '<ul>';
				foreach ( $files as $file ) {
					$list .= '<li><a href="' . esc_url( $file->get_edit_url() ) . '">' . esc_url( $file->get_url() ) . '</a></li>';
				}
				$list  .= '</ul>';
				$result = array(
					'state'   => 'success',
					/* translators: %1$s will be replaced by list of successfully saved URLs. */
					'message' => '<p>' . sprintf( __( 'The following URLs has been saved in media library: %1$s', 'external-files-in-media-library' ), wp_kses_post( $list ) ) . '</p>',
				);
			}
		} else {
			// collect the error-list for the response.
			$error_list = '<ul class="eml-file-list">';
			foreach ( $errors as $error ) {
				$error_list .= '<li>' . esc_html( $error ) . '</li>';
			}
			$error_list .= '</ul>';

			// get the log-url.
			$url_log = helper::get_log_url();

			// collect response.
			$result = array(
				'state'   => 'error',
				'message' => sprintf(
					/* translators: %1$s is replaced by the file-list, %2$s is replaced by the URL to the plugin-log */
					_n( '<p>Following URL could not be saved in the media library:</p>%1$s<p>Details are visible <a href="%2$s">in the log</a>.</p>', '<p>Following URLs could not be saved in the media library:</p>%1$s<p>Details are visible <a href="%2$s">in the log</a>.</p>', count( $errors ), 'external-files-in-media-library' ),
					$error_list,
					$url_log
				),
			);
		}
	}

	// send response as JSON.
	echo wp_json_encode( $result );
	wp_die();
}
add_action( 'wp_ajax_eml_add_external_urls', 'eml_admin_add_urls_via_ajax', 10, 0 );

/**
 * Add filter in media library for external files.
 *
 * @return void
 */
function eml_admin_add_media_filter_for_external_files(): void {
	// only for upload-screen.
	$scr = get_current_screen();
	if ( 'upload' !== $scr->base ) {
		return;
	}

	// get value from request.
	$request_value = isset( $_GET['admin_filter_media_external_files'] ) ? sanitize_text_field( wp_unslash( $_GET['admin_filter_media_external_files'] ) ) : '';

	// define possible options.
	$options = array(
		'none'         => __( 'All files', 'external-files-in-media-library' ),
		'external'     => __( 'only external URLs', 'external-files-in-media-library' ),
		'non-external' => __( 'no external URLs', 'external-files-in-media-library' ),
	);
	?>
	<!--suppress HtmlFormInputWithoutLabel -->
	<select name="admin_filter_media_external_files">
	<?php
	foreach ( $options as $value => $label ) {
		?>
		<option value="<?php echo esc_attr( $value ); ?>"<?php echo $request_value === $value ? ' selected="selected"' : ''; ?>><?php echo esc_html( $label ); ?></option>
		<?php
	}
	?>
	</select>
	<?php
}
add_action( 'restrict_manage_posts', 'eml_admin_add_media_filter_for_external_files' );

/**
 * Change main query to filter external files in media library if requested.
 *
 * @param WP_Query $query The Query-object.
 * @return void
 */
function eml_admin_add_media_do_filter_for_external_files( WP_Query $query ): void {
	if ( is_admin() && $query->is_main_query() ) {
		if ( isset( $_GET['admin_filter_media_external_files'] ) ) {
			if ( 'external' === $_GET['admin_filter_media_external_files'] ) {
				$query->set(
					'meta_query',
					array(
						array(
							'key'     => EML_POST_META_URL,
							'compare' => 'EXISTS',
						),
					)
				);
			}
			if ( 'non-external' === $_GET['admin_filter_media_external_files'] ) {
				$query->set(
					'meta_query',
					array(
						array(
							'key'     => EML_POST_META_URL,
							'compare' => 'NOT EXISTS',
						),
					)
				);
			}
		}
	}
}
add_action( 'pre_get_posts', 'eml_admin_add_media_do_filter_for_external_files' );

/**
 * Add meta box for external fields on media edit screen.
 *
 * @return void
 */
function eml_admin_add_media_box(): void {
	// get files-object.
	$external_files_obj = external_files::get_instance();

	// get file by its ID.
	$external_file_obj = $external_files_obj->get_file( get_the_ID() );

	// add box if the file is an external file-URL.
	if ( $external_file_obj && $external_file_obj->is_valid() ) {
		add_meta_box( 'attachment_external_file', __( 'External file', 'external-files-in-media-library' ), 'eml_admin_media_box', 'attachment', 'side', 'low' );

		// if this is an external hostet file, hide "Replace Media"-box from plugin "Enable Media Replace".
		if ( false === $external_file_obj->is_locally_saved() ) {
			remove_meta_box( 'emr-replace-box', 'attachment', 'side' );
			remove_meta_box( 'emr-showthumbs-box', 'attachment', 'side' );
		}
	}
}
add_action( 'add_meta_boxes_attachment', 'eml_admin_add_media_box', 20, 0 );

/**
 * Create the content of the meta-box on media-edit-page.
 *
 * @return void
 */
function eml_admin_media_box(): void {
	// get files-object.
	$external_files_obj = external_files::get_instance();

	// get file by its ID.
	$external_file_obj = $external_files_obj->get_file( get_the_ID() );

	// show box-content if file is a valid file-URL.
	if ( false !== $external_file_obj && false !== $external_file_obj->is_valid() ) {
		// URL to link.
		$url = $external_file_obj->get_url( true );

		// get shorter URL to show (only protocol and host) to save space.
		$parsed_url  = wp_parse_url( $url );
		$url_to_show = $url;
		if ( ! empty( $parsed_url['scheme'] ) ) {
			$url_to_show = $parsed_url['scheme'] . '://' . $parsed_url['host'] . '..';
		}

		// output.
		?>
			<div class="misc-pub-external-file">
				<p>
					<?php echo esc_html__( 'File-URL:', 'external-files-in-media-library' ); ?><br><a href="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( $url_to_show ); ?></a>
				</p>
				<?php
				if ( $external_file_obj->get_availability() ) {
					?>
						<p id="eml_url_file_state"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html__( 'File-URL is available.', 'external-files-in-media-library' ); ?></p>
					<?php
				} else {
					$log_url = helper::get_log_url();
					?>
						<p id="eml_url_file_state"><span class="dashicons dashicons-no-alt"></span>
						<?php
							/* translators: %1$s will be replaced by the URL for the logs */
							printf( esc_html__( 'File-URL is NOT available! Check <a href="%1$s">the log</a> for details.', 'external-files-in-media-library' ), esc_url( $log_url ) );
						?>
						</p>
					<?php
				}
				?>
					<a class="button" href="#" id="eml_recheck_availability"><?php echo esc_html__( 'Recheck availability', 'external-files-in-media-library' ); ?></a>
					<p>
						<?php
						if ( false !== $external_file_obj->is_locally_saved() ) {
							echo esc_html__( 'This file is local hostet.', 'external-files-in-media-library' );
						} else {
							echo esc_html__( 'This file is extern hostet.', 'external-files-in-media-library' );
						}
						?>
					</p>
					<p>
						<?php
						// TODO nur anzeigen wenn proxy aktiviert ist.
						if ( false !== $external_file_obj->is_cached() ) {
							echo esc_html__( 'This file is delivered through proxied cache.', 'external-files-in-media-library' );
						} else {
							echo esc_html__( 'This file is not cached in proxy.', 'external-files-in-media-library' );
						}
						?>
					</p>
				</div>
			<?php
	} else {
		?>
				<div class="notice notice-error notice-alt inline">
					<p>
					<?php
						echo esc_html__( 'This file is not an external file.', 'external-files-in-media-library' );
					?>
					</p>
				</div>
			<?php
	}
}

/**
 * Check file availability.
 *
 * @return       void
 * @noinspection PhpNoReturnAttributeCanBeAddedInspection
 */
function eml_admin_check_file_availability(): void {
	// check nonce.
	check_ajax_referer( 'eml-availability-check-nonce', 'nonce' );

	// create error-result.
	$result = array(
		'state'   => 'error',
		'message' => __( 'No ID given.', 'external-files-in-media-library' ),
	);

	// get ID.
	$attachment_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
	if ( $attachment_id > 0 ) {
		// get files-object.
		$external_files_obj = external_files::get_instance();

		// get the file.
		$external_file_obj = $external_files_obj->get_file( $attachment_id );

		if ( $external_file_obj ) {
			// check its availability.
			$external_file_obj->set_availability( $external_files_obj->check_availability( $external_file_obj->get_url() ) );

			// return result depending on availability-value.
			if ( $external_file_obj->get_availability() ) {
				$result = array(
					'state'   => 'success',
					'message' => __( 'File-URL is available.', 'external-files-in-media-library' ),
				);
			} else {
				$url    = helper::get_log_url();
				$result = array(
					'state'   => 'error',
					/* translators: %1$s will be replaced by the URL for the logs */
					'message' => sprintf( __( 'URL-File is NOT available! Check <a href="%1$s">the log</a> for details.', 'external-files-in-media-library' ), $url ),
				);
			}
		}
	}

	// send response as JSON.
	echo wp_json_encode( $result );
	wp_die();
}
add_action( 'wp_ajax_eml_check_availability', 'eml_admin_check_file_availability', 10, 0 );

/**
 * Define settings-page for this plugin.
 *
 * @return void
 */
function eml_admin_settings(): void {
	// check user capabilities.
	if ( false === current_user_can( 'manage_options' ) ) {
		return;
	}

	// get the active tab from the $_GET param.
	$default_tab = null;
	$tab         = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : $default_tab;

	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<nav class="nav-tab-wrapper">
			<a href="?page=eml_settings" class="nav-tab
			<?php
			if ( null === $tab ) :
				?>
				nav-tab-active
				<?php
			endif;
			?>
			"><?php esc_html_e( 'General Settings', 'external-files-in-media-library' ); ?></a>
			<a href="?page=eml_settings&tab=logs" class="nav-tab
			<?php
			if ( 'logs' === $tab ) :
				?>
				nav-tab-active
				<?php
			endif;
			?>
			"><?php esc_html_e( 'Logs', 'external-files-in-media-library' ); ?></a>
		</nav>

		<div class="tab-content">
	<?php
	do_action( 'eml_admin_settings_tab_' . ( null === $tab ? 'general' : $tab ) );
	?>
		</div>
	</div>
	<?php
}

/**
 * Add general settings for this plugin.
 *
 * @return void
 */
function eml_admin_settings_tab_general(): void {
	// check user capabilities.
	if ( false === current_user_can( 'manage_options' ) ) {
		return;
	}

	?>
	<!--suppress HtmlUnknownTarget -->
	<form method="POST" action="options.php">
	<?php
	settings_fields( 'eml_settings_group' );
	do_settings_sections( 'eml_settings_page' );
	submit_button();
	?>
	</form>
	<?php
}
add_action( 'eml_admin_settings_tab_general', 'eml_admin_settings_tab_general' );

/**
 * Add general settings for this plugin.
 *
 * @return void
 */
function eml_admin_settings_tab_logs(): void {
	// if WP_List_Table is not loaded automatically, we need to load it.
	if ( ! class_exists( 'WP_List_Table' ) ) {
		include_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}
	$log = new Logs();
	$log->prepare_items();
	?>
	<div class="wrap">
		<div id="icon-users" class="icon32"></div>
		<h2><?php echo esc_html__( 'Logs', 'external-files-in-media-library' ); ?></h2>
	<?php $log->display(); ?>
	</div>
	<?php
}
add_action( 'eml_admin_settings_tab_logs', 'eml_admin_settings_tab_logs' );

/**
 * Show a checkbox in settings.
 *
 * @param array $attr List of settings.
 *
 * @return void
 */
function eml_admin_checkbox_field( array $attr ): void {
	if ( ! empty( $attr['fieldId'] ) ) {
		// get title.
		$title = '';
		if ( isset( $attr['title'] ) ) {
			$title = $attr['title'];
		}

		// set readonly.
		$readonly = '';
		if ( isset( $attr['readonly'] ) && false !== $attr['readonly'] ) {
			$readonly = ' disabled="disabled"';
		}

		?>
		<input type="checkbox" id="<?php echo esc_attr( $attr['fieldId'] ); ?>"
			name="<?php echo esc_attr( $attr['fieldId'] ); ?>"
			value="1"
		<?php
		echo esc_attr( $readonly );
		echo 1 === absint( get_option( $attr['fieldId'], 0 ) ) ? ' checked="checked"' : '';
		?>
			class="eml-field-width"
			title="<?php echo esc_attr( $title ); ?>"
		>
		<?php

		// show optional description for this checkbox.
		if ( ! empty( $attr['description'] ) ) {
			echo '<p>' . wp_kses_post( $attr['description'] ) . '</p>';
		}
	}
}

/**
 * Show a number-field in settings.
 *
 * @param array $attr List of settings.
 *
 * @return void
 */
function eml_admin_number_field( array $attr ): void {
	if ( ! empty( $attr['fieldId'] ) ) {
		// get title.
		$title = '';
		if ( isset( $attr['title'] ) ) {
			$title = $attr['title'];
		}

		// get value.
		$value = get_option( $attr['fieldId'], 0 );

		// set readonly.
		$readonly = '';
		if ( isset( $attr['readonly'] ) && false !== $attr['readonly'] ) {
			$readonly = ' disabled="disabled"';
		}

		?>
		<input type="number" id="<?php echo esc_attr( $attr['fieldId'] ); ?>"
				name="<?php echo esc_attr( $attr['fieldId'] ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				step="1"
				min="0"
				max="10000"
				class="eml-field-width"
				title="<?php echo esc_attr( $title ); ?>"
			<?php
			echo esc_attr( $readonly );
			?>
		>
		<?php

		// show optional description for this checkbox.
		if ( ! empty( $attr['description'] ) ) {
			echo '<p>' . wp_kses_post( $attr['description'] ) . '</p>';
		}
	}
}

/**
 * Validate the checkbox-value.
 *
 * @param ?int $value The checkbox-value.
 *
 * @return       ?int
 * @noinspection PhpUnused
 */
function eml_admin_validate_checkbox( ?int $value ): ?int {
	return absint( $value );
}

/**
 * Show select-field with given values.
 *
 * @param array $attr   Settings as array.
 *
 * @return void
 */
function eml_admin_select_field( array $attr ): void {
	if ( ! empty( $attr['fieldId'] ) && ! empty( $attr['values'] ) ) {
		// get value from config.
		$value = get_option( $attr['fieldId'], '' );

		// get title.
		$title = '';
		if ( isset( $attr['title'] ) ) {
			$title = $attr['title'];
		}

		?>
		<select id="<?php echo esc_attr( $attr['fieldId'] ); ?>" name="<?php echo esc_attr( $attr['fieldId'] ); ?>" class="eml-field-width" title="<?php echo esc_attr( $title ); ?>">
		<?php
		foreach ( $attr['values'] as $key => $label ) {
			?>
			<option value="<?php echo esc_attr( $key ); ?>"<?php echo ( $value === (string) $key ? ' selected="selected"' : '' ); ?>><?php echo esc_html( $label ); ?></option>
			<?php
		}
		?>
		</select>
		<?php
		if ( ! empty( $attr['description'] ) ) {
			echo '<p>' . wp_kses_post( $attr['description'] ) . '</p>';
		}
	} elseif ( empty( $attr['values'] ) && ! empty( $attr['noValues'] ) ) {
		echo '<p>' . esc_html( $attr['noValues'] ) . '</p>';
	}
}

/**
 * Validate the interval-selection-value.
 *
 * @param string $value Interval-setting.
 *
 * @return       string
 * @noinspection PhpUnused
 */
function eml_admin_validate_interval_select( string $value ): string {
	if ( empty( $value ) ) {
		return '';
	}

	// disable the check.
	if ( 'eml_disable_check' === $value ) {
		wp_clear_scheduled_hook( 'eml_check_files' );
		return $value;
	}

	// check if given interval exist.
	$intervals = wp_get_schedules();
	if ( empty( $intervals[ $value ] ) ) {
		add_settings_error( 'eml_check_files', 'eml_check_files', __( 'The given interval does not exists.', 'external-files-in-media-library' ) );
		return '';
	}

	// change the interval.
	wp_clear_scheduled_hook( 'eml_check_files' );
	wp_schedule_event( time(), $value, 'eml_check_files' );

	// return value    for option-value.
	return $value;
}

/**
 * Show multiselect-field with given values.
 *
 * @param array $attr List of settings.
 *
 * @return       void
 * @noinspection PhpUnused
 */
function eml_admin_multiselect_field( array $attr ): void {
	if ( ! empty( $attr['fieldId'] ) && ! empty( $attr['values'] ) ) {
		// get value from config.
		$actual_values = get_option( $attr['fieldId'], array() );
		if ( empty( $actual_values ) ) {
			$actual_values = array();
		}

		// if $actualValues is a string, convert it.
		if ( ! is_array( $actual_values ) ) {
			$actual_values = explode( ',', $actual_values );
		}

		// use values as key if set.
		if ( ! empty( $attr['useValuesAsKeys'] ) ) {
			$new_array = array();
			foreach ( $attr['values'] as $value ) {
				$new_array[ $value ] = $value;
			}
			$attr['values'] = $new_array;
		}

		// get title.
		$title = '';
		if ( isset( $attr['title'] ) ) {
			$title = $attr['title'];
		}

		?>
		<select id="<?php echo esc_attr( $attr['fieldId'] ); ?>" name="<?php echo esc_attr( $attr['fieldId'] ); ?>[]" multiple class="eml-field-width" title="<?php echo esc_attr( $title ); ?>">
		<?php
		foreach ( $attr['values'] as $key => $value ) {
			?>
			<option value="<?php echo esc_attr( $key ); ?>"<?php echo in_array( $key, $actual_values, true ) ? ' selected="selected"' : ''; ?>><?php echo esc_html( $value ); ?></option>
			<?php
		}
		?>
		</select>
		<?php
		if ( ! empty( $attr['description'] ) ) {
			echo '<p>' . wp_kses_post( $attr['description'] ) . '</p>';
		}
	}
}

/**
 * Validate allowed mime-types.
 *
 * @param ?array $values List of mime-types to check.
 *
 * @return       ?array
 * @noinspection PhpUnused
 */
function eml_admin_validate_allowed_mime_types( ?array $values ): ?array {
	// get the possible mime-types.
	$mime_types = External_Files::get_instance()->get_possible_mime_types();

	// check if all mimes in the request are allowed.
	$error = false;
	foreach ( $values as $key => $value ) {
		if ( ! isset( $mime_types[ $value ] ) ) {
			$error = true;
			unset( $values[ $key ] );
		}
	}

	// show error of a not supported mime-type is set.
	if ( $error ) {
		add_settings_error( 'eml_allowed_mime_types', 'eml_allowed_mime_types', __( 'The given mime-type is not supported. Setting will not be saved.', 'external-files-in-media-library' ) );
	}

	// if list is not empty, remove any notification about it.
	if ( ! empty( $values ) ) {
		$transients_obj = Transients::get_instance();
		$transients_obj->get_transient_by_name( 'eml_missing_mime_types' )->delete();
	}

	// return resulting list.
	return $values;
}

/**
 * Set capabilities after saving settings.
 *
 * @param array|null $values The setting.
 *
 * @return array
 * @noinspection PhpNoReturnAttributeCanBeAddedInspection
 * @noinspection PhpUnused
 */
function eml_admin_set_capability( ?array $values ): array {
	if ( ! is_array( $values ) ) {
		$values = array();
	}

	// set capabilities.
	helper::set_capabilities( $values );

	// return given value.
	return $values;
}

/**
 * Checks on each admin-initialization.
 *
 * @return void
 */
function eml_admin_init(): void {
	$external_files_obj = External_Files::get_instance();
	if ( empty( $external_files_obj->get_allowed_mime_types() ) ) {
		// get the transients-object to add the new one.
		$transients_obj = Transients::get_instance();
		$transient_obj  = $transients_obj->add();
		$transient_obj->set_dismissible_days( 14 );
		$transient_obj->set_name( 'eml_missing_mime_types' );
		$transient_obj->set_message( __( 'External files could not be used as no mime-types are allowed.', 'external-files-in-media-library' ) );
		$transient_obj->set_type( 'error' );
		$transient_obj->save();
	}
}
add_action( 'admin_init', 'eml_admin_init' );

/**
 * Show known transients only for users with rights.
 *
 * @return void
 */
function eml_admin_notices(): void {
	if ( current_user_can( 'manage_options' ) ) {
		$transients_obj = Transients::get_instance();
		$transients_obj->check_transients();
	}
}
add_action( 'admin_notices', 'eml_admin_notices' );

/**
 * Process dismiss of notices in wp-backend.
 *
 * @return void
 * @noinspection PhpNoReturnAttributeCanBeAddedInspection
 */
function eml_admin_dismiss(): void {
	// check nonce.
	check_ajax_referer( 'eml-dismiss-nonce', 'nonce' );

	// get values.
	$option_name        = isset( $_POST['option_name'] ) ? sanitize_text_field( wp_unslash( $_POST['option_name'] ) ) : false;
	$dismissible_length = isset( $_POST['dismissible_length'] ) ? sanitize_text_field( wp_unslash( $_POST['dismissible_length'] ) ) : 14;

	if ( 'forever' !== $dismissible_length ) {
		// If $dismissible_length is not an integer default to 14.
		$dismissible_length = ( 0 === absint( $dismissible_length ) ) ? 14 : $dismissible_length;
		$dismissible_length = strtotime( absint( $dismissible_length ) . ' days' );
	}

	// save value.
	update_site_option( 'pi-dismissed-' . md5( $option_name ), $dismissible_length );

	// return nothing.
	wp_die();
}
add_action( 'wp_ajax_dismiss_admin_notice', 'eml_admin_dismiss' );

/**
 * Validate the value from number-field.
 *
 * @param string $value Variable to validate.
 * @return int
 */
function eml_admin_validate_number( string $value ): int {
	return absint( $value );
}
