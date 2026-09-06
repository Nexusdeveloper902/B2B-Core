<?php

namespace App\Services\NlQuery;

use App\Services\NlQuery\Exceptions\NlQueryException;

/**
 * NL-query orchestration (Phase E).
 *
 * Flow: question + function schema -> LLM selects a function -> backend
 * executes the REAL query -> structured result back to the LLM -> the LLM
 * phrases a natural-language answer. The LLM never computes or fabricates
 * the answer; it only selects/phrases.
 *
 * Wire format: DeepSeek Chat Completions (OpenAI-compatible messages).
 * The assistant turn from each tool round is echoed back VERBATIM
 * (including tool_calls and their ids) followed by one role:"tool"
 * message per call carrying the backend result — exactly the multi-turn
 * contract api-docs.deepseek.com/guides/tool_calls prescribes.
 *
 * Max 3 tool rounds so a confused model cannot loop forever.
 */
class NlQueryService
{
    private const MAX_TOOL_ROUNDS = 3;

    private const SYSTEM_PROMPT = 'You answer questions about a school presence platform: '
        .'class attendance, the PAE school feeding program, and recycling points. '
        .'When a question needs data, call one of the provided functions; the backend '
        .'executes the real query and returns the numbers — never invent numbers. '
        .'After receiving function results, answer concisely in the language of the question.';

    public function __construct(
        private readonly DeepSeekClient $client,
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
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $question],
        ];

        $functionsCalled = [];

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $result = $this->client->generate($messages, $declarations);

            // Final answer?
            if ($result['tool_calls'] === []) {
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

            // Echo the assistant turn VERBATIM (raw message, including
            // tool_calls with their ids) — the documented multi-turn tool
            // contract; rebuilding it by hand would drop the ids and break
            // the role:"tool" replies.
            $messages[] = $result['message'];

            foreach ($result['tool_calls'] as $call) {
                $functionsCalled[] = ['name' => $call['name'], 'args' => $call['arguments']];

                // Execute locally — the single source of numbers is the backend.
                $functionResult = $this->registry->execute($call['name'], $call['arguments']);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => json_encode($functionResult),
                ];
            }
        }

        throw NlQueryException::maxRoundsExceeded();
    }
}
