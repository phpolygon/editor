<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Build a game project into a standalone, distributable executable by driving
 * the engine's own builder (`vendor/bin/phpolygon build`) in the project
 * directory. The engine downloads the matching micro.sfx runtime (from
 * hmennen90/static-php-cli) and concatenates it with the project PHAR.
 *
 * Prerequisites (checked / documented):
 * - The project's dependencies are installed (vendor/bin/phpolygon exists) —
 *   run install_project_dependencies first.
 * - A system `composer` on PATH: the engine builder runs `composer update`
 *   itself during the build (it does not honour PHPOLYGON_COMPOSER_PHAR).
 * - `phar.readonly=0` — passed via -d here.
 *
 * Long-running + network-bound (composer + runtime download + packaging).
 * The build's own progress is plain text; this command returns the combined
 * output and the build output directory.
 */
class BuildGamePackageCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $projectDir = is_string($this->args['projectDir'] ?? null) ? trim($this->args['projectDir']) : '';
        if ($projectDir === '' || ! is_dir($projectDir)) {
            throw new RuntimeException("Project directory not found: {$projectDir}");
        }

        $engineBin = Path::join($projectDir, 'vendor/bin/phpolygon');
        if (! is_file($engineBin)) {
            throw new RuntimeException(
                'Engine CLI not found (vendor/bin/phpolygon). Install the project dependencies first.'
            );
        }

        $platform = is_string($this->args['platform'] ?? null) ? trim((string) $this->args['platform']) : '';
        $variant = ($this->args['variant'] ?? null) === 'steam' ? 'steam' : 'base';
        $phpVersion = ($this->args['phpVersion'] ?? null) === '8.4' ? '8.4' : '8.5';

        [$exitCode, $output] = $this->runBuild($projectDir, $engineBin, $platform, $variant, $phpVersion);

        return [
            'built' => $exitCode === 0,
            'exitCode' => $exitCode,
            'output' => $output,
            'outputDir' => Path::join($projectDir, 'build'),
            'variant' => $variant,
            'phpVersion' => $phpVersion,
        ];
    }

    /**
     * Run the engine build in the project directory.
     * Returns [exitCode, combinedOutput]. Overridable so tests can stub it.
     *
     * @return array{0: int, 1: string}
     */
    protected function runBuild(string $projectDir, string $engineBin, string $platform, string $variant, string $phpVersion): array
    {
        // `phar.readonly=0` is mandatory (the CLI aborts otherwise); PHP_BINARY
        // as the interpreter keeps it Windows-safe rather than relying on the
        // shebang.
        $argv = [PHP_BINARY, '-d', 'phar.readonly=0', $engineBin, 'build'];
        if ($platform !== '') {
            $argv[] = $platform;
        }
        $argv = array_merge($argv, ['--variant', $variant, '--php-version', $phpVersion]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($argv, $descriptors, $pipes, $projectDir);
        if (! is_resource($process)) {
            return [-1, 'Failed to start the engine build'];
        }

        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, trim(($out ?: '')."\n".($err ?: ''))];
    }
}
