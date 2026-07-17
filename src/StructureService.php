<?php
namespace BricksCodeStudio;

final class StructureService {
	private const SUPPORTED = [ 'section', 'container', 'block', 'div', 'heading', 'text-basic', 'text', 'text-link', 'button', 'image', 'html' ];
	private const LEAF      = [ 'heading', 'text-basic', 'text', 'text-link', 'button', 'image', 'html' ];

	public function projection( int $post_id ) {
		$result = Ability::run( 'bricks/get-page-elements', [ 'postId' => $post_id ] );
		if ( is_wp_error( $result ) ) { return $result; }
		$elements = $result['elements'] ?? [];
		return [
			'postId'       => $post_id,
			'html'         => $this->serialize( $elements ),
			'treeHash'     => Support::canonical_json_hash( $elements ),
			'protectedIds' => array_values( array_map( static function ( $el ) { return $el['id']; }, array_filter( $elements, [ $this, 'is_protected' ] ) ) ),
		];
	}

	public function preview( int $post_id, string $html, string $base_hash ) {
		$merged = $this->merge( $post_id, $html, $base_hash );
		if ( is_wp_error( $merged ) ) { return $merged; }
		$rendered = Ability::run( 'bricks/render-elements', [ 'postId' => $post_id, 'elements' => $merged['elements'] ] );
		if ( is_wp_error( $rendered ) ) { return $rendered; }
		return [
			'treeHash' => $base_hash,
			'diff'     => $merged['diff'],
			'preview'  => $rendered,
			'warnings' => $merged['warnings'],
		];
	}

	public function apply( int $post_id, string $html, string $base_hash, bool $confirmed ) {
		if ( ! $confirmed ) {
			return Support::error( 'bcs_confirmation_required', __( 'Review and confirm the structure diff before applying it.', 'bricks-code-studio' ), 409 );
		}
		$merged = $this->merge( $post_id, $html, $base_hash );
		if ( is_wp_error( $merged ) ) { return $merged; }

		$saved = Ability::run( 'bricks/set-page-elements', [ 'postId' => $post_id, 'elements' => $merged['elements'] ] );
		if ( is_wp_error( $saved ) ) { return $saved; }
		$readback  = Ability::run( 'bricks/get-page-elements', [ 'postId' => $post_id ] );
		$revisions = Ability::run( 'bricks/list-revisions', [ 'postId' => $post_id, 'limit' => 5 ] );
		if ( is_wp_error( $readback ) ) { return $readback; }

		return [
			'revisionId' => $saved['revisionId'] ?? null,
			'elementCount' => $saved['elementCount'] ?? count( $merged['elements'] ),
			'treeHash' => Support::canonical_json_hash( $readback['elements'] ?? [] ),
			'diff' => $merged['diff'],
			'revisionVerified' => ! is_wp_error( $revisions ),
		];
	}

	public function restore( int $post_id, int $revision_id ) {
		$result = Ability::run( 'bricks/restore-revision', [ 'postId' => $post_id, 'revisionId' => $revision_id ] );
		if ( is_wp_error( $result ) ) { return $result; }
		$readback = Ability::run( 'bricks/get-page-elements', [ 'postId' => $post_id ] );
		return [
			'restored' => true,
			'revisionId' => $revision_id,
			'treeHash' => is_wp_error( $readback ) ? '' : Support::canonical_json_hash( $readback['elements'] ?? [] ),
		];
	}

	private function merge( int $post_id, string $html, string $base_hash ) {
		if ( strlen( $html ) > Support::MAX_CONVERSION_BYTES ) {
			return Support::error( 'bcs_structure_too_large', __( 'HTML structure is limited to 2 MB.', 'bricks-code-studio' ), 413 );
		}
		$current = Ability::run( 'bricks/get-page-elements', [ 'postId' => $post_id ] );
		if ( is_wp_error( $current ) ) { return $current; }
		$original = $current['elements'] ?? [];
		$current_hash = Support::canonical_json_hash( $original );
		if ( ! $base_hash || ! hash_equals( $current_hash, $base_hash ) ) {
			return Support::error( 'bcs_stale_structure', __( 'The Bricks tree changed after this HTML view was generated. Regenerate the view before applying.', 'bricks-code-studio' ), 409, [ 'treeHash' => $current_hash ] );
		}

		$dom = new \DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?><div id="bcs-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		if ( ! $loaded ) {
			return Support::error( 'bcs_invalid_html', __( 'The HTML could not be parsed.', 'bricks-code-studio' ), 422 );
		}
		$root = $dom->getElementById( 'bcs-root' );
		if ( ! $root ) { return Support::error( 'bcs_invalid_html', __( 'The HTML root is missing.', 'bricks-code-studio' ), 422 ); }

