<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * End-to-end coverage of the supervising process behind the Play button.
 *
 * These run a real child process rather than mocking it: the whole point of the
 * command is process lifecycle — that output reaches the log, that an exit code
 * is reported, and that a stop request actually kills the game — and none of
 * that is meaningfully testable through a double.
 */
class RunGameCommandTest extends TestCase
{
    private string $projectDir;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir().'/phpolygon-play-'.uniqid();
        mkdir($this->projectDir);
        $this->logFile = $this->projectDir.'/play.log';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->projectDir);
        parent::tearDown();
    }

    private function writeGame(string $body): void
    {
        file_put_contents($this->projectDir.'/game.php', "<?php\n".$body);
    }

    private function log(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    public function test_streams_game_output_into_the_log(): void
    {
        $this->writeGame("echo \"hello from the game\\n\";\n");

        Artisan::call('editor:run-game', [
            'projectDir' => $this->projectDir,
            'logFile' => $this->logFile,
            '--command' => 'php game.php',
        ]);

        $this->assertStringContainsString('hello from the game', $this->log());
    }

    public function test_appends_the_exit_marker_on_clean_exit(): void
    {
        $this->writeGame("exit(0);\n");

        Artisan::call('editor:run-game', [
            'projectDir' => $this->projectDir,
            'logFile' => $this->logFile,
            '--command' => 'php game.php',
        ]);

        $this->assertStringContainsString('GAME_EXITED:0', $this->log());
    }

    public function test_reports_the_games_exit_code(): void
    {
        $this->writeGame("exit(3);\n");

        Artisan::call('editor:run-game', [
            'projectDir' => $this->projectDir,
            'logFile' => $this->logFile,
            '--command' => 'php game.php',
        ]);

        $this->assertStringContainsString('GAME_EXITED:3', $this->log());
    }

    public function test_captures_stderr_so_a_fatal_is_visible(): void
    {
        // A game that dies on boot is the case the console exists for.
        $this->writeGame("fwrite(STDERR, \"boom\\n\"); exit(255);\n");

        Artisan::call('editor:run-game', [
            'projectDir' => $this->projectDir,
            'logFile' => $this->logFile,
            '--command' => 'php game.php',
        ]);

        $log = $this->log();
        $this->assertStringContainsString('boom', $log);
        $this->assertStringContainsString('GAME_EXITED:255', $log);
    }

    public function test_runs_with_the_project_as_working_directory(): void
    {
        // The entry point is a relative path, so a wrong cwd means the game
        // never starts.
        $this->writeGame("echo getcwd();\n");

        Artisan::call('editor:run-game', [
            'projectDir' => $this->projectDir,
            'logFile' => $this->logFile,
            '--command' => 'php game.php',
        ]);

        $this->assertStringContainsString(basename($this->projectDir), $this->log());
    }

    public function test_stop_request_terminates_a_running_game(): void
    {
        // The game would otherwise run for a minute; the stop file already
        // exists, so the supervisor kills it on its first poll.
        $this->writeGame("sleep(60);\n");
        $stopFile = $this->projectDir.'/stop';
        touch($stopFile);

        $started = microtime(true);
        Artisan::call('editor:run-game', [
            'projectDir' => $this->projectDir,
            'logFile' => $this->logFile,
            '--command' => 'php game.php',
            '--stop-file' => $stopFile,
        ]);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(20.0, $elapsed, 'a stop request must not wait for the game to finish');
        $this->assertStringContainsString('Stopped from the editor', $this->log());
        $this->assertStringContainsString('GAME_EXITED:0', $this->log());
    }

    public function test_reports_a_missing_project_directory(): void
    {
        Artisan::call('editor:run-game', [
            'projectDir' => $this->projectDir.'-does-not-exist',
            'logFile' => $this->logFile,
            '--command' => 'php game.php',
        ]);

        $log = $this->log();
        $this->assertStringContainsString('Project directory not found', $log);
        $this->assertStringContainsString('GAME_EXITED:-1', $log);
    }

    public function test_logs_the_command_it_runs(): void
    {
        // Without this, "nothing happened" gives no clue what was attempted.
        $this->writeGame("exit(0);\n");

        Artisan::call('editor:run-game', [
            'projectDir' => $this->projectDir,
            'logFile' => $this->logFile,
            '--command' => 'php game.php',
        ]);

        $this->assertStringContainsString('game.php', $this->log());
    }
}
