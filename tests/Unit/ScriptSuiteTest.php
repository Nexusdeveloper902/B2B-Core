<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the ./run script suite (TASK-003, ADR-009/010/011): structure,
 * executability, bash syntax, bilingual documentation parity, toolchain
 * invariant (no bare `php` invocations) and CI/module-list consistency.
 * If the suite drifts — a script renamed, a command undocumented, a module
 * list out of sync with CI — this test fails the build.
 */
class ScriptSuiteTest extends TestCase
{
    /** Command => script map (mirrors `script_for` in ./run). */
    private const COMMAND_SCRIPTS = [
        'setup' => 'scripts/setup.sh',
        'serve' => 'scripts/serve.sh',
        'test' => 'scripts/test.sh',
        'e2e' => 'scripts/e2e.sh',
        'quality' => 'scripts/quality.sh',
        'doctor' => 'scripts/doctor.sh',
        'status' => 'scripts/status.sh',
        'reset' => 'scripts/reset.sh',
        'model' => 'scripts/model-server.sh',
        'toolchain' => 'scripts/provision-toolchain.sh',
        'ci' => 'scripts/ci.sh',
    ];

    #[Test]
    public function run_dispatcher_exists_and_is_executable(): void
    {
        $run = base_path('run');
        $this->assertFileExists($run);
        $this->assertTrue(is_executable($run), 'the ./run entry point must carry the executable bit in git (mode 100755)');
    }

    #[Test]
    #[DataProvider('commandScriptProvider')]
    public function every_command_delegates_to_an_executable_script(string $command, string $script): void
    {
        $path = base_path($script);
        $this->assertFileExists($path, "command [{$command}] expects [{$script}]");
        $this->assertTrue(is_executable($path), "[{$script}] must be executable");

        // The dispatcher must actually map the command to that script.
        $dispatcher = (string) file_get_contents(base_path('run'));
        $this->assertStringContainsString($script, $dispatcher, "run must dispatch [{$command}] to [{$script}]");
    }

    public static function commandScriptProvider(): array
    {
        $cases = [];
        foreach (self::COMMAND_SCRIPTS as $command => $script) {
            $cases["command {$command}"] = [$command, $script];
        }

        return $cases;
    }

    #[Test]
    public function every_shell_file_passes_bash_syntax_check(): void
    {
        $targets = array_merge(
            ['run', 'scripts/_lib/common.sh'],
            array_map(fn ($script) => $script, self::COMMAND_SCRIPTS),
        );

        foreach ($targets as $target) {
            $path = base_path($target);
            exec(sprintf('bash -n %s 2>&1', escapeshellarg($path)), $output, $code);
            $this->assertSame(
                0,
                $code,
                "bash -n failed for [{$target}]: ".implode(PHP_EOL, $output)
            );
        }
    }

    #[Test]
    public function run_help_lists_every_command(): void
    {
        exec('./run help 2>&1', $outputLines, $code);
        $help = implode(PHP_EOL, $outputLines);

        $this->assertSame(0, $code, "'./run help' must exit 0, got {$code}: {$help}");

        foreach (array_keys(self::COMMAND_SCRIPTS) as $command) {
            $this->assertMatchesRegularExpression(
                '/^  '.preg_quote($command, '/').'\s+/m',
                $help,
                "'./run help' must list command [{$command}]"
            );
        }
    }

    #[Test]
    public function every_command_is_documented_in_both_languages(): void
    {
        $en = (string) file_get_contents(base_path('docs/SCRIPTS.md'));
        $es = (string) file_get_contents(base_path('docs/SCRIPTS.es.md'));

        foreach (array_keys(self::COMMAND_SCRIPTS) as $command) {
            $heading = '## `'.$command.'`';
            $this->assertStringContainsString($heading, $en, "EN docs missing section for [{$command}]");
            $this->assertStringContainsString($heading, $es, "ES docs missing section for [{$command}]");
        }
    }

