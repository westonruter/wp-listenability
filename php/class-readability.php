<?php

namespace Listenability;

class Readability {

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * @param Plugin $plugin
	 */
	function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'save_post', array( $this, 'expand_url_in_post_content' ) );

		if ( ! $this->get_parser_api_key() && current_user_can( 'manage_options' ) ) {
			add_action( 'admin_notices', array( $this, 'display_missing_key_notice' ) );
		}
	}

	/**
	 * Return the Readability Parser API key as provided by option or constant.
	 *
	 * @return string
	 */
	function get_parser_api_key() {
		$key = get_option( 'readability_parser_api_key' );
		if ( empty( $key ) && defined( 'READABILITY_PARSER_API_KEY' ) ) {
			$key = READABILITY_PARSER_API_KEY;
		}
		return $key;
	}

	/**
	 * Display notice to supply API key.
	 *
	 * @action admin_notices
	 */
	function display_missing_key_notice() {
		?>
		<div class="error">
			<p>
				<strong><?php esc_html_e( 'Listenability:', 'listenability' ) ?></strong>
				<?php
				echo sprintf(
					__( 'Finish activation by <a href="%s">supplying your Readability Parser API key</a>.', 'listenability' ),
					esc_url( admin_url( 'options-general.php?page=listenability' ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register settings.
	 *
	 * @action admin_init
	 */
	function register_settings() {
		$section_id = 'readability';
		$section_title = __( 'Readability', 'listenability' );
		$callback = function () {
			?>
			<p>
				<?php _e( 'When you create a post that contains just an article\'s URL as the post content (e.g. if you shared a URL from Chrome to Gmail and made a <a href="http://jetpack.me/support/post-by-email/">post by email</a>), the Readability <a href="https://www.readability.com/developers/api/parser">Parser API</a> is used to extract the text of the article for passing into the text-to-speech engine. This Parser API is freely available for non-commercial use.' ); ?>
			</p>
			<?php
		};
		add_settings_section( $section_id, $section_title, $callback, Plugin::OPTIONS_PAGE_MENU_SLUG );

		$option_name = 'readability_parser_api_key';
		$title = __( 'Readability Parser API Key', 'listenability' );
		$callback = function () use ( $option_name ) {
			$placeholder = defined( 'READABILITY_PARSER_API_KEY' ) ? READABILITY_PARSER_API_KEY : __( 'e.g. abc1234567890def1234567890abcdef12345678', 'listenability' );

			?>
			<input
				name="<?php echo esc_attr( $option_name ) ?>"
				id="<?php echo esc_attr( $option_name ) ?>"
				type="text"
				pattern="[0-9a-f]{40}"
				placeholder="<?php echo esc_attr( $placeholder ) ?>"
				size="50"
				title="<?php esc_attr_e( 'A 40-character hexadecimal string.', 'listenability' ); ?>"
				value="<?php echo esc_attr( get_option( $option_name ) ); ?>"
				/>
			<?php echo sprintf( __( 'Access via <a href="%s" target="_blank">your Readability account</a>.', 'listenability' ), 'https://www.readability.com/settings/account#api' ) ?>
			<?php
		};
		$args = array(
			'label_for' => $option_name,
		);
		add_settings_field( $option_name, $title, $callback, Plugin::OPTIONS_PAGE_MENU_SLUG, $section_id, $args );

		$sanitizer = function ( $key ) {
			return preg_replace( '/[^0-9a-f]/', '', $key );
		};
		register_setting( Plugin::OPTIONS_PAGE_MENU_SLUG, $option_name, $sanitizer );
	}

	/**
	 * Prevent save_post recursion.
	 *
	 * @var array
	 */
	protected $processed_ids = array();

	/**
	 * Prepend post_content with the Readability Parser content.
	 *
	 * @action save_post
	 * @param int $post_id
	 */
	function expand_url_in_post_content( $post_id ) {
		// Abort during autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Abort if Readability API Key not supplied.
		$api_key = $this->get_parser_api_key();
		if ( empty( $api_key ) ) {
			return;
		}

		// Recursion prevention.
		if ( in_array( $post_id, $this->processed_ids ) ) {
			return;
		}
		$this->processed_ids[] = $post_id;

		// Skip expanding URLs when the post already has an audio attachment.
		$audio_attachments = get_attached_media( 'audio' );
		if ( ! empty( $audio_attachments ) ) {
			return;
		}

		$post = get_post( $post_id );

		// Only process published posts of the given type.
		if ( $post->post_type !== $this->plugin->config['post_type'] || 'publish' !== $post->post_status ) {
			return;
		}

		$content = $post->post_content;

		// Skip posts that already have the readability content embedded.
		if ( preg_match( '#<hr[^>]+end-readability-content[^>]+>#s', $content ) ) {
			return;
		}

		delete_post_meta( $post->ID, 'readability_parser_response' );

		$content = trim( strip_shortcodes( strip_tags( $content ) ) );

		// Skip posts that already have content.
		$word_count = str_word_count( strip_tags( $content ) );
		if ( $word_count > $this->plugin->config['readability']['word_count_skip_threshold'] ) {
			update_post_meta( $post->ID, 'readability_status', 'over_word_count_skip_threshold' );
			return;
		}

		$urls = array_filter( wp_extract_urls( $content ), function ( $url ) {
			return preg_match( '#^https?://#', $url );
		} );
		if ( empty( $urls ) ) {
			update_post_meta( $post->ID, 'readability_status', 'no_content_url' );
			return;
		}

		$url = array_shift( $urls );
		$args = array(
			'url' => $url,
			'token' => $api_key,
		);
		$parser_endpoint = 'https://readability.com/api/content/v1/parser';
		$parser_endpoint .= '?' . http_build_query( $args );
		$transient_key = 'readability' . md5( $parser_endpoint );

		$r = get_transient( $transient_key );
		if ( empty( $r ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( "Listenability: Fetching $parser_endpoint" );
			}
			$r = wp_remote_get( $parser_endpoint );
		}

		$ttl = 15 * MINUTE_IN_SECONDS;
		if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) {
			$ttl = 1 * MINUTE_IN_SECONDS;
		}
		set_transient( $transient_key, $r, $ttl );

		if ( is_wp_error( $r ) ) {
			update_post_meta( $post->ID, 'readability_status', wp_slash( 'wp_request_error:' . $r->get_error_code() . '; ' . $r->get_error_message() ) );
			return;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $r ) ) {
			update_post_meta( $post->ID, 'readability_status', wp_slash( 'wp_http_error: HTTP ' . wp_remote_retrieve_response_code( $r ) . ': ' . wp_remote_retrieve_response_message( $r ) . "\n" . wp_remote_retrieve_body( $r ) ) );
			return;
		}

		$body = wp_remote_retrieve_body( $r );
		update_post_meta( $post->ID, 'readability_parser_response', wp_slash( $body ) );
		$response = json_decode( $body, true );
		if ( empty( $response['content'] ) ) {
			update_post_meta( $post->ID, 'readability_status', 'error:no_content' );
			return;
		}

		$r = $this->amend_post_content_with_readability_response( $post_id, $response );
		if ( is_wp_error( $r ) ) {
			update_post_meta( $post->ID, 'readability_status', wp_slash( 'amend_error:' . $r->get_error_code() . '; ' . $r->get_error_message() ) );
			return;
		}

		update_post_meta( $post->ID, 'readability_status', 'success' );
	}

	/**
	 * Format the Readability Parser content for prepending the post content and update the post.
	 *
	 * @param int $post_id
	 * @param array $response
	 *
	 * @return \WP_Error
	 */
	function amend_post_content_with_readability_response( $post_id, $response ) {
		$post = get_post( $post_id );
		if ( empty( $post ) ) {
			return new \WP_Error( 'bad_post' );
		}
		$post_content = '';
		$post_content .= '<h2>' . $response['title'] . '</h2>' . "\n";

		if ( ! empty( $response['dek'] ) ) {
			$post_content .= '<p>' . $response['dek'] . '</p>';
			$post_content .= '<hr>';
		}

		$post_content .= '<p>' . $response['content'] . '</p>';
		$post_content .= '<hr>';

		$post_content .= '<address class="vcard">';

		if ( ! empty( $response['author'] ) ) {
			$post_content .= esc_html__( 'Author: ', 'listenability' ) . '<span class="fn p-author h-card">' . esc_html( $response['author'] ) . "</span>\n";
		}
		$post_content .= esc_html__( 'Source: ', 'listenability' ) . sprintf( '<a class="u-url" href="%s">%s</a>', esc_url( $response['url'] ), esc_attr( $response['domain'] ) ) . "\n";
		if ( ! empty( $response['date_published'] ) ) {
			$post_content .= esc_html__( 'Published: ', 'listenability' ) . sprintf( '<time class="dt-published" datetime="%s">%s</time>', $response['date_published'], $response['date_published'] ) . "\n";
		}
		$post_content .= '</address>';

		// Sideload lead_image_url and set as featured images.
		if ( ! empty( $response['lead_image_url'] ) && ! get_post_thumbnail_id( $post->ID ) ) {
			$attachment_id = $this->sideload_image( $post->ID, $response['lead_image_url'], $response['title'] );
			if ( ! is_wp_error( $attachment_id ) ) {
				set_post_thumbnail( $post->ID, $attachment_id );
			}
		}

		$postarr = $post->to_array();

		// Remove everything before <hr class="end-readability-content">, and then re-add the content before this HR.
		$postarr['post_content'] = preg_replace( '#.*<hr[^>]+end-readability-content[^>]+>#s', '', $postarr['post_content'] );
		$postarr['post_content'] = $post_content . '<hr class="end-readability-content">' . $postarr['post_content'];
		return wp_insert_post( $postarr, true );
	}

	/**
	 * Attach an image to the given post and return the attachment ID.
	 *
	 * @param int    $post_id
	 * @param string $file
	 * @param string $desc
	 *
	 * @see media_sideload_image()
	 *
	 * @return int|\WP_Error
	 */
	function sideload_image( $post_id, $file, $desc ) {
		// Set variables for storage, fix file filename for query strings.
		if ( ! preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $file, $matches ) ) {
			return new \WP_Error( 'not_an_image' );
		}
		require_once( ABSPATH . 'wp-admin/includes/media.php' );
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		$file_array = array();
		$file_array['name'] = basename( $matches[0] );

		// Download file to temp location.
		$file_array['tmp_name'] = download_url( $file );

		// If error storing temporarily, return the error.
		if ( is_wp_error( $file_array['tmp_name'] ) ) {
			return $file_array['tmp_name'];
		}

		// Do the validation and storage stuff.
		$id = media_handle_sideload( $file_array, $post_id, $desc );

		// If error storing permanently, unlink.
		if ( is_wp_error( $id ) ) {
			// @codingStandardsIgnoreStart
			@unlink( $file_array['tmp_name'] );
			// @codingStandardsIgnoreEnd
			return $id;
		}

		return $id;
	}
}
