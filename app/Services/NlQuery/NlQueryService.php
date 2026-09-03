<?php

namespace App\Services\NlQuery;

use App\Services\NlQuery\Exceptions\NlQueryException;

/**
 * NL-query orchestration (Phase E).
 *
 * Flow: question + function schema -> LLM selects a function -> backend
 * executes the REAL query -> structured result back to the LLM -> the LLM
 * phrases a natural-language answer. The LLM never computes or fabricates
 * the answer; it only selects/phrase.
 *
 * Max 3 tool rounds so a confused model cannot loop forever.
 */
class NlQueryService
{
    private const MAX_TOOL_ROUNDS = 3;

    public function __construct(
        private readonly GeminiClient $client,
        private readonly FunctionRegistry $registry,
    ) {}

    /**
     * @return array{
     *   answer: string,
     *   functions_called: array<int, array{name: string, args: array<string, mixed>}>,
     *   blocked: bool,
     *   blocked_reason: ?string
     * }
     *
     * @throws NlQueryException when no LLM credential is configured (the
     *                          controller maps this to a structured 503)
     */
    public function ask(string $question): array
    {
        if (! $this->client->isConfigured()) {
            // Credential-dependent blocker (protocol Phase E / ADR-005).
            throw NlQueryException::notConfigured();
        }

        $declarations = $this->registry->declarations();
        $contents = [
            ['role' => 'user', 'parts' => [['text' => $question]]],
        ];

        $functionsCalled = [];

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $result = $this->client->generate($contents, $declarations);

            // Final answer?
            if ($result['function_call'] === null) {
                $answer = trim((string) ($result['text'] ?? ''));

                if ($answer === '') {
                    throw NlQueryException::noFunctionSelected();
                }

                return [
                    'answer' => $answer,
                    'functions_called' => $functionsCalled,
                    'blocked' => false,
                    'blocked_reason' => null,
                ];
            }

            $call = $result['function_call'];
            $functionsCalled[] = ['name' => $call['name'], 'args' => $call['args']];

            // Execute locally — the single source of numbers is the backend.
            $functionResult = $this->registry->execute($call['name'], $call['args']);

            // Echo the model turn VERBATIM when raw parts are available
            // (Gemini 3.x thoughtSignature contract). The fallback builds
            // the same turn from the parsed call — keeps mocked tests and
            // hand-rolled clients working identically.
            $modelParts = $result['parts'] ?? [['functionCall' => [
                'name' => $call['name'],
                'args' => $call['args'],
            ]]];
            $contents[] = ['role' => 'model', 'parts' => $modelParts];

            // Continue the conversation with the function response.
            $contents[] = ['role' => 'user', 'parts' => [['functionResponse' => [
                'name' => $call['name'],
                'response' => ['result' => $functionResult],
            ]]]];
        }

        throw NlQueryException::maxRoundsExceeded();
    }
}
