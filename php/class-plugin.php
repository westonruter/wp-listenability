<?php

namespace Listenability;

/**
 * Main plugin bootstrap file.
 */
class Plugin extends Plugin_Base {

	const ENDPOINT_SLUG = 'listenability';

	const QUERY_VAR = 'listenability';

	const OPTIONS_PAGE_MENU_SLUG = 'listenability';

	/**
	 * @var Plugin
	 */
	static $instance;

	/**
	 * @var Readability
	 */
	public $readability;

	/**
	 * @var Presentation
	 */
	public $presentation;

	/**
	 * @var Feed
	 */
	public $feed;

	/**
	 * @var Speechification
	 */
	public $speechification;

	/**
	 * @var Status_Taxonomy
	 */
	public $status_taxonomy;

	/**
	 * @var string
	 */
	public $options_page_hook_suffix;

	/**
	 * @see register_activation_hook
	 */
	static function activate() {
		static::add_endpoint();
		flush_rewrite_rules();
	}

	/**
	 * @see register_deactivation_hook
	 */
	static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * @param array $config
	 */
	public function __construct( $config = array() ) {
		if ( empty( static::$instance ) ) {
			static::$instance = $this;
		}

		$default_config = array(
			'post_type' => 'post',
			'readability' => array(
				'word_count_skip_threshold' => 100,
			),
			'speechification' => array(
				'audio_upload_key' => '',
			),
		);

		$this->config = array_merge( $default_config, $config );

		add_action( 'after_setup_theme', array( $this, 'init' ) );

		parent::__construct(); // autoload classes and set $slug, $dir_path, and $dir_url vars
	}

	/**
	 * @action after_setup_theme
	 */
	function init() {
		if ( empty( $this->config['speechification']['audio_upload_key'] ) ) {
			if ( defined( 'LISTENABILITY_AUDIO_UPLOAD_KEY' ) ) {
				$this->config['speechification']['audio_upload_key'] = LISTENABILITY_AUDIO_UPLOAD_KEY;
			} else {
				$this->config['speechification']['audio_upload_key'] = wp_hash( 'listenability' );
			}
		}
		$this->config = \apply_filters( 'listenability_plugin_config', $this->config, $this );

		$this->readability = new Readability( $this );
		$this->presentation = new Presentation( $this );
		$this->speechification = new Speechification( $this );
		$this->feed = new Feed( $this );
		$this->status_taxonomy = new Status_Taxonomy( $this );

		add_action( 'init', array( get_class( $this ), 'add_endpoint' ) );
		add_action( 'admin_menu', array( $this, 'add_options_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/listenability.php' ), array( $this, 'filter_plugin_action_links' ) );
	}

	/**
	 * @action admin_enqueue_scripts
	 */
	function admin_enqueue_scripts( $hook ) {
		if ( $this->options_page_hook_suffix !== $hook ) {
			return;
		}
		$handle = 'listenability-options-page';
		$src = $this->dir_url . 'css/options-page.css';
		wp_enqueue_style( $handle, $src );
	}

