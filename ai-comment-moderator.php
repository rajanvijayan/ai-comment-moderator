<?php
/**
 * Plugin Name:       AI Comment Moderator
 * Plugin URI:        https://github.com/rajanvijayan/ai-comment-moderator
 * Description:       AI-powered comment moderation with Groq, Gemini or OpenAI: spam & toxicity scoring, async processing, context-aware auto-replies with human review, moderation logs and analytics.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Rajan Vijayan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-comment-moderator
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'AICM_VERSION', '2.0.0' );
define( 'AICM_FILE', __FILE__ );
define( 'AICM_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICM_URL', plugin_dir_url( __FILE__ ) );

// Composer autoloader if present, otherwise a tiny PSR-4 fallback so the
// plugin works when installed straight from the repository (no runtime deps).
if ( file_exists( AICM_DIR . 'vendor/autoload.php' ) ) {
	require AICM_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'AICommentModerator\\';
			if ( 0 !== strpos( $class, $prefix ) ) {
				return;
			}
			$file = AICM_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}

register_activation_hook( __FILE__, [ \AICommentModerator\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \AICommentModerator\Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ \AICommentModerator\Plugin::class, 'boot' ] );
