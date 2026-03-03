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
			?>
			<div class="acf-icon-picker-wrap" id="<?php echo esc_attr( $field['id'] ); ?>">
				<div class="acf-icon-search">
					<span class="dashicons dashicons-search"></span>
					<input type="text" class="acf-icon-search-input" placeholder="Search icons..." autocomplete="off" />
				</div>

				<div class="icon-tiles-grid">
					<?php // "No icon" / clear tile. ?>
					<label
						class="icon-tile<?php echo ( '' === $current_value ) ? ' selected' : ''; ?>"
						data-icon-name=""
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
		 * Retrieves the raw SVG markup for the stored icon ID so that calling
		 * get_field() in a template returns inline SVG code ready to output.
		 *
		 * @param mixed  $value   The raw value stored in the database (icon ID).
		 * @param int    $post_id The post ID from which the value was loaded.
		 * @param array  $field   The ACF field array.
		 * @return string Raw SVG markup string, or empty string if no icon set.
		 */
		public function format_value( $value, $post_id, $field ) {
			if ( empty( $value ) ) {
				return '';
			}

			if ( ! class_exists( 'ACF_Icon_Storage' ) ) {
				return '';
			}

			$svg = ACF_Icon_Storage::get_svg_content( $value );

			return is_string( $svg ) ? $svg : '';
		}

		/**
		 * Render optional settings for this field type in the ACF field group editor.
		 *
		 * Adds a "Picker icon size" select so admins can control how large icons
		 * appear inside the picker grid tiles.
		 *
		 * @param array $field The ACF field array.
		 * @return void
		 */
		public function render_field_settings( $field ) {
			acf_render_field_setting(
				$field,
				array(
					'label'        => __( 'Picker Icon Size', 'acf-custom-icon' ),
					'instructions' => __( 'Display size of icons inside the picker grid.', 'acf-custom-icon' ),
					'type'         => 'select',
					'name'         => 'picker_icon_size',
					'choices'      => array(
						'small'  => __( 'Small (24px)', 'acf-custom-icon' ),
						'medium' => __( 'Medium (40px)', 'acf-custom-icon' ),
						'large'  => __( 'Large (56px)', 'acf-custom-icon' ),
					),
					'default_value' => 'medium',
				)
			);
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
		 * Only permits safe, presentational SVG elements. Strips any script
		 * or event-handler attributes to prevent XSS from uploaded SVGs.
		 *
		 * @return array Allowed tags and attributes for wp_kses().
		 */
		private function get_allowed_svg_tags() {
			return array(
				'svg'      => array(
					'xmlns'           => true,
					'viewbox'         => true,
					'width'           => true,
					'height'          => true,
					'fill'            => true,
					'stroke'          => true,
					'stroke-width'    => true,
					'stroke-linecap'  => true,
					'stroke-linejoin' => true,
					'aria-hidden'     => true,
					'role'            => true,
					'class'           => true,
					'style'           => true,
				),
				'g'        => array(
					'fill'         => true,
					'stroke'       => true,
					'transform'    => true,
					'class'        => true,
					'style'        => true,
					'fill-rule'    => true,
					'clip-rule'    => true,
					'stroke-width' => true,
				),
				'path'     => array(
					'd'               => true,
					'fill'            => true,
					'stroke'          => true,
					'stroke-width'    => true,
					'stroke-linecap'  => true,
					'stroke-linejoin' => true,
					'fill-rule'       => true,
					'clip-rule'       => true,
					'class'           => true,
					'style'           => true,
					'transform'       => true,
				),
				'circle'   => array(
					'cx'           => true,
					'cy'           => true,
					'r'            => true,
					'fill'         => true,
					'stroke'       => true,
					'stroke-width' => true,
					'class'        => true,
					'style'        => true,
				),
				'rect'     => array(
					'x'            => true,
					'y'            => true,
					'width'        => true,
					'height'       => true,
					'rx'           => true,
					'ry'           => true,
					'fill'         => true,
					'stroke'       => true,
					'stroke-width' => true,
					'class'        => true,
					'style'        => true,
					'transform'    => true,
				),
				'line'     => array(
					'x1'           => true,
					'y1'           => true,
					'x2'           => true,
					'y2'           => true,
					'stroke'       => true,
					'stroke-width' => true,
					'stroke-linecap' => true,
					'class'        => true,
					'style'        => true,
				),
				'polyline' => array(
					'points'       => true,
					'fill'         => true,
					'stroke'       => true,
					'stroke-width' => true,
					'stroke-linecap' => true,
					'stroke-linejoin' => true,
					'class'        => true,
					'style'        => true,
				),
				'polygon'  => array(
					'points'       => true,
					'fill'         => true,
					'stroke'       => true,
					'stroke-width' => true,
					'class'        => true,
					'style'        => true,
				),
				'ellipse'  => array(
					'cx'           => true,
					'cy'           => true,
					'rx'           => true,
					'ry'           => true,
					'fill'         => true,
					'stroke'       => true,
					'stroke-width' => true,
					'class'        => true,
					'style'        => true,
				),
				'defs'     => array(),
				'title'    => array(),
				'desc'     => array(),
				'symbol'   => array(
					'id'      => true,
					'viewbox' => true,
					'width'   => true,
					'height'  => true,
				),
				'use'      => array(
					'href'   => true,
					'x'      => true,
					'y'      => true,
					'width'  => true,
					'height' => true,
				),
				'mask'     => array(
					'id'      => true,
					'x'       => true,
					'y'       => true,
					'width'   => true,
					'height'  => true,
					'maskUnits' => true,
				),
				'clippath' => array(
					'id'        => true,
					'clipPathUnits' => true,
				),
				'lineargradient' => array(
					'id'                => true,
					'x1'               => true,
					'y1'               => true,
					'x2'               => true,
					'y2'               => true,
					'gradientUnits'    => true,
					'gradientTransform' => true,
				),
				'radialgradient' => array(
					'id'                => true,
					'cx'               => true,
					'cy'               => true,
					'r'                => true,
					'fx'               => true,
					'fy'               => true,
					'gradientUnits'    => true,
					'gradientTransform' => true,
				),
				'stop'     => array(
					'offset'     => true,
					'stop-color' => true,
					'stop-opacity' => true,
					'style'      => true,
				),
			);
		}
	}

	acf_register_field_type( 'ACF_Field_Custom_Icon' );

endif;
