<?php

namespace Listenability;

/**
 * @todo Merge this class into Speechification
 */
class Presentation {

	const TEXT_CONTENT_TYPE = 'text/plain+embedded-speech-commands';

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * @param Plugin $plugin
	 */
	function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_filter( 'template_include', array( $this, 'filter_template_include' ) );
	}

	/**
	 * Override the template for the TTS text representations.
	 *
	 * @param $template
	 * @return string
	 */
	function filter_template_include( $template ) {
		if ( is_singular( $this->plugin->config['post_type'] ) && 'nope' !== get_query_var( Plugin::QUERY_VAR, 'nope' ) ) {
			$template = __DIR__ . '/template-singular.php';
		}
		return $template;
	}

	/**
	 * Prepare the listenability content for the day command.
	 *
	 * @param array $options
	 */
	function the_content( $options = array() ) {
		$options = wp_parse_args( $options, array(
			'strip_embedded_speech_commands' => false,
		) );

		$readability_parser_response = get_post_meta( get_the_ID(), 'readability_parser_response', true );
		if ( $readability_parser_response ) {
			$readability_parser_response = json_decode( $readability_parser_response, true );
		}
		if ( ! empty( $readability_parser_response['url'] ) ) {
			$url = $readability_parser_response['url'];
		} else {
			$url = get_permalink();
		}

		ob_start();
		the_content();
		$content = ob_get_clean();

		$whitespace_normalized_content = preg_replace( '/\s+/', ' ', preg_replace( '/(?<=>)\s+(?=<)/', '', $content ) );

		/**
		 * Final text replacement before rendering the listenability text.
		 *
		 * @param string $whitespace_normalized_content
		 * @param array $args {
		 *     @type string       $url
		 *     @type string       $content
		 *     @type array|null   $readability_parser_response
		 * }
		 */
		$content = apply_filters( 'listenability_content', $whitespace_normalized_content, compact( 'content', 'readability_parser_response' ) );

		$document = new \DOMDocument();
		$document->preserveWhiteSpace = false;
		$document->formatOutput = false;
		$html = sprintf( '<html><meta charset="%s"><body>%s</body></html>', esc_attr( get_bloginfo( 'charset' ) ), $content );
		// @codingStandardsIgnoreStart
		@$document->loadHTML( $html );
		// @codingStandardsIgnoreEnd
		$xpath = new \DOMXPath( $document );

		/**
		 * Strip out elements from Wikipedia articles.
		 *
		 * @param array $args {
		 *     @type \DOMDocument $document
		 *     @type \DOMXPath    $xpath
		 *     @type string       $url
		 *     @type array|null   $readability_parser_response
		 * }
		 */
		do_action( 'listenability_document', compact( 'document', 'xpath', 'url', 'readability_parser_response' ) );

		// Make sure each list item ends in a sentence termination marker.
		foreach ( $xpath->query( '//li' ) as $li ) {
			/** @var \DOMElement $li */
			if ( ! preg_match( '/[,;\.\?!]\s*$/', $li->textContent ) ) {
				$li->appendChild( $document->createTextNode( '.' ) );
			}
		}
		foreach ( $xpath->query( '//h1 | //h2 | //h3 | //h4 | //h5 | //h6' ) as $heading ) {
			/** @var \DOMElement $heading */
			$heading->insertBefore( $document->createTextNode( '[[slnc 200]]' ), $heading->firstChild );
			$heading->appendChild( $document->createTextNode( '[[slnc 200]]' ) );
		}
		foreach ( $xpath->query( '//p | //h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //ul | //ol' ) as $p ) {
			/** @var \DOMElement $p */
			$p->insertBefore( $document->createTextNode( "\n\n" ), $p->firstChild );
			$p->appendChild( $document->createTextNode( "\n\n" ) );
		}
		foreach ( $xpath->query( '//ul/li' ) as $li ) {
			/** @var \DOMElement $li */
			// @todo Use something like this instead of asterisk? $bullet = html_entity_decode( '&bull;', ENT_QUOTES, get_bloginfo( 'charset' ) );
			$li->insertBefore( $document->createTextNode( ' - ' ), $li->firstChild );
			$li->appendChild( $document->createTextNode( "\n" ) );
		}
		foreach ( $xpath->query( '//ol' ) as $ol ) {
			/** @var \DOMElement $ol */
			$i = 0;
			foreach ( $xpath->query( './li', $ol ) as $li ) {
				/** @var \DOMElement $li */
				$i += 1;
				$li->insertBefore( $document->createTextNode( " $i. " ), $li->firstChild );
			}
		}
		foreach ( $xpath->query( '//br' ) as $br ) {
			/** @var \DOMElement $br */
			$br->parentNode->appendChild( $document->createTextNode( "\n" ) );
		}
		foreach ( $xpath->query( '//hr' ) as $hr ) {
			/** @var \DOMElement $hr */
			$hr->appendChild( $document->createTextNode( "\n\n[[slnc 1000]]---------------------\n\n" ) );
		}
		foreach ( $xpath->query( '//abbr[@title]' ) as $abbr ) {
			/** @var \DOMElement $abbr */
			$abbr->appendChild( $document->createTextNode( sprintf( ' (%s)', $abbr->getAttribute( 'title' ) ) ) );
		}
		foreach ( $xpath->query( '//strong | //em | //b | //i' ) as $el ) {
			/** @var \DOMElement $el */
			$el->insertBefore( $document->createTextNode( '[[emph +]]' ), $el->firstChild );
			$el->appendChild( $document->createTextNode( '[[emph -]]' ) );
		}
		foreach ( $xpath->query( '//blockquote' ) as $li ) {
			/** @var \DOMElement $li */
			$li->appendChild( $document->createTextNode( "\n" ) );
		}
		foreach ( $xpath->query( '//audio' ) as $audio ) {
			/** @var \DOMElement $audio */
			$audio->parentNode->removeChild( $audio );
		}

		$element_names = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'ul', 'ol', 'blockquote', 'q', 'abbr', 'strong', 'em', 'i', 'b', 'hr' );
		$element_id = 0;
		foreach ( $xpath->query( '//' . join( ' | //', $element_names ) ) as $heading ) {
			/** @var \DOMElement $heading */
			$element_id += 1;
			$prefix = "[[cmnt tag:{$heading->nodeName}#$element_id]]";
			$heading->insertBefore( $document->createTextNode( $prefix ), $heading->firstChild );
			$suffix = "[[cmnt tag:/{$heading->nodeName}#$element_id]]";
			$heading->appendChild( $document->createTextNode( $suffix ) );
		}

		$text_content = $document->textContent;

		if ( $options['strip_embedded_speech_commands'] ) {
			$text_content = preg_replace( '/\[\[\w+.*?\]\]/', '', $text_content );
		}

		$text_content = preg_replace( "/\n +/", "\n", $text_content );
		$text_content = preg_replace( "/\n\n\n+/", "\n\n", $text_content );
		$text_content = trim( $text_content );

		/**
		 * Final text replacement before rendering the listenability text.
		 *
		 * @param string $text_content
		 * @param array $args {
		 *     @type \DOMDocument $document
		 *     @type \DOMXPath    $xpath
		 *     @type string       $url
		 *     @type array|null   $readability_parser_response
		 * }
		 */
		$text_content = apply_filters( 'listenability_text_content', $text_content, compact( 'document', 'xpath', 'url', 'readability_parser_response' ) );

		echo $text_content;
	}
}
