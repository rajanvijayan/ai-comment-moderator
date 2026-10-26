<?php
namespace AICommentModerator\CLI;

use AICommentModerator\AI\AIException;
use AICommentModerator\AI\ProviderFactory;
use AICommentModerator\Logging\Logger;
use AICommentModerator\Moderation\CommentModerator;
use AICommentModerator\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Manage AI comment moderation from the command line.
 */
class Command {

	/** @var CommentModerator */
	private $moderator;
	/** @var Logger */
	private $logger;
	/** @var Settings */
	private $s;

	public function __construct( CommentModerator $moderator, Logger $logger, Settings $s ) {
		$this->moderator = $moderator;
		$this->logger    = $logger;
		$this->s         = $s;
	}

	/**
	 * Analyse one or more comments now and apply the verdict.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Comment IDs.
	 *
	 * [--dry-run]
	 * : Print the verdict without changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-moderator check 123 124
	 *     wp ai-moderator check 123 --dry-run
	 */
	public function check( array $args, array $assoc ): void {
		$rows = [];
		foreach ( $args as $id ) {
			$comment = get_comment( (int) $id );
			if ( ! $comment ) {
				\WP_CLI::warning( "Comment {$id} not found." );
				continue;
			}
			try {
				if ( \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false ) ) {
					$v = $this->moderator->analyzer()->analyze( $comment, $this->s->bool( 'auto_response' ) );
				} else {
					$v = $this->moderator->run( (int) $id, true );
				}
			} catch ( AIException $e ) {
				\WP_CLI::warning( "Comment {$id}: " . $e->getMessage() );
				continue;
			}
			if ( $v ) {
				$rows[] = [
					'id'       => $id,
					'action'   => $v->action,
					'spam'     => $v->spam_score,
					'toxicity' => $v->toxicity_score,
					'flags'    => implode( ',', $v->categories ),
					'reason'   => $v->reason,
					'ms'       => $v->latency_ms,
				];
			}
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'action', 'spam', 'toxicity', 'flags', 'reason', 'ms' ] );
	}

	/**
	 * Analyse comments that were never checked.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : hold, approve or all.
	 * ---
	 * default: hold
	 * options:
	 *   - hold
	 *   - approve
	 *   - all
	 * ---
	 *
	 * [--limit=<n>]
	 * : Maximum comments to process.
	 * ---
	 * default: 100
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-moderator scan --status=hold --limit=500
	 */
	public function scan( array $args, array $assoc ): void {
		$limit = max( 1, (int) $assoc['limit'] );
		$total = [ 'processed' => 0, 'actions' => [] ];
		$bar   = \WP_CLI\Utils\make_progress_bar( 'Scanning', $limit );

		while ( $total['processed'] < $limit ) {
			$res = $this->moderator->scan( $assoc['status'], min( 10, $limit - $total['processed'] ) );
			if ( ! $res['processed'] ) {
				break;
			}
			$total['processed'] += $res['processed'];
			foreach ( $res['actions'] as $k => $n ) {
				$total['actions'][ $k ] = ( $total['actions'][ $k ] ?? 0 ) + $n;
			}
			$bar->tick( $res['processed'] );
		}
		$bar->finish();

		\WP_CLI::success( sprintf( '%d comments analysed. %s', $total['processed'], wp_json_encode( $total['actions'] ) ) );
	}

	/**
	 * Show moderation statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Period length.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 */
	public function stats( array $args, array $assoc ): void {
		$st = $this->logger->stats( (int) $assoc['days'] );
		if ( 'json' === $assoc['format'] ) {
			\WP_CLI::line( wp_json_encode( $st, JSON_PRETTY_PRINT ) );
			return;
		}
		$rows = [];
		foreach ( $st['by_action'] as $k => $n ) {
			$rows[] = [ 'metric' => "outcome:{$k}", 'value' => $n ];
		}
		$rows[] = [ 'metric' => 'replies_sent', 'value' => $st['replies'] ];
		$rows[] = [ 'metric' => 'avg_latency_ms', 'value' => $st['latency'] ];
		$rows[] = [ 'metric' => 'tokens', 'value' => $st['tokens_in'] + $st['tokens_out'] ];
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'metric', 'value' ] );
	}

	/**
	 * Test the configured AI provider(s).
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-moderator test
	 */
	public function test(): void {
		$chain = ProviderFactory::chain( $this->s );
		if ( ! $chain ) {
			\WP_CLI::error( 'No provider configured.' );
		}
		foreach ( $chain as $p ) {
			try {
				$r = $p->complete( 'Reply with a JSON object {"ok": true}.', 'ping', [ 'json' => true, 'max_tokens' => 50 ] );
				\WP_CLI::success( sprintf( '%s / %s – %d ms', $p->id(), $r['model'], $r['latency_ms'] ) );
			} catch ( AIException $e ) {
				\WP_CLI::warning( $p->id() . ': ' . $e->getMessage() );
			}
		}
	}
}
