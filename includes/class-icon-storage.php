<?php
/**
 * Icon Storage
 *
 * Manages SVG icon files in the uploads directory and maintains
 * the icon index in the WordPress options table.
 *
 * @package ACF_Custom_Icon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ACF_Icon_Storage
 *
 * Each icon entry in the index is an associative array with keys:
 *   - id       (string) Unique identifier generated with uniqid('icon_', true)
 *   - name     (string) Human-readable display name
 *   - filename (string) Stored filename, e.g. icon_abc123.svg
 *   - path     (string) Absolute filesystem path to the file
 *   - url      (string) Public URL to the file
 *
 * Methods are available both as static calls and as instance methods
 * to support both direct utility usage and dependency-injected usage.
 */
class ACF_Icon_Storage {

	/**
	 * WordPress option key for the icon index.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'acf_custom_icons';

	/**
	 * Option holding the plugin version the library was last re-sanitized under.
	 *
	 * @var string
	 */
	const SANITIZED_VERSION_KEY = 'acf_custom_icon_sanitized_version';

	/**
	 * Option holding the summary of the last re-sanitize pass, until it is shown.
	 *
	 * @var string
	 */
	const RESANITIZE_SUMMARY_KEY = 'acf_custom_icon_resanitize_summary';

	/**
	 * Request-scoped cache of the resolved icon index.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Request-scoped cache of kses-filtered SVG markup, keyed by icon ID.
	 *
	 * @var array
	 */
	private static $markup_cache = array();

	/**
	 * Get the absolute path to the icons uploads directory.
	 *
	 * @return string
	 */
	public static function get_upload_dir() {
		return wp_upload_dir()['basedir'] . '/acf-custom-icons';
	}

	/**
	 * Get the public URL to the icons uploads directory.
	 *
	 * @return string
	 */
	public static function get_upload_url() {
		return wp_upload_dir()['baseurl'] . '/acf-custom-icons';
	}

