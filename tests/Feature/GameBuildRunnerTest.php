<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\GameBuildRunner;
use Tests\TestCase;

class GameBuildRunnerTest extends TestCase
{
    private GameBuildRunner $runner;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new GameBuildRunner();
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function testStatusForUnknownBuildReportsNotFound(): void
    {
        $status = $this->runner->status('deadbeefdeadbeef');

        $this->assertFalse($status['found']);
        $this->assertTrue($status['done']);
    }

    public function testStatusForRunningBuildIsNotDone(): void
    {
        $id = $this->writeLog("Preparing dependencies...\nStaging files...\n");

        $status = $this->runner->status($id);

        $this->assertTrue($status['found']);
        $this->assertFalse($status['done']);
        $this->assertNull($status['exitCode']);
        $this->assertStringContainsString('Staging files', $status['log']);
    }

    public function testStatusForFinishedBuildReportsDoneAndExitCode(): void
    {
        $id = $this->writeLog("Building...\nDone packaging\nBUILD_DONE:0\n");

        $status = $this->runner->status($id);

        $this->assertTrue($status['done']);
        $this->assertSame(0, $status['exitCode']);
        // The marker is stripped from the surfaced log.
        $this->assertStringNotContainsString('BUILD_DONE', $status['log']);
        $this->assertStringContainsString('Done packaging', $status['log']);
    }

    public function testStatusSurfacesNonZeroExitCode(): void
    {
        $id = $this->writeLog("micro.sfx not found\nBUILD_DONE:1\n");

        $status = $this->runner->status($id);

        $this->assertTrue($status['done']);
        $this->assertSame(1, $status['exitCode']);
    }

    private function writeLog(string $contents): string
    {
        $id = bin2hex(random_bytes(8));
        $path = $this->runner->buildsDir().DIRECTORY_SEPARATOR.$id.'.log';
        file_put_contents($path, $contents);
        $this->written[] = $path;

        return $id;
    }
}
