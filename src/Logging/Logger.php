<?php
namespace AICommentModerator\Logging;

use AICommentModerator\Moderation\Verdict;

defined( 'ABSPATH' ) || exit;

/**
 * Moderation log stored in a dedicated table ({prefix}aicm_log) for
 * auditing and analytics.
 */
class Logger {

	const DB_VERSION  = '1';
	const DB_OPTION   = 'aicm_db_version';
	const CRON_PRUNE  = 'aicm_prune_logs';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aicm_log';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				comment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event varchar(20) NOT NULL DEFAULT 'moderation',
				action varchar(20) NOT NULL DEFAULT '',
				source varchar(20) NOT NULL DEFAULT '',
				spam_score decimal(4,3) NOT NULL DEFAULT 0,
				toxicity_score decimal(4,3) NOT NULL DEFAULT 0,
				sentiment varchar(10) NOT NULL DEFAULT '',
				language varchar(8) NOT NULL DEFAULT '',
				categories varchar(255) NOT NULL DEFAULT '',
				reason text NULL,
				provider varchar(20) NOT NULL DEFAULT '',
				model varchar(100) NOT NULL DEFAULT '',
				latency_ms int(10) unsigned NOT NULL DEFAULT 0,
				tokens_in int(10) unsigned NOT NULL DEFAULT 0,
				tokens_out int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY comment_id (comment_id),
				KEY action (action)
			) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	public function log( \WP_Comment $comment, Verdict $v ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'created_at'     => current_time( 'mysql', true ),
				'comment_id'     => (int) $comment->comment_ID,
				'post_id'        => (int) $comment->comment_post_ID,
				'event'          => 'moderation',
				'action'         => $v->action,
				'source'         => $v->source,
				'spam_score'     => $v->spam_score,
				'toxicity_score' => $v->toxicity_score,
				'sentiment'      => $v->sentiment,
				'language'       => $v->language,
				'categories'     => implode( ',', $v->categories ),
				'reason'         => $v->error ? $v->error : $v->reason,
				'provider'       => $v->provider,
				'model'          => $v->model,
				'latency_ms'     => $v->latency_ms,
				'tokens_in'      => $v->tokens_in,
				'tokens_out'     => $v->tokens_out,
			],
			[ '%s', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' ]
		);
	}

	public function log_reply( \WP_Comment $comment, int $reply_id, bool $manual ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'created_at' => current_time( 'mysql', true ),
				'comment_id' => (int) $comment->comment_ID,
				'post_id'    => (int) $comment->comment_post_ID,
				'event'      => 'reply',
				'action'     => 'sent',
				'source'     => $manual ? 'manual' : 'auto',
				'reason'     => sprintf( 'Reply #%d', $reply_id ),
			]
		);
	}

	public function prune( int $days ): void {
		if ( $days <= 0 ) {
			return;
		}
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	}

	/**
	 * @param array $args { page, per_page, action, event, search }
	 * @return array{rows: array, total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$table    = self::table();
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 25 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where    = [ '1=1' ];
		$params   = [];

		if ( ! empty( $args['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = $args['action'];
		}
		if ( ! empty( $args['event'] ) ) {
			$where[]  = 'event = %s';
			$params[] = $args['event'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(reason LIKE %s OR categories LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$sql_where = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$rows_sql  = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, [ $per_page, ( $page - 1 ) * $per_page ] ) ), ARRAY_A );
		// phpcs:enable

		return [ 'rows' => $rows ? $rows : [], 'total' => $total ];
	}

	/**
	 * Aggregates for the dashboard.
	 */
	public function stats( int $days = 30 ): array {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB
		$by_action = $wpdb->get_results( $wpdb->prepare( "SELECT action, COUNT(*) n FROM {$table} WHERE event='moderation' AND created_at >= %s GROUP BY action", $since ), ARRAY_A );
		$daily     = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) d, action, COUNT(*) n FROM {$table} WHERE event='moderation' AND created_at >= %s GROUP BY d, action ORDER BY d", $since ), ARRAY_A );
		$agg       = $wpdb->get_row( $wpdb->prepare( "SELECT AVG(NULLIF(latency_ms,0)) latency, SUM(tokens_in) tin, SUM(tokens_out) tout, AVG(CASE WHEN source='ai' THEN spam_score END) avg_spam FROM {$table} WHERE event='moderation' AND created_at >= %s", $since ), ARRAY_A );
		$sent      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event='reply' AND created_at >= %s", $since ) );
		$sent_cnt  = $wpdb->get_results( $wpdb->prepare( "SELECT sentiment, COUNT(*) n FROM {$table} WHERE event='moderation' AND source='ai' AND created_at >= %s GROUP BY sentiment", $since ), ARRAY_A );
		$cats      = $wpdb->get_col( $wpdb->prepare( "SELECT categories FROM {$table} WHERE event='moderation' AND categories <> '' AND created_at >= %s", $since ) );
		// phpcs:enable

		$categories = [];
		foreach ( $cats as $line ) {
			foreach ( explode( ',', $line ) as $c ) {
				$categories[ $c ] = ( $categories[ $c ] ?? 0 ) + 1;
			}
		}
		arsort( $categories );

		return [
			'by_action'  => wp_list_pluck( $by_action, 'n', 'action' ),
			'daily'      => $daily,
			'latency'    => $agg ? (int) $agg['latency'] : 0,
			'tokens_in'  => $agg ? (int) $agg['tin'] : 0,
			'tokens_out' => $agg ? (int) $agg['tout'] : 0,
			'replies'    => $sent,
			'sentiment'  => wp_list_pluck( $sent_cnt, 'n', 'sentiment' ),
			'categories' => array_slice( $categories, 0, 8, true ),
		];
	}
}
