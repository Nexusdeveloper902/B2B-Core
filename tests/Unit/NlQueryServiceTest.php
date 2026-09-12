<?php

namespace Tests\Unit;

use App\Services\NlQuery\DeepSeekClient;
use App\Services\NlQuery\Exceptions\NlQueryException;
use App\Services\NlQuery\FunctionRegistry;
use App\Services\NlQuery\NlQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Full tool-calling orchestration — with the transport MOCKED, so the
 * LLM protocol (call -> real query -> response -> final answer) is verified
 * without any network or API quota.
 */
class NlQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function throws_not_configured_when_no_api_key(): void
    {
        config(['recycling.nl_query.api_key' => null]);

        $service = $this->makeService(new DeepSeekClient(null, 'deepseek-flash'));

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.not_configured');

        $service->ask('How many kids were late this week?');
    }

    #[Test]
    public function executes_the_selected_function_and_phrases_the_answer(): void
    {
        $fake = new class('fake-key', 'deepseek-flash') extends DeepSeekClient
        {
            public int $calls = 0;

            public bool $sawToolResult = false;

            public array $lastMessages = [];

            public function generate(array $messages, ?array $tools = null): array
            {
                $this->lastMessages = $messages;
                $this->calls++;

                // Round 1: the model selects a function to call.
                if ($this->calls === 1) {
                    return [
                        'text' => null,
                        'tool_calls' => [[
                            'id' => 'call_0_test',
                            'name' => 'get_attendance_count',
                            'arguments' => ['date' => now()->toDateString()],
                        ]],
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_0_test',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_attendance_count',
                                    'arguments' => json_encode(['date' => now()->toDateString()]),
                                ],
                            ]],
                        ],
                    ];
                }

                // Round 2: the model saw the role:"tool" result and answers.
                $this->sawToolResult = collect($messages)
                    ->contains(fn ($m) => ($m['role'] ?? '') === 'tool' && isset($m['tool_call_id']));

                return [
                    'text' => 'Three students attended class today.',
                    'tool_calls' => [],
                    'message' => ['role' => 'assistant', 'content' => 'Three students attended class today.'],
                ];
            }
        };

        $service = $this->makeService($fake);
        $result = $service->ask('How many students attended today?');

        $this->assertSame('Three students attended class today.', $result['answer']);
        $this->assertSame(2, $fake->calls, 'Exactly one tool round must happen.');
        $this->assertSame('get_attendance_count', $result['functions_called'][0]['name']);
        $this->assertTrue($fake->sawToolResult, 'The model must receive the tool result before answering.');

        // The conversation fed back to the model contains the real backend result.
        $assistantTurn = collect($fake->lastMessages)->firstWhere('role', 'assistant');
        $this->assertNotNull($assistantTurn);
        $this->assertSame('get_attendance_count', $assistantTurn['tool_calls'][0]['function']['name']);
        $this->assertSame('call_0_test', $assistantTurn['tool_calls'][0]['id'], 'The assistant turn must keep the tool call id for the tool reply.');

        $toolTurn = collect($fake->lastMessages)->firstWhere('role', 'tool');
        $this->assertNotNull($toolTurn);
        $this->assertSame('call_0_test', $toolTurn['tool_call_id']);
        $toolPayload = json_decode((string) $toolTurn['content'], true);
        $this->assertArrayHasKey('attendance_count', $toolPayload);

        // The question rides as the user message; the system message sets
        // the language/behavior contract.
        $this->assertSame('How many students attended today?', $fake->lastMessages[1]['content']);
        $this->assertSame('system', $fake->lastMessages[0]['role']);
    }

    #[Test]
    public function refuses_to_loop_forever(): void
    {
        $alwaysCalls = new class('fake-key', 'deepseek-flash') extends DeepSeekClient
        {
            public function generate(array $messages, ?array $tools = null): array
            {
                return [
                    'text' => null,
                    'tool_calls' => [[
                        'id' => 'call_loop',
                        'name' => 'get_recycling_totals',
                        'arguments' => ['date_from' => '2026-09-01', 'date_to' => '2026-09-02'],
                    ]],
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_loop',
                            'type' => 'function',
                            'function' => [
                                'name' => 'get_recycling_totals',
                                'arguments' => json_encode(['date_from' => '2026-09-01', 'date_to' => '2026-09-02']),
                            ],
                        ]],
                    ],
                ];
            }
        };

        $service = $this->makeService($alwaysCalls);

        try {
            $service->ask('recycling totals?');
            $this->fail('Expected NlQueryException for runaway tool calling.');
        } catch (NlQueryException $e) {
            $this->assertSame('nl_query.max_rounds_exceeded', $e->getMessage());
        }
    }

    #[Test]
    public function empty_model_answer_is_an_error_not_a_fake_success(): void
    {
        $silent = new class('fake-key', 'deepseek-flash') extends DeepSeekClient
        {
            public function generate(array $messages, ?array $tools = null): array
            {
                return ['text' => '', 'tool_calls' => [], 'message' => ['role' => 'assistant', 'content' => '']];
            }
        };

        $service = $this->makeService($silent);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.no_function_selected');

        $service->ask('anything');
    }

    private function makeService(DeepSeekClient $client): NlQueryService
    {
        return new NlQueryService($client, app(FunctionRegistry::class));
    }
}
