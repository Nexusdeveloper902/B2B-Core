<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Tenancy\CurrentSchool;
use Illuminate\Database\Seeder;

/**
 * TASK-045 (ADR-064) — the ONE system administrator, seeded OUTSIDE
 * every organization.
 *
 * Deliberately its own seeder, not a block inside RealisticSeeder: the
 * platform operator is not part of any school's dataset, and keeping
 * the two apart is what makes "exactly one admin, and it is not in the
 * school" checkable rather than merely intended.
 *
 * An admin whose `school_id` is NULL is the system administrator: it
 * belongs to no school and therefore operates across all of them
 * (ADR-064). It renders in stock Pulse branding, because branding
 * follows the account's school and this account has none.
 *
 * IDEMPOTENT by email, and it REFUSES to mint a second one: rerunning
 * `./run seed-realistic` can never fork the operator account.
 *
 * Credentials follow the project's existing seeding conventions (the
 * `accounts.*` settings presets — see SettingsService), exactly like
 * DemoSeeder and PilotSeeder. Nothing here is a production secret:
 * every seeder credential in this repository is a published, shared
 * DEV/DEMO value.
 */
class SystemAdminSeeder extends Seeder
{
    public const EMAIL = 'admin@presence.test';

    public function run(): void
    {
        $password = (string) settings()->studentInitialPassword();

        // Seeding is maintenance work that spans organizations by
        // definition — say so, rather than inheriting whatever school a
        // previous seeder happened to leave active.
        app(CurrentSchool::class)->withoutScoping(function () use ($password): void {
            $existing = User::query()
                ->where('role', UserRole::Admin->value)
                ->whereNull('school_id')
                ->get();

            if ($existing->count() > 1) {
                $this->command?->error(sprintf(
                    'Refusing to seed: %d system administrators already exist (%s) — there must be exactly one.',
                    $existing->count(),
                    $existing->pluck('email')->join(', '),
                ));
                $this->command?->error('Siembra rechazada: ya existen '.$existing->count().' administradores del sistema — debe haber exactamente uno.');

                return;
            }

            $admin = User::firstOrNew(['email' => self::EMAIL]);
            $admin->name = 'Pulse System Admin';
            $admin->role = UserRole::Admin->value;
            $admin->school_id = null;     // the whole point: no organization
            $admin->student_id = null;
            $admin->must_change_password = false;
            $admin->password = $password;
            $admin->save();

            $this->command?->info(" [EN] System administrator: {$admin->email} / {$password} — belongs to NO school (stock Pulse shell, sees every organization).");
            $this->command?->info(" [ES] Administrador del sistema: {$admin->email} / {$password} — sin colegio (interfaz Pulse estándar, ve todas las organizaciones).");
        });
    }
}
