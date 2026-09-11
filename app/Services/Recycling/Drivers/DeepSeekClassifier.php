<?php

namespace App\Services\Recycling\Drivers;

use App\Contracts\MaterialClassifier;
use App\Enums\MaterialClass;
use App\Services\Recycling\ClassificationException;
use Illuminate\Support\Facades\Http;

/**
 * Optional cloud vision fallback using the DeepSeek API (ADR-046 —
 * defaults re-verified against api-docs.deepseek.com 2026-09-11).
 *
 * Model: `deepseek-flash` (DeepSeek-V4.1-Flash — the vision-capable
 * Flash model; non-vision models reject images with a 400). The legacy
 * `deepseek-v4-flash-vision-exp` name is still accepted by the API but
 * retired — configurable via DEEPSEEK_VISION_MODEL, default is the
 * canonical ID. Disabled unless a DEEPSEEK_API_KEY is configured.
 *
 * Wire contract (all reconfirmed current):
 * - images ride as base64 data URLs in image_url parts of a USER
 *   message (system/assistant images 400; non-vision models 400).
 * - `detail: high` is pinned explicitly: the classifier closes a
 *   trust gap (a wrong award breaks trust), token cost is capped
 *   either way, and an explicit value is immune to future `auto`
 *   redefinitions.
 * - `thinking.disabled` + temperature 0: thinking is on by default
 *   (effort high) and ignores temperature — determinism needs it off.
 * - response_format json_object + the word "json" plus a format
 *   example in the prompt (the JSON Output guide's contract).
 *
 * Pre-flight (docs Limits section): JPEG/PNG/GIF/WebP detected from
 * file CONTENT (never the extension — the API does the same), 32 MiB
 * single-image cap. Violations are honest driverUnavailable messages,
 * never cryptic 400s.
 *
 * Like every driver, this class is only ever reached through the
 * MaterialClassifier contract — controllers never know which driver ran.
 */
class DeepSeekClassifier implements MaterialClassifier
{
    private const ENDPOINT = 'https://api.deepseek.com/chat/completions';

    /** Docs ceiling for one inline/base64 image. */
    private const MAX_IMAGE_BYTES = 32 * 1024 * 1024;

