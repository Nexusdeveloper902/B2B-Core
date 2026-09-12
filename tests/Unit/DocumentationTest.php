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

        foreach (['POST /api/v1/events/tap', 'POST /api/v1/recycling/classify', 'POST /api/v1/students', 'nl-query', 'POST /api/v1/admin/students/{id}/arm-pairing', 'POST /api/v1/admin/cards/pair', 'GET /api/v1/admin/pairing/status', 'last_rejection', 'TASK-012', 'SANCTUM_STATEFUL_DOMAINS', '--host=0.0.0.0', 'America/Bogota'] as $needle) {
            $this->assertStringContainsString($needle, $en, "EN API docs must document [{$needle}]");
            $this->assertStringContainsString($needle, $es, "ES API docs must document [{$needle}]");
        }

        // TASK-027 — the admin management surface must stay documented in
        // BOTH languages (readers settings, students + CSV import, the
        // per-card unpair, the capture-image door, the PAE gate).
        // TASK-030-A — the login backfill endpoint joins the contract.
        foreach ([
            'PUT /api/v1/admin/readers/{id}',
            'POST /api/v1/admin/readers',
            'POST /api/v1/admin/readers/{reader}/rotate-key',
            'POST /api/v1/admin/students/import',
            'POST /api/v1/admin/students/{student}/account',
            'POST /api/v1/admin/classes',
            'DELETE /api/v1/admin/cards/{id}',
            'GET /api/v1/admin/captures/{deposit}/image',
            'student_not_pae',
            'get_repeatedly_absent_students',
            'get_student_time_in_school',
            'markdown.js',
            'class_created',
            'reader_updated',
            // TASK-031 (ADR-046) — the canonical model ID, both languages.
            'deepseek-flash',
        ] as $needle) {
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
            '{{base_url}}/api/v1/admin/readers',
            '{{base_url}}/api/v1/admin/readers/{{reader_id}}/rotate-key',
            '{{base_url}}/api/v1/students/{{student_id}}/redeem',
            '{{base_url}}/api/v1/nl-query',
            '{{base_url}}/api/v1/admin/students/{{student_id}}/arm-pairing',
            '{{base_url}}/api/v1/admin/students/{{student_id}}/account',
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

    #[Test]
    public function the_realtime_feed_contract_is_documented(): void
    {
        // TASK-016 — the WS protocol contract lives with the architecture
        // records (not the HTTP API docs): ws URL, token, frame shapes.
        $doc = base_path('.agent/ARCHITECTURE/realtime-feed.md');
        $this->assertFileExists($doc);

        $contents = (string) file_get_contents($doc);
        foreach (['realtime:serve', 'realtime:tap', 'realtime:roster', 'hello', 'token', '8081'] as $needle) {
            $this->assertStringContainsString($needle, $contents, "realtime contract must document [{$needle}]");
        }

        // The serve script's realtime line must be documented bilingually.
        foreach (['docs/SCRIPTS.md', 'docs/SCRIPTS.es.md'] as $path) {
            $scripts = (string) file_get_contents(base_path($path));
            $this->assertStringContainsString('realtime', $scripts, "{$path} must document the realtime feed");
        }
    }

    #[Test]
    public function the_frontend_guide_and_gap_ledger_exist_bilingually(): void
    {
        // TASK-026 — the mockup-driven redesign MUST ship its design
        // reference + gap ledger (the owner's "document what needs
        // functionality that doesn't exist yet") in both languages.
        foreach (['docs/FRONTEND.md', 'docs/FRONTEND.es.md'] as $path) {
            $doc = (string) file_get_contents(base_path($path));

            $this->assertStringContainsString('Datum', $doc, "{$path} must name the design system");
            $this->assertStringContainsString('gap ledger', $doc, "{$path} must present the gap ledger");
            // the two new read-only pages are documented
            $this->assertStringContainsString('/student/leaderboard', $doc);
            $this->assertStringContainsString('/admin/ecostation', $doc);
            // TASK-029 — the realtime passover coverage table + GUI completion
            $this->assertStringContainsString('roster', $doc, "{$path} must document the roster channel");
            $this->assertStringContainsString('students_imported', $doc, "{$path} must document the import frame");
            $this->assertStringContainsString('pagination', $doc, "{$path} must document the design-system paginator");
            // key gaps stay cataloged (honesty floor for aspirational UI)
            $this->assertStringContainsString('R1', $doc, "{$path} must catalog the voucher/QR redemption gap");
            $this->assertStringContainsString('E1', $doc, "{$path} must catalog the private capture-image gap");
            $this->assertStringContainsString('P5', $doc, "{$path} must catalog the advisor-contact gap");
        }
    }

    /**
     * THE security invariant: no committed file may contain a real secret.
     * Detects CREDENTIAL PATTERNS (not literal values — the test itself must
     * never embed a real key). gitleaks runs in CI too — this is the fast
     * local tripwire.
     */
    #[Test]
    public function no_real_llm_or_github_keys_are_committed(): void
    {
        $patterns = [
            'Gemini API key (AIza…)' => '/AIza[0-9A-Za-z_\-]{35}/',
            'Gemini API key (AQ…)' => '/AQ\.[0-9A-Za-z_\-]{20,}/',
            'DeepSeek API key (sk-…)' => '/sk-[0-9a-f]{32}/',
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
