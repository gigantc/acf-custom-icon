<?php
/**
 * ACF Custom Icon Field Type
 *
 * Registers a custom ACF field type that renders a visual icon picker grid.
 * Icons are loaded from ACF_Icon_Storage and the field stores the selected icon ID.
 * format_value() returns raw SVG markup so get_field() returns inline SVG in templates.
 *
 * @package ACF_Custom_Icon
 */

if ( ! class_exists( 'ACF_Field_Custom_Icon' ) ) :

	/**
	 * Class ACF_Field_Custom_Icon
	 *
	 * Extends acf_field to provide a visual SVG icon picker for content editors.
	 */
	class ACF_Field_Custom_Icon extends acf_field {

		/**
		 * Initialize field type metadata.
		 *
		 * Called by the parent acf_field constructor. Sets up name, label,
		 * category, description, and defaults for this field type.
		 *
		 * @return void
		 */
		public function initialize() {
			$this->name        = 'custom_icon';
			$this->label       = __( 'Custom Icon', 'acf-custom-icon' );
			$this->category    = 'content';
			$this->description = __( 'A visual icon picker that lets editors select from uploaded SVG icons.', 'acf-custom-icon' );
			$this->defaults    = array(
				'value' => '',
			);
		}

		/**
		 * Render the icon picker field in the ACF field group editor.
		 *
		 * Outputs a grid of icon tiles as radio inputs. Each tile shows the SVG
		 * inline along with the icon name. A "No icon" tile is always first.
		 * Selected state is tracked via a hidden input that stores the icon ID.
		 *
		 * @param array $field The ACF field array.
		 * @return void
		 */
		public function render_field( $field ) {
			$icons         = class_exists( 'ACF_Icon_Storage' ) ? ACF_Icon_Storage::get_all() : array();
			$current_value = isset( $field['value'] ) ? $field['value'] : '';

			// Allowed SVG tags and attributes for wp_kses().
			$allowed_svg = $this->get_allowed_svg_tags();

			// Get selected icon info for the preview bar.
			$selected_name = 'None';
			$selected_svg  = '';
			if ( ! empty( $current_value ) && isset( $icons[ $current_value ] ) ) {
				$selected_name = $icons[ $current_value ]['name'] ?? $current_value;
				$svg_raw       = ACF_Icon_Storage::get_svg_content( $current_value );
				if ( $svg_raw ) {
					$selected_svg = wp_kses( $svg_raw, $allowed_svg );
				}
			}
			?>
			<div class="acf-icon-picker-wrap" id="<?php echo esc_attr( $field['id'] ); ?>">
				<div class="acf-icon-selected-bar">
					<span class="acf-icon-selected-preview">
						<?php if ( $selected_svg ) : ?>
							<?php echo $selected_svg; ?>
						<?php else : ?>
							<span class="dashicons dashicons-minus"></span>
						<?php endif; ?>
					</span>
					<span class="acf-icon-selected-name"><?php echo esc_html( $selected_name ); ?></span>
					<button type="button" class="acf-icon-edit-btn"><?php esc_html_e( 'Edit', 'acf-custom-icon' ); ?></button>
				</div>

				<div class="acf-icon-picker-panel" style="display:none;">
					<div class="acf-icon-search">
						<span class="dashicons dashicons-search"></span>
						<input type="text" class="acf-icon-search-input" placeholder="Search icons..." autocomplete="off" />
					</div>

					<div class="icon-tiles-grid">
						<?php // "No icon" / clear tile. ?>
						<label
							class="icon-tile<?php echo ( '' === $current_value ) ? ' selected' : ''; ?>"
							data-icon-name=""
							data-icon-svg=""
							title="None"
						>
							<input
								type="radio"
								name="<?php echo esc_attr( $field['name'] ); ?>"
								value=""
								<?php checked( $current_value, '' ); ?>
							/>
							<div class="icon-preview icon-preview--empty">
								<span class="dashicons dashicons-minus"></span>
							</div>
						</label>

						<?php if ( ! empty( $icons ) ) : ?>
							<?php foreach ( $icons as $icon_id => $icon ) : ?>
								<?php
								$svg_content = class_exists( 'ACF_Icon_Storage' ) ? ACF_Icon_Storage::get_svg_content( $icon_id ) : '';
								$icon_name   = isset( $icon['name'] ) ? $icon['name'] : $icon_id;
								$is_selected = ( (string) $icon_id === (string) $current_value );
								$tile_class  = 'icon-tile' . ( $is_selected ? ' selected' : '' );
								?>
								<label
									class="<?php echo esc_attr( $tile_class ); ?>"
									data-icon-name="<?php echo esc_attr( $icon_name ); ?>"
									data-icon-svg="<?php echo esc_attr( $svg_content ); ?>"
									title="<?php echo esc_attr( $icon_name ); ?>"
								>
									<input
										type="radio"
										name="<?php echo esc_attr( $field['name'] ); ?>"
										value="<?php echo esc_attr( $icon_id ); ?>"
										<?php checked( $is_selected ); ?>
									/>
									<div class="icon-preview">
										<?php
										if ( ! empty( $svg_content ) ) {
											echo wp_kses( $svg_content, $allowed_svg );
										}
										?>
									</div>
								</label>
							<?php endforeach; ?>
						<?php else : ?>
							<p class="acf-custom-icon-no-icons">
								<?php esc_html_e( 'No icons uploaded yet. Add icons via the Icon Manager.', 'acf-custom-icon' ); ?>
							</p>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Sanitize and save the selected icon ID to the database.
		 *
		 * Strips all tags and sanitizes the stored icon ID string.
		 *
		 * @param mixed  $value   The value submitted by the field.
		 * @param int    $post_id The post ID being saved to.
		 * @param array  $field   The ACF field array.
		 * @return string Sanitized icon ID or empty string.
		 */
		public function update_value( $value, $post_id, $field ) {
			if ( empty( $value ) ) {
				return '';
			}

			// Sanitize the icon ID — preserve dots since uniqid() produces IDs like icon_67c5e3b1.12345678.
			return sanitize_text_field( wp_unslash( $value ) );
		}

		/**
		 * Format the stored value for use in templates.
		 *
		 * Returns an associative array with 'path' and 'url' keys so templates
		 * can choose how to render the icon (inline SVG via file_get_contents,
		 * or as an <img> tag via the URL).
		 *
		 * @param mixed  $value   The raw value stored in the database (icon ID).
		 * @param int    $post_id The post ID from which the value was loaded.
		 * @param array  $field   The ACF field array.
		 * @return array|false Array with 'path' and 'url' keys, or false if no icon set.
		 */
		public function format_value( $value, $post_id, $field ) {
			if ( empty( $value ) ) {
				return false;
			}

			if ( ! class_exists( 'ACF_Icon_Storage' ) ) {
				return false;
			}

			$icon = ACF_Icon_Storage::get_by_id( $value );

			if ( false === $icon ) {
				return false;
			}

			$result = array(
				'path' => isset( $icon['path'] ) ? $icon['path'] : '',
				'url'  => isset( $icon['url'] ) ? $icon['url'] : '',
			);

			/**
			 * Filters the icon data returned by get_field() for a custom icon field.
			 *
			 * @param array $result  Array with 'path' and 'url' keys.
			 * @param mixed $value   The raw stored icon ID.
			 * @param int   $post_id The post ID.
			 * @param array $field   The ACF field array.
			 */
			return apply_filters( 'acf_custom_icon_format_value', $result, $value, $post_id, $field );
		}

		/**
		 * Enqueue CSS and JS assets for the field in the ACF field editor.
		 *
		 * Called automatically by ACF when this field type is present on the page.
		 *
		 * @return void
		 */
		public function input_admin_enqueue_scripts() {
			$plugin_url = plugin_dir_url( __FILE__ ) . '../assets/';
			$version    = defined( 'ACF_CUSTOM_ICON_VERSION' ) ? ACF_CUSTOM_ICON_VERSION : '1.0.0';

			wp_enqueue_style(
				'acf-custom-icon-field',
				$plugin_url . 'css/field.css',
				array(),
				$version
			);

			wp_enqueue_script(
				'acf-custom-icon-field',
				$plugin_url . 'js/field.js',
				array( 'jquery', 'acf-input' ),
				$version,
				true
			);
		}

		/**
		 * Returns the allowed SVG tags and attributes array for wp_kses().
		 *
		 * Delegates to ACF_SVG_Sanitizer::get_allowed_svg_tags() so the
		 * allowlist is defined in one place.
		 *
		 * @return array Allowed tags and attributes for wp_kses().
		 */
		private function get_allowed_svg_tags() {
			return ACF_SVG_Sanitizer::get_allowed_svg_tags();
		}
	}

	acf_register_field_type( 'ACF_Field_Custom_Icon' );

endif;
