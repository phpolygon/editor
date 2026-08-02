<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GameRunner;
use Illuminate\Console\Command;
use PHPolygon\Editor\Support\PhpCommand;

/**
 * Runs a game project for the editor's Play button, streaming its combined
 * output into a log file and appending `GAME_EXITED:<code>` when it finishes.
 *
 * Spawned DETACHED by {@see GameRunner} so the web request returns
 * immediately; the editor polls the log.
 *
 * Unlike {@see RunBuildCommand} this process has to be *stoppable*, which is why
 * it supervises rather than just waiting: it watches for a stop file appearing
 * and terminates the game when it does. A stop file rather than a signal because
 * the editor is a web request with no handle on a detached process, and signals
 * do not exist on Windows.
 *
 * Terminating kills the whole tree, not just the child. With the default entry
 * point the child *is* the game, but a project-declared `runCommand` goes
 * through a shell, so killing only the direct child would orphan the game.
 */
class RunGameCommand extends Command
{
    protected $signature = 'editor:run-game
        {projectDir : The game project directory to run}
        {logFile : Where to stream the game output}
        {--command= : Shell command to run (defaults to the manifest entry point)}
        {--stop-file= : Terminate the game when this file appears}';

    protected $description = 'Run a game project (used internally by the editor Play button)';

    /** How often to check whether the game exited or a stop was requested. */
    private const POLL_MICROSECONDS = 100_000;

    public function handle(): int
    {
        $projectDir = (string) $this->argument('projectDir');
        $logFile = (string) $this->argument('logFile');
        $stopFile = (string) $this->option('stop-file');
        $command = (string) $this->option('command');

        $fh = fopen($logFile, 'w');
        if ($fh === false) {
            return self::FAILURE;
        }

        $code = $this->superviseGame($projectDir, $command, $stopFile, $fh);

        fwrite($fh, "\nGAME_EXITED:{$code}\n");
        fclose($fh);

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Start the game and stay with it until it exits or a stop is requested.
     *
     * Named around supervising rather than `run()`, which is already taken by
     * the Console\Command base class.
     *
     * @param  resource  $fh
     */
    private function superviseGame(string $projectDir, string $command, string $stopFile, $fh): int
    {
        if (! is_dir($projectDir)) {
            fwrite($fh, "Project directory not found: {$projectDir}\n");

            return -1;
        }

        // A bare `php` relies on the editor process's PATH, which a GUI-launched
        // editor often lacks — rewrite it to the absolute binary.
        $resolved = PhpCommand::resolve($command);
        fwrite($fh, "==> {$resolved}\n");
        fflush($fh);

        $descriptors = [0 => ['pipe', 'r'], 1 => $fh, 2 => $fh];
        $process = proc_open($resolved, $descriptors, $pipes, $projectDir);
        if (! is_resource($process)) {
            fwrite($fh, "Failed to start the game.\n");

            return -1;
        }
        fclose($pipes[0]);

        $pid = (int) (proc_get_status($process)['pid'] ?? 0);

        while (true) {
            $status = proc_get_status($process);
            if ($status === false || ! $status['running']) {
                // proc_close reaps the child; its status is authoritative only
                // the first time proc_get_status sees it stopped.
                $exitCode = is_array($status) ? (int) $status['exitcode'] : -1;
                proc_close($process);

                return $exitCode;
            }

            if ($stopFile !== '' && is_file($stopFile)) {
                fwrite($fh, "\n==> Stopped from the editor.\n");
                $this->terminateTree($process, $pid);
                proc_close($process);

                return 0;
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Kill the game and anything it spawned.
     *
     * @param  resource  $process
     */
    private function terminateTree($process, int $pid): void
    {
        if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
            // /T takes the tree, /F is required because a windowed game will not
            // answer a polite close request while it owns the message loop.
            @exec('taskkill /PID '.$pid.' /T /F 2>NUL');

            return;
        }

        if ($pid > 0) {
            // Best effort at the children first: with a shell-run command the
            // direct child is the shell, and killing only it orphans the game.
            @exec('pkill -TERM -P '.$pid.' 2>/dev/null');
        }

        proc_terminate($process);
    }
}
