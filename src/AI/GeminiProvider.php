<?php
namespace AICommentModerator\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Google Gemini (Generative Language API) client.
 */
class GeminiProvider implements ProviderInterface {

	/** @var string */
	private $api_key;
	/** @var string */
	private $model;
	/** @var int */
	private $timeout;
	/** @var float */
	private $temperature;

	public function __construct( string $api_key, string $model, int $timeout = 20, float $temperature = 0.2 ) {
		$this->api_key     = $api_key;
		$this->model       = $model;
		$this->timeout     = $timeout;
		$this->temperature = $temperature;
	}

	public function id(): string {
		return 'gemini';
	}

	public function model(): string {
		return $this->model;
	}

	public function complete( string $system, string $user, array $opts = [] ): array {
		$model = isset( $opts['model'] ) ? $opts['model'] : $this->model;
		$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';

		$gen = [
			'temperature' => isset( $opts['temperature'] ) ? (float) $opts['temperature'] : $this->temperature,
		];
		if ( ! empty( $opts['max_tokens'] ) ) {
			$gen['maxOutputTokens'] = (int) $opts['max_tokens'];
		}
		if ( ! empty( $opts['json'] ) ) {
			$gen['responseMimeType'] = 'application/json';
		}

		$body = [
			'contents'         => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $user ] ] ] ],
			'generationConfig' => $gen,
		];
		if ( '' !== $system ) {
			$body['systemInstruction'] = [ 'parts' => [ [ 'text' => $system ] ] ];
		}

		$started  = microtime( true );
		$response = wp_remote_post(
			$url,
			[
				'timeout' => $this->timeout,
				'headers' => [
					'x-goog-api-key' => $this->api_key,
					'Content-Type'   => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);
		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			throw new AIException( 'gemini: ' . $response->get_error_message(), true );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $status;
			throw new AIException( sprintf( 'gemini (%d): %s', $status, $msg ), 429 === $status || $status >= 500, 0, $status );
		}

		$text = '';
		if ( isset( $data['candidates'][0]['content']['parts'] ) ) {
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				$text .= isset( $part['text'] ) ? $part['text'] : '';
			}
		}
		if ( '' === $text ) {
			$reason = isset( $data['promptFeedback']['blockReason'] ) ? $data['promptFeedback']['blockReason'] : 'empty completion';
			throw new AIException( 'gemini: ' . $reason, false );
		}

		return [
			'text'       => $text,
			'usage'      => [
				'prompt'     => isset( $data['usageMetadata']['promptTokenCount'] ) ? (int) $data['usageMetadata']['promptTokenCount'] : 0,
				'completion' => isset( $data['usageMetadata']['candidatesTokenCount'] ) ? (int) $data['usageMetadata']['candidatesTokenCount'] : 0,
			],
			'latency_ms' => $latency,
			'model'      => $model,
		];
	}
}
