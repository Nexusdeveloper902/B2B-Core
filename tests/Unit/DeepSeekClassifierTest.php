<?php

namespace Tests\Unit;

use App\Services\Recycling\ClassificationException;
use App\Services\Recycling\Drivers\DeepSeekClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-031 (ADR-046) — the DeepSeek vision driver, verified without a
 * key: every wire claim is pinned against Http::fake (model ID,
 * detail:high, thinking-disabled, JSON contract, user-message-only
 * shape), and every failure mode degrades to an honest
 * driverUnavailable — never an exception leak, never a half-parse.
 *
 * Live round-trips still need DEEPSEEK_API_KEY (owner action); these
 * tests prove the REQUEST we would send matches today's
 * api-docs.deepseek.com, byte for byte where it matters.
 */
class DeepSeekClassifierTest extends TestCase
{
    use RefreshDatabase;

    private string $png;

    private string $txt;

    protected function setUp(): void
    {
        parent::setUp();

        // Real 1x1 PNG bytes (content-detected, never extension-trusted).
        // Written straight to the final names — tempnam would orphan its
        // own file alongside (finding 10).
        $this->png = sys_get_temp_dir().'/dscl-'.uniqid().'.png';
        file_put_contents(
            $this->png,
            (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
        );

        $this->txt = sys_get_temp_dir().'/dscl-'.uniqid().'.txt';
        file_put_contents($this->txt, 'not an image at all');
    }

    protected function tearDown(): void
    {
        @unlink($this->png);
        @unlink($this->txt);
        parent::tearDown();
    }

    private function okResponse(array $content): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($content)]]],
            ], 200),
        ]);
    }

    #[Test]
    public function a_happy_path_returns_the_full_boundary_schema(): void
    {
        $this->okResponse([
            'material_class' => 'plastic',
            'confidence' => 0.91,
            'is_bottle' => true,
            'is_recyclable' => true,
        ]);

        $result = (new DeepSeekClassifier('k'))->classify($this->png);

        $this->assertSame('plastic', $result['material_class']);
        $this->assertSame(0.91, $result['confidence']);
        $this->assertTrue($result['is_bottle']);
        $this->assertTrue($result['is_recyclable']);
    }

    #[Test]
    public function the_request_matches_todays_documented_wire_format(): void
    {
        $this->okResponse(['material_class' => 'paper', 'confidence' => 0.5]);

        (new DeepSeekClassifier('k'))->classify($this->png);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return ($payload['model'] ?? null) === 'deepseek-flash'
                && ($payload['response_format'] ?? []) === ['type' => 'json_object']
                && ($payload['temperature'] ?? null) === 0
                && ($payload['thinking'] ?? []) === ['type' => 'disabled']
                && ($payload['messages'][0]['role'] ?? null) === 'user'
                && str_contains((string) ($payload['messages'][0]['content'][0]['text'] ?? ''), 'json')
                && ($payload['messages'][0]['content'][1]['type'] ?? null) === 'image_url'
                && str_starts_with((string) ($payload['messages'][0]['content'][1]['image_url']['url'] ?? ''), 'data:image/png;base64,')
                && ($payload['messages'][0]['content'][1]['image_url']['detail'] ?? null) === 'high';
        });
    }

    #[Test]
    public function the_bearer_key_rides_the_header_never_the_url(): void
    {
        $this->okResponse(['material_class' => 'glass', 'confidence' => 0.7]);

        (new DeepSeekClassifier('secret-key'))->classify($this->png);

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer secret-key')
                && ! str_contains($request->url(), 'secret-key');
        });
    }

    #[Test]
    public function boundary_fields_default_from_the_class_when_omitted(): void
    {
        $this->okResponse(['material_class' => 'paper', 'confidence' => 0.6]);
        $paper = (new DeepSeekClassifier('k'))->classify($this->png);
        $this->assertFalse($paper['is_bottle']);
        $this->assertTrue($paper['is_recyclable']);
    }

    #[Test]
    public function other_defaults_to_non_bottle_non_recyclable(): void
    {
        $this->okResponse(['material_class' => 'other', 'confidence' => 0.4]);
        $other = (new DeepSeekClassifier('k'))->classify($this->png);
        $this->assertFalse($other['is_bottle']);
        $this->assertFalse($other['is_recyclable']);
    }

    #[Test]
    public function confidence_above_one_clamps_to_one(): void
    {
        $this->okResponse(['material_class' => 'metal', 'confidence' => 4.2]);
        $this->assertSame(1.0, (new DeepSeekClassifier('k'))->classify($this->png)['confidence']);
    }

    #[Test]
    public function confidence_below_zero_clamps_to_zero(): void
    {
        $this->okResponse(['material_class' => 'metal', 'confidence' => -3]);
        $this->assertSame(0.0, (new DeepSeekClassifier('k'))->classify($this->png)['confidence']);
    }

    #[Test]
    public function a_missing_key_is_a_driver_failure_not_a_http_call(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        try {
            (new DeepSeekClassifier(null))->classify($this->png);
            $this->fail('classify without a key must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('no DEEPSEEK_API_KEY', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function an_unreadable_image_never_reaches_the_api(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        try {
            (new DeepSeekClassifier('k'))->classify('/no/such/image.png');
            $this->fail('classify on a missing file must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('not readable', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_non_image_file_is_rejected_by_content_not_extension(): void
    {
        // Faked transport FIRST: if a regression ever lets the type
        // through, the suite fails on stray-request instead of firing
        // real (slow, billable) traffic from a unit test.
        Http::fake();
        Http::preventStrayRequests();

        // .txt suffix AND text bytes: finfo must refuse it.
        $this->expectException(ClassificationException::class);
        $this->expectExceptionMessageMatches('/not a supported kind/');

        (new DeepSeekClassifier('k'))->classify($this->txt);
    }

    #[Test]
    public function an_oversized_image_is_rejected_before_any_http(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        // Injectable ceiling (production default is the documented
        // 32 MiB — a real 33 MiB fixture has no place in a unit test).
        // The message names the EFFECTIVE ceiling, not a constant.
        try {
            (new DeepSeekClassifier('k', 'deepseek-flash', 15.0, 10))->classify($this->png);
            $this->fail('classify over the ceiling must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('MiB single-image ceiling', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_400_is_a_typed_driver_failure(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'nope']], 400)]);

        try {
            (new DeepSeekClassifier('k'))->classify($this->png);
            $this->fail('a 400 must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
        }
    }

    #[Test]
    public function a_500_is_a_typed_driver_failure(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response('boom', 500)]);

        try {
            (new DeepSeekClassifier('k'))->classify($this->png);
            $this->fail('a 500 must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }
    }

    #[Test]
    public function empty_model_content_is_named_as_the_documented_flake(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => '']]],
        ], 200)]);

        try {
            (new DeepSeekClassifier('k'))->classify($this->png);
            $this->fail('empty content must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('empty content', $e->getMessage());
        }
    }

    #[Test]
    public function stringy_booleans_parse_without_cast_traps(): void
    {
        // (bool)"false" === true in PHP — the strict parser must not
        // inherit that trap.
        $this->okResponse(['material_class' => 'plastic', 'confidence' => 0.9, 'is_bottle' => 'false', 'is_recyclable' => 'yes']);
        $result = (new DeepSeekClassifier('k'))->classify($this->png);

        $this->assertFalse($result['is_bottle']);
        $this->assertTrue($result['is_recyclable']);
    }

    #[Test]
    public function prose_instead_of_json_throws_loudly(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'a bottle, probably']]],
        ], 200)]);

        try {
            (new DeepSeekClassifier('k'))->classify($this->png);
            $this->fail('prose must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('valid material_class', $e->getMessage());
        }
    }

    #[Test]
    public function an_unknown_class_throws_loudly(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['material_class' => 'unobtanium', 'confidence' => 1])]]],
        ], 200)]);

        try {
            (new DeepSeekClassifier('k'))->classify($this->png);
            $this->fail('an unknown class must throw');
        } catch (ClassificationException $e) {
            $this->assertStringContainsString('valid material_class', $e->getMessage());
        }
    }

    #[Test]
    public function a_custom_model_override_still_sends(): void
    {
        $this->okResponse(['material_class' => 'plastic', 'confidence' => 0.8]);

        // Legacy retired names keep serving (pricing footnote 1) — the
        // override path is how an operator pins one.
        (new DeepSeekClassifier('k', 'deepseek-v4-flash-vision-exp'))->classify($this->png);

        Http::assertSent(fn ($request): bool => ($request->data()['model'] ?? null) === 'deepseek-v4-flash-vision-exp');
    }
}
