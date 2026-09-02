<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\NlQuery\GeminiClient;
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
