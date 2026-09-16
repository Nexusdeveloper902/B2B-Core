<?php

namespace App\Support\Branding;

use Illuminate\Contracts\Support\Arrayable;

/**
 * TASK-045 (ADR-065) — one resolved branding profile.
 *
 * The ONLY thing views ask about branding. Blade never asks "is this
 * school X?"; it asks `$brand->logoSrc()`, `$brand->name()`,
 * `$brand->isDefault()`. Adding a school therefore changes config and
 * assets, never markup.
 *
 * @implements Arrayable<string, mixed>
 */
final class Brand implements Arrayable
{
    /**
     * @param  array<string, string>  $tokens  CSS custom property overrides
     * @param  array{src: string, width: int, height: int}  $logo
     * @param  array<string, string>  $icons
     */
    public function __construct(
        private readonly string $key,
        private readonly ?string $name,
        private readonly ?string $tag,
        private readonly string $primary,
        private readonly array $tokens,
        private readonly string $themeColor,
        private readonly array $logo,
        private readonly array $icons,
        private readonly string $ogImage,
        /** @var array<int, array<string, string>> */
        private readonly array $pwaIcons,
        private readonly bool $default,
    ) {}

    /**
     * Build a profile from its config block, hardening every field
     * against a half-written entry: a malformed profile degrades to the
     * Pulse value for that field instead of rendering a broken shell.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config, bool $isDefault): self
    {
        $fallback = (array) config('branding.brands.pulse', []);
        $logo = (array) ($config['logo'] ?? $fallback['logo'] ?? []);
        $icons = (array) ($config['icons'] ?? []) + (array) ($fallback['icons'] ?? []);

        return new self(
            key: $key,
            name: isset($config['name']) && $config['name'] !== '' ? (string) $config['name'] : null,
            tag: isset($config['tag']) && $config['tag'] !== '' ? (string) $config['tag'] : null,
            primary: (string) ($config['primary'] ?? '#242423'),
            tokens: array_filter(
                (array) ($config['tokens'] ?? []),
                fn ($value, $name) => is_string($name) && is_string($value) && str_starts_with($name, '--brand-'),
                ARRAY_FILTER_USE_BOTH,
            ),
            themeColor: (string) ($config['theme_color'] ?? $fallback['theme_color'] ?? '#E8EDDF'),
            logo: [
                'src' => (string) ($logo['src'] ?? 'brand/mark-96.png'),
                'width' => (int) ($logo['width'] ?? 42),
                'height' => (int) ($logo['height'] ?? 30),
            ],
            icons: $icons,
            ogImage: (string) ($config['og_image'] ?? $fallback['og_image'] ?? 'brand/og-image.png'),
            pwaIcons: (array) ($config['pwa_icons'] ?? $fallback['pwa_icons'] ?? []),
            default: $isDefault,
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    /** True for the stock Pulse identity — the shell every unbranded account gets. */
    public function isDefault(): bool
    {
        return $this->default;
    }

    /** The institution's name, or the product name for default Pulse. */
    public function name(): string
    {
        return $this->name ?? (string) __('app.app_name');
    }

    /** The short "branded for" chip (null on default Pulse). */
    public function tag(): ?string
    {
        return $this->tag;
    }

    /** The brand/accent color — the ACTION color, never a universal replacement. */
    public function primaryColor(): string
    {
        return $this->primary;
    }

    /** @return array<string, string> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * The token overrides as a `:root{…}` rule, ready to inline in the
     * document head (empty string for default Pulse — nothing to say).
     *
     * Values are whitelisted to a conservative CSS-color grammar: a
     * profile is developer-authored config, but this block lands inside
     * a <style> tag, so it never carries anything it does not need to.
     */
    public function styleBlock(): string
    {
        $declarations = '';

        foreach ($this->tokens as $name => $value) {
            if (preg_match('/^[#a-zA-Z0-9(),.%\s\/-]+$/', $value) !== 1) {
                continue;
            }

            $declarations .= $name.':'.trim($value).';';
        }

        return $declarations === '' ? '' : ':root{'.$declarations.'}';
    }

    public function themeColor(): string
    {
        return $this->themeColor;
    }

    public function logoSrc(): string
    {
        return $this->logo['src'];
    }

    public function logoWidth(): int
    {
        return $this->logo['width'];
    }

    public function logoHeight(): int
    {
        return $this->logo['height'];
    }

    public function icon(string $which): string
    {
        return (string) ($this->icons[$which] ?? '');
    }

    public function ogImage(): string
    {
        return $this->ogImage;
    }

    /**
     * The installable-app icon set, absolute-URL'd for the manifest.
     *
     * @return array<int, array<string, string>>
     */
    public function manifestIcons(): array
    {
        return array_map(
            fn (array $icon) => array_filter([
                'src' => asset((string) ($icon['src'] ?? '')),
                'sizes' => (string) ($icon['sizes'] ?? ''),
                'type' => (string) ($icon['type'] ?? 'image/png'),
                'purpose' => (string) ($icon['purpose'] ?? ''),
            ], fn ($value) => $value !== ''),
            $this->pwaIcons,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name(),
            'tag' => $this->tag,
            'is_default' => $this->default,
            'primary_color' => $this->primary,
            'logo' => $this->logo,
        ];
    }
}
