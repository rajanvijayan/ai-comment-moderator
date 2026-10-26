<?php
namespace AICommentModerator\Moderation;

use AICommentModerator\AI\AIException;
use AICommentModerator\AI\ProviderFactory;
use AICommentModerator\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a comment into a Verdict: cheap local rules first, then the AI chain
 * (primary provider → fallback), then threshold-based decision.
 */
class Analyzer {

	const BACKOFF_TRANSIENT = 'aicm_backoff_until';

	/** @var Settings */
	private $s;
	/** @var PromptBuilder */
	private $prompts;

	public function __construct( Settings $s ) {
		$this->s       = $s;
		$this->prompts = new PromptBuilder( $s );
	}

	/**
	 * @throws AIException Retryable failures bubble up so the job can be retried.
	 */
	public function analyze( \WP_Comment $comment, bool $with_reply = false ): Verdict {
		$pre = $this->prefilter( $comment );
		if ( $pre ) {
			return $pre;
		}

		$until = (int) get_transient( self::BACKOFF_TRANSIENT );
		if ( $until > time() ) {
			throw new AIException( 'Rate limited; backing off.', true, $until - time() );
		}

		$guard = $this->prompt_guard( $comment );
		if ( $guard ) {
			return $guard;
		}

		$chain = ProviderFactory::chain( $this->s );
		if ( ! $chain ) {
			return Verdict::error( __( 'No AI provider is configured.', 'ai-comment-moderator' ) );
		}

		$system   = $this->prompts->classification_system( $with_reply );
		$user     = $this->prompts->context( $comment );
		$last     = null;
		$retrying = false;

		foreach ( $chain as $provider ) {
			try {
				$res  = $provider->complete( $system, $user, [ 'json' => true, 'max_tokens' => $with_reply ? 700 : 300 ] );
				$json = $this->parse_json( $res['text'] );
				if ( null === $json ) {
					throw new AIException( $provider->id() . ': response was not valid JSON', true );
				}
				$v             = Verdict::from_ai( $json );
				$v->provider   = $provider->id();
				$v->model      = $res['model'];
				$v->latency_ms = $res['latency_ms'];
				$v->tokens_in  = $res['usage']['prompt'];
				$v->tokens_out = $res['usage']['completion'];
				$v->reply      = $with_reply && $v->reply_worthy ? ReplyManager::clean_reply( $v->reply, (int) $this->s->get( 'reply_max_words' ) ) : '';
				$this->decide( $v, $comment );
				return apply_filters( 'aicm_verdict', $v, $comment );
			} catch ( AIException $e ) {
				$last = $e;
				if ( 429 === $e->getCode() && 'groq' === $provider->id() ) {
					set_transient( self::BACKOFF_TRANSIENT, time() + max( 10, $e->retry_after() ), max( 10, $e->retry_after() ) );
				}
				$retrying = $retrying || $e->is_retryable();
				continue; // Try the fallback provider.
			}
		}

		if ( $retrying ) {
			throw $last;
		}
		return Verdict::error( $last ? $last->getMessage() : 'Unknown AI error' );
	}

	/**
	 * Applies thresholds to AI scores.
	 */
	public function decide( Verdict $v, \WP_Comment $comment ): void {
		$spam = (float) $this->s->get( 'spam_threshold' );
		$tox  = (float) $this->s->get( 'toxicity_threshold' );
		$hold = (float) $this->s->get( 'hold_threshold' );

		$max_links = (int) $this->s->get( 'max_links' );
		$links     = preg_match_all( '#https?://#i', $comment->comment_content );

		if ( $v->spam_score >= $spam ) {
			$v->action = Verdict::SPAM;
		} elseif ( $v->toxicity_score >= $tox ) {
			$v->action = (string) $this->s->get( 'toxic_action' );
		} elseif ( $v->needs_review || max( $v->spam_score, $v->toxicity_score ) >= $hold || ( $max_links && $links > $max_links ) ) {
			$v->action = Verdict::HOLD;
			if ( $max_links && $links > $max_links && ! $v->reason ) {
				$v->reason = sprintf( __( 'Contains %d links.', 'ai-comment-moderator' ), $links );
			}
		} else {
			$v->action = Verdict::APPROVE;
		}

		if ( Verdict::APPROVE !== $v->action ) {
			$v->reply        = '';
			$v->reply_worthy = false;
		}
	}

