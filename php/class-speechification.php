<?php

namespace Listenability;

class Speechification {

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * @param Plugin $plugin
	 */
	function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'template_redirect', array( $this, 'handle_audio_request' ) );
	}

	/**
	 * Handle audio requests for Listenability.
	 */
	function handle_audio_request() {
		if ( 'audio' !== get_query_var( Plugin::QUERY_VAR ) ) {
			return;
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$this->receive_audio_upload();
		} else {
			$this->send_audio_response();
		}
		exit;
	}

	/**
	 * Handle OS X uploading the TTS audio from the say command output.
	 */
	function receive_audio_upload() {
		if ( ! is_singular( $this->plugin->config['post_type'] ) ) {
			status_header( 400 );
			wp_send_json_error( __( 'Cannot attach audio to this object.', 'listenability' ) );
		}
		if ( empty( $_REQUEST['audio_upload_key'] ) ) {
			status_header( 400 );
			wp_send_json_error( __( 'Missing audio_upload_key param.', 'listenability' ) );
		}
		if ( $this->plugin->config['speechification']['audio_upload_key'] !== wp_unslash( $_REQUEST['audio_upload_key'] ) ) {
			status_header( 400 );
			wp_send_json_error( __( 'Bad audio_upload_key param.', 'listenability' ) );
		}
		if ( count( $this->plugin->status_taxonomy->get_mp3_enclosure_urls( get_queried_object_id() ) ) > 0 ) {
			status_header( 400 );
			wp_send_json_error( __( 'Post already has an attached audio.', 'listenability' ) );
		}

		if ( empty( $_FILES ) || empty( $_FILES['audio_file'] ) ) {
			status_header( 400 );
			wp_send_json_error( __( 'Missing audio_file upload.', 'listenability' ) );
		}
		$audio_file = $_FILES['audio_file'];

		$check_audio_mp3 = function ( $wp_check_filetype_and_ext, $file ) {
			require_once( ABSPATH . 'wp-admin/includes/media.php' );
			$metadata = wp_read_audio_metadata( $file );
			if ( empty( $metadata ) || ( 'audio/mp3' !== $metadata['mime_type'] && 'audio/mpeg' !== $metadata['mime_type'] ) ) {
				$wp_check_filetype_and_ext['ext'] = false;
				$wp_check_filetype_and_ext['type'] = false;
			}
			return $wp_check_filetype_and_ext;
		};
		add_filter( 'wp_check_filetype_and_ext', $check_audio_mp3, 10, 2 );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload = wp_handle_upload( $audio_file, array(
			'test_form' => false,
			'test_type' => true,
			'mimes' => array( 'mp3' => 'audio/mp3' ),
			'unique_filename_callback' => function ( $dir, $name, $ext ) {
				unset( $dir );
				return md5( wp_rand() ) . '-' . $name . $ext;
			},
		) );
		remove_filter( 'wp_check_filetype_and_ext', $check_audio_mp3 );

		if ( ! empty( $upload['error'] ) ) {
			status_header( 400 );
			wp_send_json_error( $upload['error'] );
		}

		$postarr = array(
			'post_title'     => $audio_file['name'],
			'guid'           => $upload['url'],
			'post_mime_type' => 'audio/mp3',
			'file'           => $upload['file'],
			'post_parent'    => get_queried_object_id(),
			'post_type'      => 'attachment',
			'post_content'   => '',
			'post_status'    => '',
		);
		$r = wp_insert_post( $postarr, true );
		if ( is_wp_error( $r ) ) {
			status_header( 500 );
			wp_send_json_error( $r->get_error_message() );
		}
		$attachment_id = $r;

		require_once( ABSPATH . 'wp-admin/includes/media.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		$audio_metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $audio_metadata ) ) {
			$audio_metadata = wp_generate_attachment_metadata( $attachment_id, get_attached_file( $attachment_id ) );
			wp_update_attachment_metadata( $attachment_id, $audio_metadata );
		}

		$audio_shortcode = sprintf( '[audio mp3="%s"][/audio]', esc_url( $upload['url'] ) );

		$postarr = get_queried_object()->to_array();
		$postarr['post_content'] = $audio_shortcode . "\n\n" . $postarr['post_content'];
		$r = wp_insert_post( $postarr, true );
		if ( is_wp_error( $r ) ) {
			status_header( 500 );
			wp_send_json_error( $r->get_error_message() );
		}

		wp_send_json_success( $upload['url'] );
	}

	/**
	 * Redirect to the first mp3 found if there is one.
	 */
	function send_audio_response() {
		foreach ( get_attached_media( 'audio' ) as $audio_attachment ) {
			if ( 'audio/mp3' === $audio_attachment->post_mime_type || 'audio/mpeg' === $audio_attachment->post_mime_type ) {
				$url = wp_get_attachment_url( $audio_attachment->ID );
				if ( $url ) {
					wp_redirect( $url );
					exit;
				}
			}
		}
		status_header( 404 );
		wp_send_json_error( __( 'No mp3 attached to this post.', 'listenability' ) );
	}
}
