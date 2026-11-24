<?php
/**
 * SVG Sanitizer
 *
 * Rebuilds uploaded SVG markup from an allowlist of elements and attributes.
 *
 * @package ACF_Custom_Icon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ACF_SVG_Sanitizer
 *
 * Parses the SVG without entity expansion, then copies only allowlisted
 * elements, attributes and safe values into a fresh document. Everything
 * else is dropped. <style> blocks are inlined onto the elements they target,
 * because inline SVG styles are page-global and collide between icons.
 *
 * get_allowed_svg_tags() is the single source of truth: the stored output
 * and wp_kses() previews are both built from it.
 *
 * Ids are prefixed per icon (aci-{hash}-) so gradients, clips and masks from
 * different inline icons can't collide on one page. The 'acf_custom_icon_prefix_ids'
 * filter (default true) turns this off.
 */
class ACF_SVG_Sanitizer {

	const NS_SVG   = 'http://www.w3.org/2000/svg';
	const NS_XLINK = 'http://www.w3.org/1999/xlink';
	const NS_XML   = 'http://www.w3.org/XML/1998/namespace';

	/**
	 * Elements that are removed but whose children are kept.
	 *
	 * @var string[]
	 */
	private static $unwrap = array( 'a', 'switch' );

	/**
	 * Elements whose text nodes are kept.
	 *
	 * @var string[]
	 */
	private static $text_elements = array( 'text', 'tspan', 'title', 'desc' );

	/**
	 * Properties that exist as presentation attributes. CSS rules for these
	 * become attributes (when the element allows them); others go to style.
	 *
	 * @var string[]
	 */
	private static $presentation = array(
		'fill',
		'stroke',
		'stroke-width',
		'stroke-linecap',
		'stroke-linejoin',
		'opacity',
		'fill-opacity',
		'fill-rule',
		'stroke-opacity',
		'stroke-dasharray',
		'stroke-dashoffset',
		'stroke-miterlimit',
		'clip-path',
		'clip-rule',
		'mask',
		'filter',
		'display',
		'visibility',
		'color',
		'marker-start',
		'marker-mid',
		'marker-end',
		'font-family',
		'font-size',
		'font-weight',
		'font-style',
		'text-anchor',
		'letter-spacing',
		'stop-color',
		'stop-opacity',
		'flood-color',
		'flood-opacity',
		'color-interpolation-filters',
	);

	/**
	 * Report for the most recent sanitize_svg() call.
	 *
	 * @var array
	 */
	private static $report = array();

	/**
	 * Allowlist in use for the current run (lowercase keys).
	 *
	 * @var array
	 */
	private static $allowed = array();

	/**
	 * Parsed class rules for the current run.
	 *
	 * @var array[]
	 */
	private static $rules = array();

	/**
	 * Indexes of rules applied to at least one kept element.
	 *
	 * @var array
	 */
	private static $rules_used = array();

	/**
	 * Whether the output uses xlink: attributes.
	 *
	 * @var bool
	 */
	private static $uses_xlink = false;

	/**
	 * Get a blank report.
	 *
	 * @return array
	 */
	private static function blank_report() {
		return array(
			'elements'     => array(),
			'attributes'   => array(),
			'style_blocks' => 0,
			'style_rules'  => 0,
			'css_dropped'  => array(),
			'ids_prefixed' => 0,
			'rejected'     => '',
		);
	}

	/**
	 * Get the report for the most recent sanitize_svg() call.
	 *
	 * Keys: 'elements' and 'attributes' (name => count removed),
	 * 'style_blocks' (style elements inlined), 'style_rules' (rules applied),
	 * 'css_dropped' (list of unsupported or unsafe CSS), 'rejected'
	 * (translated reason string, empty unless the input was rejected),
	 * 'ids_prefixed' (number of ids rewritten, informational).
	 *
	 * @return array
	 */
	public static function get_last_report() {
		return empty( self::$report ) ? self::blank_report() : self::$report;
	}

	/**
	 * Reject the input and record why.
	 *
	 * @param string $reason Translated reason.
	 * @return false
	 */
	private static function reject( $reason ) {
		self::$report['rejected'] = $reason;
		return false;
	}

