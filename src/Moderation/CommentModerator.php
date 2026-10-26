<?php
namespace AICommentModerator\Moderation;

use AICommentModerator\AI\AIException;
use AICommentModerator\Logging\Logger;
use AICommentModerator\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Moderation pipeline:
 *   comment_post → (optionally held) → queued → analysed in the background
 *   → verdict applied (approve / hold / spam / trash) → logged → reply queued.
 */
class CommentModerator {

	const CRON_ANALYZE = 'aicm_analyze_comment';
	const CRON_SWEEP   = 'aicm_sweep';
	const MAX_ATTEMPTS = 5;

	const META_STATE    = '_aicm_state';     // queued | done | failed.
	const META_VERDICT  = '_aicm_verdict';
	const META_ATTEMPTS = '_aicm_attempts';
	const META_HELD     = '_aicm_auto_held';

	/** @var Settings */
	private $s;
	/** @var Logger */
	private $logger;
	/** @var ReplyManager */
	private $replies;
	/** @var Analyzer */
	private $analyzer;
	/** @var bool Set while we change statuses ourselves. */
	private $applying = false;
	/** @var bool */
	private $hold_next = false;

	public function __construct( Settings $s, Logger $logger, ReplyManager $replies ) {
		$this->s        = $s;
		$this->logger   = $logger;
		$this->replies  = $replies;
		$this->analyzer = new Analyzer( $s );
		$replies->set_analyzer( $this->analyzer );
	}

	public function analyzer(): Analyzer {
		return $this->analyzer;
	}

	public function register(): void {
		add_filter( 'pre_comment_approved', [ $this, 'maybe_hold_new' ], 99, 2 );
		add_action( 'comment_post', [ $this, 'on_comment_post' ], 20, 2 );
		add_action( self::CRON_ANALYZE, [ $this, 'run' ] );
		add_action( self::CRON_SWEEP, [ $this, 'sweep' ] );
		add_action( 'transition_comment_status', [ $this, 'on_manual_status_change' ], 10, 3 );
		add_action( 'init', [ $this, 'ensure_sweep_scheduled' ] );
	}

