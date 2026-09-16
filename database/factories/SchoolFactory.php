<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * TASK-045 (ADR-064) — organizations for tests.
 *
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'IE '.fake()->unique()->city();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            // Unbranded by default: most organizations render as Pulse.
            'brand_key' => null,
        ];
    }

    /** The flagship branded school (the one this feature shipped for). */
    public function ieConcejoDeSabaneta(): static
    {
        return $this->state(fn () => [
            'name' => 'IE Concejo de Sabaneta J.M.C.B',
            'slug' => 'ie-concejo-de-sabaneta',
            'brand_key' => 'ie-concejo-de-sabaneta',
        ]);
    }

    /** A school naming a branding profile that does not exist (yet). */
    public function withUnknownBrand(): static
    {
        return $this->state(fn () => ['brand_key' => 'a-school-whose-profile-has-not-shipped']);
    }
}
