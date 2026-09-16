<?php

/*
|--------------------------------------------------------------------------
| School branding profiles (TASK-045, ADR-065)
|--------------------------------------------------------------------------
|
| Pulse renders as itself by default. A school row (`schools.brand_key`)
| may NAME one of the profiles below, and the whole shell then renders in
| that institution's identity — same layout, same components, same
| spacing, same behavior. This is a branding LAYER, not a second app.
|
| Adding a school later is three things and no code:
|   1. a `schools` row (name + slug + brand_key),
|   2. a block in this file,
|   3. the logo files under public/brand/schools/<key>/.
|
| `tokens` are the CSS custom properties from the brand layer at the top
| of public/css/tokens.css. They are injected into the document head
| before first paint, so a branded account never flashes Pulse colors
| first. Only the six brand-layer variables belong here: everything else
| in the design system (surfaces, type, spacing, the gold points accent,
| the semantic error/success family) is deliberately shared, so a brand
| can change the ACTION color without being able to break contrast,
| hierarchy or the meaning of a status color.
|
| Every ramp below is derived from the single brand hue, not hand-picked:
| hover = +6% lightness, muted = 93% L / 45% S, muted-dim = 85% L / 40% S,
| on-muted = 18% L. Contrast against the brand color is verified in
| tests/Feature/Web/SchoolBrandingTest.php.
|
*/

return [

    /*
    | The profile used when an account has no school, when its school
    | names no brand_key, or when that key is unknown (a future school
    | whose profile has not shipped yet). Pulse is ALWAYS the fallback.
    */
    'default' => 'pulse',

    'brands' => [

        'pulse' => [
            // null => the localized product name (lang/*/app.php app_name).
            'name' => null,
            'tag' => null,
            'primary' => '#242423',
            // No overrides: public/css/tokens.css IS the Pulse palette.
            'tokens' => [],
            'theme_color' => '#E8EDDF',
            'logo' => [
                'src' => 'brand/mark-96.png',
                'width' => 42,
                'height' => 30,
            ],
            'icons' => [
                'ico' => 'favicon.ico',
                'png32' => 'brand/favicon-32.png',
                'png16' => 'brand/favicon-16.png',
                'apple' => 'brand/apple-touch-icon.png',
            ],
            'og_image' => 'brand/og-image.png',
            // The installable-app icon set (served by GET /manifest.webmanifest).
            'pwa_icons' => [
                ['src' => 'brand/icon-192.png', 'sizes' => '192x192'],
                ['src' => 'brand/icon-512.png', 'sizes' => '512x512'],
                ['src' => 'brand/icon-maskable-192.png', 'sizes' => '192x192', 'purpose' => 'maskable'],
                ['src' => 'brand/icon-maskable-512.png', 'sizes' => '512x512', 'purpose' => 'maskable'],
            ],
        ],

        'ie-concejo-de-sabaneta' => [
            'name' => 'IE Concejo de Sabaneta J.M.C.B',
            // The topbar chip: "Pulse · <tag>" — the product stays named,
            // the institution is who it is branded FOR.
            'tag' => 'IE Concejo de Sabaneta',
            'primary' => '#80193c',
            'tokens' => [
                '--brand-primary' => '#80193c',
                '--brand-primary-hover' => '#9a1e48',
                '--brand-primary-contrast' => '#ffffff',
                '--brand-primary-muted' => '#f5e5eb',
                '--brand-primary-muted-dim' => '#e8c9d4',
                '--brand-primary-on-muted' => '#4d0f24',
                // A square crest needs slightly more height than the wide
                // Pulse lockup to read at the same optical size. The
                // topbar row is 64px, so this never changes its geometry.
                '--brand-mark-height' => '36px',
            ],
            'theme_color' => '#E8EDDF',
            'logo' => [
                // The institution's official crest, background removed from
                // the supplied artwork (B2B-Logo-Suite/School_Logo.jpeg) and
                // padded to a square canvas so it can never render stretched.
                'src' => 'brand/schools/ie-concejo-de-sabaneta/crest-96.png',
                'width' => 34,
                'height' => 34,
            ],
            'icons' => [
                'ico' => 'brand/schools/ie-concejo-de-sabaneta/favicon-32.png',
                'png32' => 'brand/schools/ie-concejo-de-sabaneta/favicon-32.png',
                'png16' => 'brand/schools/ie-concejo-de-sabaneta/favicon-16.png',
                'apple' => 'brand/schools/ie-concejo-de-sabaneta/apple-touch-icon.png',
            ],
            'og_image' => 'brand/schools/ie-concejo-de-sabaneta/og-image.png',
            'pwa_icons' => [
                ['src' => 'brand/schools/ie-concejo-de-sabaneta/icon-192.png', 'sizes' => '192x192'],
                ['src' => 'brand/schools/ie-concejo-de-sabaneta/icon-512.png', 'sizes' => '512x512'],
                ['src' => 'brand/schools/ie-concejo-de-sabaneta/icon-maskable-192.png', 'sizes' => '192x192', 'purpose' => 'maskable'],
                ['src' => 'brand/schools/ie-concejo-de-sabaneta/icon-maskable-512.png', 'sizes' => '512x512', 'purpose' => 'maskable'],
            ],
        ],

    ],

];
