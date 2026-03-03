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
	 * Sanitize SVG content.
	 *
	 * @param string $svg_content Raw SVG markup string.
	 * @return string|false Sanitized SVG string, or false on failure.
	 */
	public function sanitize( $svg_content ) {
		return self::sanitize_svg( $svg_content );
	}

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
