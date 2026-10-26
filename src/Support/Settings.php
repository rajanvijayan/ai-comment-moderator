<?php
namespace AICommentModerator\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Single, namespaced option array (`aicm_settings`) with typed accessors,
 * defaults, sanitisation and a migration from the v1 loose options.
 */
class Settings {

	const OPTION = 'aicm_settings';

	/** @var array|null */
	private $cache;

	public static function defaults(): array {
		return [
			// Provider.
			'provider'            => 'groq',
			'groq_api_key'        => '',
			'groq_model'          => 'llama-3.3-70b-versatile',
			'gemini_api_key'      => '',
			'gemini_model'        => 'gemini-2.5-flash',
			'openai_api_key'      => '',
			'openai_model'        => 'gpt-4.1-mini',
			'fallback_provider'   => '',
			'request_timeout'     => 20,
			'temperature'         => 0.2,

			// Moderation.
			'moderation_enabled'  => 1,
			'process_async'       => 1,
			'hold_until_checked'  => 0,        // Keep new comments pending until the AI clears them.
			'ai_can_approve'      => 0,        // Let the AI approve comments WordPress itself held.
			'spam_threshold'      => 0.85,
			'toxicity_threshold'  => 0.75,
			'hold_threshold'      => 0.5,
			'toxic_action'        => 'hold',   // hold | trash | spam.
			'skip_trusted'        => 1,        // Skip logged-in editors/admins.
			'skip_approved_authors' => 0,      // Skip authors with a previously approved comment.
			'max_links'           => 3,
			'blocklist'           => '',
			'allowlist'           => '',
			'custom_rules'        => '',
			'prompt_guard'        => 0,
			'prompt_guard_threshold' => 0.8,

			// Replies.
			'auto_response'       => 0,
			'response_mode'       => 'friendly',
			'reply_review'        => 1,        // Drafts need human approval.
			'reply_delay_minutes' => 15,
			'reply_daily_cap'     => 50,
			'reply_max_words'     => 80,
			'reply_language'      => 'match',  // match | site.
			'reply_skip_negative' => 1,
			'moderator_user'      => 0,

			// Housekeeping.
			'sweep_schedule'      => 'hourly',
			'log_retention_days'  => 90,
			'notify_email'        => '',
			'debug'               => 0,
		];
	}

	public static function response_modes(): array {
		return [
			'professional'   => __( 'Professional – formal and respectful', 'ai-comment-moderator' ),
			'friendly'       => __( 'Friendly – warm and approachable', 'ai-comment-moderator' ),
			'humorous'       => __( 'Humorous – lighthearted', 'ai-comment-moderator' ),
			'concise'        => __( 'Concise – brief and to the point', 'ai-comment-moderator' ),
			'empathetic'     => __( 'Empathetic – understanding and compassionate', 'ai-comment-moderator' ),
			'curious'        => __( 'Curious – asks a follow-up question', 'ai-comment-moderator' ),
			'supportive'     => __( 'Supportive – encouraging', 'ai-comment-moderator' ),
			'informative'    => __( 'Informative – adds useful detail', 'ai-comment-moderator' ),
			'grateful'       => __( 'Grateful – thankful and appreciative', 'ai-comment-moderator' ),
			'conversational' => __( 'Conversational – chatty and informal', 'ai-comment-moderator' ),
			'neutral'        => __( 'Neutral – balanced', 'ai-comment-moderator' ),
		];
	}

