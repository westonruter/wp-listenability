<?php

namespace Listenability;

class Status_Taxonomy {

	const TAXONOMY_SLUG = 'listenability_status';

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * @param Plugin $plugin
	 */
	function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'save_post', array( $this, 'update_status' ) );
		add_action( 'added_post_meta', array( $this, 'added_enclosure_post_meta' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'deleted_enclosure_post_meta' ), 10, 4 );
	}

	/**
	 * Register the status taxonomy.
	 *
	 * @action init
	 */
	function register_taxonomy() {
		register_taxonomy( static::TAXONOMY_SLUG, $this->plugin->config['post_type'], array(
			'labels'         => array( 'name' => __( 'Listenability Status', 'listenability' ) ),
			'public'         => true, // @todo false
			'hierarchical'   => false,
		) );
	}

	/**
	 * Update the initial taxonomy status before the enclosures get processed.
	 *
	 * @param int $post_id
	 * @action save_post
	 */
	function update_status( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== $this->plugin->config['post_type'] || 'publish' !== $post->post_status ) {
			return;
		}

		// Abort during autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( count( $this->get_mp3_enclosure_urls( $post->ID ) ) === 0 ) {
			// @todo We need to add these terms initially upon plugin activation.
			wp_remove_object_terms( $post->ID, 'has-audio-enclosure', static::TAXONOMY_SLUG );
			wp_add_object_terms( $post->ID, 'needs-audio-enclosure', static::TAXONOMY_SLUG );
		} else {
			// @todo This will be unnecessary when the plugin upon activation ensures the proper status is assigned.
			wp_remove_object_terms( $post->ID, 'needs-audio-enclosure', static::TAXONOMY_SLUG );
			wp_add_object_terms( $post->ID, 'has-audio-enclosure', static::TAXONOMY_SLUG );
		}
	}

	/**
	 * Add the status taxonomy term when an MP3 enclosure is added.
	 *
	 * @param $mid
	 * @param int $object_id
	 * @param string $meta_key
	 * @param string $_meta_value
	 */
	function added_enclosure_post_meta( $mid, $object_id, $meta_key, $_meta_value ) {
		unset( $mid );
		if ( 'enclosure' !== $meta_key ) {
			return;
		}
		$post = get_post( $object_id );
		if ( ! $post || $post->post_type !== $this->plugin->config['post_type'] ) {
			return;
		}
		$enclosure_parts = explode( "\n", trim( $_meta_value ) );

		$mime = array_pop( $enclosure_parts );
		$is_mp3 = ( 'audio/mp3' === $mime || 'audio/mpeg' === $mime );
		if ( $is_mp3 ) {
			// @todo We need to add these terms initially upon plugin activation.
			wp_remove_object_terms( $post->ID, 'needs-audio-enclosure', static::TAXONOMY_SLUG );
			wp_add_object_terms( $post->ID, 'has-audio-enclosure', static::TAXONOMY_SLUG );
		}
	}

	/**
	 * Update the status taxonomy term when an MP3 enclosure is removed.
	 *
	 * @param $meta_ids
	 * @param int $object_id
	 * @param string $meta_key
	 * @param string $_meta_value
	 */
	function deleted_enclosure_post_meta( $meta_ids, $object_id, $meta_key, $_meta_value ) {
		unset( $meta_ids );
		if ( 'enclosure' !== $meta_key ) {
			return;
		}
		$post = get_post( $object_id );
		if ( ! $post || $post->post_type !== $this->plugin->config['post_type'] ) {
			return;
		}
		$enclosure_parts = explode( "\n", trim( $_meta_value ) );
		$mime = array_pop( $enclosure_parts );
		$is_mp3 = ( 'audio/mp3' === $mime || 'audio/mpeg' === $mime );
		if ( ! $is_mp3 ) {
			return;
		}

		if ( count( $this->get_mp3_enclosure_urls( $post->ID ) ) === 0 ) {
			// @todo We need to add these terms initially upon plugin activation.
			wp_remove_object_terms( $post->ID, 'has-audio-enclosure', static::TAXONOMY_SLUG );
			wp_add_object_terms( $post->ID, 'needs-audio-enclosure', static::TAXONOMY_SLUG );
		} else {
			// @todo This will be unnecessary when the plugin upon activation ensures the proper status is assigned.
			wp_remove_object_terms( $post->ID, 'needs-audio-enclosure', static::TAXONOMY_SLUG );
			wp_add_object_terms( $post->ID, 'has-audio-enclosure', static::TAXONOMY_SLUG );
		}
	}

	/**
	 * Get an array of the mp3 enclosures on a post.
	 *
	 * @param int $post_id
	 *
	 * @return array
	 */
	function get_mp3_enclosure_urls( $post_id ) {
		$mp3_enclosures = array();
		$enclosures = get_post_meta( $post_id, 'enclosure', false );
		foreach ( $enclosures as $enclosure ) {
			$enclosure_parts = explode( "\n", trim( $enclosure ) );
			$mime = array_pop( $enclosure_parts );
			if ( 'audio/mp3' === $mime || 'audio/mpeg' === $mime ) {
				$mp3_enclosures[] = array_shift( $enclosure_parts );
			}
		}
		return $mp3_enclosures;
	}
}
