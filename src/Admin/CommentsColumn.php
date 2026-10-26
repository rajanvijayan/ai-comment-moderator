<?php
namespace AICommentModerator\Admin;

use AICommentModerator\Moderation\CommentModerator;
use AICommentModerator\Moderation\ReplyManager;
use AICommentModerator\Moderation\Verdict;
use AICommentModerator\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * AI column, row actions, bulk actions, reply-review UI and a "drafts awaiting
 * review" filter on the core Comments screen.
 */
class CommentsColumn {

	/** @var Settings */
	private $s;
	/** @var CommentModerator */
	private $moderator;
	/** @var ReplyManager */
	private $replies;

	public function __construct( Settings $s, CommentModerator $moderator, ReplyManager $replies ) {
		$this->s         = $s;
		$this->moderator = $moderator;
		$this->replies   = $replies;
	}

	public function register(): void {
		add_filter( 'manage_edit-comments_columns', [ $this, 'columns' ] );
		add_action( 'manage_comments_custom_column', [ $this, 'render' ], 10, 2 );
		add_filter( 'comment_row_actions', [ $this, 'row_actions' ], 10, 2 );
		add_filter( 'bulk_actions-edit-comments', [ $this, 'bulk_actions' ] );
		add_filter( 'handle_bulk_actions-edit-comments', [ $this, 'handle_bulk' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'bulk_notice' ] );
		add_filter( 'comment_status_links', [ $this, 'status_link' ] );
		add_filter( 'comments_list_table_query_args', [ $this, 'filter_review_queue' ] );

		foreach ( [ 'recheck', 'reply_send', 'reply_discard', 'reply_generate' ] as $a ) {
			add_action( 'wp_ajax_aicm_' . $a, [ $this, 'ajax_' . $a ] );
		}
	}