	/**
	 * Add option page.
	 *
	 * @action admin_menu
	 */
	function add_options_page() {
		$page_title = __( 'Listenability', 'listenability' );
		$menu_title = __( 'Listenability', 'listenability' );
		$capability = 'edit_theme_options';
		$menu_slug = static::OPTIONS_PAGE_MENU_SLUG;

		$callback = function () use ( $menu_slug ) {
			?>
			<div class="wrap">
				<h2><?php esc_html_e( 'Listenability', 'listenability' ) ?></h2>

				<form method="post" action="options.php">
					<?php settings_fields( $menu_slug ); ?>

					<p>
						<em><?php _e( 'Create a text-to-speech podcast of your posts, with the content from embedded URLs supplied by Readability\'s Parser API. This is a self-hosted re-incarnation of <a href="http://soundgecko.com/" target="_blank">SoundGecko</a>.', 'listenability' ); ?></em>
					</p>
					<p>
						<?php
						echo sprintf(
							__( 'Subscribe to your feed in your podcast app by adding your <a href="%s" target="_blank">feed URL</a> to your podcast app, such as <a href="http://www.shiftyjelly.com/pocketcasts" target="_blank">Pocket Casts</a>. Note that you can create separate podcasts by supplying the RSS2 feeds for categories, tags, or any other WordPress queries.', 'listenability' ),
							get_feed_link()
						);
						?>
					</p>
					<p>
						<?php _e( 'Entries will not appear in your podcast until they have an attached MP3 enclosure. If you do not manually supply the MP3 in the post, then you need to set up your Mac to generate the audio for you via the <code>say</code> command in OS X. Here is how you do that:', 'listenability' ); ?>
					</p>
					<ol>
						<li><?php _e( 'First make sure you\'ve supplied your Readability Parser API key (<a href="#readability_parser_api_key">below</a>), as this is needed to expand bare URLs to their full article contents. If any errors occur when connecting to the Readability API, the error message will be added to a Custom Field on the post in question.', 'listenability' ) ?></li>
						<li><?php _e( 'Install <a href="http://brew.sh/" target=_blank>Homebrew</a> if you haven\'t done so already.', 'listenability' ) ?></li>
						<li>
							<?php _e( 'Install the <a href="http://sox.sourceforge.net/">SoX</a> command to convert the audio generated by <code>say</code> into an MP3:', 'listenability' ) ?><br>
							<code>brew install sox</code>
						</li>
						<li>
							<?php _e( 'Clone the Git repo for Listenability onto your machine:', 'listenability' ) ?><br>
							<code>git clone https://github.com/westonruter/wp-listenability.git /User/johnsmith/wp-listenability</code><br>
							<?php _e( '(or wherever you want to put it, but take note of the location)', 'listenability' ) ?>
						</li>
						<li>
							<?php _e( 'Create a new post on your blog as a test, either with the full content pasted in, or with just a bare article URL which will be expanded with content from Readability.', 'listenability' ) ?>
						</li>
						<li>
							<?php _e( 'Run the speechify command manually from your Mac to make sure it works:', 'listenability' ) ?><br>
							<?php
							$needing_audio_feed = add_query_arg( array( 'listenability_status' => 'needs-audio-enclosure' ), get_feed_link() );
							$speechify_cmd = sprintf(
								'/usr/bin/php /User/johnsmith/wp-listenability/bin/speechify.php --feed-url=%s --audio-upload-key=%s',
								escapeshellarg( $needing_audio_feed ),
								escapeshellarg( $this->config['speechification']['audio_upload_key'] )
							);
							?>
							<code><?php echo esc_html( $speechify_cmd ) ?></code><br>
							<?php printf( __( 'You should see it succeed, and your <a href="%s">feed</a> should now include your test post with the MP3 enclosure you just created.', 'listenability' ), get_feed_link() ) ?>
							<?php printf( __( '(Notice the <code>listenability_status=needs-audio-enclosure</code> query param is added to the <a href="%s" target="_blank">feed URL</a>, and this forces entries without enclosed MP3 audio to be included.)', 'listenability' ), $needing_audio_feed ); ?>
							<?php _e( 'You can install additional voices on your Mac and then reference them in the <code>speechify.php</code> command via the <code>--voice</code> param. You can also modify the speed via the <code>--rate</code> (in <abbr title="words per minute">WPM</abbr>) param.', 'listenability' ); ?>
						</li>
						<li>
							<?php _e( 'Now set up the speechify command to be run automatically every 10 minutes to process any new items that appear on your feed. Open your Mac\'s crontab via:', 'listenability' ) ?><br>
							<code>crontab -e</code><br>
							<?php _e( 'And add a new line (which is a 10 minute schedule followed by the same command you tested above):', 'listenability' ) ?><br>
							<code>
								<?php
								echo esc_html( sprintf( '*/10 * * * * %s > /dev/null', $speechify_cmd ) );
								?>
							</code>
						</li>
						<li>
							<?php _e( '(Recommended) Install the <a href="http://jetpack.me/" target=_blank>Jetpack</a> plugin to utilize the <a href="http://jetpack.me/support/post-by-email/" target="_blank">Post by Email</a> feature.', 'listenability' ) ?>
						</li>
					</ol>
					<p>
						<?php _e( 'Within 10 minutes of creating a new post on your blog, you should see the post prepended with an MP3 generated by your Mac and this post should appear in your podcast feed.', 'listenability' ) ?>
					</p>

					<?php do_settings_sections( $menu_slug ); ?>

					<p>
						<?php submit_button(); ?>
					</p>
				</form>
			</div>
			<?php
		};

		$this->options_page_hook_suffix = add_options_page( $page_title, $menu_title, $capability, $menu_slug, $callback );
	}

	/**
	 * Add endpoint to posts for listenability content.
	 */
	static function add_endpoint() {
		add_rewrite_endpoint( static::ENDPOINT_SLUG, EP_PERMALINK, static::QUERY_VAR );
	}

	/**
	 * Add a link to the options page from the plugin action links.
	 *
	 * @param array $links
	 * @return array
	 */
	function filter_plugin_action_links( $links ) {
		$links[] = sprintf(
			'<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=listenability' ) ),
			esc_html__( 'Options', 'listenability' )
		);
		return $links;
	}
}
