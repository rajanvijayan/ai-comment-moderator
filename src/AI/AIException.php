<?php
namespace AICommentModerator\AI;

defined( 'ABSPATH' ) || exit;

class AIException extends \RuntimeException {

	/** @var bool Whether the call may succeed if retried later (429/5xx/timeouts). */
	private $retryable;

	/** @var int Seconds the provider asked us to wait, if any. */
	private $retry_after;

	public function __construct( string $message, bool $retryable = false, int $retry_after = 0, int $code = 0 ) {
		parent::__construct( $message, $code );
		$this->retryable   = $retryable;
		$this->retry_after = $retry_after;
	}

	public function is_retryable(): bool {
		return $this->retryable;
	}

	public function retry_after(): int {
		return $this->retry_after;
	}
}
