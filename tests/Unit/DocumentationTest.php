<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the bilingual (EN/ES) documentation requirement and the
 * never-commit-secrets invariant — documentation drift fails the build.
 */
class DocumentationTest extends TestCase
{
    #[Test]
    public function bilingual_readmes_exist(): void
    {
        $this->assertFileExists(base_path('README.md'));
        $this->assertFileExists(base_path('README.es.md'));

        $en = file_get_contents(base_path('README.md'));
        $es = file_get_contents(base_path('README.es.md'));

        $this->assertStringContainsString('# Presence Platform', $en);
        $this->assertStringContainsString('Plataforma de Presencia', $es);
        $this->assertStringContainsString('php artisan migrate', $en);
        $this->assertStringContainsString('php artisan migrate', $es);
    }

    #[Test]
    public function bilingual_api_docs_exist(): void
    {
        $this->assertFileExists(base_path('docs/API.md'));
        $this->assertFileExists(base_path('docs/API.es.md'));

        $en = file_get_contents(base_path('docs/API.md'));
        $es = file_get_contents(base_path('docs/API.es.md'));

        foreach (['POST /api/v1/events/tap', 'POST /api/v1/recycling/classify', 'POST /api/v1/students', 'nl-query', 'POST /api/v1/admin/students/{id}/arm-pairing', 'POST /api/v1/admin/cards/pair', 'GET /api/v1/admin/pairing/status', 'last_rejection', 'TASK-012', 'SANCTUM_STATEFUL_DOMAINS', '--host=0.0.0.0'] as $needle) {
            $this->assertStringContainsString($needle, $en, "EN API docs must document [{$needle}]");
            $this->assertStringContainsString($needle, $es, "ES API docs must document [{$needle}]");
        }
    }

    #[Test]
    public function local_model_guide_exists_in_both_languages(): void
    {
        $this->assertFileExists(base_path('docs/LOCAL_MODEL.md'));
        $this->assertFileExists(base_path('docs/LOCAL_MODEL.es.md'));
    }

    #[Test]
    public function postman_collection_exists_and_is_valid_json(): void
    {
        $path = base_path('docs/postman_collection.json');
        $this->assertFileExists($path);

        $collection = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($collection);
        $this->assertStringContainsString('collection.json', (string) ($collection['info']['schema'] ?? ''));

        $urls = $this->collectRequestUrls($collection);
        foreach ([
            '{{base_url}}/api/v1/events/tap',
            '{{base_url}}/api/v1/recycling/classify',
            '{{base_url}}/api/v1/admin/readers/{{reader_id}}/mode',
            '{{base_url}}/api/v1/students/{{student_id}}/redeem',
            '{{base_url}}/api/v1/nl-query',
            '{{base_url}}/api/v1/admin/students/{{student_id}}/arm-pairing',
            '{{base_url}}/api/v1/admin/cards/pair',
            '{{base_url}}/api/v1/admin/pairing/status',
        ] as $expected) {
            $this->assertContains($expected, $urls, "Postman collection must cover [{$expected}]");
        }
    }

    #[Test]
    public function ci_workflows_exist(): void
    {
        $this->assertFileExists(base_path('.github/workflows/ci.yml'));
    }

    /**
     * THE security invariant: no committed file may contain a real secret.
     * Detects CREDENTIAL PATTERNS (not literal values — the test itself must
     * never embed a real key). gitleaks runs in CI too — this is the fast
     * local tripwire.
     */
    #[Test]
    public function no_real_gemini_or_github_keys_are_committed(): void
    {
        $patterns = [
            'Gemini API key (AIza…)' => '/AIza[0-9A-Za-z_\-]{35}/',
            'Gemini API key (AQ…)' => '/AQ\.[0-9A-Za-z_\-]{20,}/',
            'GitHub classic PAT (ghp_…)' => '/gh[pousr]_[0-9A-Za-z]{36,}/',
        ];

        foreach ($this->committedTextFiles() as $file) {
            $content = (string) file_get_contents($file);

            foreach ($patterns as $label => $pattern) {
                $matches = preg_match($pattern, $content);
                $this->assertSame(
                    0,
                    $matches,
                    "A credential pattern [{$label}] leaked into the committed file [{$file}] — remove it and rotate the credential immediately."
                );
            }
        }
    }

    /**
     * Files that git tracks (untracked/generated files like vendor/ are
     * skipped; .env is gitignored and would not appear here anyway).
     *
     * @return array<int, string>
     */
    private function committedTextFiles(): array
    {
        $output = shell_exec('git ls-files 2>/dev/null');
        if ($output === null || trim($output) === '') {
            $this->markTestSkipped('No git repository available in this context.');
        }

        $skip = ['png', 'jpg', 'jpeg', 'ico', 'woff', 'woff2', 'ttf', 'gz', 'zip', 'sqlite'];

        return collect(explode("\n", trim($output)))
            ->filter(fn ($f) => $f !== '' && is_file(base_path($f)))
            ->filter(fn ($f) => ! in_array(pathinfo($f, PATHINFO_EXTENSION), $skip))
            ->map(fn ($f) => base_path($f))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function collectRequestUrls(array $node): array
    {
        $urls = [];
        foreach ($node['item'] ?? [] as $item) {
            if (isset($item['request']['url'])) {
                $url = $item['request']['url'];
                $urls[] = is_array($url) ? ($url['raw'] ?? '') : $url;
            }
            if (isset($item['item'])) {
                $urls = array_merge($urls, $this->collectRequestUrls($item));
            }
        }

        return $urls;
    }
}
