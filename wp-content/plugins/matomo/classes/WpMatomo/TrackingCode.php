<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @package matomo
 */

namespace WpMatomo;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // if accessed directly
}

use \WpMatomo\TrackingCode\TrackingCodeGenerator;

class TrackingCode {
	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var TrackingCodeGenerator
	 */
	private $generator;

	/**
	 * @param Settings $settings
	 */
	public function __construct( $settings ) {
		$this->settings  = $settings;
		$this->logger    = new Logger();
		$this->generator = new TrackingCodeGenerator( $this->settings );
		$this->generator->register_hooks();
	}

	public function register_hooks() {
		if ( $this->settings->is_tracking_enabled() ) {
			if ( $this->settings->is_track_feed() ) {
				add_filter( 'the_excerpt_rss', array( $this, 'add_feed_tracking' ) );
				add_filter( 'the_content', array( $this, 'add_feed_tracking' ) );
			}
			if ( $this->settings->is_add_feed_campaign() ) {
				add_filter( 'post_link', array( $this, 'add_feed_campaign' ) );
			}
			if ( $this->settings->is_cross_domain_linking_enabled() ) {
				add_filter( 'wp_redirect', array( $this, 'forward_cross_domain_visitor_id' ) );
			}

			$is_admin = is_admin() || !empty($GLOBALS['MATOMO_LOADED_DIRECTLY']);

			if ( ! $is_admin || $this->settings->is_admin_tracking_enabled() ) {
				$prefix = 'wp';
				if ( $is_admin ) {
					$prefix = 'admin';
				}

				$position = $prefix . '_head';
				if ( $this->settings->get_tracking_code_position() === 'footer' ) {
					$position = $prefix . '_footer';
				}

				add_action( $position, array( $this, 'add_javascript_code' ) );

				if ( $this->settings->is_add_no_script_code() ) {
					add_action( $prefix . '_footer', array( $this, 'add_noscript_code' ) );
				}
			}
		}
	}

	/**
	 * Check if user should not be tracked
	 *
	 * @return boolean Do not track user?
	 */
	public function is_hidden_user() {
		return current_user_can( Capabilities::KEY_STEALTH );
	}

	/**
	 * Echo javascript tracking code
	 */
	public function add_javascript_code() {
		if ( $this->is_hidden_user() ) {
			$this->logger->log( 'Do not add tracking code to site (user should not be tracked) Blog ID: ' . get_current_blog_id(), Logger::LEVEL_DEBUG );

			return;
		}

		$tracking_code = $this->generator->get_tracking_code();

		$this->logger->log( 'Add tracking code. Blog ID: ' . get_current_blog_id(), Logger::LEVEL_DEBUG );

		if ( $this->settings->is_network_enabled()
			 && 'manually' === $this->settings->get_global_option( 'track_mode' ) ) {
			$site    = new Site();
			$site_id = $site->get_current_matomo_site_id();
			if ( $site_id ) {
				$tracking_code = str_replace( '{MATOMO_API_ENDPOINT}', wp_json_encode( $this->generator->get_tracker_endpoint() ), $tracking_code );
				$tracking_code = str_replace( '{MATOMO_JS_ENDPOINT}', wp_json_encode( $this->generator->get_js_endpoint() ), $tracking_code );
				echo str_replace( '{MATOMO_IDSITE}', $site_id, $tracking_code );
			} else {
				echo '<!-- Site not yet synced with Matomo, tracking code will be added later -->';
			}
		} else {
			echo $tracking_code;
		}
	}

	/**
	 * Echo noscript tracking code
	 */
	public function add_noscript_code() {
		if ( $this->is_hidden_user() ) {
			$this->logger->log( 'Do not add noscript code to site (user should not be tracked) Blog ID: ' . get_current_blog_id(), Logger::LEVEL_DEBUG );

			return;
		}

		$code = $this->generator->get_noscript_code();

		if ( ! empty( $code ) ) {
			$this->logger->log( 'Add noscript code. Blog ID: ' . get_current_blog_id(), Logger::LEVEL_DEBUG );
			echo $code . "\n";
		} else {
			$this->logger->log( 'No noscript code present. Blog ID: ' . get_current_blog_id(), Logger::LEVEL_DEBUG );
		}
	}

	/**
	 * Add a campaign parameter to feed permalink
	 *
	 * @param string $permalink
	 *            permalink
	 *
	 * @return string permalink extended by campaign parameter
	 */
	public function add_feed_campaign( $permalink ) {
		global $post;
		if ( is_feed() ) {
			$this->logger->log( 'Add campaign to feed permalink.' );
			$sep        = ( strpos( $permalink, '?' ) === false ? '?' : '&' );
			$permalink .= $sep . 'pk_campaign=' . rawurlencode( $this->settings->get_global_option( 'track_feed_campaign' ) ) . '&pk_kwd=' . rawurlencode( $post->post_name );
		}

		return $permalink;
	}

	/**
	 * Add tracking pixels to feed content
	 *
	 * @param string $content
	 *            post content
	 *
	 * @return string post content extended by tracking pixel
	 */
	public function add_feed_tracking( $content ) {
		global $post;
		if ( is_feed() ) {
			$this->logger->log( 'Add tracking image to feed entry.' );
			$site    = new Site();
			$site_id = $site->get_current_matomo_site_id();
			if ( ! $site_id ) {
				return false;
			}
			$title   = the_title( null, null, false );
			$posturl = get_permalink( $post->ID );
			$urlref  = get_bloginfo( 'rss2_url' );

			$tracker_endpoint = $this->generator->get_tracker_endpoint();

			$tracking_image = $tracker_endpoint . '?idsite=' . $site_id . '&amp;rec=1&amp;url=' . rawurlencode( $posturl ) . '&amp;action_name=' . rawurlencode( $title ) . '&amp;urlref=' . rawurlencode( $urlref );
			$content       .= '<img src="' . $tracking_image . '" style="border:0;width:0;height:0" width="0" height="0" alt="" />';
		}

		return $content;
	}

	/**
	 * Forwards the cross domain parameter pk_vid if the URL parameter is set and a user is about to be redirected.
	 * When another website links to WooCommerce with a pk_vid parameter, and WooCommerce redirects the user to another
	 * URL, the pk_vid parameter would get lost and the visitorId would later not be applied by the tracking code
	 * due to the lost pk_vid URL parameter. If the URL parameter is set, we make sure to forward this parameter.
	 *
	 * @param string $location
	 *
	 * @return string location extended by pk_vid URL parameter if the URL parameter is set
	 */
	public function forward_cross_domain_visitor_id( $location ) {
		if ( ! empty( $_GET['pk_vid'] )
			 && preg_match( '/^[a-zA-Z0-9]{24,60}$/', $_GET['pk_vid'] ) ) {
			// currently, the pk_vid parameter is 32 characters long, but it may vary over time.
			$location = add_query_arg( 'pk_vid', $_GET['pk_vid'], $location );
		}

		return $location;
	}
}