	/**
	 * Get all icons from the index.
	 *
	 * Returns an associative array keyed by icon ID for easy lookup.
	 * Each value is an icon entry array with id, name, filename, path, url, and type keys.
	 *
	 * @return array Associative array of icon entries keyed by icon ID.
	 */
	public static function get_all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$raw = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $raw ) ) {
			self::$cache = array();
			return self::$cache;
		}

		// Re-index by icon ID and fix URLs/paths for the current environment.
		$upload_url = self::get_upload_url();
		$upload_dir = self::get_upload_dir();
		$indexed    = array();

		foreach ( $raw as $icon ) {
			if ( isset( $icon['id'] ) ) {
				// Reconstruct URL and path from filename to handle environment migrations.
				if ( ! empty( $icon['filename'] ) ) {
					$icon['url']  = $upload_url . '/' . $icon['filename'];
					$icon['path'] = $upload_dir . '/' . $icon['filename'];
				}
				$indexed[ $icon['id'] ] = $icon;
			}
		}

		self::$cache = $indexed;
		return self::$cache;
	}

	/**
	 * Invalidate the request-scoped cache.
	 *
	 * Called after any mutation so subsequent reads see fresh data.
	 *
	 * @return void
	 */
	private static function flush_cache() {
		self::$cache        = null;
		self::$markup_cache = array();
	}

	/**
	 * Get a single icon entry by ID.
	 *
	 * @param string $id Icon ID.
	 * @return array|false Icon entry array or false if not found.
	 */
	public static function get_by_id( $id ) {
		$icons = self::get_all();

		// get_all() returns an ID-keyed array; direct lookup.
		if ( isset( $icons[ $id ] ) ) {
			return $icons[ $id ];
		}

		return false;
	}

	/**
	 * Get the raw SVG file content for an icon.
	 *
	 * @param string $id Icon ID.
	 * @return string|false SVG markup string or false if not found.
	 */
	public static function get_svg_content( $id ) {
		$icon = self::get_by_id( $id );

		if ( false === $icon ) {
			return false;
		}

		// Reconstruct the path from filename to handle database migrations between environments.
		$path = self::resolve_icon_path( $icon );

		if ( empty( $path ) || ! file_exists( $path ) ) {
			return false;
		}

		if ( ! self::is_within_upload_dir( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $path );

		if ( false === $content ) {
			return false;
		}

		return $content;
	}

	/**
	 * Get an icon's SVG markup, filtered for safe output, read from disk once per request.
	 *
	 * Used by the library and every field picker instance. Returns an empty
	 * string when the file is missing or unreadable.
	 *
	 * @param string $id Icon ID.
	 * @return string
	 */
	public static function get_preview_markup( $id ) {
		if ( ! isset( self::$markup_cache[ $id ] ) ) {
			$svg = self::get_svg_content( $id );

			self::$markup_cache[ $id ] = $svg ? wp_kses( $svg, ACF_SVG_Sanitizer::get_allowed_svg_tags() ) : '';
		}

		return self::$markup_cache[ $id ];
	}

	/**
	 * Whether a path resolves to a file inside the icons uploads directory.
	 *
	 * The trailing slash matters: without it a sibling such as
	 * acf-custom-icons-evil/ would pass a prefix comparison.
	 *
	 * @param string $path Filesystem path.
	 * @return bool
	 */
	public static function is_within_upload_dir( $path ) {
		$real_path = realpath( $path );
		$real_dir  = realpath( self::get_upload_dir() );

		return $real_path && $real_dir && 0 === strpos( $real_path, trailingslashit( $real_dir ) );
	}

	/**
	 * Whether an icon's file is present on disk.
	 *
	 * @param array $icon Icon entry array.
	 * @return bool
	 */
	public static function has_file( array $icon ) {
		$path = self::resolve_icon_path( $icon );

		return ! empty( $path ) && file_exists( $path );
	}

	/**
	 * Create the icons directory and its protective files if they are missing.
	 *
	 * Idempotent: existing files are never touched. The .htaccess only turns off
	 * directory listing, so the icons stay publicly loadable by URL.
	 *
	 * @return bool True if the directory exists afterwards.
	 */
	public static function ensure_directory() {
		$dir = self::get_upload_dir();

		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$htaccess_file = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess_file, "Options -Indexes\n" );
		}

		$index_file = $dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index_file, '<?php // Silence is golden.' );
		}

		return true;
	}

	/**
	 * Resolve the actual filesystem path for an icon.
	 *
	 * Prefers the stored path if it exists on disk. Falls back to
	 * reconstructing from the filename, which handles database migrations
	 * between environments (e.g. local to WP Engine).
	 *
	 * @param array $icon Icon entry array.
	 * @return string Resolved path, or empty string if unresolvable.
	 */
	public static function resolve_icon_path( array $icon ) {
		// Try the stored path first.
		$stored_path = isset( $icon['path'] ) ? $icon['path'] : '';

		if ( ! empty( $stored_path ) && file_exists( $stored_path ) ) {
			return $stored_path;
		}

		// Fall back to reconstructing from filename.
		$filename = isset( $icon['filename'] ) ? $icon['filename'] : '';

		if ( ! empty( $filename ) ) {
			return self::get_upload_dir() . '/' . $filename;
		}

		return '';
	}

	/**
	 * Add a new icon to the library.
	 *
	 * Sanitizes the SVG, saves the file to the uploads directory,
	 * and appends an entry to the icon index option.
	 *
	 * @param string $name        Human-readable display name.
	 * @param string $svg_content Raw SVG markup string.
	 * @param string $style       Icon style: 'line' or 'custom'. Defaults to 'line'.
	 * @return string|WP_Error New icon ID on success, WP_Error on failure.
	 */
	public static function add( $name, $svg_content, $style = 'line' ) {
		// Validate and sanitize the name.
		$name = sanitize_text_field( $name );

		if ( empty( $name ) ) {
			return new WP_Error( 'invalid_name', __( 'Icon name cannot be empty.', 'acf-custom-icon' ) );
		}

		// Ensure the upload directory and its protective files exist.
		if ( ! self::ensure_directory() ) {
			return new WP_Error( 'mkdir_failed', __( 'Could not create the icon uploads directory.', 'acf-custom-icon' ) );
		}

		$upload_dir = self::get_upload_dir();

		// Generate unique ID and filename.
		$id       = uniqid( 'icon_', true );
		$filename = $id . '.svg';
		$path     = $upload_dir . '/' . $filename;
		$url      = self::get_upload_url() . '/' . $filename;

		if ( empty( $svg_content ) ) {
			return new WP_Error( 'invalid_svg', __( 'SVG content cannot be empty.', 'acf-custom-icon' ) );
		}

		// Sanitize the SVG.
		$sanitized_svg = ACF_SVG_Sanitizer::sanitize_svg( $svg_content );

		if ( false === $sanitized_svg ) {
			return new WP_Error( 'sanitize_failed', __( 'SVG could not be sanitized. The file may be invalid or contain unsafe content.', 'acf-custom-icon' ) );
		}

		// Write the sanitized SVG to disk.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes_written = file_put_contents( $path, $sanitized_svg );

		if ( false === $bytes_written ) {
			return new WP_Error( 'write_failed', __( 'Could not save the SVG file.', 'acf-custom-icon' ) );
		}

		// Build the icon entry.
		$entry = array(
			'id'       => $id,
			'name'     => $name,
			'filename' => $filename,
			'path'     => $path,
			'url'      => $url,
			'type'     => 'svg',
			'style'    => in_array( $style, array( 'line', 'custom' ), true ) ? $style : 'line',
		);

		// Prepend to the index so new icons appear first, then persist.
		$icons_indexed = self::get_all();
		$new_order     = array_merge( array( $id => $entry ), $icons_indexed );

		update_option( self::OPTION_KEY, array_values( $new_order ), false );
		self::flush_cache();

		return $id;
	}

	/**
	 * Rename an icon in the library.
	 *
	 * Updates the display name for an existing icon entry.
	 *
	 * @param string $id       Icon ID.
	 * @param string $new_name New display name.
	 * @return bool True on success, false if icon not found or name is empty.
	 */
	public static function rename( $id, $new_name ) {
		$name = sanitize_text_field( $new_name );

		if ( empty( $name ) ) {
			return false;
		}

		$icons = self::get_all();

		if ( ! isset( $icons[ $id ] ) ) {
			return false;
		}

		$icons[ $id ]['name'] = $name;
		update_option( self::OPTION_KEY, array_values( $icons ), false );
		self::flush_cache();

		return true;
	}

	/**
	 * Reorder the icon library.
	 *
	 * Accepts an array of icon IDs in the desired display order and rewrites
	 * the option to match. Any IDs not present in the list are appended at the end.
	 *
	 * @param string[] $ordered_ids Ordered array of icon IDs.
	 * @return bool True on success.
	 */
	public static function reorder( array $ordered_ids ) {
		$icons     = self::get_all();
		$reordered = array();

		foreach ( $ordered_ids as $id ) {
			if ( isset( $icons[ $id ] ) ) {
				$reordered[ $id ] = $icons[ $id ];
			}
		}

		// Append any icons missing from the submitted order as a safety net.
		foreach ( $icons as $id => $icon ) {
			if ( ! isset( $reordered[ $id ] ) ) {
				$reordered[ $id ] = $icon;
			}
		}

		update_option( self::OPTION_KEY, array_values( $reordered ), false );
		self::flush_cache();

		return true;
	}

	/**
	 * Delete an icon from the library.
	 *
	 * Removes the SVG file from disk and removes the entry from the index.
	 *
	 * @param string $id Icon ID.
	 * @return bool True on success, false if icon was not found.
	 */
	public static function delete( $id ) {
		// get_all() returns ID-keyed array.
		$icons = self::get_all();

		if ( ! isset( $icons[ $id ] ) ) {
			return false;
		}

		// Remove the file from disk if it exists.
		$icon      = $icons[ $id ];
		$file_path = self::resolve_icon_path( $icon );

		if ( ! empty( $file_path ) && file_exists( $file_path ) ) {
			// Verify the file is inside the expected uploads directory before deleting.
			if ( self::is_within_upload_dir( $file_path ) ) {
				wp_delete_file( $file_path );
			}
		}

		unset( $icons[ $id ] );

		// Save as sequential array (get_all re-indexes on read).
		update_option( self::OPTION_KEY, array_values( $icons ), false );
		self::flush_cache();

		return true;
	}

	/**
	 * Run the re-sanitize pass once per plugin version.
	 *
	 * Hooked to admin_init rather than activation, because activation never runs
	 * on git or SFTP deploys. The version option is only updated after the pass
	 * completes, so an interrupted run simply repeats.
	 *
	 * @return void
	 */
	public static function maybe_resanitize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( version_compare( (string) get_option( self::SANITIZED_VERSION_KEY, '0' ), ACF_CUSTOM_ICON_VERSION, '>=' ) ) {
			return;
		}

		/**
		 * Filters whether stored icons are re-sanitized after a plugin upgrade.
		 *
		 * @param bool $run Whether to run the pass. Default true.
		 */
		if ( ! apply_filters( 'acf_custom_icon_resanitize_on_upgrade', true ) ) {
			return;
		}

		update_option( self::RESANITIZE_SUMMARY_KEY, self::resanitize_all(), false );
		update_option( self::SANITIZED_VERSION_KEY, ACF_CUSTOM_ICON_VERSION, false );
	}

	/**
	 * Re-run every stored SVG through the sanitizer.
	 *
	 * Files whose sanitized output is identical are not touched. Rejected files
	 * are left exactly as they are. Missing files are only counted. Writes go
	 * through a temp file and rename so an interruption never leaves a partial icon.
	 *
	 * @return array Counts (checked, rewritten, unchanged, rejected, missing, failed) and rejected_names.
	 */
	public static function resanitize_all() {
		$summary = array(
			'checked'        => 0,
			'rewritten'      => 0,
			'unchanged'      => 0,
			'rejected'       => 0,
			'missing'        => 0,
			'failed'         => 0,
			'rejected_names' => array(),
		);

		self::ensure_directory();

		foreach ( self::get_all() as $icon ) {
			$filename = isset( $icon['filename'] ) ? $icon['filename'] : '';
			$path     = self::resolve_icon_path( $icon );

			++$summary['checked'];

			// Anything that isn't a readable SVG inside the icons directory counts as missing.
			if ( 'svg' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) || empty( $path ) || ! file_exists( $path ) || ! self::is_within_upload_dir( $path ) ) {
				++$summary['missing'];
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$current   = file_get_contents( $path );
			$sanitized = ( false === $current ) ? false : ACF_SVG_Sanitizer::sanitize_svg( $current );

			if ( false === $sanitized ) {
				++$summary['rejected'];
				$summary['rejected_names'][] = isset( $icon['name'] ) ? $icon['name'] : $filename;
				continue;
			}

			if ( $sanitized === $current ) {
				++$summary['unchanged'];
				continue;
			}

			$tmp = $path . '.tmp';

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === file_put_contents( $tmp, $sanitized ) || ! rename( $tmp, $path ) ) {
				wp_delete_file( $tmp );
				++$summary['failed'];
				continue;
			}

			++$summary['rewritten'];
		}

		return $summary;
	}

	/**
	 * Find where an icon is in use.
	 *
	 * The field stores the icon ID as a plain string, so this looks for the ID in
	 * post meta (classic fields and repeater/group rows), post content (ACF
	 * blocks keep field data as JSON in block comments) and options (ACF options
	 * pages). Revisions, auto-drafts, transients and the plugin's own options
	 * are skipped.
	 *
	 * @param string $icon_id Icon ID.
	 * @return array {
	 *     @type array[] $posts   Each with 'id' and 'title'.
	 *     @type int     $options Number of matching option rows.
	 * }
	 */
	public static function get_usage( $icon_id ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $icon_id ) . '%';

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
				WHERE p.post_type != 'revision'
				AND p.post_status != 'auto-draft'
				AND ( p.post_content LIKE %s OR p.ID IN ( SELECT m.post_id FROM {$wpdb->postmeta} m WHERE m.meta_value LIKE %s ) )
				ORDER BY p.ID DESC",
				$like,
				$like
			)
		);

		$options = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				WHERE option_value LIKE %s
				AND option_name NOT IN ( %s, %s, %s )
				AND option_name NOT LIKE %s
				AND option_name NOT LIKE %s",
				$like,
				self::OPTION_KEY,
				self::RESANITIZE_SUMMARY_KEY,
				self::SANITIZED_VERSION_KEY,
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%'
			)
		);

		$found = array();

		foreach ( (array) $posts as $post ) {
			$found[] = array(
				'id'    => (int) $post->ID,
				'title' => $post->post_title,
			);
		}

		return array(
			'posts'   => $found,
			'options' => $options,
		);
	}
}
