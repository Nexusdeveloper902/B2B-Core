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
 *
 * TASK-008 adds the Windows fallback contracts (ADR-017): auto-detection,
 * candidate exclusion of the Linux ELF hermetic PHP, Windows wrapper
 * probes, the run.cmd delegator, the .gitattributes line-ending contract
 * and the windows-smoke CI job. These tests run on EVERY OS — the
 * B2B_OS=windows simulations execute on Linux runners too.
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
        'llm-check' => 'scripts/llm-check.sh',
        'toolchain' => 'scripts/provision-toolchain.sh',
        'ci' => 'scripts/ci.sh',
    ];

    #[Test]
    public function run_dispatcher_exists_and_is_executable(): void
    {
        $run = base_path('run');
        $this->assertFileExists($run);
        if (PHP_OS_FAMILY === 'Windows') {
            // Git can carry the mode bit even when Windows cannot express
            // executability for an extensionless file — the +x contract is
            // enforced on Linux runners (and by ScriptSuiteTest history).
            return;
        }
        $this->assertTrue(is_executable($run), 'the ./run entry point must carry the executable bit in git (mode 100755)');
    }

    #[Test]
    #[DataProvider('commandScriptProvider')]
    public function every_command_delegates_to_an_executable_script(string $command, string $script): void
    {
        $path = base_path($script);
        $this->assertFileExists($path, "command [{$command}] expects [{$script}]");
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertTrue(is_executable($path), "[{$script}] must be executable");
        }

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
            // Forward slashes survive Symfony Process + cmd.exe quoting on
            // Windows; backslashes inside double quotes do not.
            $pathArg = str_replace('\\', '/', $path);
            exec(sprintf('bash -n %s 2>&1', escapeshellarg($pathArg)), $output, $code);
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
        // `bash run` (not `./run`) so the check also works under cmd.exe on
        // Windows, where a shebang file cannot be exec'd directly.
        exec('bash run help 2>&1', $outputLines, $code);
        $help = implode(PHP_EOL, $outputLines);

        $this->assertSame(0, $code, "'bash run help' must exit 0, got {$code}: {$help}");

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
    public function php_minimum_version_matches_the_ci_version_floor(): void
    {
        $common = (string) file_get_contents(base_path('scripts/_lib/common.sh'));
        $this->assertMatchesRegularExpression(
            '/B2B_PHP_MIN_VERSION="([^"]+)"/',
            $common,
            'common.sh must define B2B_PHP_MIN_VERSION'
        );
        preg_match('/B2B_PHP_MIN_VERSION="([^"]+)"/', $common, $m);
        $minimum = $m[1];

        // The lock file (Laravel 13.30 / symfony 8.1) requires PHP >= 8.4.1, so
        // the workflow pins 8.4 (with 8.5 exercised by the arch-smoke job).
        // Every php-version the workflow uses must satisfy the runtime floor
        // the script suite enforces.
        $ciYaml = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
        preg_match_all("/php-version:\s*'([^']+)'/", $ciYaml, $versions);
        $this->assertNotEmpty($versions[1], 'could not parse php-version values from ci.yml');
        foreach ($versions[1] as $version) {
            $this->assertTrue(
                version_compare($version, $minimum, '>='),
                "ci.yml uses PHP {$version} which is below B2B_PHP_MIN_VERSION [{$minimum}]"
            );
        }
    }

    #[Test]
    public function e2e_runner_uses_the_shared_toolchain_resolution(): void
    {
        $e2e = (string) file_get_contents(base_path('scripts/e2e.sh'));

        $this->assertStringContainsString('_lib/common.sh', $e2e, 'e2e.sh must source the shared library');
        $this->assertStringContainsString('resolve_php', $e2e, 'e2e.sh must resolve its interpreter');
        $this->assertStringContainsString('"$PHP_BIN" artisan serve', $e2e, 'the server must boot via $PHP_BIN');
    }

    // =========================================================================
    // Windows fallback contracts (TASK-008, ADR-017). These run on every OS;
    // the behavioral ones drive the real B2B_OS=windows seam.
    // =========================================================================

    #[Test]
    public function os_detection_layer_exists_in_the_shared_library(): void
    {
        $common = (string) file_get_contents(base_path('scripts/_lib/common.sh'));

        $this->assertStringContainsString('detect_os()', $common, 'common.sh must define detect_os()');
        $this->assertStringContainsString('is_windows()', $common, 'common.sh must define is_windows()');
        // The override seam: an explicit B2B_OS env var must win over
        // detection (this is how the tests simulate Windows on Linux).
        $this->assertMatchesRegularExpression(
            '/case "\$\{B2B_OS:-\}" in/',
            $common,
            'common.sh must honor an explicit B2B_OS override'
        );
        // The kernel fingerprints that classify Git Bash/MSYS/Cygwin.
        $this->assertStringContainsString('MINGW', $common);
        $this->assertStringContainsString('CYGWIN', $common);
        $this->assertStringContainsString('Windows_NT', $common, 'the OS=Windows_NT secondary signal must be probed');
    }

    #[Test]
    public function windows_mode_never_probes_the_linux_static_php(): void
    {
        // Behavioral: with B2B_OS=windows the candidate list must NOT contain
        // the Linux ELF .tools/php — even when it exists and is executable
        // (it does in the hermetic-scope environments; skip only when absent).
        if (! is_executable(base_path('.tools/php'))) {
            $this->markTestSkipped('No hermetic .tools/php present to prove the exclusion.');
        }

        $command = 'B2B_OS=windows B2B_PHP= bash -c '
            .escapeshellarg('source scripts/_lib/common.sh; php_candidates');
        exec($command.' 2>&1', $output, $code);
        $candidates = implode(PHP_EOL, $output);

        $this->assertSame(0, $code, "windows-simulated php_candidates failed: {$candidates}");
        $this->assertStringNotContainsString(
            '.tools/php',
            $candidates,
            'B2B_OS=windows must never probe the Linux ELF hermetic PHP (ADR-017)'
        );
    }

    #[Test]
    public function linux_mode_still_probes_the_hermetic_toolchain(): void
    {
        // Counter-test: the fallback must not have removed the Linux path.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Linux resolution semantics are proven on Linux runners.');
        }
        if (! is_executable(base_path('.tools/php'))) {
            $this->markTestSkipped('No hermetic .tools/php present.');
        }

        $command = 'B2B_OS=linux B2B_PHP= bash -c '
            .escapeshellarg('source scripts/_lib/common.sh; php_candidates');
        exec($command.' 2>&1', $output, $code);
        $candidates = implode(PHP_EOL, $output);

        $this->assertSame(0, $code, "php_candidates failed: {$candidates}");
        $this->assertStringContainsString('.tools/php', $candidates, 'Linux mode must keep probing .tools/php');
    }

    #[Test]
    public function windows_mode_probes_composer_wrapper_spellings(): void
    {
        // Bash cannot auto-resolve .bat/.cmd by bare name, so windows mode
        // must probe each spelling explicitly (source-level contract).
        $common = (string) file_get_contents(base_path('scripts/_lib/common.sh'));

        $this->assertStringContainsString('composer.bat', $common, 'windows composer candidates must probe composer.bat');
        $this->assertStringContainsString('composer.cmd', $common, 'windows composer candidates must probe composer.cmd');
        $this->assertStringContainsString('composer.phar', $common, 'windows composer candidates must probe composer.phar');

        // Dual invocation modes: phars via $PHP_BIN, wrappers direct.
        $this->assertStringContainsString('COMPOSER_BIN_MODE', $common, 'composer resolution must track its invocation mode');
    }

    #[Test]
    public function windows_mode_prints_windows_install_guidance(): void
    {
        // Behavioral: with no PHP candidates at all, windows mode must fail
        // with Windows guidance (winget/choco/php.net), not distro hints.
        // php_candidates is overridden to empty — deterministic on any OS.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Simulated-windows guidance is proven on Linux runners.');
        }

        $command = 'B2B_OS=windows bash -c '
            .escapeshellarg('source scripts/_lib/common.sh; php_candidates() { return 0; }; resolve_php 2>&1');
        exec($command.' 2>&1', $output, $code);
        $out = implode(PHP_EOL, $output);

        $this->assertSame(1, $code, 'resolve_php must die (exit 1) when no candidate is valid');
        $this->assertStringContainsString('winget install PHP.PHP', $out, 'windows guidance must name the winget install');
        $this->assertStringContainsString('windows.php.net', $out, 'windows guidance must point at the manual zip download');
    }

    #[Test]
    public function windows_specific_mechanics_are_guarded_not_forked(): void
    {
        // The Windows mechanics live INSIDE the single suite as guarded
        // branches — no second script suite may exist (ADR-017).
        $modelServer = (string) file_get_contents(base_path('scripts/model-server.sh'));
        $this->assertStringContainsString('Scripts', $modelServer, 'the venv must use the Windows Scripts/ layout when B2B_OS=windows');
        $this->assertStringContainsString('winpid', $modelServer, 'model stop must translate msys -> Windows pids on Windows');
        $this->assertStringContainsString('taskkill', $modelServer, 'model stop must fall back to taskkill on Windows');

        $toolchain = (string) file_get_contents(base_path('scripts/provision-toolchain.sh'));
        $this->assertStringContainsString('is_windows', $toolchain, 'toolchain must branch for the windows composer-only path');
        $this->assertStringContainsString('Linux ELF', $toolchain, 'the windows refusal of the static PHP must be explained in-output');

        $doctor = (string) file_get_contents(base_path('scripts/doctor.sh'));
        $this->assertStringContainsString('is_windows', $doctor, 'doctor must print windows-specific remediation');
    }

    #[Test]
    public function windows_entry_point_run_cmd_delegates_to_the_bash_dispatcher(): void
    {
        $runCmd = base_path('run.cmd');
        $this->assertFileExists($runCmd, 'the windows entry point run.cmd must exist');

        $content = (string) file_get_contents($runCmd);
        // Thin delegator contract: it forwards every argument to the SAME
        // bash dispatcher — it must NOT contain its own command routing.
        $this->assertStringContainsString('%ROOT%run', $content, 'run.cmd must forward to the bash ./run dispatcher');
        $this->assertStringContainsString('bash.exe', $content, 'run.cmd must locate Git Bash');
        $this->assertStringContainsString('B2B_BASH', $content, 'run.cmd must honor the B2B_BASH override');
        $this->assertStringContainsString('%*', $content, 'run.cmd must forward all arguments');
        foreach (array_keys(self::COMMAND_SCRIPTS) as $command) {
            $this->assertStringNotContainsString(
                'if "'.$command.'"',
                strtolower($content),
                'run.cmd must not route commands itself (single-source dispatch, ADR-009)'
            );
        }

        // CRLF line endings: cmd.exe requires them; run.cmd must have them.
        $raw = (string) file_get_contents($runCmd);
        $this->assertStringContainsString("\r\n", $raw, 'run.cmd must use CRLF line endings');
        $this->assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $raw, 'run.cmd must not mix LF into its CRLF content');
    }

    #[Test]
    public function line_ending_contract_is_pinned_by_gitattributes(): void
    {
        $attributes = base_path('.gitattributes');
        $this->assertFileExists($attributes, '.gitattributes must exist (line-ending contract)');

        $content = (string) file_get_contents($attributes);
        // Bash files MUST stay LF (CRLF breaks bash)…
        $this->assertStringContainsString('*.sh text eol=lf', $content, '.sh files must be pinned to LF');
        $this->assertStringContainsString('run text eol=lf', $content, 'the run dispatcher must be pinned to LF');
        // …and Windows entry points MUST be CRLF.
        $this->assertStringContainsString('*.cmd text eol=crlf', $content, '*.cmd files must be pinned to CRLF');
        $this->assertStringContainsString('*.bat text eol=crlf', $content, '*.bat files must be pinned to CRLF');
    }

    #[Test]
    public function ci_exercises_the_windows_fallback_on_real_hardware(): void
    {
        $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertStringContainsString('windows-smoke:', $ci, 'ci.yml must define the windows-smoke job');
        $this->assertStringContainsString('runs-on: windows-latest', $ci, 'the windows job must run on real Windows hardware');

        // The job must prove the full fallback journey, not a subset.
        $windowsJob = $this->extractJob($ci, 'windows-smoke');
        foreach (['./run setup --ci', './run doctor', './run quality', './run test', './run e2e'] as $proof) {
            $this->assertStringContainsString(
                $proof,
                $windowsJob,
                "the windows-smoke job must run [{$proof}] (real-Hardware proof, ADR-017)"
            );
        }
    }

    #[Test]
    public function windows_fallback_is_documented_in_both_languages(): void
    {
        foreach (['docs/SCRIPTS.md', 'docs/SCRIPTS.es.md'] as $doc) {
            $content = (string) file_get_contents(base_path($doc));
            $this->assertMatchesRegularExpression(
                '/##\s+Windows/i',
                $content,
                "[{$doc}] must have a dedicated Windows section"
            );
            $this->assertStringContainsString('Git Bash', $content, "[{$doc}] must name Git Bash");
            $this->assertStringContainsString('winget', $content, "[{$doc}] must document the winget install path");
        }
    }

    /**
     * Extracts one job body from the CI workflow YAML (job name -> steps).
     */
    private function extractJob(string $yaml, string $jobKey): string
    {
        $start = strpos($yaml, "  {$jobKey}:");
        $this->assertNotFalse($start, "job [{$jobKey}] not found in ci.yml");

        $rest = substr($yaml, $start + strlen("  {$jobKey}:"));
        // The job ends at the next top-level two-space key or EOF.
        if (preg_match('/\n  [a-zA-Z][a-zA-Z0-9_-]*:/', $rest, $m, PREG_OFFSET_CAPTURE)) {
            $rest = substr($rest, 0, $m[0][1]);
        }

        return $rest;
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
