<?php
/**
 * ACF Custom Icon - Admin Page
 *
 * Manages the WordPress admin page for the icon library.
 *
 * @package ACF_Custom_Icon
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ACF_Icon_Admin_Page
 *
 * Handles the admin UI for uploading, viewing, and deleting custom icons.
 */
class ACF_Icon_Admin_Page {

	/**
	 * Admin menu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'acf-icon-library';

	/**
	 * Nonce action name.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'acf_icon_library_nonce';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_acf_icon_upload', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_acf_icon_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_acf_icon_rename', array( $this, 'handle_rename' ) );
	}

	/**
	 * Register top-level admin menu item.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Icon Library', 'acf-custom-icon' ),
			__( 'Icon Library', 'acf-custom-icon' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-images-alt2',
			80
		);
	}

	/**
	 * Enqueue admin assets only on the icon library page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		$version = defined( 'ACF_CUSTOM_ICON_VERSION' ) ? ACF_CUSTOM_ICON_VERSION : '1.0.0';

		wp_enqueue_style(
			'acf-icon-library-admin',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/admin.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'acf-icon-library-admin',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/admin.js',
			array(),
			$version,
			true
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acf-custom-icon' ) );
		}

		$notice = $this->get_notice();
		$icons  = ACF_Icon_Storage::get_all();
		?>
		<div class="wrap acf-icon-library">
			<h1><?php esc_html_e( 'Icon Library', 'acf-custom-icon' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="acf-icon-library__upload-section">
				<h2><?php esc_html_e( 'Upload Icon', 'acf-custom-icon' ); ?></h2>
				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					enctype="multipart/form-data"
					class="acf-icon-library__upload-form"
				>
					<?php wp_nonce_field( self::NONCE_ACTION, '_nonce' ); ?>
					<input type="hidden" name="action" value="acf_icon_upload">

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="acf_icon_name">
										<?php esc_html_e( 'Icon Name', 'acf-custom-icon' ); ?>
									</label>
								</th>
								<td>
									<input
										type="text"
										id="acf_icon_name"
										name="icon_name"
										class="regular-text"
										required
										placeholder="<?php esc_attr_e( 'e.g. arrow-right', 'acf-custom-icon' ); ?>"
									>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="acf_icon_file">
										<?php esc_html_e( 'Icon File', 'acf-custom-icon' ); ?>
									</label>
								</th>
								<td>
									<input
										type="file"
										id="acf_icon_file"
										name="icon_file"
										accept=".svg,image/svg+xml"
										required
									>
									<p class="description">
										<?php esc_html_e( 'Accepted format: SVG', 'acf-custom-icon' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Upload Icon', 'acf-custom-icon' ) ); ?>
				</form>
			</div>

			<div class="acf-icon-library__grid-section">
				<h2><?php esc_html_e( 'Saved Icons', 'acf-custom-icon' ); ?></h2>

				<?php if ( empty( $icons ) ) : ?>
					<p><?php esc_html_e( 'No icons uploaded yet.', 'acf-custom-icon' ); ?></p>
				<?php else : ?>
					<div class="acf-icon-library__grid">
						<?php foreach ( $icons as $icon ) :
							$icon_id   = esc_attr( $icon['id'] );
							$icon_name = esc_html( $icon['name'] );
						?>
							<div class="acf-icon-item">
								<div class="acf-icon-item__inner">
									<div class="acf-icon-item__preview">
										<?php $this->render_icon_preview( $icon ); ?>
									</div>
									<div class="acf-icon-item__name" title="<?php echo esc_attr( $icon['name'] ); ?>">
										<?php echo esc_html( $icon['name'] ); ?>
									</div>
								</div>
								<div class="acf-icon-item__actions">
									<button type="button" class="icon-action-btn icon-edit-btn" title="Rename">
										<span class="dashicons dashicons-edit" aria-hidden="true"></span>
										<span class="screen-reader-text"><?php esc_html_e( 'Rename', 'acf-custom-icon' ); ?></span>
									</button>
									<form
										method="post"
										action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										class="icon-delete-form"
										data-icon-name="<?php echo $icon_name; ?>"
									>
										<?php wp_nonce_field( self::NONCE_ACTION, '_nonce' ); ?>
										<input type="hidden" name="action" value="acf_icon_delete">
										<input type="hidden" name="icon_id" value="<?php echo $icon_id; ?>">
										<button type="submit" class="icon-action-btn icon-action-btn--delete" title="Delete">
											<span class="dashicons dashicons-trash" aria-hidden="true"></span>
											<span class="screen-reader-text"><?php esc_html_e( 'Delete', 'acf-custom-icon' ); ?></span>
										</button>
									</form>
								</div>
								<div class="acf-icon-item__rename" hidden>
									<form
										method="post"
										action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									>
										<?php wp_nonce_field( self::NONCE_ACTION, '_nonce' ); ?>
										<input type="hidden" name="action" value="acf_icon_rename">
										<input type="hidden" name="icon_id" value="<?php echo $icon_id; ?>">
										<input
											type="text"
											name="icon_name"
											value="<?php echo $icon_name; ?>"
											required
										>
										<div class="rename-actions">
											<button type="submit" class="icon-action-btn icon-action-btn--save" title="Save">
												<span class="dashicons dashicons-yes" aria-hidden="true"></span>
												<span class="screen-reader-text"><?php esc_html_e( 'Save', 'acf-custom-icon' ); ?></span>
											</button>
											<button type="button" class="icon-action-btn icon-action-btn--cancel icon-rename-cancel" title="Cancel">
												<span class="dashicons dashicons-no" aria-hidden="true"></span>
												<span class="screen-reader-text"><?php esc_html_e( 'Cancel', 'acf-custom-icon' ); ?></span>
											</button>
										</div>
									</form>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single icon preview (inline SVG or img fallback).
	 *
	 * @param array $icon Icon data array with at least 'id' and 'type' keys.
	 * @return void
	 */
	private function render_icon_preview( array $icon ): void {
		$id   = $icon['id'];
		$type = $icon['type'] ?? '';

		if ( 'svg' === $type ) {
			$svg_content = ACF_Icon_Storage::get_svg_content( $id );

			if ( $svg_content ) {
				// Define allowed SVG tags and attributes for wp_kses().
				$allowed_svg = ACF_SVG_Sanitizer::get_allowed_svg_tags();
				echo wp_kses( $svg_content, $allowed_svg );
				return;
			}
		}

		// PNG or SVG content unavailable — use img tag fallback.
		$url = $icon['url'] ?? '';
		if ( $url ) {
			printf(
				'<img src="%s" alt="%s" class="acf-icon-library__img">',
				esc_url( $url ),
				esc_attr( $icon['name'] ?? '' )
			);
		}
	}