		$index = [];
		foreach ( $original as $element ) { if ( ! empty( $element['id'] ) ) { $index[ $element['id'] ] = $element; } }
		$seen = [];
		$out = [];
		$warnings = [];
		foreach ( iterator_to_array( $root->childNodes ) as $node ) {
			if ( $node instanceof \DOMElement ) {
				$built = $this->build_node( $node, 0, $index, $seen, $post_id, $warnings );
				if ( is_wp_error( $built ) ) { return $built; }
				$out = array_merge( $out, $built );
			}
		}

		$ids = array_column( $out, 'id' );
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			return Support::error( 'bcs_duplicate_element_id', __( 'The HTML contains the same Bricks element more than once.', 'bricks-code-studio' ), 422 );
		}

		return [ 'elements' => $out, 'diff' => $this->diff( $original, $out ), 'warnings' => $warnings ];
	}

	private function build_node( \DOMElement $node, $parent, array $index, array &$seen, int $post_id, array &$warnings ) {
		$id = sanitize_key( $node->getAttribute( 'data-bcs-id' ) );
		if ( $id && isset( $seen[ $id ] ) ) {
			return Support::error( 'bcs_duplicate_element_id', __( 'A Bricks element may only appear once in the HTML view.', 'bricks-code-studio' ), 422 );
		}

		if ( $id && isset( $index[ $id ] ) && $this->is_protected( $index[ $id ] ) ) {
			$allowed_attributes = [ 'data-bcs-id', 'data-bcs-name', 'data-bcs-protected' ];
			$actual_attributes = [];
			foreach ( $node->attributes as $attribute ) { $actual_attributes[] = $attribute->name; }
			sort( $allowed_attributes );
			sort( $actual_attributes );
			$metadata_changed = strtolower( $node->tagName ) !== 'bricks-node'
				|| $actual_attributes !== $allowed_attributes
				|| $node->getAttribute( 'data-bcs-name' ) !== (string) ( $index[ $id ]['name'] ?? '' )
				|| $node->getAttribute( 'data-bcs-protected' ) !== 'true';
			if ( $metadata_changed || trim( $node->textContent ) !== '' || $this->element_child_count( $node ) > 0 ) {
				return Support::error( 'bcs_protected_node_modified', sprintf( __( 'Protected element %s cannot be edited internally.', 'bricks-code-studio' ), $id ), 422 );
			}
			$seen[ $id ] = true;
			return $this->preserved_subtree( $id, $parent, $index, $seen );
		}

		if ( $id && isset( $index[ $id ] ) ) {
			$seen[ $id ] = true;
			$element = $index[ $id ];
			$element['parent'] = $parent;
			$element['settings'] = $this->update_settings_from_node( $element, $node );
			$children = [];
			$descendants = [];
			if ( ! in_array( $element['name'], self::LEAF, true ) ) {
				foreach ( iterator_to_array( $node->childNodes ) as $child ) {
					if ( ! $child instanceof \DOMElement ) { continue; }
					$child_elements = $this->build_node( $child, $id, $index, $seen, $post_id, $warnings );
					if ( is_wp_error( $child_elements ) ) { return $child_elements; }
					if ( ! empty( $child_elements[0]['id'] ) ) { $children[] = $child_elements[0]['id']; }
					$descendants = array_merge( $descendants, $child_elements );
				}
			}
			$element['children'] = $children;
			return array_merge( [ $element ], $descendants );
		}

		$outer = $node->ownerDocument->saveHTML( $node );
		if ( strpos( $outer, 'data-bcs-id=' ) === false ) {
			$converted = Ability::run(
				'bricks/convert-html-css-to-bricks-data',
				[
					'html' => $outer,
					'postId' => $post_id,
					'options' => [ 'create_global_classes' => false, 'extract_variables' => false ],
				]
			);
			if ( ! is_wp_error( $converted ) && ! empty( $converted['elements'] ) ) {
				$converted_elements = $converted['elements'];
				foreach ( $converted_elements as &$converted_element ) {
					if ( empty( $converted_element['parent'] ) ) { $converted_element['parent'] = $parent; }
					$seen[ $converted_element['id'] ] = true;
				}
				unset( $converted_element );
				$warnings = array_merge( $warnings, $converted['warnings'] ?? [] );
				return $converted_elements;
			}
		}

		$new = $this->new_element_from_node( $node, $parent );
		$seen[ $new['id'] ] = true;
		$children = [];
		$descendants = [];
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( ! $child instanceof \DOMElement ) { continue; }
			$child_elements = $this->build_node( $child, $new['id'], $index, $seen, $post_id, $warnings );
			if ( is_wp_error( $child_elements ) ) { return $child_elements; }
			if ( ! empty( $child_elements[0]['id'] ) ) { $children[] = $child_elements[0]['id']; }
			$descendants = array_merge( $descendants, $child_elements );
		}
		$new['children'] = $children;
		return array_merge( [ $new ], $descendants );
	}

	private function preserved_subtree( string $id, $parent, array $index, array &$seen ): array {
		$root = $index[ $id ];
		$root['parent'] = $parent;
		$out = [ $root ];
		$queue = $root['children'] ?? [];
		while ( $queue ) {
			$child_id = array_shift( $queue );
			if ( ! isset( $index[ $child_id ] ) ) { continue; }
			$seen[ $child_id ] = true;
			$out[] = $index[ $child_id ];
			$queue = array_merge( $queue, $index[ $child_id ]['children'] ?? [] );
		}
		return $out;
	}

	private function serialize( array $elements ): string {
		$index = [];
		foreach ( $elements as $element ) { if ( ! empty( $element['id'] ) ) { $index[ $element['id'] ] = $element; } }
		$html = [];
		foreach ( $elements as $element ) {
			if ( empty( $element['parent'] ) || ! isset( $index[ $element['parent'] ] ) ) {
				$html[] = $this->serialize_element( $element, $index, 0 );
			}
		}
		return implode( "\n", $html );
	}

	private function serialize_element( array $element, array $index, int $depth ): string {
		$indent = str_repeat( '  ', $depth );
		$id = esc_attr( $element['id'] ?? '' );
		$name = esc_attr( $element['name'] ?? 'unknown' );
		if ( $this->is_protected( $element ) ) {
			return sprintf( '%s<bricks-node data-bcs-id="%s" data-bcs-name="%s" data-bcs-protected="true"></bricks-node>', $indent, $id, $name );
		}
		$tag = $this->tag_for( $element );
		$classes = trim( (string) ( $element['settings']['_cssClasses'] ?? '' ) );
		$attrs = sprintf( ' data-bcs-id="%s" data-bcs-name="%s"', $id, $name );
		if ( $classes ) { $attrs .= ' class="' . esc_attr( $classes ) . '"'; }
		if ( $element['name'] === 'image' ) {
			$src = $element['settings']['image']['url'] ?? '';
			return sprintf( '%s<img%s src="%s" />', $indent, $attrs, esc_url( $src ) );
		}
		$content = $this->content_for( $element );
		if ( ! in_array( $element['name'], self::LEAF, true ) ) {
			$children = [];
			foreach ( $element['children'] ?? [] as $child_id ) {
				if ( isset( $index[ $child_id ] ) ) { $children[] = $this->serialize_element( $index[ $child_id ], $index, $depth + 1 ); }
			}
			$content = $children ? "\n" . implode( "\n", $children ) . "\n" . $indent : '';
		}
		return sprintf( '%s<%s%s>%s</%s>', $indent, $tag, $attrs, $content, $tag );
	}

	public function is_protected( array $element ): bool {
		$settings = $element['settings'] ?? [];
		return ! in_array( $element['name'] ?? '', self::SUPPORTED, true )
			|| ! empty( $element['cid'] ) || ! empty( $element['properties'] ) || ! empty( $element['slotChildren'] )
			|| ! empty( $settings['hasLoop'] ) || ! empty( $settings['query'] ) || ! empty( $settings['_conditions'] ) || ! empty( $settings['_interactions'] )
			|| $this->contains_dynamic_data( $settings );
	}

	private function contains_dynamic_data( $value ): bool {
		if ( is_string( $value ) ) { return (bool) preg_match( '/\{[a-zA-Z_][^}]*\}/', $value ); }
		if ( is_array( $value ) ) { foreach ( $value as $item ) { if ( $this->contains_dynamic_data( $item ) ) { return true; } } }
		return false;
	}

	private function tag_for( array $element ): string {
		$settings = $element['settings'] ?? [];
		if ( ! empty( $settings['tag'] ) && preg_match( '/^[a-z][a-z0-9-]*$/i', $settings['tag'] ) ) { return strtolower( $settings['tag'] ); }
		$map = [ 'section' => 'section', 'heading' => 'h2', 'text-basic' => 'p', 'text' => 'div', 'text-link' => 'a', 'button' => 'button', 'html' => 'div' ];
		return $map[ $element['name'] ?? '' ] ?? 'div';
	}

	private function content_for( array $element ): string {
		$settings = $element['settings'] ?? [];
		if ( $element['name'] === 'html' ) { return (string) ( $settings['html'] ?? '' ); }
		return (string) ( $settings['text'] ?? '' );
	}

	private function update_settings_from_node( array $element, \DOMElement $node ): array {
		$settings = $element['settings'] ?? [];
		$classes = trim( preg_replace( '/\s+/', ' ', $node->getAttribute( 'class' ) ) );
		if ( $classes ) { $settings['_cssClasses'] = $classes; } else { unset( $settings['_cssClasses'] ); }
		if ( in_array( $element['name'], [ 'heading', 'text-basic', 'text', 'text-link', 'button' ], true ) ) {
			$settings['text'] = $this->inner_html( $node );
		}
		if ( $element['name'] === 'html' ) { $settings['html'] = $this->inner_html( $node ); }
		if ( $element['name'] === 'image' && $node->hasAttribute( 'src' ) ) {
			$settings['image'] = is_array( $settings['image'] ?? null ) ? $settings['image'] : [];
			$settings['image']['url'] = esc_url_raw( $node->getAttribute( 'src' ) );
		}
		$tag = strtolower( $node->tagName );
		if ( ! in_array( $tag, [ 'bricks-node', 'img' ], true ) ) {
			$original_tag = $this->tag_for( $element );
			if ( $tag !== $original_tag ) {
				$settings['tag'] = $tag;
			} elseif ( ! array_key_exists( 'tag', $element['settings'] ?? [] ) ) {
				unset( $settings['tag'] );
			}
		}
		return $settings;
	}

	private function new_element_from_node( \DOMElement $node, $parent ): array {
		$tag = strtolower( $node->tagName );
		$name = 'div';
		if ( in_array( $tag, [ 'section', 'header', 'footer', 'article', 'aside' ], true ) ) { $name = 'section'; }
		elseif ( preg_match( '/^h[1-6]$/', $tag ) ) { $name = 'heading'; }
		elseif ( in_array( $tag, [ 'p', 'label', 'span' ], true ) ) { $name = 'text-basic'; }
		elseif ( $tag === 'a' ) { $name = 'text-link'; }
		elseif ( $tag === 'button' ) { $name = 'button'; }
		elseif ( $tag === 'img' ) { $name = 'image'; }
		elseif ( strpos( ' ' . $node->getAttribute( 'class' ) . ' ', ' brxe-container ' ) !== false ) { $name = 'container'; }
		elseif ( strpos( ' ' . $node->getAttribute( 'class' ) . ' ', ' brxe-block ' ) !== false ) { $name = 'block'; }
		$element = [ 'id' => Support::random_id(), 'name' => $name, 'parent' => $parent, 'children' => [], 'settings' => [] ];
		return array_merge( $element, [ 'settings' => $this->update_settings_from_node( $element, $node ) ] );
	}

	private function diff( array $before, array $after ): array {
		$a = []; $b = [];
		foreach ( $before as $el ) { $a[ $el['id'] ] = $el; }
		foreach ( $after as $el ) { $b[ $el['id'] ] = $el; }
		$added = array_values( array_diff( array_keys( $b ), array_keys( $a ) ) );
		$removed = array_values( array_diff( array_keys( $a ), array_keys( $b ) ) );
		$moved = []; $updated = [];
		foreach ( array_intersect( array_keys( $a ), array_keys( $b ) ) as $id ) {
			if ( ( $a[ $id ]['parent'] ?? 0 ) !== ( $b[ $id ]['parent'] ?? 0 ) || ( $a[ $id ]['children'] ?? [] ) !== ( $b[ $id ]['children'] ?? [] ) ) { $moved[] = $id; }
			if ( Support::canonical_json_hash( $a[ $id ]['settings'] ?? [] ) !== Support::canonical_json_hash( $b[ $id ]['settings'] ?? [] ) ) { $updated[] = $id; }
		}
		return [ 'added' => $added, 'removed' => $removed, 'moved' => $moved, 'updated' => $updated, 'destructive' => ! empty( $removed ) ];
	}

	private function inner_html( \DOMElement $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) { $html .= $node->ownerDocument->saveHTML( $child ); }
		return $html;
	}

	private function element_child_count( \DOMElement $node ): int {
		$count = 0;
		foreach ( $node->childNodes as $child ) { if ( $child instanceof \DOMElement ) { $count++; } }
		return $count;
	}
}