	public function ensure_sweep_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_SWEEP ) ) {
			wp_schedule_event( time() + 300, (string) $this->s->get( 'sweep_schedule', 'hourly' ), self::CRON_SWEEP );
		}
	}

	/**
	 * "Hold until checked": comments WordPress would publish immediately are
	 * kept pending until the AI clears them, so spam is never visible.
	 *
	 * @param int|string $approved
	 * @param array      $data
	 * @return int|string|\WP_Error
	 */
	public function maybe_hold_new( $approved, $data ) {
		$this->hold_next = false;
		if ( 1 !== $approved && '1' !== $approved ) {
			return $approved;
		}
		if ( ! $this->s->bool( 'moderation_enabled' ) || ! $this->s->bool( 'hold_until_checked' ) || '' === $this->s->api_key( (string) $this->s->get( 'provider' ) ) ) {
			return $approved;
		}
		$user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		if ( $user_id && $this->s->bool( 'skip_trusted' ) && user_can( $user_id, 'moderate_comments' ) ) {
			return $approved;
		}
		$type = isset( $data['comment_type'] ) ? $data['comment_type'] : 'comment';
		if ( in_array( $type, [ 'pingback', 'trackback' ], true ) ) {
			return $approved;
		}
		$this->hold_next = true;
		return 0;
	}

	/**
	 * @param int        $comment_id
	 * @param int|string $approved
	 */
	public function on_comment_post( $comment_id, $approved ): void {
		if ( $this->hold_next ) {
			update_comment_meta( $comment_id, self::META_HELD, 1 );
			$this->hold_next = false;
		}
		if ( ! $this->s->bool( 'moderation_enabled' ) || 'spam' === $approved || 'trash' === $approved ) {
			return;
		}
		$this->enqueue( (int) $comment_id );
	}

	public function enqueue( int $comment_id, bool $force_sync = false ): void {
		update_comment_meta( $comment_id, self::META_STATE, 'queued' );
		if ( $this->s->bool( 'process_async' ) && ! $force_sync ) {
			wp_schedule_single_event( time(), self::CRON_ANALYZE, [ $comment_id ] );
			// Kick WP-Cron now so the verdict lands within seconds, not at the next page view.
			if ( function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
				spawn_cron();
			}
			return;
		}
		$this->run( $comment_id );
	}

	/**
	 * Analyses one comment and applies the verdict.
	 *
	 * @param int|string $comment_id
	 */
	public function run( $comment_id, bool $force = false ): ?Verdict {
		$comment = get_comment( (int) $comment_id );
		if ( ! $comment ) {
			return null;
		}
		$status = wp_get_comment_status( $comment );
		if ( in_array( $status, [ 'spam', 'trash', 'deleted' ], true ) && ! $force ) {
			delete_comment_meta( $comment->comment_ID, self::META_STATE );
			return null;
		}
		if ( ! $force && 'done' === get_comment_meta( $comment->comment_ID, self::META_STATE, true ) ) {
			return null;
		}

		$lock = 'aicm_lock_' . $comment->comment_ID;
		if ( get_transient( $lock ) && ! $force ) {
			return null;
		}
		set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

		try {
			$verdict = $this->analyzer->analyze( $comment, $this->s->bool( 'auto_response' ) );
		} catch ( AIException $e ) {
			$attempts = (int) get_comment_meta( $comment->comment_ID, self::META_ATTEMPTS, true ) + 1;
			update_comment_meta( $comment->comment_ID, self::META_ATTEMPTS, $attempts );
			delete_transient( $lock );

			if ( $attempts < self::MAX_ATTEMPTS ) {
				$delay = max( $e->retry_after(), 60 * ( 2 ** ( $attempts - 1 ) ) ); // 1, 2, 4, 8 min.
				wp_schedule_single_event( time() + $delay, self::CRON_ANALYZE, [ (int) $comment->comment_ID ] );
				$this->debug( "Comment {$comment->comment_ID}: retry {$attempts} in {$delay}s – " . $e->getMessage() );
				return null;
			}
			$verdict = Verdict::error( $e->getMessage() );
		}

		$this->apply( $comment, $verdict );
		delete_transient( $lock );
		return $verdict;
	}

	public function apply( \WP_Comment $comment, Verdict $v ): void {
		$id        = (int) $comment->comment_ID;
		$status    = wp_get_comment_status( $comment );
		$auto_held = (bool) get_comment_meta( $id, self::META_HELD, true );

		$this->applying = true;
		switch ( $v->action ) {
			case Verdict::SPAM:
				wp_spam_comment( $id );
				break;
			case Verdict::TRASH:
				wp_trash_comment( $id );
				break;
			case Verdict::HOLD:
				if ( 'approved' === $status ) {
					wp_set_comment_status( $id, 'hold' );
				}
				break;
			case Verdict::APPROVE:
				if ( 'unapproved' === $status && ( $auto_held || $this->s->bool( 'ai_can_approve' ) ) ) {
					wp_set_comment_status( $id, 'approve' );
				}
				break;
			case Verdict::SKIP:
			case Verdict::ERROR:
				// Fail open: restore what WordPress would have done without us.
				if ( $auto_held && 'unapproved' === $status && apply_filters( 'aicm_fail_open', true, $comment, $v ) ) {
					wp_set_comment_status( $id, 'approve' );
				}
				break;
		}
		$this->applying = false;

		delete_comment_meta( $id, self::META_HELD );
		delete_comment_meta( $id, self::META_ATTEMPTS );
		update_comment_meta( $id, self::META_VERDICT, $v->to_array() );
		update_comment_meta( $id, self::META_STATE, Verdict::ERROR === $v->action ? 'failed' : 'done' );

		if ( Verdict::SKIP !== $v->action || $this->s->bool( 'debug' ) ) {
			$this->logger->log( $comment, $v );
		}

		$comment = get_comment( $id );
		if ( $comment && 'approved' === wp_get_comment_status( $comment ) && Verdict::APPROVE === $v->action ) {
			$this->replies->maybe_queue( $comment, $v );
		}

		if ( in_array( $v->action, [ Verdict::HOLD, Verdict::TRASH ], true ) && $v->toxicity_score >= (float) $this->s->get( 'toxicity_threshold' ) ) {
			$this->notify( $comment ? $comment : get_comment( $id ), $v );
		}

		do_action( 'aicm_after_moderation', $v, $comment );
	}

	/**
	 * When a human approves a comment the AI held, offer a reply if one was drafted
	 * or the comment was judged reply-worthy.
	 *
	 * @param string     $new
	 * @param string     $old
	 * @param \WP_Comment $comment
	 */
	public function on_manual_status_change( $new, $old, $comment ): void {
		if ( $this->applying || 'approved' !== $new || 'approved' === $old ) {
			return;
		}
		$raw = get_comment_meta( $comment->comment_ID, self::META_VERDICT, true );
		if ( is_array( $raw ) ) {
			$v = Verdict::from_array( $raw );
			// A human approved it, so trust them — but never auto-reply to toxic content.
			if ( in_array( $v->action, [ Verdict::HOLD, Verdict::APPROVE ], true ) && $v->toxicity_score < (float) $this->s->get( 'toxicity_threshold' ) ) {
				$this->replies->maybe_queue( $comment, $v, true );
			}
		}
	}

	/**
	 * Safety net: re-queues comments stuck in "queued" (e.g. WP-Cron was not
	 * triggered) and prunes old logs.
	 */
	public function sweep(): void {
		$stuck = get_comments(
			[
				'status'     => 'all',
				'number'     => 25,
				'meta_key'   => self::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => 'queued',         // phpcs:ignore WordPress.DB.SlowDBQuery
				'date_query' => [ [ 'before' => '5 minutes ago' ] ],
				'fields'     => 'ids',
			]
		);
		foreach ( $stuck as $id ) {
			if ( ! wp_next_scheduled( self::CRON_ANALYZE, [ (int) $id ] ) ) {
				$this->run( (int) $id );
			}
		}
		$this->logger->prune( (int) $this->s->get( 'log_retention_days' ) );
	}

	/**
	 * Analyses existing comments that were never checked.
	 *
	 * @param string $status hold | approve | all.
	 * @return array{processed:int, actions:array<string,int>}
	 */
	public function scan( string $status = 'hold', int $limit = 20 ): array {
		$ids = get_comments(
			[
				'status'     => $status,
				'number'     => $limit,
				'type'       => 'comment',
				'fields'     => 'ids',
				// Never-checked comments, or queued ones not currently in retry backoff.
				// "failed" comments are excluded so batch loops always terminate;
				// use "Re-check with AI" to retry those individually.
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					[ 'key' => self::META_STATE, 'compare' => 'NOT EXISTS' ],
					[
						'relation' => 'AND',
						[ 'key' => self::META_STATE, 'value' => 'queued' ],
						[ 'key' => self::META_ATTEMPTS, 'compare' => 'NOT EXISTS' ],
					],
				],
			]
		);

		$out = [ 'processed' => 0, 'actions' => [] ];
		foreach ( $ids as $id ) {
			$v = $this->run( (int) $id, true );
			if ( $v ) {
				$out['processed']++;
				$out['actions'][ $v->action ] = ( isset( $out['actions'][ $v->action ] ) ? $out['actions'][ $v->action ] : 0 ) + 1;
			}
		}
		return $out;
	}

	private function notify( $comment, Verdict $v ): void {
		$to = (string) $this->s->get( 'notify_email' );
		if ( ! $to || ! $comment || get_comment_meta( $comment->comment_ID, '_aicm_notified', true ) ) {
			return;
		}
		update_comment_meta( $comment->comment_ID, '_aicm_notified', 1 );

		$subject = sprintf( __( '[%s] Comment flagged by AI moderator', 'ai-comment-moderator' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body    = sprintf(
			"%s\n\n%s: %s\n%s: %.2f\n%s: %s\n\n%s\n\n%s",
			__( 'A comment was flagged as potentially toxic.', 'ai-comment-moderator' ),
			__( 'Action', 'ai-comment-moderator' ),
			$v->action,
			__( 'Toxicity', 'ai-comment-moderator' ),
			$v->toxicity_score,
			__( 'Reason', 'ai-comment-moderator' ),
			$v->reason,
			wp_trim_words( $comment->comment_content, 60 ),
			admin_url( 'comment.php?action=editcomment&c=' . $comment->comment_ID )
		);
		wp_mail( $to, $subject, $body );
	}

	private function debug( string $msg ): void {
		if ( $this->s->bool( 'debug' ) ) {
			error_log( '[AI Comment Moderator] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
