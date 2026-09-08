<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\NlQuery\DeepSeekClient;
use App\Services\NlQuery\Exceptions\NlQueryException;
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
    public function without_a_deepseek_key_the_endpoint_reports_a_structured_blocker(): void
    {
        config(['recycling.nl_query.api_key' => null]);
        // Re-resolve the singleton with the nulled key.
        $this->app->forgetInstance(DeepSeekClient::class);

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
        $this->app->instance(DeepSeekClient::class, new class('stale-key', 'deepseek-v4-flash') extends DeepSeekClient
        {
            public function generate(array $messages, ?array $tools = null): array
            {
                throw NlQueryException::invalidKey(
                    'HTTP 401: Authentication Fails, Your api key: ***stale is invalid'
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
    public function an_empty_account_balance_reports_its_own_actionable_blocker(): void
    {
        // DeepSeek's documented 402 — the key is VALID, the account is out
        // of balance (pay-as-you-go, no free tier): the fix is a top-up.
        $this->app->instance(DeepSeekClient::class, new class('valid-key', 'deepseek-v4-flash') extends DeepSeekClient
        {
            public function generate(array $messages, ?array $tools = null): array
            {
                throw NlQueryException::insufficientBalance(
                    'HTTP 402: Insufficient Balance'
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
        $this->assertSame('llm_insufficient_balance', $json['blocked_reason']);
        // Honest semantics: the refusal is about the balance, not the key.
        $this->assertStringContainsString('balance', (string) $json['message']);
    }

    #[Test]
    public function teachers_reach_the_endpoint_but_are_scope_fenced(): void
    {
        // TASK-027 — teachers get their own NL interface now: the role
        // wall OPENS for them (the missing-credential blocker below proves
        // the request passed role:admin,teacher and reached the service —
        // the data wall itself is pinned by the scope tests).
        config(['recycling.nl_query.api_key' => null]);
        $this->app->forgetInstance(DeepSeekClient::class);

        $this->actingAs($this->user('teacher'))
            ->postJson('/api/v1/nl-query', ['question' => 'attendance?'])
            ->assertStatus(503)
            ->assertJson([
                'status' => 'blocked',
                'blocked_reason' => 'missing_llm_credential',
            ]);
    }

    #[Test]
    public function students_are_forbidden(): void
    {
        // TASK-027 — the NL surface stays staff-only: a student account
        // never crosses the role wall.
        $this->actingAs(User::where('role', 'student')->firstOrFail())
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
     * flag are both set (keeps the paid balance and CI safe by default).
     *
     * RUN_LIVE_LLM_TESTS=1 DEEPSEEK_API_KEY=... php artisan test --filter=NlQueryApiTest
     */
    #[Test]
    public function live_end_to_end_query_with_a_real_deepseek_key(): void
    {
        if (empty(env('DEEPSEEK_API_KEY')) || env('RUN_LIVE_LLM_TESTS') !== '1') {
            $this->markTestSkipped('Live LLM test requires DEEPSEEK_API_KEY and RUN_LIVE_LLM_TESTS=1 (used sparingly).');
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
