<?php
namespace AICommentModerator\Admin;

use AICommentModerator\Logging\Logger;
use AICommentModerator\Moderation\CommentModerator;
use AICommentModerator\Moderation\ReplyManager;
use AICommentModerator\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Comments → AI Moderation: stats, trends, bulk scan tools and the audit log.
 */
class Dashboard {

	const SLUG = 'aicm-dashboard';

	/** @var Settings */
	private $s;
	/** @var Logger */
	private $logger;
	/** @var CommentModerator */
	private $moderator;

	public function __construct( Settings $s, Logger $logger, CommentModerator $moderator ) {
		$this->s         = $s;
		$this->logger    = $logger;
		$this->moderator = $moderator;
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'wp_ajax_aicm_scan', [ $this, 'ajax_scan' ] );
	}

	public function menu(): void {
		add_comments_page(
			__( 'AI Moderation', 'ai-comment-moderator' ),
			__( 'AI Moderation', 'ai-comment-moderator' ),
			'moderate_comments',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$days   = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 30;
		$filter = [
			'page'     => isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1,
			'per_page' => 25,
			'action'   => isset( $_GET['log_action'] ) ? sanitize_key( $_GET['log_action'] ) : '',
			'event'    => isset( $_GET['log_event'] ) ? sanitize_key( $_GET['log_event'] ) : '',
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		];
		// phpcs:enable

		$stats   = $this->logger->stats( $days );
		$a       = $stats['by_action'];
		$checked = array_sum( $a );
		$blocked = ( $a['spam'] ?? 0 ) + ( $a['trash'] ?? 0 );
		$held    = $a['hold'] ?? 0;
		$errors  = $a['error'] ?? 0;
		$review  = (int) get_comments( [ 'count' => true, 'status' => 'approve', 'meta_key' => ReplyManager::META_STATE, 'meta_value' => 'review' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$pending = (int) wp_count_comments()->moderated;
		$base    = admin_url( 'edit-comments.php?page=' . self::SLUG );
		?>
		<div class="wrap aicm-wrap aicm-dashboard">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'AI Moderation', 'ai-comment-moderator' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . SettingsPage::SLUG ) ); ?>"><?php esc_html_e( 'Settings', 'ai-comment-moderator' ); ?></a>
			<hr class="wp-header-end" />

			<p class="aicm-range-links">
				<?php foreach ( [ 7, 30, 90 ] as $d ) : ?>
					<a class="<?php echo $d === $days ? 'current' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'days', $d, $base ) ); ?>"><?php echo esc_html( sprintf( _n( 'Last %d day', 'Last %d days', $d, 'ai-comment-moderator' ), $d ) ); ?></a>
				<?php endforeach; ?>
			</p>

			<div class="aicm-cards">
				<?php
				$this->card( __( 'Comments analysed', 'ai-comment-moderator' ), number_format_i18n( $checked ) );
				$this->card( __( 'Blocked (spam / toxic)', 'ai-comment-moderator' ), number_format_i18n( $blocked ), $checked ? round( 100 * $blocked / $checked ) . '%' : '' );
				$this->card( __( 'Held for review', 'ai-comment-moderator' ), number_format_i18n( $held ) );
				$this->card( __( 'AI replies sent', 'ai-comment-moderator' ), number_format_i18n( $stats['replies'] ) );
				$this->card( __( 'Avg. AI latency', 'ai-comment-moderator' ), $stats['latency'] ? number_format_i18n( $stats['latency'] ) . ' ms' : '—' );
				$this->card( __( 'Tokens used', 'ai-comment-moderator' ), number_format_i18n( $stats['tokens_in'] + $stats['tokens_out'] ) );
				?>
			</div>

			<?php if ( $errors ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( _n( '%d comment could not be analysed (provider error). Check the log below.', '%d comments could not be analysed (provider error). Check the log below.', $errors, 'ai-comment-moderator' ), $errors ) ); ?></p></div>
			<?php endif; ?>

			<div class="aicm-grid">
				<div class="aicm-box aicm-box-wide">
					<h2><?php esc_html_e( 'Daily activity', 'ai-comment-moderator' ); ?></h2>
					<?php $this->chart( $stats['daily'], $days ); ?>
				</div>

				<div class="aicm-box">
					<h2><?php esc_html_e( 'Top flags', 'ai-comment-moderator' ); ?></h2>
					<?php $this->bars( $stats['categories'] ); ?>
					<h2><?php esc_html_e( 'Sentiment', 'ai-comment-moderator' ); ?></h2>
					<?php $this->bars( $stats['sentiment'] ); ?>
				</div>

				<div class="aicm-box">
					<h2><?php esc_html_e( 'Work queue', 'ai-comment-moderator' ); ?></h2>
					<ul class="aicm-queue">
						<li><a href="<?php echo esc_url( admin_url( 'edit-comments.php?aicm_reply=review' ) ); ?>"><?php echo esc_html( sprintf( _n( '%d AI reply awaiting review', '%d AI replies awaiting review', $review, 'ai-comment-moderator' ), $review ) ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ); ?>"><?php echo esc_html( sprintf( _n( '%d comment pending moderation', '%d comments pending moderation', $pending, 'ai-comment-moderator' ), $pending ) ); ?></a></li>
					</ul>
					<h2><?php esc_html_e( 'Scan existing comments', 'ai-comment-moderator' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Analyse comments that were never checked by the AI, in batches of 10.', 'ai-comment-moderator' ); ?></p>
					<p>
						<button type="button" class="button aicm-scan" data-status="hold"><?php esc_html_e( 'Scan pending', 'ai-comment-moderator' ); ?></button>
						<button type="button" class="button aicm-scan" data-status="approve"><?php esc_html_e( 'Scan approved', 'ai-comment-moderator' ); ?></button>
					</p>
					<div class="aicm-scan-progress" aria-live="polite"></div>
				</div>
			</div>

			<h2><?php esc_html_e( 'Moderation log', 'ai-comment-moderator' ); ?></h2>
			<?php $this->log_table( $filter, $base ); ?>
		</div>
		<?php
	}

	private function card( string $label, string $value, string $sub = '' ): void {
		printf(
			'<div class="aicm-card"><span class="aicm-card-value">%s%s</span><span class="aicm-card-label">%s</span></div>',
			esc_html( $value ),
			$sub ? ' <small>' . esc_html( $sub ) . '</small>' : '',
			esc_html( $label )
		);
	}

	private function bars( array $data ): void {
		if ( ! $data ) {
			echo '<p class="description">' . esc_html__( 'No data yet.', 'ai-comment-moderator' ) . '</p>';
			return;
		}
		$max = max( $data );
		echo '<ul class="aicm-bars">';
		foreach ( $data as $label => $n ) {
			printf(
				'<li><span class="aicm-bars-label">%s</span><span class="aicm-bars-track"><span style="width:%d%%"></span></span><span class="aicm-bars-n">%s</span></li>',
				esc_html( ucwords( str_replace( '_', ' ', (string) $label ) ) ),
				(int) round( 100 * $n / max( 1, $max ) ),
				esc_html( number_format_i18n( (int) $n ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * Server-rendered stacked bar chart (no JS dependency).
	 */
	private function chart( array $daily, int $days ): void {
		$series = [ 'approve' => [], 'hold' => [], 'spam' => [], 'trash' => [] ];
		$dates  = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$dates[] = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		}
		foreach ( $daily as $row ) {
			if ( isset( $series[ $row['action'] ] ) ) {
				$series[ $row['action'] ][ $row['d'] ] = (int) $row['n'];
			}
		}
		$totals = [];
		foreach ( $dates as $d ) {
			$totals[ $d ] = 0;
			foreach ( $series as $s ) {
				$totals[ $d ] += $s[ $d ] ?? 0;
			}
		}
		$max = max( 1, max( $totals ) );
		if ( 1 === $max && 0 === array_sum( $totals ) ) {
			echo '<p class="description">' . esc_html__( 'No comments analysed in this period yet.', 'ai-comment-moderator' ) . '</p>';
			return;
		}

		$w     = 720;
		$h     = 180;
		$bar_w = $w / count( $dates );
		echo '<svg class="aicm-chart" viewBox="0 0 ' . (int) $w . ' ' . ( $h + 20 ) . '" role="img" aria-label="' . esc_attr__( 'Comments analysed per day', 'ai-comment-moderator' ) . '">';
		foreach ( $dates as $i => $d ) {
			$y = $h;
			foreach ( $series as $action => $s ) {
				$n = $s[ $d ] ?? 0;
				if ( ! $n ) {
					continue;
				}
				$bh = $h * $n / $max;
				$y -= $bh;
				printf(
					'<rect class="aicm-c-%s" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="1.5"><title>%s · %s: %d</title></rect>',
					esc_attr( $action ),
					$i * $bar_w + 1,
					$y,
					max( 1, $bar_w - 2 ),
					$bh,
					esc_html( $d ),
					esc_html( $action ),
					(int) $n
				);
			}
		}
		printf( '<text x="0" y="%d" class="aicm-axis">%s</text>', $h + 16, esc_html( date_i18n( 'M j', strtotime( $dates[0] ) ) ) );
		printf( '<text x="%d" y="%d" class="aicm-axis" text-anchor="end">%s</text>', $w, $h + 16, esc_html( date_i18n( 'M j', strtotime( end( $dates ) ) ) ) );
		echo '</svg>';
		echo '<p class="aicm-legend"><span class="aicm-c-approve"></span>' . esc_html__( 'Clean', 'ai-comment-moderator' ) . ' <span class="aicm-c-hold"></span>' . esc_html__( 'Held', 'ai-comment-moderator' ) . ' <span class="aicm-c-spam"></span>' . esc_html__( 'Spam', 'ai-comment-moderator' ) . ' <span class="aicm-c-trash"></span>' . esc_html__( 'Toxic', 'ai-comment-moderator' ) . '</p>';
	}

	private function log_table( array $filter, string $base ): void {
		$res   = $this->logger->query( $filter );
		$pages = (int) ceil( $res['total'] / $filter['per_page'] );
		?>
		<form method="get" class="aicm-log-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<select name="log_action">
				<option value=""><?php esc_html_e( 'All outcomes', 'ai-comment-moderator' ); ?></option>
				<?php foreach ( [ 'approve', 'hold', 'spam', 'trash', 'error', 'skip', 'sent' ] as $act ) : ?>
					<option value="<?php echo esc_attr( $act ); ?>" <?php selected( $filter['action'], $act ); ?>><?php echo esc_html( ucfirst( $act ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="log_event">
				<option value=""><?php esc_html_e( 'All events', 'ai-comment-moderator' ); ?></option>
				<option value="moderation" <?php selected( $filter['event'], 'moderation' ); ?>><?php esc_html_e( 'Moderation', 'ai-comment-moderator' ); ?></option>
				<option value="reply" <?php selected( $filter['event'], 'reply' ); ?>><?php esc_html_e( 'Replies', 'ai-comment-moderator' ); ?></option>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $filter['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search reasons…', 'ai-comment-moderator' ); ?>" />
			<?php submit_button( __( 'Filter', 'ai-comment-moderator' ), 'secondary', '', false ); ?>
		</form>

		<table class="widefat striped aicm-log">
			<thead><tr>
				<th><?php esc_html_e( 'When', 'ai-comment-moderator' ); ?></th>
				<th><?php esc_html_e( 'Comment', 'ai-comment-moderator' ); ?></th>
				<th><?php esc_html_e( 'Outcome', 'ai-comment-moderator' ); ?></th>
				<th><?php esc_html_e( 'Spam', 'ai-comment-moderator' ); ?></th>
				<th><?php esc_html_e( 'Toxic', 'ai-comment-moderator' ); ?></th>
				<th><?php esc_html_e( 'Flags', 'ai-comment-moderator' ); ?></th>
				<th><?php esc_html_e( 'Reason', 'ai-comment-moderator' ); ?></th>
				<th><?php esc_html_e( 'Model', 'ai-comment-moderator' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $res['rows'] ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'No log entries yet.', 'ai-comment-moderator' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $res['rows'] as $r ) : ?>
				<tr>
					<td><?php echo esc_html( human_time_diff( strtotime( $r['created_at'] . ' UTC' ) ) . ' ' . __( 'ago', 'ai-comment-moderator' ) ); ?></td>
					<td><?php
					$c = get_comment( (int) $r['comment_id'] );
					if ( $c ) {
						printf( '<a href="%s">#%d</a> %s', esc_url( admin_url( 'comment.php?action=editcomment&c=' . $c->comment_ID ) ), (int) $c->comment_ID, esc_html( wp_trim_words( $c->comment_content, 10 ) ) );
					} else {
						echo '#' . (int) $r['comment_id'] . ' <em>' . esc_html__( '(deleted)', 'ai-comment-moderator' ) . '</em>';
					}
					?></td>
					<td><span class="aicm-badge is-<?php echo esc_attr( $r['action'] ); ?>"><?php echo esc_html( 'reply' === $r['event'] ? __( 'Reply sent', 'ai-comment-moderator' ) : ucfirst( $r['action'] ) ); ?></span> <small><?php echo esc_html( $r['source'] ); ?></small></td>
					<td><?php echo 'reply' === $r['event'] ? '' : esc_html( number_format( (float) $r['spam_score'], 2 ) ); ?></td>
					<td><?php echo 'reply' === $r['event'] ? '' : esc_html( number_format( (float) $r['toxicity_score'], 2 ) ); ?></td>
					<td><?php echo esc_html( str_replace( ',', ', ', $r['categories'] ) ); ?></td>
					<td><?php echo esc_html( (string) $r['reason'] ); ?></td>
					<td><?php echo $r['model'] ? esc_html( $r['provider'] . ' / ' . $r['model'] ) . ( $r['latency_ms'] ? '<br/><small>' . esc_html( $r['latency_ms'] ) . ' ms</small>' : '' ) : ''; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
				[
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => max( 1, $filter['page'] ),
					'total'   => $pages,
				]
			);
			echo '</div></div>';
		}
	}

	public function ajax_scan(): void {
		check_ajax_referer( 'aicm_admin', 'nonce' );
		if ( ! current_user_can( 'moderate_comments' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-comment-moderator' ) ], 403 );
		}
		$status = isset( $_POST['status'] ) && 'approve' === $_POST['status'] ? 'approve' : 'hold';
		$res    = $this->moderator->scan( $status, 10 );
		wp_send_json_success( $res );
	}
}
