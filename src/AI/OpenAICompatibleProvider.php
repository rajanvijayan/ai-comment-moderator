<?php
namespace AICommentModerator\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Chat Completions client for OpenAI-compatible APIs (Groq, OpenAI).
 */
class OpenAICompatibleProvider implements ProviderInterface {

	const ENDPOINTS = [
		'groq'   => 'https://api.groq.com/openai/v1/chat/completions',
		'openai' => 'https://api.openai.com/v1/chat/completions',
	];

	/** @var string */
	private $id;
	/** @var string */
	private $api_key;
	/** @var string */
	private $model;
	/** @var int */
	private $timeout;
	/** @var float */
	private $temperature;

	public function __construct( string $id, string $api_key, string $model, int $timeout = 20, float $temperature = 0.2 ) {
		if ( ! isset( self::ENDPOINTS[ $id ] ) ) {
			throw new AIException( "Unknown OpenAI-compatible provider: {$id}" );
		}
		$this->id          = $id;
		$this->api_key     = $api_key;
		$this->model       = $model;
		$this->timeout     = $timeout;
		$this->temperature = $temperature;
	}

	public function id(): string {
		return $this->id;
	}

	public function model(): string {
		return $this->model;
	}

	public function complete( string $system, string $user, array $opts = [] ): array {
		$model = isset( $opts['model'] ) ? $opts['model'] : $this->model;

		$messages = [];
		if ( '' !== $system ) {
			$messages[] = [ 'role' => 'system', 'content' => $system ];
		}
		$messages[] = [ 'role' => 'user', 'content' => $user ];

		$body = [
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => isset( $opts['temperature'] ) ? (float) $opts['temperature'] : $this->temperature,
		];
		if ( ! empty( $opts['max_tokens'] ) ) {
			// Groq accepts both; OpenAI's newer models require max_completion_tokens.
			$body[ 'openai' === $this->id ? 'max_completion_tokens' : 'max_tokens' ] = (int) $opts['max_tokens'];
		}
		if ( ! empty( $opts['json'] ) ) {
			$body['response_format'] = [ 'type' => 'json_object' ];
		}
		if ( 'groq' === $this->id && 0 === strpos( $model, 'openai/gpt-oss' ) ) {
			// Keep reasoning short for classification latency/cost.
			$body['reasoning_effort'] = 'low';
		}

		$started  = microtime( true );
		$response = wp_remote_post(
			self::ENDPOINTS[ $this->id ],
			[
				'timeout' => $this->timeout,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);
		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			throw new AIException( $this->id . ': ' . $response->get_error_message(), true );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$msg         = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $status;
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$retryable   = 429 === $status || $status >= 500;
			throw new AIException( sprintf( '%s (%d): %s', $this->id, $status, $msg ), $retryable, $retry_after, $status );
		}

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			throw new AIException( $this->id . ': empty completion', true );
		}

		return [
			'text'       => (string) $data['choices'][0]['message']['content'],
			'usage'      => [
				'prompt'     => isset( $data['usage']['prompt_tokens'] ) ? (int) $data['usage']['prompt_tokens'] : 0,
				'completion' => isset( $data['usage']['completion_tokens'] ) ? (int) $data['usage']['completion_tokens'] : 0,
			],
			'latency_ms' => $latency,
			'model'      => isset( $data['model'] ) ? (string) $data['model'] : $model,
		];
	}
}