	public function columns( array $cols ): array {
		$out = [];
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'comment' === $k ) {
				$out['aicm'] = __( 'AI Moderation', 'ai-comment-moderator' );
			}
		}
		return $out;
	}

	public function render( string $column, $comment_id ): void {
		if ( 'aicm' !== $column ) {
			return;
		}
		echo '<div class="aicm-cell" data-comment="' . esc_attr( (string) $comment_id ) . '">';
		echo $this->cell_html( (int) $comment_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo '</div>';
	}

	public function cell_html( int $comment_id ): string {
		if ( get_comment_meta( $comment_id, ReplyManager::META_IS_AI, true ) ) {
			return '<span class="aicm-badge is-ai">' . esc_html__( 'AI reply', 'ai-comment-moderator' ) . '</span>';
		}

		$state = get_comment_meta( $comment_id, CommentModerator::META_STATE, true );
		$raw   = get_comment_meta( $comment_id, CommentModerator::META_VERDICT, true );
		ob_start();

		if ( 'queued' === $state ) {
			echo '<span class="aicm-badge is-queued">' . esc_html__( 'Queued', 'ai-comment-moderator' ) . '</span>';
		} elseif ( is_array( $raw ) ) {
			$v      = Verdict::from_array( $raw );
			$labels = [
				Verdict::APPROVE => __( 'Clean', 'ai-comment-moderator' ),
				Verdict::HOLD    => __( 'Held', 'ai-comment-moderator' ),
				Verdict::SPAM    => __( 'Spam', 'ai-comment-moderator' ),
				Verdict::TRASH   => __( 'Toxic', 'ai-comment-moderator' ),
				Verdict::SKIP    => __( 'Skipped', 'ai-comment-moderator' ),
				Verdict::ERROR   => __( 'Error', 'ai-comment-moderator' ),
			];
			printf( '<span class="aicm-badge is-%s" title="%s">%s</span>', esc_attr( $v->action ), esc_attr( $v->reason ), esc_html( isset( $labels[ $v->action ] ) ? $labels[ $v->action ] : $v->action ) );

			if ( 'ai' === $v->source ) {
				echo '<div class="aicm-scores">';
				$this->meter( __( 'Spam', 'ai-comment-moderator' ), $v->spam_score, (float) $this->s->get( 'spam_threshold' ) );
				$this->meter( __( 'Toxic', 'ai-comment-moderator' ), $v->toxicity_score, (float) $this->s->get( 'toxicity_threshold' ) );
				echo '</div>';
				$meta = array_filter( [ $v->sentiment, $v->language ? strtoupper( $v->language ) : '', implode( ', ', $v->categories ) ] );
				echo '<div class="aicm-meta">' . esc_html( implode( ' · ', $meta ) ) . '</div>';
			}
			if ( $v->reason ) {
				echo '<div class="aicm-reason">' . esc_html( $v->reason ) . '</div>';
			}
		} else {
			echo '<span class="aicm-badge is-none">' . esc_html__( 'Not checked', 'ai-comment-moderator' ) . '</span>';
		}

		// Reply section.
		$rstate = get_comment_meta( $comment_id, ReplyManager::META_STATE, true );
		$draft  = (string) get_comment_meta( $comment_id, ReplyManager::META_DRAFT, true );
		if ( $draft && in_array( $rstate, [ 'review', 'scheduled' ], true ) ) {
			echo '<div class="aicm-draft">';
			echo '<strong>' . ( 'review' === $rstate ? esc_html__( 'Draft reply – needs review', 'ai-comment-moderator' ) : esc_html__( 'Reply scheduled', 'ai-comment-moderator' ) ) . '</strong>';
			echo '<p class="aicm-draft-text">' . esc_html( $draft ) . '</p>';
			echo '<p class="aicm-draft-actions">';
			echo '<button type="button" class="button button-small button-primary aicm-reply-send">' . esc_html__( 'Send', 'ai-comment-moderator' ) . '</button> ';
			echo '<button type="button" class="button button-small aicm-reply-edit">' . esc_html__( 'Edit', 'ai-comment-moderator' ) . '</button> ';
			echo '<button type="button" class="button button-small aicm-reply-generate">' . esc_html__( 'Regenerate', 'ai-comment-moderator' ) . '</button> ';
			echo '<button type="button" class="button-link aicm-reply-discard">' . esc_html__( 'Discard', 'ai-comment-moderator' ) . '</button>';
			echo '</p></div>';
		} elseif ( 'sent' === $rstate ) {
			$rid = (int) get_comment_meta( $comment_id, ReplyManager::META_REPLY_ID, true );
			printf( '<div class="aicm-replied">✓ <a href="%s">%s</a></div>', esc_url( get_comment_link( $rid ) ), esc_html__( 'AI replied', 'ai-comment-moderator' ) );
		}

		return (string) ob_get_clean();
	}

	private function meter( string $label, float $score, float $threshold ): void {
		$pct   = (int) round( $score * 100 );
		$class = $score >= $threshold ? 'is-high' : ( $score >= (float) $this->s->get( 'hold_threshold' ) ? 'is-mid' : 'is-low' );
		printf(
			'<span class="aicm-meter %s" title="%s %d%%"><span class="aicm-meter-label">%s</span><span class="aicm-meter-bar"><span style="width:%d%%"></span></span><span class="aicm-meter-val">%d</span></span>',
			esc_attr( $class ),
			esc_attr( $label ),
			$pct,
			esc_html( $label ),
			$pct,
			$pct
		);
	}

	public function row_actions( array $actions, \WP_Comment $comment ): array {
		if ( ! current_user_can( 'edit_comment', $comment->comment_ID ) || get_comment_meta( $comment->comment_ID, ReplyManager::META_IS_AI, true ) ) {
			return $actions;
		}
		$actions['aicm_recheck'] = '<a href="#" class="aicm-recheck">' . esc_html__( 'Re-check with AI', 'ai-comment-moderator' ) . '</a>';
		if ( $this->replies->moderator() && 'approved' === wp_get_comment_status( $comment ) && ! get_comment_meta( $comment->comment_ID, ReplyManager::META_DRAFT, true ) ) {
			$actions['aicm_reply'] = '<a href="#" class="aicm-reply-generate">' . esc_html__( 'Draft AI reply', 'ai-comment-moderator' ) . '</a>';
		}
		return $actions;
	}

	public function bulk_actions( array $actions ): array {
		$actions['aicm_recheck']       = __( 'Re-check with AI', 'ai-comment-moderator' );
		$actions['aicm_draft_replies'] = __( 'Draft AI replies', 'ai-comment-moderator' );
		return $actions;
	}

	public function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( ! in_array( $action, [ 'aicm_recheck', 'aicm_draft_replies' ], true ) ) {
			return $redirect;
		}
		$ids  = array_slice( array_map( 'intval', $ids ), 0, 50 ); // Keep requests bounded.
		$done = 0;
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_comment', $id ) ) {
				continue;
			}
			if ( 'aicm_recheck' === $action ) {
				delete_comment_meta( $id, CommentModerator::META_STATE );
				$this->moderator->enqueue( $id );
				$done++;
			} elseif ( ! is_wp_error( $this->replies->generate_draft( $id ) ) ) {
				$done++;
			}
		}
		return add_query_arg( [ 'aicm_bulk' => $action, 'aicm_count' => $done ], $redirect );
	}

	public function bulk_notice(): void {
		if ( empty( $_GET['aicm_bulk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$count = isset( $_GET['aicm_count'] ) ? (int) $_GET['aicm_count'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$msg   = 'aicm_recheck' === $_GET['aicm_bulk'] // phpcs:ignore WordPress.Security.NonceVerification
			? sprintf( _n( '%d comment queued for AI analysis.', '%d comments queued for AI analysis.', $count, 'ai-comment-moderator' ), $count )
			: sprintf( _n( '%d reply drafted for review.', '%d replies drafted for review.', $count, 'ai-comment-moderator' ), $count );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	public function status_link( array $links ): array {
		$count = $this->review_count();
		if ( $count ) {
			$current = isset( $_GET['aicm_reply'] ) ? ' class="current"' : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$links['aicm_review'] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( admin_url( 'edit-comments.php?aicm_reply=review' ) ), $current, esc_html__( 'AI drafts', 'ai-comment-moderator' ), $count );
		}
		return $links;
	}

	public function review_count(): int {
		return (int) get_comments( [ 'count' => true, 'status' => 'approve', 'meta_key' => ReplyManager::META_STATE, 'meta_value' => 'review' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/**
	 * Narrows the Comments list (and its pagination count) to replies awaiting
	 * review. Uses the list-table filter so other screen counts are untouched.
	 */
	public function filter_review_queue( array $args ): array {
		if ( empty( $_GET['aicm_reply'] ) || 'review' !== $_GET['aicm_reply'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $args;
		}
		$args['meta_key']   = ReplyManager::META_STATE; // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_value'] = 'review';                 // phpcs:ignore WordPress.DB.SlowDBQuery
		return $args;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	private function guard(): int {
		check_ajax_referer( 'aicm_admin', 'nonce' );
		$id = isset( $_POST['comment_id'] ) ? (int) $_POST['comment_id'] : 0;
		if ( ! $id || ! current_user_can( 'edit_comment', $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-comment-moderator' ) ], 403 );
		}
		return $id;
	}

	private function respond( int $id, string $message = '' ): void {
		wp_send_json_success( [ 'html' => $this->cell_html( $id ), 'message' => $message ] );
	}

	public function ajax_recheck(): void {
		$id = $this->guard();
		$v  = $this->moderator->run( $id, true );
		if ( ! $v ) {
			$this->respond( $id, __( 'Queued for retry (provider busy).', 'ai-comment-moderator' ) );
		}
		$status = wp_get_comment_status( $id );
		wp_send_json_success( [ 'html' => $this->cell_html( $id ), 'status' => $status ] );
	}

	public function ajax_reply_send(): void {
		$id     = $this->guard();
		$edited = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : null;
		$res    = $this->replies->approve_draft( $id, $edited );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		}
		$this->respond( $id );
	}

	public function ajax_reply_discard(): void {
		$id = $this->guard();
		$this->replies->discard( $id );
		$this->respond( $id );
	}

	public function ajax_reply_generate(): void {
		$id  = $this->guard();
		$res = $this->replies->generate_draft( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		}
		$this->respond( $id );
	}
}
