<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\NlQuery\Exceptions\NlQueryException;
use App\Services\NlQuery\GeminiClient;
use App\Services\NlQuery\NlQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NlQueryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function without_a_gemini_key_the_endpoint_reports_a_structured_blocker(): void
    {
        config(['recycling.nl_query.api_key' => null]);
        // Re-resolve the singleton with the nulled key.
        $this->app->forgetInstance(GeminiClient::class);

        $response = $this->actingAs($this->user('admin'))
            ->postJson('/api/v1/nl-query', [
                'question' => 'How many kids were late this week?',
            ]);

        // Blocked ≠ failed: an explicit, honest 503 with the reason code.
        $response->assertStatus(503)
            ->assertJson([
                'status' => 'blocked',
                'blocked_reason' => 'missing_llm_credential',
            ]);
    }

    #[Test]
    public function an_invalid_key_reports_its_own_actionable_blocker(): void
    {
        // TASK-007: every failure class gets a DISTINCT reason + message,
        // so the owner sees "your key was rejected" instead of a generic
        // "service unavailable" that hides the actual cause.
        $this->app->instance(GeminiClient::class, new class('stale-key', 'gemini-3.1-flash-lite') extends GeminiClient
        {
            public function generate(array $contents, ?array $tools = null): array
            {
                throw NlQueryException::invalidKey(
                    'HTTP 400 [API_KEY_INVALID] INVALID_ARGUMENT: API key not valid. Please pass a valid API key.'
                );
            }
        });
        $this->app->forgetInstance(NlQueryService::class);

        $response = $this->actingAs($this->user('admin'))
            ->postJson('/api/v1/nl-query', [
                'question' => 'How many kids were late this week?',
            ]);

        $json = $response->assertStatus(503)->json();
        $this->assertSame('blocked', $json['status']);
        $this->assertSame('llm_invalid_key', $json['blocked_reason']);
        // The message must be actionable: point at ./run llm-check.
        $this->assertStringContainsString('llm-check', (string) $json['message']);
    }

    #[Test]
    public function a_region_refusal_reports_its_own_actionable_blocker(): void
    {
        $this->app->instance(GeminiClient::class, new class('valid-key', 'gemini-3.1-flash-lite') extends GeminiClient
        {
            public function generate(array $contents, ?array $tools = null): array
            {
                throw NlQueryException::regionUnsupported(
                    'HTTP 400 FAILED_PRECONDITION: User location is not supported for the API use.'
                );
            }
        });
        $this->app->forgetInstance(NlQueryService::class);

        $response = $this->actingAs($this->user('admin'))
            ->postJson('/api/v1/nl-query', [
                'question' => 'How many kids were late this week?',
            ]);

        $json = $response->assertStatus(503)->json();
        $this->assertSame('llm_region_unsupported', $json['blocked_reason']);
        // Honest semantics: the refusal is about the region, not the key.
        $this->assertStringContainsString('valid', (string) $json['message']);
    }

    #[Test]
    public function teachers_are_forbidden(): void
    {
        $this->actingAs($this->user('teacher'))
            ->postJson('/api/v1/nl-query', ['question' => 'attendance?'])
            ->assertForbidden();
    }

    #[Test]
    public function guests_are_unauthorized(): void
    {
        $this->postJson('/api/v1/nl-query', ['question' => 'attendance?'])
            ->assertUnauthorized();
    }

    #[Test]
    public function a_missing_question_is_a_validation_error(): void
    {
        $this->actingAs($this->user('admin'))
            ->postJson('/api/v1/nl-query', [])
            ->assertUnprocessable();
    }

    /**
     * LIVE smoke test — only runs when a real key AND the explicit opt-in
     * flag are both set (keeps the free tier and CI safe by default).
     *
     * RUN_LIVE_LLM_TESTS=1 GEMINI_API_KEY=... php artisan test --filter=NlQueryApiTest
     */
    #[Test]
    public function live_end_to_end_query_with_a_real_gemini_key(): void
    {
        if (empty(env('GEMINI_API_KEY')) || env('RUN_LIVE_LLM_TESTS') !== '1') {
            $this->markTestSkipped('Live LLM test requires GEMINI_API_KEY and RUN_LIVE_LLM_TESTS=1 (used sparingly).');
        }

        // Give the journey some real data to ask about.
        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->cardUidFor('Maria González'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')]);

        $response = $this->actingAs($this->user('admin'))
            ->postJson('/api/v1/nl-query', [
                'question' => 'How many students attended class today, '.now()->toDateString().'?',
            ]);

        // Surface the honest blocker payload when the live call is refused
        // (raw Google error remains visible in the CI probe step).
        if ($response->status() !== 200) {
            fwrite(STDERR, "\n[live-smoke] blocked payload: ".json_encode($response->json())."\n");
        }

        $json = $response->assertOk()->json();

        $this->assertSame('ok', $json['status']);
        $this->assertNotEmpty($json['answer']);
        $this->assertNotEmpty($json['functions_called'], 'The model must have called a backend function.');
        $this->assertContains('get_attendance_count', array_column($json['functions_called'], 'name'));
    }

    private function user(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }
}