	/**
	 * Static sanitize method for direct calls.
	 *
	 * @param string $svg_content Raw SVG markup string.
	 * @return string|false Sanitized SVG string, or false on failure.
	 */
	public static function sanitize_svg( $svg_content ) {
		self::$report     = self::blank_report();
		self::$rules      = array();
		self::$rules_used = array();
		self::$uses_xlink = false;

		if ( ! is_string( $svg_content ) || '' === trim( $svg_content ) ) {
			return self::reject( __( 'is empty', 'acf-custom-icon' ) );
		}

		// Entities and DTDs are never needed for an icon and are the XXE and
		// entity-expansion attack surface, so refuse them outright.
		if ( preg_match( '/<!\s*(DOCTYPE|ENTITY)/i', $svg_content ) ) {
			return self::reject( __( 'contains a DOCTYPE/entity declaration', 'acf-custom-icon' ) );
		}

		$svg_content = str_replace( array( "\r\n", "\r" ), "\n", $svg_content );

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$loaded   = $dom->loadXML( $svg_content, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return self::reject( __( 'is not valid XML', 'acf-custom-icon' ) );
		}

		// Catches encodings (e.g. UTF-16) where the raw string check above can't see the DTD.
		if ( null !== $dom->doctype ) {
			return self::reject( __( 'contains a DOCTYPE/entity declaration', 'acf-custom-icon' ) );
		}

		$root = $dom->documentElement;

		if ( ! $root || 'svg' !== strtolower( $root->localName ) || ! self::is_svg_namespace( $root ) ) {
			return self::reject( __( 'does not have an svg root element', 'acf-custom-icon' ) );
		}

		self::$allowed = self::normalize_allowlist( self::get_allowed_svg_tags() );

		if ( ! isset( self::$allowed['svg'] ) ) {
			return self::reject( __( 'cannot be sanitized because svg is not an allowed element', 'acf-custom-icon' ) );
		}

		self::collect_styles( $dom );

		$out      = new DOMDocument( '1.0', 'UTF-8' );
		$out_root = $out->createElementNS( self::NS_SVG, $root->localName );
		$out->appendChild( $out_root );

		self::copy_attributes( $root, $out_root, 'svg' );
		self::copy_children( $root, $out_root, 'svg', $out );

		if ( self::$uses_xlink || self::declares_xlink( $root ) ) {
			$out_root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xlink', self::NS_XLINK );
		}

		self::$report['style_rules'] = count( self::$rules_used );

		if ( apply_filters( 'acf_custom_icon_prefix_ids', true ) ) {
			self::$report['ids_prefixed'] = self::prefix_ids( $out, $out_root );
		}

		$sanitized = $out->saveXML( $out_root );

		return ( false === $sanitized || '' === $sanitized ) ? false : $sanitized;
	}