	/**
	 * Handle icon upload form submission.
	 *
	 * @return void
	 */
	public function handle_upload(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'acf-custom-icon' ) );
		}

		if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_nonce'] ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'acf-custom-icon' ) );
		}

		$icon_name = isset( $_POST['icon_name'] ) ? sanitize_text_field( wp_unslash( $_POST['icon_name'] ) ) : '';

		if ( empty( $icon_name ) ) {
			$this->redirect_with_notice( 'error', __( 'Icon name is required.', 'acf-custom-icon' ) );
			return;
		}

		if ( empty( $_FILES['icon_file']['name'] ) || UPLOAD_ERR_OK !== $_FILES['icon_file']['error'] ) {
			$this->redirect_with_notice( 'error', __( 'File upload failed. Please try again.', 'acf-custom-icon' ) );
			return;
		}

		$file = $_FILES['icon_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.

		$allowed_mimes = array(
			'svg' => 'image/svg+xml',
		);

		$file_type = wp_check_filetype( $file['name'], $allowed_mimes );

		if ( empty( $file_type['ext'] ) ) {
			$this->redirect_with_notice( 'error', __( 'Invalid file type. Only SVG files are allowed.', 'acf-custom-icon' ) );
			return;
		}

		$ext     = $file_type['ext'];
		$raw_svg = null;

		if ( 'svg' === $ext ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
			$raw_svg = file_get_contents( $file['tmp_name'] );

			if ( false === $raw_svg ) {
				$this->redirect_with_notice( 'error', __( 'Could not read uploaded file.', 'acf-custom-icon' ) );
				return;
			}
		}

		$result = ACF_Icon_Storage::add( $icon_name, $raw_svg, $ext, $file );

		if ( $result && ! is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'success', __( 'Icon uploaded successfully.', 'acf-custom-icon' ) );
		} else {
			$this->redirect_with_notice( 'error', __( 'Failed to save icon. Please try again.', 'acf-custom-icon' ) );
		}
	}

	/**
	 * Handle icon delete form submission.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'acf-custom-icon' ) );
		}

		if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_nonce'] ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'acf-custom-icon' ) );
		}

		$icon_id = isset( $_POST['icon_id'] ) ? sanitize_text_field( wp_unslash( $_POST['icon_id'] ) ) : '';

		if ( ! $icon_id ) {
			$this->redirect_with_notice( 'error', __( 'Invalid icon ID.', 'acf-custom-icon' ) );
			return;
		}

		$result = ACF_Icon_Storage::delete( $icon_id );

		if ( $result ) {
			$this->redirect_with_notice( 'success', __( 'Icon deleted successfully.', 'acf-custom-icon' ) );
		} else {
			$this->redirect_with_notice( 'error', __( 'Failed to delete icon. Please try again.', 'acf-custom-icon' ) );
		}
	}

	/**
	 * Handle icon rename form submission.
	 *
	 * @return void
	 */
	public function handle_rename(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'acf-custom-icon' ) );
		}

		if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_nonce'] ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'acf-custom-icon' ) );
		}

		$icon_id   = isset( $_POST['icon_id'] ) ? sanitize_text_field( wp_unslash( $_POST['icon_id'] ) ) : '';
		$icon_name = isset( $_POST['icon_name'] ) ? sanitize_text_field( wp_unslash( $_POST['icon_name'] ) ) : '';

		if ( ! $icon_id ) {
			$this->redirect_with_notice( 'error', __( 'Invalid icon ID.', 'acf-custom-icon' ) );
			return;
		}

		if ( empty( $icon_name ) ) {
			$this->redirect_with_notice( 'error', __( 'Icon name is required.', 'acf-custom-icon' ) );
			return;
		}

		$result = ACF_Icon_Storage::rename( $icon_id, $icon_name );

		if ( $result ) {
			$this->redirect_with_notice( 'success', __( 'Icon renamed successfully.', 'acf-custom-icon' ) );
		} else {
			$this->redirect_with_notice( 'error', __( 'Failed to rename icon. Please try again.', 'acf-custom-icon' ) );
		}
	}

	/**
	 * Redirect back to the admin page with a transient notice.
	 *
	 * @param string $type    Notice type: 'success' or 'error'.
	 * @param string $message Notice message.
	 * @return void
	 */
	private function redirect_with_notice( string $type, string $message ): void {
		$user_id = get_current_user_id();
		set_transient( 'acf_icon_notice_' . $user_id, array( 'type' => $type, 'message' => $message ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * Get and clear the current user's queued notice transient.
	 *
	 * @return array|null Array with 'type' and 'message' keys, or null.
	 */
	private function get_notice(): ?array {
		$user_id = get_current_user_id();
		$key     = 'acf_icon_notice_' . $user_id;
		$notice  = get_transient( $key );

		if ( $notice ) {
			delete_transient( $key );
			return $notice;
		}

		return null;
	}

}
