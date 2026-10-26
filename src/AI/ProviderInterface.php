<?php
namespace AICommentModerator\AI;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {

	/** Machine name, e.g. "groq". */
	public function id(): string;

	/** Model in use. */
	public function model(): string;

	/**
	 * Runs a chat completion.
	 *
	 * @param string $system System instructions.
	 * @param string $user   User message.
	 * @param array  $opts   { json: bool, max_tokens: int, temperature: float, model: string }.
	 * @return array { text: string, usage: array{prompt:int,completion:int}, latency_ms: int, model: string }
	 * @throws AIException On transport/API failure.
	 */
	public function complete( string $system, string $user, array $opts = [] ): array;
}