	/**
	 * Returns the allowed SVG tags and attributes array for wp_kses().
	 *
	 * Used for the stored output as well as admin and field previews.
	 * Keys are lowercase, as wp_kses() expects.
	 * Filterable via the 'acf_custom_icon_allowed_svg_tags' filter.
	 *
	 * @return array Allowed tags and attributes for wp_kses().
	 */
	public static function get_allowed_svg_tags() {
		$flags = static function ( array $names ) {
			return array_fill_keys( $names, true );
		};

		$common = array( 'id', 'class', 'style', 'transform' );

		$pres = array(
			'fill',
			'stroke',
			'stroke-width',
			'stroke-linecap',
			'stroke-linejoin',
			'opacity',
			'fill-opacity',
			'fill-rule',
			'stroke-opacity',
			'stroke-dasharray',
			'stroke-dashoffset',
			'stroke-miterlimit',
			'clip-path',
			'clip-rule',
			'mask',
			'filter',
			'display',
			'visibility',
			'color',
		);

		$markers = array( 'marker-start', 'marker-mid', 'marker-end' );
		$href    = array( 'href', 'xlink:href' );
		$text    = array( 'x', 'y', 'dx', 'dy', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'letter-spacing' );
		$prim    = array( 'id', 'class', 'style', 'x', 'y', 'width', 'height', 'result', 'color-interpolation-filters' );

		$tags = array(
			'svg'            => $flags(
				array_merge(
					$common,
					$pres,
					array( 'xmlns', 'xmlns:xlink', 'xml:space', 'version', 'viewbox', 'preserveaspectratio', 'x', 'y', 'width', 'height', 'aria-hidden', 'role', 'focusable' )
				)
			),
			'g'              => $flags( array_merge( $common, $pres ) ),
			'path'           => $flags( array_merge( $common, $pres, $markers, array( 'd' ) ) ),
			'circle'         => $flags( array_merge( $common, $pres, array( 'cx', 'cy', 'r' ) ) ),
			'rect'           => $flags( array_merge( $common, $pres, array( 'x', 'y', 'width', 'height', 'rx', 'ry' ) ) ),
			'line'           => $flags( array_merge( $common, $pres, $markers, array( 'x1', 'y1', 'x2', 'y2' ) ) ),
			'polyline'       => $flags( array_merge( $common, $pres, $markers, array( 'points' ) ) ),
			'polygon'        => $flags( array_merge( $common, $pres, $markers, array( 'points' ) ) ),
			'ellipse'        => $flags( array_merge( $common, $pres, array( 'cx', 'cy', 'rx', 'ry' ) ) ),
			'text'           => $flags( array_merge( $common, $pres, $text ) ),
			'tspan'          => $flags( array_merge( $common, $pres, $text ) ),
			'image'          => $flags( array_merge( $common, $pres, $href, array( 'x', 'y', 'width', 'height', 'preserveaspectratio' ) ) ),
			'use'            => $flags( array_merge( $common, $pres, $href, array( 'x', 'y', 'width', 'height' ) ) ),
			'defs'           => array(),
			'title'          => array(),
			'desc'           => array(),
			'symbol'         => $flags( array( 'id', 'class', 'style', 'viewbox', 'width', 'height', 'preserveaspectratio' ) ),
			'marker'         => $flags( array( 'id', 'class', 'style', 'viewbox', 'refx', 'refy', 'markerwidth', 'markerheight', 'orient', 'markerunits' ) ),
			'mask'           => $flags( array( 'id', 'class', 'style', 'x', 'y', 'width', 'height', 'maskunits', 'maskcontentunits' ) ),
			'clippath'       => $flags( array( 'id', 'class', 'style', 'transform', 'clippathunits' ) ),
			'pattern'        => $flags( array( 'id', 'class', 'style', 'x', 'y', 'width', 'height', 'patternunits', 'patterncontentunits', 'patterntransform', 'viewbox' ) ),
			'lineargradient' => $flags( array_merge( $href, array( 'id', 'class', 'style', 'x1', 'y1', 'x2', 'y2', 'gradientunits', 'gradienttransform', 'spreadmethod' ) ) ),
			'radialgradient' => $flags( array_merge( $href, array( 'id', 'class', 'style', 'cx', 'cy', 'r', 'fx', 'fy', 'gradientunits', 'gradienttransform', 'spreadmethod' ) ) ),
			'stop'           => $flags( array( 'id', 'class', 'style', 'offset', 'stop-color', 'stop-opacity' ) ),
			'filter'         => $flags( array( 'id', 'class', 'style', 'x', 'y', 'width', 'height', 'filterunits', 'color-interpolation-filters' ) ),
			'fegaussianblur' => $flags( array_merge( $prim, array( 'in', 'stddeviation' ) ) ),
			'feoffset'       => $flags( array_merge( $prim, array( 'in', 'dx', 'dy' ) ) ),
			'feflood'        => $flags( array_merge( $prim, array( 'flood-color', 'flood-opacity' ) ) ),
			'fecomposite'    => $flags( array_merge( $prim, array( 'in', 'in2', 'operator', 'k1', 'k2', 'k3', 'k4' ) ) ),
			'femerge'        => $flags( $prim ),
			'femergenode'    => $flags( array( 'in' ) ),
			'fecolormatrix'  => $flags( array_merge( $prim, array( 'in', 'type', 'values' ) ) ),
			'feblend'        => $flags( array_merge( $prim, array( 'in', 'in2', 'mode' ) ) ),
			'fedropshadow'   => $flags( array_merge( $prim, array( 'in', 'dx', 'dy', 'stddeviation', 'flood-color', 'flood-opacity' ) ) ),
		);

		/**
		 * Filters the allowed SVG tags and attributes for wp_kses().
		 *
		 * @param array $tags Allowed tags and attributes.
		 */
		return apply_filters( 'acf_custom_icon_allowed_svg_tags', $tags );
	}

