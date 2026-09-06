<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Services\Realtime\RealtimeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /realtime/token (TASK-016, ADR-026) — the session-authed feed
 * token mint. Guest wall, both dashboard roles, response shape.
 */
class RealtimeTokenRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get('/realtime/token')->assertRedirect('/login');
    }

    #[Test]
    public function admins_receive_a_verifiable_token_and_the_ws_url(): void
    {
        $response = $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->getJson('/realtime/token');

        $response->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'url']);

        $this->assertSame(1, RealtimeToken::verify($response->json('token')));
        $this->assertGreaterThan(time(), $response->json('expires_at'));
        $this->assertStringStartsWith('ws://', $response->json('url'));
        $this->assertStringContainsString(':'.(string) config('realtime.port'), $response->json('url'));
    }

    #[Test]
    public function teachers_receive_tokens_too(): void
    {
        $teacher = User::where('role', 'teacher')->firstOrFail();

        $response = $this->actingAs($teacher)->getJson('/realtime/token');

        $response->assertOk();
        $this->assertSame((int) $teacher->id, RealtimeToken::verify($response->json('token')));
    }
}
