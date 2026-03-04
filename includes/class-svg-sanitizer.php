<?php
/**
 * SVG Sanitizer
 *
 * Strips unsafe elements and attributes from SVG content before storage.
 *
 * @package ACF_Custom_Icon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ACF_SVG_Sanitizer
 *
 * Strips script tags, foreignObject elements, on* event attributes,
 * and javascript: href/xlink:href values from SVG markup.
 */
class ACF_SVG_Sanitizer {

	/**
	 * Elements that are not allowed in SVG.
	 *
	 * @var string[]
	 */
	private static $blocked_elements = array(
		'script',
		'foreignObject',
		'iframe',
		'object',
		'embed',
		'animate',
		'set',
		'animateMotion',
		'animateTransform',
		'discard',
	);

	/**
	 * Static sanitize method for direct calls.
	 *
	 * @param string $svg_content Raw SVG markup string.
	 * @return string|false Sanitized SVG string, or false on failure.
	 */
	public static function sanitize_svg( $svg_content ) {
		if ( empty( $svg_content ) ) {
			return false;
		}

		// Normalize line endings.
		$svg_content = str_replace( "\r\n", "\n", $svg_content );
		$svg_content = str_replace( "\r", "\n", $svg_content );

		// Load the SVG into DOMDocument.
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$dom->preserveWhiteSpace = false;

		$loaded = $dom->loadXML( $svg_content, LIBXML_NONET | LIBXML_NOENT );

		libxml_clear_errors();
		libxml_use_internal_errors( false );

		if ( ! $loaded ) {
			return false;
		}

		$root = $dom->documentElement;

		// Must be an SVG root element.
		if ( ! $root || 'svg' !== strtolower( $root->nodeName ) ) {
			return false;
		}

		// Remove blocked elements recursively.
		self::remove_blocked_elements( $dom );

		// Remove unsafe attributes from all elements.
		self::sanitize_attributes( $dom );

		// Serialize back to string.
		$sanitized = $dom->saveXML( $root );

		if ( false === $sanitized ) {
			return false;
		}

		return $sanitized;
	}

	/**
	 * Remove all blocked elements from the DOM.
	 *
	 * @param DOMDocument $dom The DOM document.
	 */
	private static function remove_blocked_elements( DOMDocument $dom ) {
		foreach ( self::$blocked_elements as $tag ) {
			$nodes = $dom->getElementsByTagName( $tag );

			// Build a list first since removing while iterating causes issues.
			$to_remove = array();
			foreach ( $nodes as $node ) {
				$to_remove[] = $node;
			}

			foreach ( $to_remove as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	/**
	 * Returns the allowed SVG tags and attributes array for wp_kses().
	 *
	 * Only permits safe, presentational SVG elements. Strips any script
	 * or event-handler attributes to prevent XSS from uploaded SVGs.
	 * Filterable via the 'acf_custom_icon_allowed_svg_tags' filter.
	 *
	 * @return array Allowed tags and attributes for wp_kses().
	 */
	public static function get_allowed_svg_tags() {
		$tags = array(
			'svg'            => array(
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
				'focusable'       => true,
			),
			'g'              => array(
				'fill'         => true,
				'stroke'       => true,
				'transform'    => true,
				'class'        => true,
				'style'        => true,
				'fill-rule'    => true,
				'clip-rule'    => true,
				'stroke-width' => true,
			),
			'path'           => array(
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
			'circle'         => array(
				'cx'           => true,
				'cy'           => true,
				'r'            => true,
				'fill'         => true,
				'stroke'       => true,
				'stroke-width' => true,
				'class'        => true,
				'style'        => true,
			),
			'rect'           => array(
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
			'line'           => array(
				'x1'             => true,
				'y1'             => true,
				'x2'             => true,
				'y2'             => true,
				'stroke'         => true,
				'stroke-width'   => true,
				'stroke-linecap' => true,
				'class'          => true,
				'style'          => true,
			),
			'polyline'       => array(
				'points'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'class'           => true,
				'style'           => true,
			),
			'polygon'        => array(
				'points'       => true,
				'fill'         => true,
				'stroke'       => true,
				'stroke-width' => true,
				'class'        => true,
				'style'        => true,
			),
			'ellipse'        => array(
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
			'defs'           => array(),
			'title'          => array(),
			'desc'           => array(),
			'symbol'         => array(
				'id'      => true,
				'viewbox' => true,
				'width'   => true,
				'height'  => true,
			),
			'use'            => array(
				'href'       => true,
				'xlink:href' => true,
				'x'          => true,
				'y'          => true,
				'width'      => true,
				'height'     => true,
			),
			'mask'           => array(
				'id'         => true,
				'x'          => true,
				'y'          => true,
				'width'      => true,
				'height'     => true,
				'maskunits'  => true,
			),
			'clippath'       => array(
				'id'             => true,
				'clippathunits'  => true,
			),
			'lineargradient' => array(
				'id'                => true,
				'x1'                => true,
				'y1'                => true,
				'x2'                => true,
				'y2'                => true,
				'gradientunits'     => true,
				'gradienttransform' => true,
			),
			'radialgradient' => array(
				'id'                => true,
				'cx'                => true,
				'cy'                => true,
				'r'                 => true,
				'fx'                => true,
				'fy'                => true,
				'gradientunits'     => true,
				'gradienttransform' => true,
			),
			'stop'           => array(
				'offset'       => true,
				'stop-color'   => true,
				'stop-opacity' => true,
				'style'        => true,
			),
		);

		/**
		 * Filters the allowed SVG tags and attributes for wp_kses().
		 *
		 * @param array $tags Allowed tags and attributes.
		 */
		return apply_filters( 'acf_custom_icon_allowed_svg_tags', $tags );
	}

	/**
	 * Sanitize attributes on all elements in the DOM.
	 *
	 * Removes on* event attributes and javascript: hrefs.
	 *
	 * @param DOMDocument $dom The DOM document.
	 */
	private static function sanitize_attributes( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );

		// Find all elements.
		$elements = $xpath->query( '//*' );

		if ( false === $elements ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! ( $element instanceof DOMElement ) ) {
				continue;
			}

			$attrs_to_remove = array();

			foreach ( $element->attributes as $attr ) {
				$attr_name  = strtolower( $attr->name );
				$attr_value = trim( $attr->value );

				// Remove all on* event handlers.
				if ( 0 === strpos( $attr_name, 'on' ) ) {
					$attrs_to_remove[] = $attr->name;
					continue;
				}

				// Remove javascript: protocol from href, xlink:href, action, src.
				if ( in_array( $attr_name, array( 'href', 'xlink:href', 'action', 'src', 'data' ), true ) ) {
					$decoded_value = strtolower( rawurldecode( $attr_value ) );
					$decoded_value = preg_replace( '/\s+/', '', $decoded_value );

					if ( 0 === strpos( $decoded_value, 'javascript:' ) ) {
						$attrs_to_remove[] = $attr->name;
						continue;
					}

					if ( 0 === strpos( $decoded_value, 'vbscript:' ) ) {
						$attrs_to_remove[] = $attr->name;
						continue;
					}

					if ( 0 === strpos( $decoded_value, 'data:' ) && false !== strpos( $decoded_value, 'html' ) ) {
						$attrs_to_remove[] = $attr->name;
						continue;
					}
				}
			}

			foreach ( $attrs_to_remove as $attr_name ) {
				$element->removeAttribute( $attr_name );
			}
		}
	}
}
