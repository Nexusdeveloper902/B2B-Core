<?php

namespace Tests\Feature\Api;

use App\Models\Reward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The platform must work in English AND Spanish: API device-facing
 * messages localize via the Accept-Language header.
 */
class ApiLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function errors_default_to_english(): void
    {
        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'X'], [
            'Authorization' => 'Bearer '.$this->readerToken('classroom'),
        ])->assertJson(['message' => 'Card not recognized']);
    }

    #[Test]
    public function spanish_is_selected_via_accept_language(): void
    {
        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'X'], [
            'Authorization' => 'Bearer '.$this->readerToken('classroom'),
            'Accept-Language' => 'es-ES,es;q=0.9',
        ])->assertJson([
            'status' => 'error',
            'message' => 'Tarjeta no reconocida',
        ]);
    }

    #[Test]
    public function bearer_token_errors_localize_too(): void
    {
        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'X'], [
            'Authorization' => 'Bearer bogus',
            'Accept-Language' => 'es',
        ])->assertJson(['message' => 'Token de portador (bearer) no válido']);

        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'X'], [
            'Authorization' => 'Bearer bogus',
            'Accept-Language' => 'en',
        ])->assertJson(['message' => 'Invalid bearer token']);
    }

    #[Test]
    public function english_is_the_fallback_for_other_languages(): void
    {
        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'X'], [
            'Authorization' => 'Bearer bogus',
            'Accept-Language' => 'fr-FR',
        ])->assertJson(['message' => 'Invalid bearer token']);
    }

    #[Test]
    public function the_redemption_shortfall_message_localizes(): void
    {
        $student = $this->cardOf('Ana Martínez')->student;

        $response = $this->actingAs($this->user('admin'))
            ->withHeaders(['Accept-Language' => 'es'])
            ->postJson("/api/v1/students/{$student->id}/redeem", [
                'reward_id' => Reward::where('point_cost', 50)->first()->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Puntos insuficientes: faltan 50');
    }

    private function user(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }
}
