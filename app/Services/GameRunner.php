<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Starts and stops a play session for the editor's Play button.
 *
 * Mirrors {@see GameBuildRunner}: the game runs as a detached process so it
 * survives the web request without a queue worker, and its output is streamed
 * into a per-session log the UI polls.
 *
 * The difference is that a play session has to be stoppable. The editor has no
 * handle on a detached process across requests, so {@see stop()} writes a
 * sentinel file that the supervising `editor:run-game` command watches for —
 * portable, and it works on Windows where signals do not exist.
 *
 * Only one session runs at a time: the id is stored on disk rather than in the
 * PHP session, because the poll and stop requests are separate from the one
 * that started it, and NativePHP may serve them from a different worker.
 */
class GameRunner
{
    /** Marker the supervising command appends when the game exits. */
    private const EXIT_MARKER = '/\nGAME_EXITED:(-?\d+)\s*$/';

    /**
     * Start a play session, replacing any session still running.
     *
     * @return string The session id used to poll {@see status()}.
     */
    public function start(string $projectDir, string $runCommand): string
    {
        $this->stopCurrent();

        $id = bin2hex(random_bytes(8));
        $log = $this->logPath($id);
        touch($log); // so an immediate status() poll finds a (running) session

        file_put_contents($this->currentPath(), $id);

        $this->spawnDetached([
            PHP_BINARY,
            base_path('artisan'),
            'editor:run-game',
            $projectDir,
            $log,
            '--command='.$runCommand,
            '--stop-file='.$this->stopPath($id),
        ]);

        return $id;
    }

    /**
     * Current log + run state for a session id.
     *
     * @return array{found: bool, log: string, running: bool, exitCode: int|null}
     */
    public function status(string $id): array
    {
        $log = $this->logPath($id);
        if ($id === '' || ! is_file($log)) {
            return ['found' => false, 'log' => '', 'running' => false, 'exitCode' => null];
        }

        $content = file_get_contents($log);
        $content = $content === false ? '' : $content;

        if (preg_match(self::EXIT_MARKER, $content, $m) === 1) {
            return [
                'found' => true,
                'log' => (string) preg_replace(self::EXIT_MARKER, '', $content),
                'running' => false,
                'exitCode' => (int) $m[1],
            ];
        }

        return ['found' => true, 'log' => $content, 'running' => true, 'exitCode' => null];
    }

    /**
     * Ask a session to stop.
     *
     * Returns immediately; the supervising process notices the sentinel within
     * its poll interval and the UI sees `running: false` on its next poll.
     */
    public function stop(string $id): void
    {
        if ($id === '') {
            return;
        }

        touch($this->stopPath($id));
    }

    /** The session the editor last started, if any. */
    public function currentId(): string
    {
        $file = $this->currentPath();
        if (! is_file($file)) {
            return '';
        }

        $id = file_get_contents($file);

        return is_string($id) ? trim($id) : '';
    }

    public function runsDir(): string
    {
        $dir = storage_path('app/play');
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        return $dir;
    }

    /** Stop whatever was running before, so Play never leaves an orphan behind. */
    private function stopCurrent(): void
    {
        $previous = $this->currentId();
        if ($previous !== '' && $this->status($previous)['running']) {
            $this->stop($previous);
        }
    }

    private function sanitizeId(string $id): string
    {
        return preg_replace('/[^a-f0-9]/', '', $id) ?? '';
    }

    private function logPath(string $id): string
    {
        return $this->runsDir().DIRECTORY_SEPARATOR.$this->sanitizeId($id).'.log';
    }

    private function stopPath(string $id): string
    {
        return $this->runsDir().DIRECTORY_SEPARATOR.$this->sanitizeId($id).'.stop';
    }

    private function currentPath(): string
    {
        return $this->runsDir().DIRECTORY_SEPARATOR.'current';
    }

    /**
     * @param  list<string>  $args
     */
    private function spawnDetached(array $args): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // `start "" /B` launches without a new window and returns immediately.
            $cmd = 'start "" /B '.implode(' ', array_map(
                fn (string $a): string => '"'.str_replace('"', '\\"', $a).'"',
                $args,
            ));
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