	public function all(): array {
		if ( null === $this->cache ) {
			$saved       = get_option( self::OPTION, [] );
			$this->cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
		}
		return $this->cache;
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public function bool( string $key ): bool {
		return ! empty( $this->get( $key ) );
	}

	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * API keys may also come from wp-config constants, which take precedence
	 * (e.g. define( 'AICM_GROQ_API_KEY', '...' );) so secrets stay out of the DB.
	 */
	public function api_key( string $provider ): string {
		$const = 'AICM_' . strtoupper( $provider ) . '_API_KEY';
		if ( defined( $const ) && constant( $const ) ) {
			return (string) constant( $const );
		}
		return (string) $this->get( $provider . '_api_key', '' );
	}

	public static function key_from_constant( string $provider ): bool {
		$const = 'AICM_' . strtoupper( $provider ) . '_API_KEY';
		return defined( $const ) && constant( $const );
	}

	/**
	 * Settings API sanitize callback.
	 *
	 * @param mixed $input
	 */
	public static function sanitize( $input ): array {
		$d     = self::defaults();
		$input = is_array( $input ) ? $input : [];
		$old   = get_option( self::OPTION, [] );
		$out   = [];

		$bools = [ 'moderation_enabled', 'process_async', 'hold_until_checked', 'ai_can_approve', 'skip_trusted', 'skip_approved_authors', 'prompt_guard', 'auto_response', 'reply_review', 'reply_skip_negative', 'debug' ];
		foreach ( $bools as $k ) {
			$out[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
		}

		$enum = [
			'provider'          => [ 'groq', 'gemini', 'openai' ],
			'fallback_provider' => [ '', 'groq', 'gemini', 'openai' ],
			'toxic_action'      => [ 'hold', 'trash', 'spam' ],
			'reply_language'    => [ 'match', 'site' ],
			'response_mode'     => array_keys( self::response_modes() ),
			'sweep_schedule'    => array_keys( wp_get_schedules() ),
		];
		foreach ( $enum as $k => $allowed ) {
			$v         = isset( $input[ $k ] ) ? sanitize_key( $input[ $k ] ) : $d[ $k ];
			$out[ $k ] = in_array( $v, $allowed, true ) ? $v : $d[ $k ];
		}
		if ( $out['fallback_provider'] === $out['provider'] ) {
			$out['fallback_provider'] = '';
		}

		$floats = [ 'spam_threshold', 'toxicity_threshold', 'hold_threshold', 'prompt_guard_threshold' ];
		foreach ( $floats as $k ) {
			$out[ $k ] = isset( $input[ $k ] ) ? max( 0.0, min( 1.0, (float) $input[ $k ] ) ) : $d[ $k ];
		}
		$out['temperature'] = isset( $input['temperature'] ) ? max( 0.0, min( 1.5, (float) $input['temperature'] ) ) : $d['temperature'];

		$ints = [
			'request_timeout'     => [ 5, 120 ],
			'max_links'           => [ 0, 50 ],
			'reply_delay_minutes' => [ 0, 10080 ],
			'reply_daily_cap'     => [ 0, 10000 ],
			'reply_max_words'     => [ 15, 400 ],
			'moderator_user'      => [ 0, PHP_INT_MAX ],
			'log_retention_days'  => [ 0, 3650 ],
		];
		foreach ( $ints as $k => $range ) {
			$v         = isset( $input[ $k ] ) ? (int) $input[ $k ] : $d[ $k ];
			$out[ $k ] = max( $range[0], min( $range[1], $v ) );
		}

		foreach ( [ 'groq_model', 'gemini_model', 'openai_model' ] as $k ) {
			$v         = isset( $input[ $k ] ) ? trim( sanitize_text_field( $input[ $k ] ) ) : '';
			$out[ $k ] = preg_match( '#^[A-Za-z0-9._:/-]{2,100}$#', $v ) ? $v : $d[ $k ];
		}

		// Keys: an empty submit keeps the stored key (field is never echoed back).
		foreach ( [ 'groq', 'gemini', 'openai' ] as $p ) {
			$k = $p . '_api_key';
			if ( ! empty( $input[ $k . '_clear' ] ) ) {
				$out[ $k ] = '';
			} elseif ( isset( $input[ $k ] ) && '' !== trim( $input[ $k ] ) ) {
				$out[ $k ] = trim( sanitize_text_field( $input[ $k ] ) );
			} else {
				$out[ $k ] = isset( $old[ $k ] ) ? $old[ $k ] : '';
			}
		}

		foreach ( [ 'blocklist', 'allowlist', 'custom_rules' ] as $k ) {
			$out[ $k ] = isset( $input[ $k ] ) ? sanitize_textarea_field( $input[ $k ] ) : '';
		}
		$out['notify_email'] = isset( $input['notify_email'] ) ? sanitize_email( $input['notify_email'] ) : '';

		// Re-schedule the sweep if its interval changed.
		$prev = isset( $old['sweep_schedule'] ) ? $old['sweep_schedule'] : $d['sweep_schedule'];
		if ( $prev !== $out['sweep_schedule'] ) {
			wp_clear_scheduled_hook( \AICommentModerator\Moderation\CommentModerator::CRON_SWEEP );
		}

		return $out;
	}

	/**
	 * Migrates v1 loose options (`api_key`, `auto_response`, ...) once.
	 */
	public static function maybe_migrate(): void {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}
		$s = self::defaults();

		$legacy_key = get_option( 'api_key', '' );
		if ( $legacy_key ) {
			// v1 used Google Gemini via rajanvijayan/ai-engine.
			$s['gemini_api_key'] = $legacy_key;
			$s['provider']       = 'gemini';
		}
		if ( '1' === (string) get_option( 'auto_response', '' ) ) {
			$s['auto_response'] = 1;
			$s['reply_review']  = 0; // Preserve v1 behaviour (replies went live automatically).
		}
		$mode = get_option( 'response_mode', '' );
		if ( $mode && isset( self::response_modes()[ $mode ] ) ) {
			$s['response_mode'] = $mode;
		}
		$sched = get_option( 'cron_schedule_time', '' );
		if ( $sched ) {
			$s['sweep_schedule'] = $sched;
		}
		$s['moderator_user'] = (int) get_option( 'moderator_user', 0 );

		add_option( self::OPTION, $s, '', false );
		wp_clear_scheduled_hook( 'ai_comment_moderation_cron' );

		// v1 queued replies in `_ai_reply_message`; turn them into drafts for review.
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT comment_id, meta_value FROM {$wpdb->commentmeta} WHERE meta_key = '_ai_reply_message'" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			$text = trim( wp_strip_all_tags( (string) $row->meta_value ) );
			if ( '' !== $text && 'false' !== $text ) {
				update_comment_meta( (int) $row->comment_id, '_aicm_reply_draft', $text );
				update_comment_meta( (int) $row->comment_id, '_aicm_reply_state', 'review' );
			}
			delete_comment_meta( (int) $row->comment_id, '_ai_reply_message' );
			delete_comment_meta( (int) $row->comment_id, '_ai_reply_time' );
		}
		// Legacy options are left in place (the generic `api_key` name may be
		// shared with other plugins) and only removed by uninstall.php if unused.
	}
}
