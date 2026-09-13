<?php

namespace App\Services\NlQuery;

use App\Services\NlQuery\Exceptions\NlQueryException;
use App\Services\StudentScope;

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
 *
 * TASK-027 — the caller's StudentScope (admin = school-wide, teacher =
 * own classes) rides along into every function execution, so answers
 * can never leave the caller's data wall.
 */
class NlQueryService
{
    private const MAX_TOOL_ROUNDS = 3;

    private const SYSTEM_PROMPT = 'You answer questions about a school presence platform: '
        .'class attendance, the PAE school feeding program, and recycling points. '
        .'When a question needs data, call one of the provided functions; the backend '
        .'executes the real query and returns the numbers — never invent numbers. '
        .'The caller is either an admin with school-wide access or a teacher whose '
        .'answers are automatically scoped to the classes they teach — functions '
        .'already apply that scope, so answer within it without apologizing for it. '
        .'After receiving function results, answer in the language of the question. '
        .'Be concise: at most three short sentences or a compact bullet list — no '
        .'preamble, no filler, no restating the question. Use light Markdown only: '
        .'**bold** for key numbers, "- " bullets for short lists, `backticks` for '
        .'identifiers; never headings and never tables.';

    /**
     * System prompt plus full date/time context (America/Bogota via
     * now()) so relative words ("hoy"/"today", "ayer", "ahora"/
     * "right now") resolve without asking the user — the reported
     * "Could you tell me the date?" bug. Present/absent polarity is
     * pinned here too: "quién vino/ha venido" is the PRESENT list,
     * "quién faltó/no vino" the ABSENT one — never swap them.
     */
    private function systemMessage(): string
    {
        $now = now();

        return self::SYSTEM_PROMPT
            .' Current date and time: '.$now->format('Y-m-d H:i').' ('.$now->getTimezone()->getName().', '.$now->format('l').').'
            .' Resolve relative dates/times against it and never ask the user for the date or time.'
            .' "Who came / quién vino / quién ha venido / who attended" means PRESENT students (get_present_students);'
            .' "who was absent / quién faltó / quién no vino / ausentes" means ABSENT students (get_absent_students).';
    }

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
    public function ask(string $question, ?StudentScope $scope = null): array
    {
        if (! $this->client->isConfigured()) {
            // Credential-dependent blocker (protocol Phase E / ADR-005).
            throw NlQueryException::notConfigured();
        }

        $declarations = $this->registry->declarations();
        $messages = [
            ['role' => 'system', 'content' => $this->systemMessage()],
            ['role' => 'user', 'content' => $question],
        ];

        $functionsCalled = [];

        // "< MAX_TOOL_ROUNDS" + a final answer round = at most 3 tool
        // executions, exactly the docblock contract (the old "<=" let a
        // 4th round execute real queries whose results were then thrown
        // away by maxRoundsExceeded).
        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
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

                // Execute locally — the single source of numbers is the
                // backend, already fenced by the caller's data wall.
                $functionResult = $this->registry->execute($call['name'], $call['arguments'], $scope);

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
