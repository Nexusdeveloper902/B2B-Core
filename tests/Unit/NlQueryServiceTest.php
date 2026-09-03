<?php

namespace Tests\Unit;

use App\Services\NlQuery\Exceptions\NlQueryException;
use App\Services\NlQuery\FunctionRegistry;
use App\Services\NlQuery\GeminiClient;
use App\Services\NlQuery\NlQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Full function-calling orchestration — with the transport MOCKED, so the
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

        $service = $this->makeService(new GeminiClient(null, 'gemini-3.1-flash-lite'));

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.not_configured');

        $service->ask('How many kids were late this week?');
    }

    #[Test]
    public function executes_the_selected_function_and_phrases_the_answer(): void
    {
        $fake = new class('fake-key', 'gemini-3.1-flash-lite') extends GeminiClient
        {
            public int $calls = 0;

            public bool $sawFunctionResponse = false;

            public array $lastContents = [];

            public function generate(array $contents, ?array $tools = null): array
            {
                $this->lastContents = $contents;
                $this->calls++;

                // Round 1: the model selects a function to call.
                if ($this->calls === 1) {
                    return [
                        'text' => null,
                        'function_call' => [
                            'name' => 'get_attendance_count',
                            'args' => ['date' => now()->toDateString()],
                        ],
                    ];
                }

                // Round 2: the model saw the functionResponse and answers.
                $this->sawFunctionResponse = collect($contents)
                    ->flatMap(fn ($c) => $c['parts'])
                    ->contains(fn ($part) => isset($part['functionResponse']));

                return ['text' => 'Three students attended class today.', 'function_call' => null];
            }
        };

        $service = $this->makeService($fake);
        $result = $service->ask('How many students attended today?');

        $this->assertSame('Three students attended class today.', $result['answer']);
        $this->assertSame(2, $fake->calls, 'Exactly one tool round must happen.');
        $this->assertSame('get_attendance_count', $result['functions_called'][0]['name']);
        $this->assertTrue($fake->sawFunctionResponse, 'The model must receive the function response before answering.');

        // The conversation fed back to the model contains the real backend result.
        $modelTurn = collect($fake->lastContents)->firstWhere('role', 'model');
        $this->assertNotNull($modelTurn);
        $this->assertSame('get_attendance_count', $modelTurn['parts'][0]['functionCall']['name']);

        $responseTurn = collect($fake->lastContents)->last();
        $this->assertArrayHasKey('functionResponse', $responseTurn['parts'][0]);
        $this->assertArrayHasKey('attendance_count', $responseTurn['parts'][0]['functionResponse']['response']['result']);
    }

    #[Test]
    public function refuses_to_loop_forever(): void
    {
        $alwaysCalls = new class('fake-key', 'gemini-3.1-flash-lite') extends GeminiClient
        {
            public function generate(array $contents, ?array $tools = null): array
            {
                return [
                    'text' => null,
                    'function_call' => ['name' => 'get_recycling_totals', 'args' => ['date_from' => '2026-09-01', 'date_to' => '2026-09-02']],
                ];
            }
        };

        $service = $this->makeService($alwaysCalls);

        try {
            $service->ask('recycling totals?');
            $this->fail('Expected NlQueryException for runaway function calling.');
        } catch (NlQueryException $e) {
            $this->assertSame('nl_query.max_rounds_exceeded', $e->getMessage());
        }
    }

    #[Test]
    public function empty_model_answer_is_an_error_not_a_fake_success(): void
    {
        $silent = new class('fake-key', 'gemini-3.1-flash-lite') extends GeminiClient
        {
            public function generate(array $contents, ?array $tools = null): array
            {
                return ['text' => '', 'function_call' => null];
            }
        };

        $service = $this->makeService($silent);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.no_function_selected');

        $service->ask('anything');
    }

    private function makeService(GeminiClient $client): NlQueryService
    {
        return new NlQueryService($client, app(FunctionRegistry::class));
    }
}
