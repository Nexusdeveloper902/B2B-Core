<?php

namespace Tests\Feature\Api;

use App\Models\PendingPairing;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-037 — the runtime settings surface (ADR-055): admin-only
 * GET/PUT /api/v1/admin/settings, cross-field validation (windows must
 * not overlap, HH:MM format, end after start), persistence in the
 * settings table, and LIVE runtime behavior — a saved window change
 * steers the very next meal tap; a saved late cutoff changes the very
 * next late computation.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        $this->travelTo(Carbon::parse('2026-09-01 08:00:00')); // Tuesday
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function admin()
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
    }

    private function getSettings()
    {
        return $this->actingAs($this->admin())->getJson('/api/v1/admin/settings');
    }

    #[Test]
    public function an_admin_reads_the_effective_settings(): void
    {
        $response = $this->getSettings()->assertOk();

        $response->assertJsonStructure([
            'settings' => [
                'pae.breakfast_start', 'pae.breakfast_end',
                'pae.lunch_start', 'pae.lunch_end',
                'attendance.late_cutoff', 'pairing.window_seconds',
                'accounts.student_email_domain', 'accounts.student_initial_password',
            ],
            'customized',
            'meal_windows',
        ]);

        // The demo seeder seeds the canonical defaults as rows.
        $this->assertSame('06:30', $response->json('settings')['pae.breakfast_start']);
        $this->assertSame(['breakfast', 'lunch'], array_keys($response->json('meal_windows')));
        $this->assertContains('pae.breakfast_start', $response->json('customized'));
    }

    #[Test]
    public function guests_and_teachers_are_walled_off(): void
    {
        $this->getJson('/api/v1/admin/settings')->assertUnauthorized();
        $this->putJson('/api/v1/admin/settings', ['settings' => []])->assertUnauthorized();

        $teacher = User::where('email', 'teacher@presence.test')->firstOrFail();
        $this->actingAs($teacher)->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->actingAs($teacher)->putJson('/api/v1/admin/settings', ['settings' => []])->assertForbidden();
    }

    #[Test]
    public function an_admin_saves_and_the_values_persist_and_apply(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/v1/admin/settings', ['settings' => [
                'pae.breakfast_start' => '07:00',
                'pae.breakfast_end' => '09:00',
            ]])
            ->assertOk()
            ->assertJson([
                'settings' => ['pae.breakfast_start' => '07:00', 'pae.breakfast_end' => '09:00'],
            ]);

        $this->assertDatabaseHas('settings', ['key' => 'pae.breakfast_start']);
        // Untouched keys keep their seeded values.
        $this->assertDatabaseHas('settings', ['key' => 'pae.lunch_start']);

        // The engine consults the NEW window on the very next tap: 06:45
        // was in the default window, now out of it.
        $uid = $this->cardUidFor('Maria González');
        PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => Reader::where('type', 'classroom')->firstOrFail()->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => Carbon::parse('2026-09-01 06:40:00'),
        ]);

        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $uid,
            'client_timestamp' => '2026-09-01 06:45:00',
        ], ['Authorization' => 'Bearer '.Reader::where('type', 'pae')->firstOrFail()->api_key])
            ->assertStatus(422)
            ->assertJson(['reason' => 'out_of_window']);
    }

    #[Test]
    public function overlapping_windows_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/v1/admin/settings', ['settings' => [
                'pae.lunch_start' => '08:00', // breakfast runs to 08:30
            ]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    #[Test]
    public function invalid_values_are_rejected_with_field_errors(): void
    {
        $response = $this->actingAs($this->admin())
            ->putJson('/api/v1/admin/settings', ['settings' => [
                'pae.breakfast_start' => '25:99',
                'pae.breakfast_end' => '07:00', // before start
                'pairing.window_seconds' => 5, // below 10
            ]])
            ->assertStatus(422);

        $errors = $response->json('errors');
        $this->assertArrayHasKey('pae.breakfast_start', $errors);
        $this->assertArrayHasKey('pae.breakfast_end', $errors);
        $this->assertArrayHasKey('pairing.window_seconds', $errors);
    }

    #[Test]
    public function unknown_keys_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/v1/admin/settings', ['settings' => ['pae.dinner_start' => '18:00']])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    #[Test]
    public function the_late_cutoff_setting_changes_attendance_derivations(): void
    {
        // Attendance at 07:50: late under the seeded 08:15? No — 07:50 is
        // BEFORE 08:15. Change the cutoff to 07:30 and the same tap
        // flips to late (settings are live, not display-only).
        PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => Reader::where('type', 'classroom')->firstOrFail()->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => Carbon::parse('2026-09-01 07:50:00'),
        ]);

        $service = new AttendanceService;
        $classId = Student::where('name', 'Maria González')->firstOrFail()->class_id;
        $rows = collect($service->classAttendanceToday($classId))->keyBy(fn ($row) => $row['student']->name);
        $this->assertSame('present', $rows['Maria González']['status']);

        $this->actingAs($this->admin())
            ->putJson('/api/v1/admin/settings', ['settings' => ['attendance.late_cutoff' => '07:30']])
            ->assertOk();

        $rows = collect($service->classAttendanceToday($classId))->keyBy(fn ($row) => $row['student']->name);
        $this->assertSame('late', $rows['Maria González']['status']);
    }

    #[Test]
    public function the_pairing_window_setting_steers_arming(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/v1/admin/settings', ['settings' => ['pairing.window_seconds' => 120]])
            ->assertOk();

        $student = Student::where('name', 'Maria González')->firstOrFail();
        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/students/{$student->id}/arm-pairing")
            ->assertOk();

        $this->assertSame(
            120,
            now()->diffInSeconds(PendingPairing::latest('id')->firstOrFail()->expires_at) > 110 ? 120 : 0
        );
    }

    #[Test]
    public function the_settings_desk_page_renders_for_admins(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringContainsString(__('app.settings_pae_group'), $html);
        $this->assertStringContainsString('pae.breakfast_start', $html);
        $this->assertStringContainsString(__('app.settings_initial_password'), $html);
    }

    #[Test]
    public function the_settings_desk_page_translates_to_spanish(): void
    {
        $this->actingAs($this->admin())->get('/locale/es');
        $html = $this->actingAs($this->admin())->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringContainsString('Ventanas de servicio PAE', $html);
        $this->assertStringContainsString('Contraseña inicial', $html);
    }

    #[Test]
    public function settings_survive_the_request_and_flush_properly(): void
    {
        app(SettingsService::class)->setMany(['pae.lunch_end' => '14:00']);
        SettingsService::flushCache();

        // Re-resolution after a flush reads the persisted row again.
        $this->assertSame('14:00', app(SettingsService::class)->get('pae.lunch_end'));
        $this->assertSame(1, Setting::where('key', 'pae.lunch_end')->count());
    }
}
