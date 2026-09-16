<?php

namespace App\Models;

use App\Support\Branding\Brand;
use App\Support\Branding\BrandResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * TASK-045 (ADR-064/ADR-065) — the organization (school) every
 * org-owned row belongs to, and the anchor of the branding chain:
 *
 *     current account → school → branding configuration
 *
 * A school is data; its branding is configuration (config/branding.php).
 * The row only NAMES a profile through `brand_key`, so an operator can
 * never drift a school into an unreadable color pair, and a school with
 * no profile (or a profile that has not shipped yet) simply renders as
 * Pulse.
 *
 * Deliberately NOT globally scoped: this is the table the scope is
 * ABOUT, and it is read while resolving that scope.
 */
class School extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'brand_key'];

    /**
     * Find-or-create a school by slug (the seeders' and importers'
     * entry point — idempotent, so reruns never fork an organization).
     */
    public static function provision(string $name, ?string $slug = null, ?string $brandKey = null): self
    {
        $slug ??= Str::slug($name);

        $school = static::firstOrNew(['slug' => $slug]);
        $school->name = $name;
        $school->brand_key = $brandKey;
        $school->save();

        return $school;
    }

    /** The resolved branding profile for this school (never null — Pulse is the floor). */
    public function brand(): Brand
    {
        return app(BrandResolver::class)->for($this);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function readers(): HasMany
    {
        return $this->hasMany(Reader::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PresenceEvent::class);
    }
}