    #[Test]
    public function gitignore_protects_generated_toolchain_state(): void
    {
        $gitignore = (string) file_get_contents(base_path('.gitignore'));

        foreach (['/.tools', '.model-server.pid', 'local-model-server/.venv'] as $pattern) {
            $this->assertStringContainsString($pattern, $gitignore, ".gitignore must ignore [{$pattern}]");
        }
    }

    #[Test]
    public function no_bare_php_invocations_in_the_script_suite(): void
    {
        // ADR-010 invariant: every PHP invocation goes through "$PHP_BIN".
        // A bare `php …` command in a script would break machines with no
        // system PHP (the hermetic path). Comments are excluded; message
        // text is safe because we only match php followed by CLI verbs.
        $targets = array_merge(['scripts/_lib/common.sh'], self::COMMAND_SCRIPTS);

        foreach ($targets as $target) {
            $lines = file(base_path($target), FILE_IGNORE_NEW_LINES);
            foreach ($lines as $number => $line) {
                if (preg_match('/^\s*#/', $line)) {
                    continue; // comment
                }
                $this->assertDoesNotMatchRegularExpression(
                    '/(^|[;&|(])\s*php\s+(artisan|-r|-m|-v|serve)\b/',
                    $line,
                    "[{$target}:{$number}] invokes bare php — use \"\$PHP_BIN\" (ADR-010)"
                );
            }
        }
    }

    #[Test]
    public function required_module_list_covers_the_ci_extension_list(): void
    {
        $modules = $this->requiredModulesFromCommonSh();
        $this->assertNotEmpty($modules, 'could not parse PHP_REQUIRED_MODULES from scripts/_lib/common.sh');

        // Every extension CI explicitly installs must be in the single
        // source of truth (ADR-010) — otherwise local doctor and CI drift.
        $ciYaml = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
        preg_match_all('/extensions:\s*([^\n]+)/', $ciYaml, $matches);
        $this->assertNotEmpty($matches[1], 'no setup-php extension lists found in ci.yml');

        foreach ($matches[1] as $extensionList) {
            foreach (array_map('trim', explode(',', $extensionList)) as $extension) {
                $this->assertContains(
                    $extension,
                    $modules,
                    "CI installs [{$extension}] but common.sh does not require it — toolchain drift"
                );
            }
        }
    }

    #[Test]
    public function php_minimum_version_matches_the_ci_matrix_floor(): void
    {
        $common = (string) file_get_contents(base_path('scripts/_lib/common.sh'));
        $this->assertMatchesRegularExpression(
            '/B2B_PHP_MIN_VERSION="([^"]+)"/',
            $common,
            'common.sh must define B2B_PHP_MIN_VERSION'
        );
        preg_match('/B2B_PHP_MIN_VERSION="([^"]+)"/', $common, $m);
        $minimum = $m[1];

        $ciYaml = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
        preg_match("/php:\s*\['([^']+)',\s*'([^']+)'\]/", $ciYaml, $matrix);
        $this->assertCount(3, $matrix, 'could not parse the PHP matrix from ci.yml');
        $this->assertSame(
            $minimum,
            $matrix[1],
            "ci.yml matrix floor [{$matrix[1]}] must equal B2B_PHP_MIN_VERSION [{$minimum}]"
        );
    }

    #[Test]
    public function e2e_runner_uses_the_shared_toolchain_resolution(): void
    {
        $e2e = (string) file_get_contents(base_path('scripts/e2e.sh'));

        $this->assertStringContainsString('_lib/common.sh', $e2e, 'e2e.sh must source the shared library');
        $this->assertStringContainsString('resolve_php', $e2e, 'e2e.sh must resolve its interpreter');
        $this->assertStringContainsString('"$PHP_BIN" artisan serve', $e2e, 'the server must boot via $PHP_BIN');
    }

    /**
     * @return array<int, string>
     */
    private function requiredModulesFromCommonSh(): array
    {
        $content = (string) file_get_contents(base_path('scripts/_lib/common.sh'));
        preg_match('/PHP_REQUIRED_MODULES=\(([^)]+)\)/', $content, $match);
        if (! isset($match[1])) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\s+/', $match[1])),
            fn ($module) => $module !== ''
        ));
    }
}