	/**
	 * Local rules that don't need an API call.
	 */
	public function prefilter( \WP_Comment $comment ): ?Verdict {
		if ( in_array( $comment->comment_type, [ 'pingback', 'trackback' ], true ) ) {
			return Verdict::rule( Verdict::SKIP, 'Pingback/trackback', 'trusted' );
		}

		$user_id = (int) $comment->user_id;
		if ( $user_id && ( (int) $this->s->get( 'moderator_user' ) === $user_id || get_comment_meta( (int) $comment->comment_ID, '_aicm_is_ai_reply', true ) ) ) {
			return Verdict::rule( Verdict::SKIP, 'AI reply / moderator account', 'trusted' );
		}
		if ( $this->s->bool( 'skip_trusted' ) && $user_id && user_can( $user_id, 'moderate_comments' ) ) {
			return Verdict::rule( Verdict::SKIP, 'Trusted user', 'trusted' );
		}

		$email = strtolower( (string) $comment->comment_author_email );
		foreach ( $this->lines( 'allowlist' ) as $line ) {
			$line = strtolower( $line );
			if ( $email && ( $email === $line || ( '@' === $line[0] && substr( $email, -strlen( $line ) ) === $line ) ) ) {
				return Verdict::rule( Verdict::APPROVE, 'Allow-listed author', 'allowlist' );
			}
		}

		$haystack = implode( "\n", [ $comment->comment_content, $comment->comment_author, $comment->comment_author_email, $comment->comment_author_url ] );
		foreach ( $this->lines( 'blocklist' ) as $line ) {
			if ( strlen( $line ) > 2 && '/' === $line[0] && '/' === substr( $line, -1 ) ) {
				$match = @preg_match( $line . 'iu', $haystack ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			} else {
				$match = false !== ( function_exists( 'mb_stripos' ) ? mb_stripos( $haystack, $line ) : stripos( $haystack, $line ) );
			}
			if ( $match ) {
				return Verdict::rule( Verdict::SPAM, sprintf( 'Blocklist match: %s', $line ) );
			}
		}

		if ( $this->s->bool( 'skip_approved_authors' ) && $email ) {
			$prior = get_comments( [ 'author_email' => $email, 'status' => 'approve', 'count' => true, 'comment__not_in' => [ (int) $comment->comment_ID ] ] );
			if ( $prior > 0 ) {
				return Verdict::rule( Verdict::SKIP, 'Returning approved author', 'trusted' );
			}
		}

		return null;
	}

	/**
	 * Optional Groq Prompt Guard pre-check. Fails open.
	 */
	private function prompt_guard( \WP_Comment $comment ): ?Verdict {
		if ( ! $this->s->bool( 'prompt_guard' ) || '' === $this->s->api_key( 'groq' ) ) {
			return null;
		}
		try {
			$provider = ProviderFactory::make( $this->s, 'groq' );
			$res      = $provider->complete( '', mb_substr( $comment->comment_content, 0, 2000 ), [ 'model' => ProviderFactory::PROMPT_GUARD_MODEL, 'temperature' => 0 ] );
			if ( preg_match( '/[01](?:\.\d+)?(?:e-?\d+)?/i', $res['text'], $m ) ) {
				$score = (float) $m[0];
				if ( $score >= (float) $this->s->get( 'prompt_guard_threshold' ) ) {
					$v               = Verdict::rule( Verdict::HOLD, sprintf( 'Possible prompt injection (guard score %.2f)', $score ) );
					$v->categories[] = 'prompt_injection';
					$v->provider     = 'groq';
					$v->model        = ProviderFactory::PROMPT_GUARD_MODEL;
					return $v;
				}
			}
		} catch ( AIException $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Plain reply generation (used for manual "Generate reply" actions).
	 *
	 * @throws AIException
	 */
	public function generate_reply( \WP_Comment $comment ): string {
		$last = null;
		foreach ( ProviderFactory::chain( $this->s ) as $provider ) {
			try {
				$res = $provider->complete( $this->prompts->reply_system(), $this->prompts->context( $comment ), [ 'max_tokens' => 500, 'temperature' => 0.6 ] );
				return ReplyManager::clean_reply( $res['text'], (int) $this->s->get( 'reply_max_words' ) );
			} catch ( AIException $e ) {
				$last = $e;
			}
		}
		throw $last ? $last : new AIException( 'No AI provider is configured.' );
	}

	/**
	 * Extracts the first JSON object even if wrapped in prose or code fences.
	 */
	public function parse_json( string $text ): ?array {
		$text = trim( $text );
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
			$data = json_decode( $m[0], true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return null;
	}

	private function lines( string $key ): array {
		$raw = preg_split( '/\r\n|\r|\n/', (string) $this->s->get( $key ) );
		return array_values( array_filter( array_map( 'trim', $raw ), 'strlen' ) );
	}
}
