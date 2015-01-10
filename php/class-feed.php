<?php

namespace Listenability;

class Feed {

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * @param Plugin $plugin
	 */
	function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'rss2_ns', function () {
			echo 'xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" ';
			echo 'xmlns:listenability="http://listenability.org/ns" ';
		} );
		add_action( 'rss2_head', array( $this, 'render_rss2_head' ) );
		add_action( 'rss2_item', array( $this, 'add_rss2_item_elements' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_podcast_feed_items' ) );
	}

	/**
	 * Ensure that only posts that have audio enclosures are returned in the feed by default,
	 * unless explicitly asking for something else.
	 *
	 * @param \WP_Query $query
	 * @action pre_get_posts
	 */
	function filter_podcast_feed_items( $query ) {
		if ( ! $query->is_feed() || ! $query->is_main_query() ) {
			return;
		}
		$is_valid_post_type = (
			( 'post' === $this->plugin->config['post_type'] && ! $query->get( 'post_type' ) )
			||
			in_array( $this->plugin->config['post_type'], (array) $query->get( 'post_type' ) )
		);
		if ( ! $is_valid_post_type ) {
			return;
		}

		if ( ! $query->get( Status_Taxonomy::TAXONOMY_SLUG ) ) {
			$query->set( Status_Taxonomy::TAXONOMY_SLUG, 'has-audio-enclosure' );
		}
	}

	/**
	 * Add elements to the RSS2 head.
	 *
	 * @action rss2_head
	 */
	function render_rss2_head() {
		if ( is_author() ) {
			printf( '<itunes:author>%s</itunes:author>', esc_html( get_queried_object()->display_name ) );
		}
		$header_image = get_header_image();
		if ( $header_image ) {
			printf( '<itunes:image href="%s" />', esc_url( $header_image ) );
		}
	}

	/**
	 * Add link to TTS text enclosure.
	 *
	 * @action rss2_item
	 */
	function add_rss2_item_elements() {
		if ( get_post_type() !== $this->plugin->config['post_type'] ) {
			return;
		}
		$post = get_post();

		if ( has_post_thumbnail() ) {
			$img = wp_get_attachment_image_src( get_post_thumbnail_id(), 'large' );
			printf( '<itunes:image href="%s" />', esc_url( $img[0] ) );
		}

		$url = get_permalink();
		$url_parts = explode( '?', $url );
		$listen_endpoint = user_trailingslashit( trailingslashit( $url_parts[0] ) . Plugin::ENDPOINT_SLUG, 'single' );
		$url = $listen_endpoint;
		if ( ! empty( $url_parts[1] ) ) {
			$url .= '?' . $url_parts[1];
		}
		printf( '<enclosure url="%s" type="%s" />' . "\n", $url, Presentation::TEXT_CONTENT_TYPE );

		$url = user_trailingslashit( trailingslashit( $listen_endpoint ) . 'audio', 'single' );
		if ( ! empty( $url_parts[1] ) ) {
			$url .= '?' . $url_parts[1];
		}

		printf( '<listenability:audio href="%s" />' . "\n", $url, Presentation::TEXT_CONTENT_TYPE );

		$audio_attachments = get_attached_media( 'audio' );
		$audio_attachment = array_shift( $audio_attachments );
		if ( $audio_attachment ) {

			require_once( ABSPATH . 'wp-admin/includes/media.php' );
			require_once( ABSPATH . 'wp-admin/includes/image.php' );
			$audio_metadata = wp_get_attachment_metadata( $audio_attachment->ID );
			if ( empty( $audio_metadata ) ) {
				$audio_metadata = wp_generate_attachment_metadata( $audio_attachment->ID, get_attached_file( $audio_attachment->ID ) );
				wp_update_attachment_metadata( $audio_attachment->ID, $audio_metadata );
			}

			if ( ! empty( $audio_metadata['length_formatted'] ) ) {
				printf( '<itunes:duration>%s</itunes:duration>', esc_attr( $audio_metadata['length_formatted'] ) );
			}
		}

		if ( $post->post_author ) {
			printf( '<itunes:author>%s</itunes:author>', esc_attr( get_the_author_meta( 'display_name', $post->post_author ) ) );
		}
	}
}
