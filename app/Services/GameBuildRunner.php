<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Starts a game package build as a detached background process and exposes its
 * live log + completion status for polling. The build itself runs via the
 * `editor:run-build` artisan command (see RunBuildCommand), which streams output
 * into a per-build log file and appends a `BUILD_DONE:<code>` marker on finish.
 *
 * Detached (not synchronous / not a queue job) so it survives the web request
 * without depending on a running queue worker, on any platform.
 */
class GameBuildRunner
{
    /**
     * Spawn a detached build. Returns the build id used to poll {@see status()}.
     */
    public function start(
        string $projectDir,
        string $platform,
        string $variant,
        string $phpVersion,
        bool $docker = false,
        string $platforms = '',
    ): string {
        $id = bin2hex(random_bytes(8));
        $log = $this->logPath($id);
        touch($log); // so an immediate status() poll finds a (running) build

        $args = [
            PHP_BINARY,
            base_path('artisan'),
            'editor:run-build',
            $projectDir,
            $log,
            '--platform='.$platform,
            '--variant='.$variant,
            '--php-version='.$phpVersion,
        ];

        if ($docker) {
            $args[] = '--docker';
            $args[] = '--platforms='.$platforms;
        }

        $this->spawnDetached($args);

        return $id;
    }

    /**
     * Current log + completion state for a build id.
     *
     * @return array{found: bool, log: string, done: bool, exitCode: int|null}
     */
    public function status(string $id): array
    {
        $log = $this->logPath($id);
        if (! is_file($log)) {
            return ['found' => false, 'log' => '', 'done' => true, 'exitCode' => -1];
        }

        $content = file_get_contents($log);
        $content = $content === false ? '' : $content;

        if (preg_match('/\nBUILD_DONE:(-?\d+)\s*$/', $content, $m)) {
            return [
                'found' => true,
                'log' => (string) preg_replace('/\n?BUILD_DONE:-?\d+\s*$/', '', $content),
                'done' => true,
                'exitCode' => (int) $m[1],
            ];
        }

        return ['found' => true, 'log' => $content, 'done' => false, 'exitCode' => null];
    }

    public function buildsDir(): string
    {
        $dir = storage_path('app/builds');
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        return $dir;
    }

    private function logPath(string $id): string
    {
        $id = preg_replace('/[^a-f0-9]/', '', $id) ?? '';

        return $this->buildsDir().DIRECTORY_SEPARATOR.$id.'.log';
    }

    /**
     * @param  list<string>  $args
     */
    private function spawnDetached(array $args): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // `start "" /B` launches without a new window and returns immediately.
            $cmd = 'start "" /B '.implode(' ', array_map(fn (string $a): string => '"'.str_replace('"', '\\"', $a).'"', $args));
            $handle = popen($cmd, 'r');
            if (is_resource($handle)) {
                pclose($handle);
            }

            return;
        }

        $cmd = implode(' ', array_map('escapeshellarg', $args)).' > /dev/null 2>&1 &';
        exec($cmd);
    }
}
