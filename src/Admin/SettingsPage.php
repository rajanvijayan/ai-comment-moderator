<?php
namespace AICommentModerator\Admin;

use AICommentModerator\AI\AIException;
use AICommentModerator\AI\ProviderFactory;
use AICommentModerator\Moderation\Analyzer;
use AICommentModerator\Support\Settings;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	const SLUG = 'ai-comment-moderator';

	/** @var Settings */
	private $s;

	public function __construct( Settings $s ) {
		$this->s = $s;
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register_setting' ] );
		add_action( 'admin_enqueue_scripts', [ Assets::class, 'enqueue' ] );
		add_action( 'wp_ajax_aicm_test_provider', [ $this, 'ajax_test_provider' ] );
		add_action( 'wp_ajax_aicm_playground', [ $this, 'ajax_playground' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( AICM_FILE ), [ $this, 'action_links' ] );
		add_action( 'admin_notices', [ $this, 'setup_notice' ] );
	}

	public function menu(): void {
		add_options_page(
			__( 'AI Comment Moderator', 'ai-comment-moderator' ),
			__( 'AI Comment Moderator', 'ai-comment-moderator' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function register_setting(): void {
		register_setting(
			'aicm_settings_group',
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
				'default'           => Settings::defaults(),
			]
		);
	}

	public function action_links( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'ai-comment-moderator' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'edit-comments.php?page=' . Dashboard::SLUG ) ) . '">' . esc_html__( 'Dashboard', 'ai-comment-moderator' ) . '</a>'
		);
		return $links;
	}

	public function setup_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || '' !== $this->s->api_key( (string) $this->s->get( 'provider' ) ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, [ 'settings_page_' . self::SLUG ], true ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'AI Comment Moderator is not checking comments yet: add an API key.', 'ai-comment-moderator' ),
			esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ),
			esc_html__( 'Configure', 'ai-comment-moderator' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Rendering helpers                                                   */
	/* ------------------------------------------------------------------ */

	private function name( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}

	private function checkbox( string $key, string $label, string $help = '' ): void {
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>%s',
			esc_attr( $this->name( $key ) ),
			checked( $this->s->bool( $key ), true, false ),
			esc_html( $label ),
			$help ? '<p class="description">' . wp_kses_post( $help ) . '</p>' : ''
		);
	}

	private function number( string $key, $min, $max, $step = 1, string $help = '', string $suffix = '' ): void {
		printf(
			'<input type="number" class="small-text" name="%s" value="%s" min="%s" max="%s" step="%s" /> %s%s',
			esc_attr( $this->name( $key ) ),
			esc_attr( (string) $this->s->get( $key ) ),
			esc_attr( (string) $min ),
			esc_attr( (string) $max ),
			esc_attr( (string) $step ),
			esc_html( $suffix ),
			$help ? '<p class="description">' . wp_kses_post( $help ) . '</p>' : ''
		);
	}

	private function slider( string $key, string $help ): void {
		$val = (float) $this->s->get( $key );
		printf(
			'<input type="range" class="aicm-range" name="%1$s" value="%2$s" min="0" max="1" step="0.05" /> <output class="aicm-range-out">%2$s</output><p class="description">%3$s</p>',
			esc_attr( $this->name( $key ) ),
			esc_attr( number_format( $val, 2 ) ),
			wp_kses_post( $help )
		);
	}

	private function select( string $key, array $options, string $help = '' ): void {
		echo '<select name="' . esc_attr( $this->name( $key ) ) . '">';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( (string) $value ), selected( (string) $this->s->get( $key ), (string) $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
	}

	private function textarea( string $key, string $help, string $placeholder = '' ): void {
		printf(
			'<textarea name="%s" rows="5" class="large-text code" placeholder="%s">%s</textarea><p class="description">%s</p>',
			esc_attr( $this->name( $key ) ),
			esc_attr( $placeholder ),
			esc_textarea( (string) $this->s->get( $key ) ),
			wp_kses_post( $help )
		);
	}

	private function row( string $label, callable $field ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		$field();
		echo '</td></tr>';
	}

	private function provider_block( string $id, string $key_url ): void {
		$label     = ProviderFactory::LABELS[ $id ];
		$has_key   = '' !== $this->s->api_key( $id );
		$from_const = Settings::key_from_constant( $id );
		?>
		<div class="aicm-provider" data-provider="<?php echo esc_attr( $id ); ?>">
			<h3><?php echo esc_html( $label ); ?>
				<span class="aicm-pill <?php echo $has_key ? 'is-ok' : ''; ?>"><?php echo $has_key ? esc_html__( 'Key saved', 'ai-comment-moderator' ) : esc_html__( 'No key', 'ai-comment-moderator' ); ?></span>
			</h3>
			<table class="form-table" role="presentation">
				<?php
				$this->row(
					__( 'API key', 'ai-comment-moderator' ),
					function () use ( $id, $has_key, $from_const, $key_url ) {
						if ( $from_const ) {
							/* translators: %s: constant name */
							echo '<p>' . sprintf( esc_html__( 'Defined in wp-config.php via %s.', 'ai-comment-moderator' ), '<code>AICM_' . esc_html( strtoupper( $id ) ) . '_API_KEY</code>' ) . '</p>';
							return;
						}
						printf(
							'<input type="password" autocomplete="new-password" class="regular-text aicm-key" name="%s" value="" placeholder="%s" />',
							esc_attr( $this->name( $id . '_api_key' ) ),
							$has_key ? esc_attr__( '•••••••• (leave blank to keep)', 'ai-comment-moderator' ) : ''
						);
						if ( $has_key ) {
							printf( ' <label><input type="checkbox" name="%s" value="1" /> %s</label>', esc_attr( $this->name( $id . '_api_key_clear' ) ), esc_html__( 'Remove key', 'ai-comment-moderator' ) );
						}
						/* translators: 1: link open, 2: link close, 3: constant */
						echo '<p class="description">' . sprintf( esc_html__( '%1$sGet a key%2$s. You can also define %3$s in wp-config.php to keep it out of the database.', 'ai-comment-moderator' ), '<a href="' . esc_url( $key_url ) . '" target="_blank" rel="noopener">', '</a>', '<code>AICM_' . esc_html( strtoupper( $id ) ) . '_API_KEY</code>' ) . '</p>';
					}
				);
				$this->row(
					__( 'Model', 'ai-comment-moderator' ),
					function () use ( $id ) {
						printf(
							'<input type="text" class="regular-text aicm-model" list="aicm-models-%1$s" name="%2$s" value="%3$s" /><datalist id="aicm-models-%1$s">',
							esc_attr( $id ),
							esc_attr( $this->name( $id . '_model' ) ),
							esc_attr( (string) $this->s->get( $id . '_model' ) )
						);
						foreach ( ProviderFactory::SUGGESTED_MODELS[ $id ] as $m ) {
							echo '<option value="' . esc_attr( $m ) . '"></option>';
						}
						echo '</datalist> <button type="button" class="button aicm-test" data-provider="' . esc_attr( $id ) . '">' . esc_html__( 'Test connection', 'ai-comment-moderator' ) . '</button> <span class="aicm-test-result" aria-live="polite"></span>';
					}
				);
				?>
			</table>
		</div>
		<?php
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$providers = ProviderFactory::LABELS;
		?>
		<div class="wrap aicm-wrap">
			<h1><?php esc_html_e( 'AI Comment Moderator', 'ai-comment-moderator' ); ?> <span class="aicm-version">v<?php echo esc_html( AICM_VERSION ); ?></span></h1>

			<nav class="nav-tab-wrapper aicm-tabs" role="tablist">
				<a href="#provider" class="nav-tab nav-tab-active"><?php esc_html_e( 'AI Provider', 'ai-comment-moderator' ); ?></a>
				<a href="#moderation" class="nav-tab"><?php esc_html_e( 'Moderation', 'ai-comment-moderator' ); ?></a>
				<a href="#replies" class="nav-tab"><?php esc_html_e( 'Auto Replies', 'ai-comment-moderator' ); ?></a>
				<a href="#advanced" class="nav-tab"><?php esc_html_e( 'Advanced', 'ai-comment-moderator' ); ?></a>
				<a href="#playground" class="nav-tab"><?php esc_html_e( 'Playground', 'ai-comment-moderator' ); ?></a>
			</nav>

			<form method="post" action="options.php" id="aicm-settings-form">
				<?php settings_fields( 'aicm_settings_group' ); ?>

				<section id="provider" class="aicm-panel is-active">
					<table class="form-table" role="presentation">
						<?php
						$this->row( __( 'Primary provider', 'ai-comment-moderator' ), function () use ( $providers ) {
							$this->select( 'provider', $providers, __( 'Groq is recommended: very low latency and generous free tier, ideal for real-time moderation.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Fallback provider', 'ai-comment-moderator' ), function () use ( $providers ) {
							$this->select( 'fallback_provider', [ '' => __( '— None —', 'ai-comment-moderator' ) ] + $providers, __( 'Used automatically when the primary provider errors or is rate-limited.', 'ai-comment-moderator' ) );
						} );
						?>
					</table>
					<?php
					$this->provider_block( 'groq', 'https://console.groq.com/keys' );
					$this->provider_block( 'gemini', 'https://aistudio.google.com/app/apikey' );
					$this->provider_block( 'openai', 'https://platform.openai.com/api-keys' );
					?>
				</section>

				<section id="moderation" class="aicm-panel">
					<table class="form-table" role="presentation">
						<?php
						$this->row( __( 'AI moderation', 'ai-comment-moderator' ), function () {
							$this->checkbox( 'moderation_enabled', __( 'Analyse new comments with AI', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Hold until checked', 'ai-comment-moderator' ), function () {
							$this->checkbox( 'hold_until_checked', __( 'Keep new comments pending until the AI clears them', 'ai-comment-moderator' ), __( 'Recommended. Spam is never visible, even for a moment. Clean comments are published automatically once checked (usually within seconds). If the AI is unavailable, comments are released as WordPress would normally handle them.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'AI approvals', 'ai-comment-moderator' ), function () {
							$this->checkbox( 'ai_can_approve', __( 'Allow the AI to approve comments WordPress put in the moderation queue', 'ai-comment-moderator' ), __( 'Off by default: the AI only makes moderation stricter than your Discussion settings.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Spam threshold', 'ai-comment-moderator' ), function () {
							$this->slider( 'spam_threshold', __( 'Spam score at or above this value → moved to Spam.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Toxicity threshold', 'ai-comment-moderator' ), function () {
							$this->slider( 'toxicity_threshold', __( 'Toxicity at or above this value triggers the toxic action below.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Toxic action', 'ai-comment-moderator' ), function () {
							$this->select( 'toxic_action', [
								'hold'  => __( 'Hold for review', 'ai-comment-moderator' ),
								'trash' => __( 'Move to Trash', 'ai-comment-moderator' ),
								'spam'  => __( 'Mark as Spam', 'ai-comment-moderator' ),
							] );
						} );
						$this->row( __( 'Review threshold', 'ai-comment-moderator' ), function () {
							$this->slider( 'hold_threshold', __( 'Borderline comments (either score at or above this) are held for a human.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Max links', 'ai-comment-moderator' ), function () {
							$this->number( 'max_links', 0, 50, 1, __( 'Comments with more links are held. 0 = no limit.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Skip trusted users', 'ai-comment-moderator' ), function () {
							$this->checkbox( 'skip_trusted', __( 'Don’t analyse comments from users who can moderate comments', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Returning commenters', 'ai-comment-moderator' ), function () {
							$this->checkbox( 'skip_approved_authors', __( 'Skip AI for authors who already have an approved comment', 'ai-comment-moderator' ), __( 'Saves API calls on busy sites.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Blocklist', 'ai-comment-moderator' ), function () {
							$this->textarea( 'blocklist', __( 'One per line. Plain words match anywhere in the content, name, email or URL; wrap in slashes for regex (e.g. <code>/casino|viagra/</code>). Matches go straight to Spam without an API call.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Allowlist', 'ai-comment-moderator' ), function () {
							$this->textarea( 'allowlist', __( 'Emails or domains (one per line, e.g. <code>jane@example.com</code> or <code>@mycompany.com</code>) that are always approved.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Custom rules', 'ai-comment-moderator' ), function () {
							$this->textarea( 'custom_rules', __( 'Plain-language policy added to the AI prompt.', 'ai-comment-moderator' ), "Self-promotion of related open-source projects is allowed.\nTreat political discussion as off-topic and hold it." );
						} );
						?>
					</table>
				</section>

				<section id="replies" class="aicm-panel">
					<table class="form-table" role="presentation">
						<?php
						$this->row( __( 'Auto replies', 'ai-comment-moderator' ), function () {
							$this->checkbox( 'auto_response', __( 'Draft context-aware replies to genuine comments', 'ai-comment-moderator' ), __( 'The AI decides which comments deserve a reply and uses the post title, excerpt and thread as context.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Human review', 'ai-comment-moderator' ), function () {
							$this->checkbox( 'reply_review', __( 'Require approval before a reply is published', 'ai-comment-moderator' ), sprintf(
								/* translators: %s: link */
								__( 'Drafts appear in the Comments screen with Send / Edit / Discard buttons. <a href="%s">View drafts awaiting review</a>.', 'ai-comment-moderator' ),
								esc_url( admin_url( 'edit-comments.php?aicm_reply=review' ) )
							) );
						} );
						$this->row( __( 'Reply author', 'ai-comment-moderator' ), function () {
							$users   = get_users( [ 'capability' => 'moderate_comments', 'orderby' => 'display_name', 'fields' => [ 'ID', 'display_name' ] ] );
							$options = [ 0 => __( '— Select a user —', 'ai-comment-moderator' ) ];
							foreach ( $users as $u ) {
								$options[ $u->ID ] = $u->display_name;
							}
							$this->select( 'moderator_user', $options, __( 'Replies are posted as this user. Required for replies.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Tone', 'ai-comment-moderator' ), function () {
							$this->select( 'response_mode', Settings::response_modes() );
						} );
						$this->row( __( 'Language', 'ai-comment-moderator' ), function () {
							$this->select( 'reply_language', [
								'match' => __( 'Reply in the commenter’s language', 'ai-comment-moderator' ),
								'site'  => __( 'Always use the site language', 'ai-comment-moderator' ),
							] );
						} );
						$this->row( __( 'Max length', 'ai-comment-moderator' ), function () {
							$this->number( 'reply_max_words', 15, 400, 5, '', __( 'words', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Delay', 'ai-comment-moderator' ), function () {
							$this->number( 'reply_delay_minutes', 0, 10080, 1, __( 'Wait before publishing (unreviewed mode) so replies feel natural.', 'ai-comment-moderator' ), __( 'minutes', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Daily cap', 'ai-comment-moderator' ), function () {
							$this->number( 'reply_daily_cap', 0, 10000, 1, __( 'Maximum automatic replies per day. 0 = unlimited.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Negative comments', 'ai-comment-moderator' ), function () {
							$this->checkbox( 'reply_skip_negative', __( 'Don’t auto-reply to negative-sentiment comments (leave those to a human)', 'ai-comment-moderator' ) );
						} );
						?>
					</table>
				</section>

				<section id="advanced" class="aicm-panel">
					<table class="form-table" role="presentation">
						<?php
						$this->row( __( 'Background processing', 'ai-comment-moderator' ), function () {
							$this->checkbox( 'process_async', __( 'Analyse comments in the background (WP-Cron)', 'ai-comment-moderator' ), __( 'Keeps comment submission instant. Failed calls are retried with exponential backoff.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Prompt-injection guard', 'ai-comment-moderator' ), function () {
							$this->checkbox( 'prompt_guard', sprintf( __( 'Pre-screen comments with Groq %s (requires a Groq key; preview model)', 'ai-comment-moderator' ), ProviderFactory::PROMPT_GUARD_MODEL ) );
						} );
						$this->row( __( 'Guard threshold', 'ai-comment-moderator' ), function () {
							$this->slider( 'prompt_guard_threshold', __( 'Injection score at or above this → held for review.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Temperature', 'ai-comment-moderator' ), function () {
							$this->number( 'temperature', 0, 1.5, 0.1, __( 'Low values give consistent classifications.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Request timeout', 'ai-comment-moderator' ), function () {
							$this->number( 'request_timeout', 5, 120, 1, '', __( 'seconds', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Safety sweep', 'ai-comment-moderator' ), function () {
							$opts = [];
							foreach ( wp_get_schedules() as $k => $v ) {
								$opts[ $k ] = $v['display'];
							}
							$this->select( 'sweep_schedule', $opts, __( 'How often to re-queue stuck comments and prune old logs.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Log retention', 'ai-comment-moderator' ), function () {
							$this->number( 'log_retention_days', 0, 3650, 1, __( '0 = keep forever.', 'ai-comment-moderator' ), __( 'days', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Alert email', 'ai-comment-moderator' ), function () {
							printf( '<input type="email" class="regular-text" name="%s" value="%s" /><p class="description">%s</p>', esc_attr( $this->name( 'notify_email' ) ), esc_attr( (string) $this->s->get( 'notify_email' ) ), esc_html__( 'Notified when a comment is flagged as toxic. Leave blank to disable.', 'ai-comment-moderator' ) );
						} );
						$this->row( __( 'Debug', 'ai-comment-moderator' ), function () {
							$this->checkbox( 'debug', __( 'Write retries to the PHP error log and log skipped comments', 'ai-comment-moderator' ) );
						} );
						?>
					</table>
				</section>

				<div class="aicm-submit"><?php submit_button(); ?></div>
			</form>

			<section id="playground" class="aicm-panel">
				<p><?php esc_html_e( 'Try the current saved configuration against any text. Nothing is stored or published.', 'ai-comment-moderator' ); ?></p>
				<p>
					<label for="aicm-pg-post"><?php esc_html_e( 'Post context (optional)', 'ai-comment-moderator' ); ?></label><br/>
					<input type="text" id="aicm-pg-post" class="large-text" placeholder="<?php esc_attr_e( 'e.g. How to speed up WordPress with object caching', 'ai-comment-moderator' ); ?>" />
				</p>
				<p>
					<label for="aicm-pg-text"><?php esc_html_e( 'Comment', 'ai-comment-moderator' ); ?></label><br/>
					<textarea id="aicm-pg-text" rows="5" class="large-text">Great article! Does Redis work better than Memcached for a WooCommerce store with ~5k products?</textarea>
				</p>
				<p><button type="button" class="button button-primary" id="aicm-pg-run"><?php esc_html_e( 'Analyse', 'ai-comment-moderator' ); ?></button> <span class="spinner"></span></p>
				<div id="aicm-pg-out" class="aicm-pg-out" aria-live="polite"></div>
			</section>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	public function ajax_test_provider(): void {
		check_ajax_referer( 'aicm_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-comment-moderator' ) ], 403 );
		}

		$id    = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$key   = isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
		$model = isset( $_POST['model'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['model'] ) ) ) : '';

		if ( ! isset( ProviderFactory::LABELS[ $id ] ) ) {
			wp_send_json_error( [ 'message' => 'Unknown provider' ] );
		}

		// Test unsaved form values without persisting them.
		$override = function ( $value ) use ( $id, $key, $model ) {
			$value = is_array( $value ) ? $value : [];
			if ( $key ) {
				$value[ $id . '_api_key' ] = $key;
			}
			if ( $model ) {
				$value[ $id . '_model' ] = $model;
			}
			return $value;
		};
		add_filter( 'option_' . Settings::OPTION, $override );
		add_filter( 'default_option_' . Settings::OPTION, $override );
		$settings = new Settings();

		try {
			$provider = ProviderFactory::make( $settings, $id );
			$res      = $provider->complete( 'Reply with a JSON object {"ok": true}.', 'ping', [ 'json' => true, 'max_tokens' => 50 ] );
			wp_send_json_success(
				[
					/* translators: 1: model 2: latency */
					'message' => sprintf( __( 'Connected · %1$s · %2$d ms', 'ai-comment-moderator' ), $res['model'], $res['latency_ms'] ),
				]
			);
		} catch ( AIException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		} finally {
			remove_filter( 'option_' . Settings::OPTION, $override );
			remove_filter( 'default_option_' . Settings::OPTION, $override );
		}
	}

	public function ajax_playground(): void {
		check_ajax_referer( 'aicm_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-comment-moderator' ) ], 403 );
		}
		$text  = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		$title = isset( $_POST['post'] ) ? sanitize_text_field( wp_unslash( $_POST['post'] ) ) : '';
		if ( '' === trim( $text ) ) {
			wp_send_json_error( [ 'message' => __( 'Enter a comment.', 'ai-comment-moderator' ) ] );
		}

		// Ephemeral, unsaved comment object.
		$comment = new \WP_Comment(
			(object) [
				'comment_ID'           => 0,
				'comment_post_ID'      => 0,
				'comment_author'       => 'Playground',
				'comment_author_email' => '',
				'comment_author_url'   => '',
				'comment_content'      => $text,
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'user_id'              => 0,
			]
		);

		$context = function ( $prompt ) use ( $title ) {
			return $title ? $prompt . "\nThe post being commented on is titled: \"" . str_replace( '"', "'", $title ) . '"' : $prompt;
		};
		add_filter( 'aicm_classification_prompt', $context );

		try {
			$analyzer = new Analyzer( $this->s );
			$verdict  = $analyzer->analyze( $comment, true );
			wp_send_json_success( $verdict->to_array() );
		} catch ( AIException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		} finally {
			remove_filter( 'aicm_classification_prompt', $context );
		}
	}
}
