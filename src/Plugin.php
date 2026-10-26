<?php
namespace AICommentModerator;

use AICommentModerator\Admin\CommentsColumn;
use AICommentModerator\Admin\Dashboard;
use AICommentModerator\Admin\SettingsPage;
use AICommentModerator\CLI\Command;
use AICommentModerator\Logging\Logger;
use AICommentModerator\Moderation\CommentModerator;
use AICommentModerator\Moderation\ReplyManager;
use AICommentModerator\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Composition root: wires services and WordPress hooks.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance;

	/** @var Settings */
	public $settings;
	/** @var Logger */
	public $logger;
	/** @var CommentModerator */
	public $moderator;
	/** @var ReplyManager */
	public $replies;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function boot(): void {
		$plugin = self::instance();
		$plugin->register();
	}

	private function __construct() {
		$this->settings  = new Settings();
		$this->logger    = new Logger();
		$this->replies   = new ReplyManager( $this->settings, $this->logger );
		$this->moderator = new CommentModerator( $this->settings, $this->logger, $this->replies );
	}

	private function register(): void {
		load_plugin_textdomain( 'ai-comment-moderator', false, dirname( plugin_basename( AICM_FILE ) ) . '/languages' );

		Settings::maybe_migrate();
		Logger::maybe_upgrade();

		$this->moderator->register();
		$this->replies->register();

		if ( is_admin() ) {
			( new SettingsPage( $this->settings ) )->register();
			( new CommentsColumn( $this->settings, $this->moderator, $this->replies ) )->register();
			( new Dashboard( $this->settings, $this->logger, $this->moderator ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ai-moderator', new Command( $this->moderator, $this->logger, $this->settings ) );
		}
	}

	public static function activate(): void {
		Settings::maybe_migrate();
		Logger::install();
	}

	public static function deactivate(): void {
		// wp_unschedule_hook() also removes per-comment events scheduled with args.
		foreach ( [ CommentModerator::CRON_SWEEP, CommentModerator::CRON_ANALYZE, ReplyManager::CRON_SEND, Logger::CRON_PRUNE, 'ai_comment_moderation_cron' ] as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}
}
