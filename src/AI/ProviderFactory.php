<?php
namespace AICommentModerator\AI;

use AICommentModerator\Support\Settings;

defined( 'ABSPATH' ) || exit;

class ProviderFactory {

	const LABELS = [
		'groq'   => 'Groq',
		'gemini' => 'Google Gemini',
		'openai' => 'OpenAI',
	];

	/** Suggested models shown in the settings datalist (free text still allowed). */
	const SUGGESTED_MODELS = [
		'groq'   => [ 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'openai/gpt-oss-20b', 'openai/gpt-oss-120b' ],
		'gemini' => [ 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro' ],
		'openai' => [ 'gpt-4.1-mini', 'gpt-4.1-nano', 'gpt-4o-mini' ],
	];

	/** Groq Prompt Guard model used for the optional injection pre-check. */
	const PROMPT_GUARD_MODEL = 'meta-llama/llama-prompt-guard-2-86m';

	/**
	 * @throws AIException When the provider is unknown or unconfigured.
	 */
	public static function make( Settings $s, string $id = '' ): ProviderInterface {
		$id  = $id ? $id : (string) $s->get( 'provider' );
		$key = $s->api_key( $id );
		if ( '' === $key ) {
			throw new AIException( sprintf( 'No API key configured for %s.', isset( self::LABELS[ $id ] ) ? self::LABELS[ $id ] : $id ) );
		}
		$timeout = (int) $s->get( 'request_timeout' );
		$temp    = (float) $s->get( 'temperature' );

		switch ( $id ) {
			case 'groq':
			case 'openai':
				return new OpenAICompatibleProvider( $id, $key, (string) $s->get( $id . '_model' ), $timeout, $temp );
			case 'gemini':
				return new GeminiProvider( $key, (string) $s->get( 'gemini_model' ), $timeout, $temp );
		}
		throw new AIException( "Unknown provider: {$id}" );
	}

	/**
	 * Primary provider plus optional fallback, in call order.
	 *
	 * @return ProviderInterface[]
	 */
	public static function chain( Settings $s ): array {
		$chain = [];
		foreach ( array_unique( array_filter( [ $s->get( 'provider' ), $s->get( 'fallback_provider' ) ] ) ) as $id ) {
			try {
				$chain[] = self::make( $s, $id );
			} catch ( AIException $e ) {
				// Unconfigured fallback is simply skipped.
				continue;
			}
		}
		return $chain;
	}
}
