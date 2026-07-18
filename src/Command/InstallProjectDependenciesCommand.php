<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Support\Path;
use RuntimeException;

/**
 * Run `composer install` inside a (scaffolded) game project to pull its
 * dependencies — primarily the PHPolygon engine.
 *
 * Note: editing a project in the editor does NOT require this, because the
 * editor resolves engine classes from its own vendor. Installing makes the
 * project self-contained and runnable. It is long-running and network-bound.
 *
 * Composer resolution: a bundled composer.phar (packaged build) is preferred
 * when `PHPOLYGON_COMPOSER_PHAR` points at one — it is run through the current
 * PHP binary — otherwise the system `composer` on PATH is used.
 */
class InstallProjectDependenciesCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $projectDir = is_string($this->args['projectDir'] ?? null) ? trim($this->args['projectDir']) : '';
        if ($projectDir === '' || ! is_dir($projectDir)) {
            throw new RuntimeException("Project directory not found: {$projectDir}");
        }

        if (! is_file(Path::join($projectDir, 'composer.json'))) {
            throw new RuntimeException("No composer.json in {$projectDir}");
        }

        [$exitCode, $output] = $this->runComposer($projectDir);

        return [
            'installed' => $exitCode === 0,
            'exitCode' => $exitCode,
            'output' => $output,
        ];
    }

    /**
     * Execute `composer install` in the project directory.
     * Returns [exitCode, combinedOutput]. Overridable so tests can stub it.
     *
     * @return array{0: int, 1: string}
     */
    protected function runComposer(string $projectDir): array
    {
        $cmd = array_merge($this->resolveComposer(), [
            'install', '--no-interaction', '--no-progress',
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $projectDir);
        if (! is_resource($process)) {
            return [-1, 'Failed to start composer'];
        }

        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, trim(($out ?: '')."\n".($err ?: ''))];
    }

    /**
     * The composer executable as an argv list.
     *
     * @return list<string>
     */
    protected function resolveComposer(): array
    {
        $phar = getenv('PHPOLYGON_COMPOSER_PHAR');
        if (is_string($phar) && $phar !== '' && is_file($phar)) {
            return [PHP_BINARY, $phar];
        }

        // On Windows the launcher is composer.bat; proc_open with an argv array
        // resolves it via PATHEXT, so the bare name works on both platforms.
        return ['composer'];
    }
}