	/**
	 * Lowercase every tag and attribute key of the (filtered) allowlist.
	 *
	 * @param array $tags Allowlist in wp_kses() format.
	 * @return array
	 */
	private static function normalize_allowlist( $tags ) {
		$normalized = array();

		foreach ( (array) $tags as $tag => $attrs ) {
			$normalized[ strtolower( $tag ) ] = is_array( $attrs ) ? array_change_key_case( $attrs, CASE_LOWER ) : array();
		}

		return $normalized;
	}

	/**
	 * Whether an element is in the SVG namespace (or has none).
	 *
	 * @param DOMElement $element Element to test.
	 * @return bool
	 */
	private static function is_svg_namespace( DOMElement $element ) {
		$ns = $element->namespaceURI;

		return null === $ns || '' === $ns || self::NS_SVG === $ns;
	}

	/**
	 * Whether the source root declares the xlink namespace.
	 *
	 * @param DOMElement $root Source root.
	 * @return bool
	 */
	private static function declares_xlink( DOMElement $root ) {
		return self::NS_XLINK === $root->lookupNamespaceURI( 'xlink' );
	}

	/**
	 * Record a removed element.
	 *
	 * @param string $name Element name.
	 */
	private static function note_element( $name ) {
		self::$report['elements'][ $name ] = ( self::$report['elements'][ $name ] ?? 0 ) + 1;
	}

	/**
	 * Record a removed attribute.
	 *
	 * @param string $name Attribute name.
	 */
	private static function note_attribute( $name ) {
		self::$report['attributes'][ $name ] = ( self::$report['attributes'][ $name ] ?? 0 ) + 1;
	}

	/**
	 * Record unsupported or unsafe CSS.
	 *
	 * @param string $what Description of the dropped CSS.
	 */
	private static function note_css( $what ) {
		$what = trim( preg_replace( '/\s+/', ' ', $what ) );

		if ( strlen( $what ) > 60 ) {
			$what = substr( $what, 0, 57 ) . '...';
		}

		self::$report['css_dropped'][] = $what;
	}

	//////////////////////////////////////
	// TREE WALK

