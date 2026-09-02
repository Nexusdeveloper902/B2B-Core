<?php

namespace Tests\E2E;

use App\Contracts\MaterialClassifier;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E2E — the same platform story told in SPANISH: an operator switches the
 * dashboard to Spanish, a device reports in Spanish, and the docs exist
 * in both languages. The app must work fully in English AND Spanish.
 */
class BilingualJourneyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_full_spanish_experience(): void
    {
        $fixtures = $this->seedDemo();
        $admin = $fixtures['admin'];

        // ---- A device with a Spanish locale gets Spanish feedback ----
        $esTap = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->cardUidFor('Maria González'),
        ], [
            'Authorization' => 'Bearer '.$this->readerToken('classroom'),
            'Accept-Language' => 'es-CO,es;q=0.9',
        ]);
        $esTap->assertOk()->assertJsonPath('student_first_name', 'Maria');

        // A rejected card speaks Spanish too.
        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'DESCONOCIDA'], [
            'Authorization' => 'Bearer '.$this->readerToken('classroom'),
            'Accept-Language' => 'es-CO,es;q=0.9',
        ])->assertJson(['message' => 'Tarjeta no reconocida']);

        // ---- The operator switches the dashboard UI to Spanish ----
        $this->actingAs($admin)->get('/locale/es');
        $esDashboard = $this->actingAs($admin)->get('/admin');

        $esDashboard->assertOk()
            ->assertSeeText('Panel del Administrador')
            ->assertSeeText('Lectores')
            ->assertSeeText('Consulta en lenguaje natural')
            ->assertSeeText('Canjear recompensa');

        // The login page also localizes (for a logged-out operator).
        auth()->logout();
        $this->withSession(['locale' => 'es'])->get('/login')
            ->assertOk()
            ->assertSeeText('Inicia sesión en la Plataforma de Presencia');

        // ---- An admin-only rejection is explained in Spanish ----
        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'X'], [
            'Authorization' => 'Bearer invalid',
            'Accept-Language' => 'es',
        ])->assertUnauthorized()->assertJson(['message' => 'Token de portador (bearer) no válido']);

        // ---- Recycling + redemption messages in Spanish ----
        $this->swap(MaterialClassifier::class, new class implements MaterialClassifier
        {
            public function classify(string $imagePath): array
            {
                return ['material_class' => 'glass', 'confidence' => 0.77];
            }
        });

        $tap = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->cardUidFor('Ana Martínez'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')]);
        $tap->assertOk();

        $this->post('/api/v1/recycling/classify', [
            'event_id' => $tap->json('event_id'),
            'image' => UploadedFile::fake()->image('jar.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')])
            ->assertOk()
            ->assertJson(['material_class' => 'glass', 'points_awarded' => 8]); // glass = 8 pts

        // Ana has 8 points; the 50-pt reward fails with a Spanish shortfall message.
        $anaId = $this->cardOf('Ana Martínez')->student->id;
        $this->actingAs($admin)
            ->withHeaders(['Accept-Language' => 'es'])
            ->postJson("/api/v1/students/{$anaId}/redeem", [
                'reward_id' => Reward::where('point_cost', 50)->first()->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Puntos insuficientes: faltan 42');

        // ---- And back to English without losing anything ----
        $this->actingAs($admin)->get('/locale/en');
        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSeeText('Admin Dashboard');
    }

    #[Test]
    public function both_language_files_cover_the_same_keys(): void
    {
        // A missing ES key would silently fall back to English — catch it.
        foreach (['api', 'app', 'auth'] as $file) {
            $en = require lang_path("en/{$file}.php");
            $es = require lang_path("es/{$file}.php");

            $missingInEs = array_diff_key($en, $es);
            $missingInEn = array_diff_key($es, $en);

            $this->assertSame([], $missingInEs, "lang/es/{$file}.php is missing keys: ".implode(', ', array_keys($missingInEs)));
            $this->assertSame([], $missingInEn, "lang/en/{$file}.php is missing keys: ".implode(', ', array_keys($missingInEn)));
        }
    }
}
