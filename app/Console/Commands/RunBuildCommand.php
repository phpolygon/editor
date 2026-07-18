<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PHPolygon\Editor\Support\Path;

/**
 * Runs a game package build for a project, streaming its combined output live
 * into a log file and appending a `BUILD_DONE:<exitCode>` marker at the end.
 * Spawned DETACHED by {@see \App\Services\GameBuildRunner} so the web request
 * returns immediately; the UI polls the log for progress.
 *
 * Two modes:
 * - Host (default): `vendor/bin/phpolygon build` for the current platform.
 * - Docker (--docker): the engine's cross-platform image builds bundles for the
 *   requested `--platforms` (windows/linux/macos) in one Linux container.
 *
 * Output goes straight into the file via proc_open descriptors (OS-level), so
 * no non-blocking pipe reads are needed — reliable on Windows.
 */
class RunBuildCommand extends Command
{
    protected $signature = 'editor:run-build
        {projectDir : The game project directory to build}
        {logFile : Where to stream build output}
        {--platform= : Host build target (empty = current host)}
        {--variant=base : base|steam}
        {--php-version=8.5 : 8.4|8.5}
        {--docker : Cross-platform build via the engine Docker image}
        {--platforms= : Docker: comma-separated targets (empty = all)}';

    protected $description = 'Run a game package build (used internally by the build GUI)';

    public function handle(): int
    {
        $projectDir = (string) $this->argument('projectDir');
        $logFile = (string) $this->argument('logFile');

        $fh = fopen($logFile, 'w');
        if ($fh === false) {
            return self::FAILURE;
        }

        $code = $this->option('docker')
            ? $this->runDocker($projectDir, $fh)
            : $this->runHost($projectDir, $fh);

        fwrite($fh, "\nBUILD_DONE:{$code}\n");
        fclose($fh);

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  resource  $fh
     */
    private function runHost(string $projectDir, $fh): int
    {
        $engineBin = Path::join($projectDir, 'vendor/bin/phpolygon');
        if (! is_dir($projectDir) || ! is_file($engineBin)) {
            fwrite($fh, "Engine CLI not found (vendor/bin/phpolygon). Install the project dependencies first.\n");

            return -1;
        }

        // phar.readonly=0 is mandatory; PHP_BINARY as interpreter is Windows-safe.
        $args = [PHP_BINARY, '-d', 'phar.readonly=0', $engineBin, 'build'];
        $platform = (string) $this->option('platform');
        if ($platform !== '') {
            $args[] = $platform;
        }
        $args = array_merge($args, ['--variant', $this->variant(), '--php-version', $this->phpVersion()]);

        return $this->runProcess($args, $projectDir, $fh);
    }

    /**
     * @param  resource  $fh
     */
    private function runDocker(string $projectDir, $fh): int
    {
        $engineDir = Path::join($projectDir, 'vendor/phpolygon/phpolygon');
        $dockerfile = Path::join($engineDir, 'docker/Dockerfile.build');
        $dockerDir = Path::join($engineDir, 'docker');

        if (! is_file($dockerfile)) {
            fwrite($fh, "Cross-platform build harness not found ({$dockerfile}). Install the project dependencies first.\n");

            return -1;
        }
        if (! is_file(Path::join($projectDir, 'build.json'))) {
            fwrite($fh, "No build.json in the project — the cross-platform build reads the app name/version/types from it.\n");

            return -1;
        }

        $token = $this->githubToken();
        if ($token === '') {
            fwrite($fh, "No GitHub token found. Cross-platform builds need one (set GITHUB_TOKEN or run `gh auth login`).\n");

            return -1;
        }

        $platforms = trim((string) $this->option('platforms'));
        $platformsSpace = $platforms === '' ? 'all' : str_replace(',', ' ', $platforms);

        // 1) Build the (cached) image. Context is just docker/, engine tree not shipped.
        fwrite($fh, "==> Building the cross-platform Docker image (cached after first run)…\n");
        fflush($fh);
        $code = $this->runProcess(['docker', 'build', '-f', $dockerfile, '-t', 'phpolygon-build', $dockerDir], $projectDir, $fh);
        if ($code !== 0) {
            fwrite($fh, "Docker image build failed. Is Docker installed and running?\n");

            return $code;
        }

        // 2) Run the build; project bind-mounted at /app, artifacts land in build/.
        fwrite($fh, "==> Building bundles for: {$platformsSpace}\n");
        fflush($fh);
        $runArgs = [
            'docker', 'run', '--rm',
            '-v', $projectDir.':/app',
            '-e', 'PLATFORMS='.$platformsSpace,
            '-e', 'VARIANT='.$this->variant(),
            '-e', 'PHP_VERSION='.$this->phpVersion(),
            '-e', 'NONINTERACTIVE=1',
            '-e', 'GITHUB_TOKEN='.$token,
            'phpolygon-build',
        ];

        return $this->runProcess($runArgs, $projectDir, $fh);
    }

    /**
     * Run a process with both stdout+stderr going into the open log handle,
     * blocking until it finishes. Returns its exit code.
     *
     * @param  list<string>  $args
     * @param  resource  $fh
     */
    private function runProcess(array $args, string $cwd, $fh): int
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => $fh, 2 => $fh];
        $process = proc_open($args, $descriptors, $pipes, $cwd);
        if (! is_resource($process)) {
            fwrite($fh, "Failed to start: {$args[0]}\n");

            return -1;
        }

        fclose($pipes[0]);

        return proc_close($process);
    }

    private function variant(): string
    {
        return $this->option('variant') === 'steam' ? 'steam' : 'base';
    }

    private function phpVersion(): string
    {
        return $this->option('php-version') === '8.4' ? '8.4' : '8.5';
    }

    private function githubToken(): string
    {
        $env = getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN') ?: '';
        if (is_string($env) && $env !== '') {
            return $env;
        }

        // Fall back to the gh CLI if the user is logged in there.
        $out = @shell_exec('gh auth token 2>'.(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));

        return is_string($out) ? trim($out) : '';
    }
}