    /** Image kinds the vision endpoint accepts (content-detected). */
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model = 'deepseek-flash',
        private readonly float $timeout = 15.0,
        private readonly int $maxImageBytes = self::MAX_IMAGE_BYTES,
    ) {}

    public function classify(string $imagePath): array
    {
        if (empty($this->apiKey)) {
            throw ClassificationException::driverUnavailable('deepseek', 'no DEEPSEEK_API_KEY configured');
        }

        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw ClassificationException::driverUnavailable('deepseek', "image not readable at [{$imagePath}]");
        }

        ['mime' => $mimeType, 'bytes' => $imageBytes] = $this->readImageBytes($imagePath);

        $payload = [
            'model' => $this->model,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    // JSON Output contract (api-docs.deepseek.com, "JSON
                    // Output"): response_format json_object REQUIRES the
                    // word "json" plus a format example in the prompt.
                    // TASK-025 item 8: the prompt also asks for the
                    // is_bottle / is_recyclable boundary semantics (spec §9)
                    // — the model OPINES, the backend still owns the rules.
                    ['type' => 'text', 'text' => 'Classify the recyclable material shown in this image. '
                        .'Answer ONLY with a json object: {"material_class": "<plastic|paper|metal|glass|other>", "confidence": <0-1>, "is_bottle": <true|false>, "is_recyclable": <true|false>}'],
                    // Vision contract: images ride as data URLs in
                    // image_url content parts (user messages only).
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => 'data:'.$mimeType.';base64,'.base64_encode($imageBytes),
                            'detail' => 'high',
                        ],
                    ],
                ],
            ]],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0,
            'thinking' => ['type' => 'disabled'],
            'max_tokens' => 200,
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->withToken($this->apiKey)
                ->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            throw ClassificationException::driverUnavailable('deepseek', $e->getMessage());
        }

        if ($response->failed()) {
            // Mirror DeepSeekClient: the body's error.message is the
            // actionable half (401 vs 402 vs 429 look identical by code
            // alone in the logs).
            $apiMessage = (string) $response->json('error.message', '');

            throw ClassificationException::driverUnavailable(
                'deepseek',
                "API returned HTTP {$response->status()}"
                    .($apiMessage !== '' ? ": {$apiMessage}" : '')
            );
        }

        $raw = (string) $response->json('choices.0.message.content', '');

        // The JSON Output guide warns the API occasionally returns empty
        // content — name it, so the log reads as a documented flake and
        // not a mystery.
        if (trim($raw) === '') {
            throw ClassificationException::driverUnavailable('deepseek', 'model returned empty content (documented JSON-output flake — safe to retry the capture)');
        }

        $decoded = json_decode($raw, true);

        $class = is_array($decoded) ? ($decoded['material_class'] ?? null) : null;
        $confidence = (float) (is_array($decoded) ? ($decoded['confidence'] ?? 0) : 0);

        if (! is_string($class) || MaterialClass::tryFrom($class) === null) {
            throw ClassificationException::driverUnavailable('deepseek', 'model response missing a valid material_class');
        }

        return [
            'material_class' => $class,
            'confidence' => round(min(max($confidence, 0.0), 1.0), 2),
            // TASK-025 item 8 — boundary semantics; defaulted from the
            // class when the model omits them (bottle => plastic shape,
            // recyclable => everything but 'other'). Strict bools only:
            // a stringy "false" must never flip truthy via (bool) cast.
            'is_bottle' => $this->strictBool(is_array($decoded) ? ($decoded['is_bottle'] ?? null) : null, $class === 'plastic'),
            'is_recyclable' => $this->strictBool(is_array($decoded) ? ($decoded['is_recyclable'] ?? null) : null, $class !== 'other'),
        ];
    }

    /**
     * A model-supplied boolean that tolerates stringy/numbery values
     * ("false", 0, "yes") but falls back honestly when absent or
     * unparseable. Note: filter_var(NULL) yields false, not null —
     * the absent case needs its own branch, or every omitted field
     * would read false.
     */
    private function strictBool(mixed $value, bool $fallback): bool
    {
        if ($value === null) {
            return $fallback;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $fallback;
    }

    /**
     * Content-detected image kind (the API detects from bytes, not the
     * extension — so do we, via finfo which ships with every PHP).
     *
     * Reads the file ONCE: the bytes feed the type check and (via the
     * returned array) the base64 payload — no TOCTOU between stat and
     * send, no 43 MiB double-buffer surprise.
     *
     * @return array{mime: string, bytes: string}
     *
     * @throws ClassificationException
     */
    private function readImageBytes(string $imagePath): array
    {
        $size = @filesize($imagePath);

        if ($size === false || $size > $this->maxImageBytes) {
            throw ClassificationException::driverUnavailable(
                'deepseek',
                sprintf(
                    'image exceeds the %d MiB single-image ceiling (downscale on the device and retry)',
                    $this->maxImageBytes >> 20
                )
            );
        }

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw ClassificationException::driverUnavailable('deepseek', 'fileinfo magic database unavailable');
        }

        try {
            $detected = finfo_file($finfo, $imagePath);
        } finally {
            finfo_close($finfo);
        }

        $mimeType = is_string($detected) ? $detected : '';

        if (! in_array($mimeType, self::ALLOWED_IMAGE_MIMES, true)) {
            throw ClassificationException::driverUnavailable(
                'deepseek',
                "image is not a supported kind (got [{$mimeType}]; JPEG, PNG, GIF or WebP detected from file content)"
            );
        }

        $bytes = @file_get_contents($imagePath);

        if ($bytes === false || $bytes === '') {
            throw ClassificationException::driverUnavailable('deepseek', "image unreadable at [{$imagePath}]");
        }

        return ['mime' => $mimeType, 'bytes' => $bytes];
    }
}
