<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\GameRunner;
use Tests\TestCase;

class GameRunnerTest extends TestCase
{
    private GameRunner $runner;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new GameRunner;
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function writeLog(string $id, string $contents): string
    {
        $path = $this->runner->runsDir().DIRECTORY_SEPARATOR.$id.'.log';
        file_put_contents($path, $contents);
        $this->written[] = $path;

        return $path;
    }

    public function test_status_for_unknown_session_reports_not_found(): void
    {
        $status = $this->runner->status('deadbeefdeadbeef');

        $this->assertFalse($status['found']);
        $this->assertFalse($status['running']);
        $this->assertNull($status['exitCode']);
    }

    public function test_status_for_empty_id_reports_not_found(): void
    {
        $this->assertFalse($this->runner->status('')['found']);
    }

    public function test_session_without_the_exit_marker_is_still_running(): void
    {
        $this->writeLog('aaaabbbbccccdddd', "==> php game.php\nBooting…\n");

        $status = $this->runner->status('aaaabbbbccccdddd');

        $this->assertTrue($status['found']);
        $this->assertTrue($status['running']);
        $this->assertNull($status['exitCode']);
        $this->assertStringContainsString('Booting', $status['log']);
    }

    public function test_exit_marker_ends_the_session_and_is_stripped_from_the_log(): void
    {
        // The marker is bookkeeping between the supervising process and the
        // editor; showing it in the console would just confuse the user.
        $this->writeLog('1111222233334444', "Booting…\nDone\n\nGAME_EXITED:0\n");

        $status = $this->runner->status('1111222233334444');

        $this->assertFalse($status['running']);
        $this->assertSame(0, $status['exitCode']);
        $this->assertStringNotContainsString('GAME_EXITED', $status['log']);
        $this->assertStringContainsString('Done', $status['log']);
    }

    public function test_non_zero_exit_code_is_reported(): void
    {
        $this->writeLog('5555666677778888', "PHP Fatal error: boom\n\nGAME_EXITED:255\n");

        $status = $this->runner->status('5555666677778888');

        $this->assertFalse($status['running']);
        $this->assertSame(255, $status['exitCode']);
        $this->assertStringContainsString('Fatal error', $status['log']);
    }

    public function test_negative_exit_code_is_reported(): void
    {
        $this->writeLog('9999aaaabbbbcccc', "Failed to start the game.\n\nGAME_EXITED:-1\n");

        $this->assertSame(-1, $this->runner->status('9999aaaabbbbcccc')['exitCode']);
    }

    public function test_stop_writes_the_sentinel_the_supervisor_watches_for(): void
    {
        $id = 'ddddeeeeffff0000';
        $this->writeLog($id, "Booting…\n");
        $stopFile = $this->runner->runsDir().DIRECTORY_SEPARATOR.$id.'.stop';
        $this->written[] = $stopFile;

        $this->runner->stop($id);

        $this->assertFileExists($stopFile);
    }

    public function test_stop_ignores_an_empty_id(): void
    {
        $this->runner->stop('');

        $this->assertFileDoesNotExist($this->runner->runsDir().DIRECTORY_SEPARATOR.'.stop');
    }

    public function test_ids_cannot_escape_the_runs_directory(): void
    {
        // Ids reach the runner straight from a request parameter.
        $status = $this->runner->status('../../../../etc/passwd');

        $this->assertFalse($status['found']);
    }

    public function test_stop_sanitises_the_id_before_touching_a_file(): void
    {
        $this->runner->stop('../../evil');

        $this->assertFileDoesNotExist(dirname($this->runner->runsDir()).DIRECTORY_SEPARATOR.'evil.stop');
    }

    public function test_current_id_is_empty_when_nothing_has_run(): void
    {
        $current = $this->runner->runsDir().DIRECTORY_SEPARATOR.'current';
        @unlink($current);

        $this->assertSame('', $this->runner->currentId());
    }

    public function test_current_id_round_trips_the_last_started_session(): void
    {
        $current = $this->runner->runsDir().DIRECTORY_SEPARATOR.'current';
        file_put_contents($current, "abcdef0123456789\n");
        $this->written[] = $current;

        $this->assertSame('abcdef0123456789', $this->runner->currentId());
    }
}