	/**
	 * Copy the allowed children of a source node into the output node.
	 *
	 * @param DOMNode     $src      Source parent.
	 * @param DOMElement  $dst      Output parent.
	 * @param string      $dst_name Lowercase name of the output parent.
	 * @param DOMDocument $out      Output document.
	 */
	private static function copy_children( DOMNode $src, DOMElement $dst, $dst_name, DOMDocument $out ) {
		foreach ( $src->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$name = strtolower( $child->localName );

				// Style blocks are inlined in collect_styles() and reported there.
				if ( 'style' === $name && self::is_svg_namespace( $child ) ) {
					continue;
				}

				if ( ! self::is_svg_namespace( $child ) ) {
					self::note_element( $child->nodeName );
					continue;
				}

				if ( isset( self::$allowed[ $name ] ) ) {
					$new = $out->createElementNS( self::NS_SVG, $child->localName );
					$dst->appendChild( $new );
					self::copy_attributes( $child, $new, $name );
					self::copy_children( $child, $new, $name, $out );
				} elseif ( in_array( $name, self::$unwrap, true ) ) {
					self::note_element( $name );
					self::copy_children( $child, $dst, $dst_name, $out );
				} else {
					self::note_element( $child->nodeName );
				}
			} elseif ( $child instanceof DOMCdataSection ) {
				continue;
			} elseif ( $child instanceof DOMText ) {
				if ( ! in_array( $dst_name, self::$text_elements, true ) ) {
					continue;
				}

				$dst->appendChild( $out->createTextNode( $child->nodeValue ) );
			}
		}
	}

	/**
	 * Copy the allowed, safe attributes of a source element onto an output element.
	 *
	 * @param DOMElement $src  Source element.
	 * @param DOMElement $dst  Output element.
	 * @param string     $name Lowercase element name.
	 */
	private static function copy_attributes( DOMElement $src, DOMElement $dst, $name ) {
		$allowed = self::$allowed[ $name ];
		$attrs   = array();
		$style   = array();

		foreach ( $src->attributes as $attr ) {
			$attr_name = $attr->nodeName;
			$ns        = $attr->namespaceURI;

			if ( self::NS_XLINK === $ns && 'href' === $attr->localName ) {
				$attr_name = 'xlink:href';
			} elseif ( self::NS_XML === $ns ) {
				$attr_name = 'xml:' . $attr->localName;
			} elseif ( null !== $ns && '' !== $ns ) {
				self::note_attribute( $attr_name );
				continue;
			}

			$lower = strtolower( $attr_name );
			$value = $attr->value;

			if ( ! isset( $allowed[ $lower ] ) ) {
				self::note_attribute( $attr_name );
				continue;
			}

			if ( 'style' === $lower ) {
				$style = self::clean_declarations( $value, 'style attribute' );
				continue;
			}

			if ( 'href' === $lower || 'xlink:href' === $lower ) {
				if ( ! self::is_safe_href( $value, $name ) ) {
					self::note_attribute( $attr_name );
					continue;
				}
			} elseif ( ! self::is_safe_attribute_value( $lower, $value ) ) {
				self::note_attribute( $attr_name );
				continue;
			}

			$attrs[ $lower ] = array( $attr_name, $value );
		}

		if ( isset( $attrs['class'] ) && self::$rules ) {
			self::inline_rules( $attrs['class'][1], $name, $attrs, $style );
		}

		if ( $style ) {
			$parts = array();
			foreach ( $style as $prop => $value ) {
				$parts[] = $prop . ':' . $value;
			}
			$attrs['style'] = array( 'style', implode( ';', $parts ) );
		}

		foreach ( $attrs as $lower => $pair ) {
			if ( 0 === strpos( $lower, 'xlink:' ) ) {
				self::$uses_xlink = true;
			}

			$dst->setAttribute( $pair[0], $pair[1] );
		}
	}

	//////////////////////////////////////
	// VALUE CHECKS

	/**
	 * Whether an href is a local fragment, or (on <image>) a raster data URI.
	 *
	 * @param string $value Attribute value.
	 * @param string $name  Lowercase element name.
	 * @return bool
	 */
	private static function is_safe_href( $value, $name ) {
		$value = trim( $value );

		if ( preg_match( '/^#[^\s"\'()<>&]+$/', $value ) ) {
			return true;
		}

		return 'image' === $name && (bool) preg_match( '#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/=\s]+$#i', $value );
	}

	/**
	 * Whether a non-href attribute value is free of unsafe content.
	 *
	 * @param string $attr  Lowercase attribute name.
	 * @param string $value Attribute value.
	 * @return bool
	 */
	private static function is_safe_attribute_value( $attr, $value ) {
		if ( in_array( $attr, array( 'id', 'class' ), true ) ) {
			return true;
		}

		return ! self::is_unsafe_value( $value );
	}

	/**
	 * Whether a CSS or attribute value contains anything that can run code or fetch a resource.
	 *
	 * @param string $value Value to test.
	 * @return bool
	 */
	private static function is_unsafe_value( $value ) {
		$v = preg_replace( '#/\*.*?\*/#s', '', $value );

		// Resolve CSS escapes (\75 rl) so they can't hide a keyword.
		$v = preg_replace_callback(
			'/\\\\(?:([0-9a-fA-F]{1,6})\s?|(.))/su',
			static function ( $m ) {
				if ( '' !== $m[1] ) {
					return self::utf8_chr( hexdec( $m[1] ) );
				}
				return $m[2];
			},
			$v
		);

		$v = strtolower( preg_replace( '/[\s\x00-\x1f]+/', '', $v ) );

		if ( preg_match( '/expression|javascript:|vbscript:|@import|behavior|-moz-binding/', $v ) ) {
			return true;
		}

		// Functions that can fetch a resource.
		if ( preg_match( '/(?<![a-z0-9_])(?:image-set|image|src|element|cross-fade)\(/', $v ) ) {
			return true;
		}

		// url() may only point at a local fragment.
		if ( preg_match_all( '/url\(([^)]*)\)?/', $v, $matches ) ) {
			foreach ( $matches[1] as $target ) {
				$target = trim( $target, '\'"' );

				if ( ! preg_match( '/^#[^"\'()]+$/', $target ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Encode a code point as UTF-8 without needing mbstring.
	 *
	 * @param int $cp Code point.
	 * @return string Empty for NUL, surrogates and out-of-range values.
	 */
	private static function utf8_chr( $cp ) {
		if ( $cp <= 0 || $cp > 0x10FFFF || ( $cp >= 0xD800 && $cp <= 0xDFFF ) ) {
			return '';
		}
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		if ( $cp < 0x10000 ) {
			return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}

		return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}

	//////////////////////////////////////
	// ID PREFIXING

	/**
	 * Prefix every id, and every reference to it, with aci-{hash}-.
	 *
	 * The hash is taken from the sanitized markup after any existing prefix is
	 * stripped, so re-sanitizing already-prefixed output gives identical output.
	 * References to ids that don't exist are left as they were.
	 *
	 * @param DOMDocument $out  Output document.
	 * @param DOMElement  $root Output root.
	 * @return int Number of ids prefixed.
	 */
	private static function prefix_ids( DOMDocument $out, DOMElement $root ) {
		$pattern = '/^aci-[0-9a-f]{8}-/';
		$ids     = array();
		$count   = 0;

		foreach ( ( new DOMXPath( $out ) )->query( '//@id' ) as $attr ) {
			$ids[ preg_replace( $pattern, '', $attr->value ) ] = true;
			++$count;
		}

		if ( ! $ids ) {
			return 0;
		}

		self::remap_ids(
			$out,
			static function ( $id ) use ( $ids, $pattern ) {
				$bare = preg_replace( $pattern, '', $id );
				return isset( $ids[ $bare ] ) ? $bare : $id;
			}
		);

		$prefix = 'aci-' . substr( md5( $out->saveXML( $root ) ), 0, 8 ) . '-';

		self::remap_ids(
			$out,
			static function ( $id ) use ( $ids, $prefix ) {
				return isset( $ids[ $id ] ) ? $prefix . $id : $id;
			}
		);

		return $count;
	}

	/**
	 * Run every id, #fragment href and url(#fragment) in the document through a mapper.
	 *
	 * @param DOMDocument $out Output document.
	 * @param callable    $map Receives an id, returns the id to use.
	 */
	private static function remap_ids( DOMDocument $out, callable $map ) {
		foreach ( ( new DOMXPath( $out ) )->query( '//@*' ) as $attr ) {
			$name  = strtolower( $attr->nodeName );
			$value = $attr->value;

			if ( 'id' === $name ) {
				$attr->value = $map( $value );
			} elseif ( ( 'href' === $name || 'xlink:href' === $name ) && 0 === strpos( $value, '#' ) ) {
				$attr->value = '#' . $map( substr( $value, 1 ) );
			} elseif ( false !== stripos( $value, 'url(' ) ) {
				$attr->value = preg_replace_callback(
					'/url\(\s*([\'"]?)#([^)\'"\s]+)\1\s*\)/i',
					static function ( $m ) use ( $map ) {
						return 'url(' . $m[1] . '#' . $map( $m[2] ) . $m[1] . ')';
					},
					$value
				);
			}
		}
	}

	//////////////////////////////////////
	// CSS

	/**
	 * Split a declaration list on semicolons outside parentheses and quotes.
	 *
	 * @param string $css Declarations.
	 * @return string[]
	 */
	private static function split_declarations( $css ) {
		$parts = array();
		$buf   = '';
		$depth = 0;
		$quote = '';
		$len   = strlen( $css );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $css[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $ch && $i + 1 < $len ) {
					$buf .= $ch . $css[ ++$i ];
					continue;
				}
				if ( $ch === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
			} elseif ( '(' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch && $depth > 0 ) {
				--$depth;
			} elseif ( ';' === $ch && 0 === $depth ) {
				$parts[] = $buf;
				$buf     = '';
				continue;
			}

			$buf .= $ch;
		}

		$parts[] = $buf;

		return $parts;
	}

	/**
	 * Parse declarations into prop => value, dropping invalid and unsafe ones.
	 *
	 * @param string $css     Declaration list.
	 * @param string $context Label used in the report.
	 * @return array Ordered prop => value map; later duplicates win.
	 */
	private static function clean_declarations( $css, $context ) {
		$clean = array();

		foreach ( self::split_declarations( $css ) as $declaration ) {
			$pos = strpos( $declaration, ':' );

			if ( false === $pos ) {
				continue;
			}

			$prop  = strtolower( trim( substr( $declaration, 0, $pos ) ) );
			$value = trim( preg_replace( '/!\s*important\s*$/i', '', substr( $declaration, $pos + 1 ) ) );

			if ( '' === $value || ! preg_match( '/^-?[a-z][a-z0-9-]*$/', $prop ) ) {
				continue;
			}

			if ( self::is_unsafe_value( $value ) ) {
				self::note_css( $context . ': ' . $prop );
				continue;
			}

			unset( $clean[ $prop ] );
			$clean[ $prop ] = $value;
		}

		return $clean;
	}

	/**
	 * Find every <style> element, parse its simple class rules, and report it as inlined.
	 *
	 * @param DOMDocument $dom Source document.
	 */
	private static function collect_styles( DOMDocument $dom ) {
		$styles = array();

		foreach ( $dom->getElementsByTagName( 'style' ) as $style ) {
			$styles[] = $style;
		}

		foreach ( $styles as $style ) {
			if ( ! self::is_svg_namespace( $style ) ) {
				continue;
			}

			++self::$report['style_blocks'];

			$type = strtolower( trim( $style->getAttribute( 'type' ) ) );

			if ( '' !== $type && 'text/css' !== $type ) {
				self::note_css( 'style type ' . $type );
				continue;
			}

			self::parse_stylesheet( $style->textContent );
		}
	}

	/**
	 * Parse a stylesheet, keeping only rules whose selectors are single classes.
	 *
	 * @param string $css Stylesheet text.
	 */
	private static function parse_stylesheet( $css ) {
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$len = strlen( $css );
		$i   = 0;

		while ( $i < $len ) {
			if ( ctype_space( $css[ $i ] ) ) {
				++$i;
				continue;
			}

			$brace = strpos( $css, '{', $i );
			$semi  = strpos( $css, ';', $i );

			// At-rule without a block, e.g. @import.
			if ( '@' === $css[ $i ] && false !== $semi && ( false === $brace || $semi < $brace ) ) {
				self::note_css( substr( $css, $i, $semi - $i ) );
				$i = $semi + 1;
				continue;
			}

			if ( false === $brace ) {
				break;
			}

			$selector = trim( substr( $css, $i, $brace - $i ) );

			// Find the matching close brace.
			$depth = 1;
			$j     = $brace + 1;

			while ( $j < $len && $depth > 0 ) {
				if ( '{' === $css[ $j ] ) {
					++$depth;
				} elseif ( '}' === $css[ $j ] ) {
					--$depth;
				}
				++$j;
			}

			$body = substr( $css, $brace + 1, $j - $brace - 2 );
			$i    = $j;

			if ( 0 === strpos( $selector, '@' ) ) {
				self::note_css( $selector );
				continue;
			}

			$classes = array();

			foreach ( explode( ',', $selector ) as $part ) {
				$part = trim( $part );

				if ( preg_match( '/^\.(-?[_a-zA-Z][\w-]*)$/', $part, $m ) ) {
					$classes[] = $m[1];
				} elseif ( '' !== $part ) {
					self::note_css( $part );
				}
			}

			if ( $classes ) {
				self::$rules[] = array(
					'classes' => $classes,
					'decls'   => self::clean_declarations( $body, $selector ),
				);
			}
		}
	}

	/**
	 * Apply matching class rules to an element's attributes and style.
	 *
	 * Class rules beat presentation attributes but lose to the inline style attribute.
	 *
	 * @param string $class_attr Element class attribute value.
	 * @param string $name       Lowercase element name.
	 * @param array  $attrs      Attributes being built (by reference).
	 * @param array  $style      Inline style declarations (by reference).
	 */
	private static function inline_rules( $class_attr, $name, array &$attrs, array &$style ) {
		$classes = preg_split( '/\s+/', trim( $class_attr ), -1, PREG_SPLIT_NO_EMPTY );
		$props   = array();

		foreach ( self::$rules as $index => $rule ) {
			if ( ! array_intersect( $rule['classes'], $classes ) ) {
				continue;
			}

			self::$rules_used[ $index ] = true;

			foreach ( $rule['decls'] as $prop => $value ) {
				$props[ $prop ] = $value;
			}
		}

		$allowed = self::$allowed[ $name ];

		foreach ( $props as $prop => $value ) {
			if ( isset( $style[ $prop ] ) ) {
				continue;
			}

			if ( in_array( $prop, self::$presentation, true ) && isset( $allowed[ $prop ] ) ) {
				$attrs[ $prop ] = array( $attrs[ $prop ][0] ?? $prop, $value );
			} elseif ( isset( $allowed['style'] ) ) {
				$style[ $prop ] = $value;
			}
		}
	}
}
