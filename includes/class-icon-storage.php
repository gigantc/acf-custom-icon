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
		$raw = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		// Re-index by icon ID and fix URLs/paths for the current environment.
		$upload_url = self::get_upload_url();
		$indexed    = array();

		foreach ( $raw as $icon ) {
			if ( isset( $icon['id'] ) ) {
				// Reconstruct URL from filename to handle environment migrations.
				if ( ! empty( $icon['filename'] ) ) {
					$icon['url'] = $upload_url . '/' . $icon['filename'];
				}
				$indexed[ $icon['id'] ] = $icon;
			}
		}

		// Sort alphabetically by icon name.
		uasort( $indexed, function ( $a, $b ) {
			return strnatcasecmp( $a['name'] ?? '', $b['name'] ?? '' );
		} );

		return $indexed;
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

		// Verify the file is inside the expected uploads directory.
		$upload_dir = self::get_upload_dir();
		$real_path  = realpath( $path );
		$real_dir   = realpath( $upload_dir );

		if ( ! $real_path || ! $real_dir || 0 !== strpos( $real_path, $real_dir ) ) {
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
	 * Resolve the actual filesystem path for an icon.
	 *
	 * Prefers the stored path if it exists on disk. Falls back to
	 * reconstructing from the filename, which handles database migrations
	 * between environments (e.g. local to WP Engine).
	 *
	 * @param array $icon Icon entry array.
	 * @return string Resolved path, or empty string if unresolvable.
	 */
	private static function resolve_icon_path( array $icon ) {
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
	 * Supports both the standard 2-argument signature (name, svg_content)
	 * and the extended 4-argument signature used by the admin page
	 * (name, svg_content_or_null, ext, file_array).
	 *
	 * @param string      $name        Human-readable display name.
	 * @param string|null $svg_content Raw SVG markup string (null for PNG uploads).
	 * @param string      $ext         File extension: 'svg' or 'png'. Defaults to 'svg'.
	 * @param array|null  $file        $_FILES entry for PNG uploads. Defaults to null.
	 * @return string|WP_Error New icon ID on success, WP_Error on failure.
	 */
	public static function add( $name, $svg_content, $ext = 'svg', $file = null ) {
		// Validate and sanitize the name.
		$name = sanitize_text_field( $name );

		if ( empty( $name ) ) {
			return new WP_Error( 'invalid_name', __( 'Icon name cannot be empty.', 'acf-custom-icon' ) );
		}

		// Ensure the upload directory exists.
		$upload_dir = self::get_upload_dir();

		if ( ! file_exists( $upload_dir ) ) {
			if ( ! wp_mkdir_p( $upload_dir ) ) {
				return new WP_Error( 'mkdir_failed', __( 'Could not create the icon uploads directory.', 'acf-custom-icon' ) );
			}
		}

		// Generate unique ID and filename.
		$id       = uniqid( 'icon_', true );
		$filename = $id . '.' . $ext;
		$path     = $upload_dir . '/' . $filename;
		$url      = self::get_upload_url() . '/' . $filename;

		if ( 'svg' === $ext ) {
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
		} elseif ( 'png' === $ext && ! empty( $file['tmp_name'] ) ) {
			// Move the uploaded PNG into the icons directory.
			if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) {
				return new WP_Error( 'move_failed', __( 'Could not save the PNG file.', 'acf-custom-icon' ) );
			}
		} else {
			return new WP_Error( 'invalid_file', __( 'Unsupported file type or missing file data.', 'acf-custom-icon' ) );
		}

		// Build the icon entry.
		$entry = array(
			'id'       => $id,
			'name'     => $name,
			'filename' => $filename,
			'path'     => $path,
			'url'      => $url,
			'type'     => $ext,
		);

		// Append to the index and persist.
		// get_all() returns ID-keyed array; convert back to sequential for storage.
		$icons_indexed              = self::get_all();
		$icons_indexed[ $id ]       = $entry;

		update_option( self::OPTION_KEY, array_values( $icons_indexed ), false );

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
			$upload_dir = self::get_upload_dir();
			$real_path  = realpath( $file_path );
			$real_dir   = realpath( $upload_dir );

			if ( $real_path && $real_dir && 0 === strpos( $real_path, $real_dir ) ) {
				wp_delete_file( $file_path );
			}
		}

		unset( $icons[ $id ] );

		// Save as sequential array (get_all re-indexes on read).
		update_option( self::OPTION_KEY, array_values( $icons ), false );

		return true;
	}
}
