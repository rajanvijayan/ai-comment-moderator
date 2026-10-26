<?php
namespace AICommentModerator\Moderation;

use AICommentModerator\AI\AIException;
use AICommentModerator\Logging\Logger;
use AICommentModerator\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * AI reply lifecycle: draft → (human review) → scheduled → sent.
 */
class ReplyManager {

	const CRON_SEND = 'aicm_send_reply';

	const META_DRAFT    = '_aicm_reply_draft';
	const META_STATE    = '_aicm_reply_state';  // review | scheduled | sent | discarded.
	const META_REPLY_ID = '_aicm_reply_id';
	const META_IS_AI    = '_aicm_is_ai_reply';

	const COUNTER_OPTION = 'aicm_reply_counter';

	/** @var Settings */
	private $s;
	/** @var Logger */
	private $logger;
	/** @var Analyzer|null */
	private $analyzer;

	public function __construct( Settings $s, Logger $logger ) {
		$this->s      = $s;
		$this->logger = $logger;
	}

	public function set_analyzer( Analyzer $a ): void {
		$this->analyzer = $a;
	}

	public function register(): void {
		add_action( self::CRON_SEND, [ $this, 'send' ] );
	}

	/**
	 * Decides whether an approved comment gets a reply, and drafts/schedules it.
	 */
	public function maybe_queue( \WP_Comment $comment, Verdict $v, bool $generate_if_missing = false ): bool {
		if ( ! $this->s->bool( 'auto_response' ) || ! $this->moderator() ) {
			return false;
		}
		$id = (int) $comment->comment_ID;
		if ( get_comment_meta( $id, self::META_REPLY_ID, true ) || get_comment_meta( $id, self::META_STATE, true ) ) {
			return false;
		}
		if ( $this->s->bool( 'reply_skip_negative' ) && 'negative' === $v->sentiment ) {
			return false;
		}
		if ( ! $this->eligible( $comment ) ) {
			return false;
		}

		$text = $v->reply;
		if ( '' === $text && $generate_if_missing && $v->reply_worthy && $this->analyzer ) {
			try {
				$text = $this->analyzer->generate_reply( $comment );
			} catch ( AIException $e ) {
				return false;
			}
		}
		if ( '' === $text ) {
			return false;
		}

		update_comment_meta( $id, self::META_DRAFT, $text );

		if ( $this->s->bool( 'reply_review' ) ) {
			update_comment_meta( $id, self::META_STATE, 'review' );
			return true;
		}
		$this->schedule( $id, (int) $this->s->get( 'reply_delay_minutes' ) * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Common guards: real comment, not our own account, no AI ping-pong.
	 */
	public function eligible( \WP_Comment $comment ): bool {
		$mod = $this->moderator();
		if ( ! $mod || ! in_array( $comment->comment_type, [ '', 'comment' ], true ) ) {
			return false;
		}
		if ( (int) $comment->user_id === $mod->ID || get_comment_meta( $comment->comment_ID, self::META_IS_AI, true ) ) {
			return false;
		}
		// Don't reply to a reply of our own AI reply (prevents bot loops).
		if ( $comment->comment_parent && get_comment_meta( (int) $comment->comment_parent, self::META_IS_AI, true ) ) {
			return (bool) apply_filters( 'aicm_reply_to_ai_thread', false, $comment );
		}
		return (bool) apply_filters( 'aicm_should_reply', true, $comment );
	}

	public function schedule( int $comment_id, int $delay = 0 ): void {
		update_comment_meta( $comment_id, self::META_STATE, 'scheduled' );
		wp_clear_scheduled_hook( self::CRON_SEND, [ $comment_id ] );
		wp_schedule_single_event( time() + max( 0, $delay ), self::CRON_SEND, [ $comment_id ] );
	}

	/**
	 * Admin action: approve the draft (optionally edited) and send now.
	 */
	public function approve_draft( int $comment_id, ?string $edited = null ) {
		if ( null !== $edited ) {
			$edited = self::clean_reply( $edited, 1000 );
			if ( '' === $edited ) {
				return new \WP_Error( 'empty', __( 'Reply is empty.', 'ai-comment-moderator' ) );
			}
			update_comment_meta( $comment_id, self::META_DRAFT, $edited );
		}
		return $this->send( $comment_id, true );
	}

	public function discard( int $comment_id ): void {
		delete_comment_meta( $comment_id, self::META_DRAFT );
		update_comment_meta( $comment_id, self::META_STATE, 'discarded' );
		wp_clear_scheduled_hook( self::CRON_SEND, [ $comment_id ] );
	}

	/**
	 * Generates (or regenerates) a draft on demand from the admin.
	 *
	 * @return string|\WP_Error
	 */
	public function generate_draft( int $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment || ! $this->analyzer ) {
			return new \WP_Error( 'missing', __( 'Comment not found.', 'ai-comment-moderator' ) );
		}
		if ( ! $this->moderator() ) {
			return new \WP_Error( 'no_moderator', __( 'Select a reply author in Settings → AI Comment Moderator → Replies.', 'ai-comment-moderator' ) );
		}
		try {
			$text = $this->analyzer->generate_reply( $comment );
		} catch ( AIException $e ) {
			return new \WP_Error( 'ai', $e->getMessage() );
		}
		update_comment_meta( $comment_id, self::META_DRAFT, $text );
		update_comment_meta( $comment_id, self::META_STATE, 'review' );
		delete_comment_meta( $comment_id, self::META_REPLY_ID );
		return $text;
	}

	/**
	 * Publishes the drafted reply.
	 *
	 * @param int|string $comment_id
	 * @return int|\WP_Error|false Reply comment ID.
	 */
	public function send( $comment_id, bool $manual = false ) {
		$comment_id = (int) $comment_id;

		// Already replied (e.g. duplicate cron event or double click): no-op.
		if ( get_comment_meta( $comment_id, self::META_REPLY_ID, true ) ) {
			return false;
		}

		$comment = get_comment( $comment_id );
		$draft   = (string) get_comment_meta( $comment_id, self::META_DRAFT, true );
		$mod     = $this->moderator();

		if ( ! $comment || '' === $draft || ! $mod ) {
			return new \WP_Error( 'missing', __( 'Nothing to send.', 'ai-comment-moderator' ) );
		}
		if ( 'approved' !== wp_get_comment_status( $comment ) ) {
			return new \WP_Error( 'not_approved', __( 'The comment is no longer approved.', 'ai-comment-moderator' ) );
		}
		if ( ! $manual && $this->cap_reached() ) {
			// Try again tomorrow.
			$this->schedule( $comment_id, DAY_IN_SECONDS );
			return false;
		}

		$reply_id = wp_insert_comment(
			[
				'comment_post_ID'      => (int) $comment->comment_post_ID,
				'comment_parent'       => $comment_id,
				'comment_content'      => $draft,
				'user_id'              => $mod->ID,
				'comment_author'       => $mod->display_name,
				'comment_author_email' => $mod->user_email,
				'comment_author_url'   => $mod->user_url,
				'comment_approved'     => 1,
				'comment_type'         => 'comment',
				'comment_meta'         => [ self::META_IS_AI => 1 ],
			]
		);
		if ( ! $reply_id ) {
			return new \WP_Error( 'insert', __( 'Could not save the reply.', 'ai-comment-moderator' ) );
		}

		update_comment_meta( $comment_id, self::META_REPLY_ID, $reply_id );
		update_comment_meta( $comment_id, self::META_STATE, 'sent' );
		delete_comment_meta( $comment_id, self::META_DRAFT );
		$this->bump_counter();
		$this->logger->log_reply( $comment, (int) $reply_id, $manual );

		do_action( 'aicm_reply_sent', (int) $reply_id, $comment );
		return (int) $reply_id;
	}

	public function moderator(): ?\WP_User {
		$id = (int) $this->s->get( 'moderator_user' );
		if ( ! $id ) {
			return null;
		}
		$u = get_userdata( $id );
		return $u ? $u : null;
	}

	public function cap_reached(): bool {
		$cap = (int) $this->s->get( 'reply_daily_cap' );
		return $cap > 0 && $this->sent_today() >= $cap;
	}

	public function sent_today(): int {
		$c = get_option( self::COUNTER_OPTION, [] );
		return isset( $c['day'], $c['n'] ) && current_time( 'Y-m-d' ) === $c['day'] ? (int) $c['n'] : 0;
	}

	private function bump_counter(): void {
		update_option( self::COUNTER_OPTION, [ 'day' => current_time( 'Y-m-d' ), 'n' => $this->sent_today() + 1 ], false );
	}

	/**
	 * Normalises model output into safe plain text.
	 */
	public static function clean_reply( string $text, int $max_words ): string {
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '#\b(?:https?://|www\.)\S+#i', '', $text );            // No links.
		$text = preg_replace( '/^\s*(?:reply|response|answer)\s*:\s*/i', '', $text );  // "Reply: ..." prefixes.
		$text = str_replace( [ '**', '__', '`', '#' ], '', $text );                     // Markdown leftovers.
		$text = trim( $text, " \t\n\r\0\x0B\"'“”" );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		if ( str_word_count( $text ) > $max_words ) {
			$text = wp_trim_words( $text, $max_words, '…' );
		}
		return trim( $text );
	}
}
