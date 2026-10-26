<?php
namespace AICommentModerator\Moderation;

defined( 'ABSPATH' ) || exit;

/**
 * Result of analysing a single comment.
 */
class Verdict {

	const APPROVE = 'approve';
	const HOLD    = 'hold';
	const SPAM    = 'spam';
	const TRASH   = 'trash';
	const SKIP    = 'skip';   // Not analysed (trusted author, already spam, disabled...).
	const ERROR   = 'error';  // AI unavailable; WordPress' own decision stands.

	const CATEGORIES = [ 'spam', 'self_promotion', 'toxic', 'harassment', 'hate', 'sexual', 'violence', 'off_topic', 'personal_info', 'prompt_injection' ];

	/** @var string */
	public $action = self::APPROVE;
	/** @var string ai | rule | allowlist | trusted | error */
	public $source = 'ai';
	/** @var float */
	public $spam_score = 0.0;
	/** @var float */
	public $toxicity_score = 0.0;
	/** @var string[] */
	public $categories = [];
	/** @var string positive | neutral | negative */
	public $sentiment = 'neutral';
	/** @var string ISO 639-1 */
	public $language = '';
	/** @var string */
	public $reason = '';
	/** @var bool */
	public $needs_review = false;
	/** @var bool */
	public $reply_worthy = false;
	/** @var string Draft reply produced in the same call, if requested. */
	public $reply = '';
	/** @var string */
	public $provider = '';
	/** @var string */
	public $model = '';
	/** @var int */
	public $latency_ms = 0;
	/** @var int */
	public $tokens_in = 0;
	/** @var int */
	public $tokens_out = 0;
	/** @var string */
	public $error = '';

	public static function rule( string $action, string $reason, string $source = 'rule' ): self {
		$v         = new self();
		$v->action = $action;
		$v->reason = $reason;
		$v->source = $source;
		if ( self::SPAM === $action ) {
			$v->spam_score   = 1.0;
			$v->categories[] = 'spam';
		}
		return $v;
	}

	public static function error( string $message ): self {
		$v         = new self();
		$v->action = self::ERROR;
		$v->source = 'error';
		$v->error  = $message;
		$v->reason = $message;
		return $v;
	}

	/**
	 * Builds a verdict from the model's JSON, tolerating minor schema drift.
	 */
	public static function from_ai( array $d ): self {
		$v                 = new self();
		$v->spam_score     = self::score( isset( $d['spam_score'] ) ? $d['spam_score'] : 0 );
		$v->toxicity_score = self::score( isset( $d['toxicity_score'] ) ? $d['toxicity_score'] : 0 );

		$cats = isset( $d['categories'] ) && is_array( $d['categories'] ) ? $d['categories'] : [];
		$cats = array_map( static function ( $c ) {
			return strtolower( str_replace( [ ' ', '-' ], '_', trim( (string) $c ) ) );
		}, $cats );
		$v->categories = array_values( array_intersect( array_unique( $cats ), self::CATEGORIES ) );

		$sent         = isset( $d['sentiment'] ) ? strtolower( (string) $d['sentiment'] ) : 'neutral';
		$v->sentiment = in_array( $sent, [ 'positive', 'neutral', 'negative' ], true ) ? $sent : 'neutral';

		$lang        = isset( $d['language'] ) ? strtolower( substr( preg_replace( '/[^a-z]/i', '', (string) $d['language'] ), 0, 5 ) ) : '';
		$v->language = $lang;

		$v->reason       = isset( $d['reason'] ) ? mb_substr( wp_strip_all_tags( (string) $d['reason'] ), 0, 300 ) : '';
		$v->needs_review = ! empty( $d['needs_human_review'] );
		$v->reply_worthy = ! empty( $d['reply_worthy'] );
		$v->reply        = isset( $d['reply'] ) && is_string( $d['reply'] ) ? $d['reply'] : '';
		return $v;
	}

	/**
	 * @param mixed $n
	 */
	private static function score( $n ): float {
		$f = is_numeric( $n ) ? (float) $n : 0.0;
		if ( $f > 1.0 && $f <= 100.0 ) {
			$f /= 100.0; // Model answered in percent.
		}
		return round( max( 0.0, min( 1.0, $f ) ), 3 );
	}

	public function is_flagged(): bool {
		return in_array( $this->action, [ self::HOLD, self::SPAM, self::TRASH ], true );
	}

	public function to_array(): array {
		return get_object_vars( $this );
	}

	public static function from_array( array $a ): self {
		$v = new self();
		foreach ( $a as $k => $val ) {
			if ( property_exists( $v, $k ) ) {
				$v->$k = $val;
			}
		}
		return $v;
	}
}
