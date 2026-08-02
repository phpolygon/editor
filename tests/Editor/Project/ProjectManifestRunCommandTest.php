<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Project;

use PHPolygon\Editor\Project\ProjectManifest;
use PHPUnit\Framework\TestCase;

/**
 * The command the editor's Play button runs.
 */
class ProjectManifestRunCommandTest extends TestCase
{
    private function manifest(string $runCommand = ''): ProjectManifest
    {
        return new ProjectManifest(
            name: 'Test',
            version: '0.1.0',
            engineVersion: '*',
            scenesPath: 'src/Scene',
            assetsPath: 'assets',
            psr4Roots: [],
            entryScene: '',
            runCommand: $runCommand,
        );
    }

    public function test_falls_back_to_the_scaffolded_entry_point(): void
    {
        // Play has to work in a generated project with no manifest entry at all.
        $this->assertSame('php game.php', $this->manifest()->resolvedRunCommand());
    }

    public function test_a_declared_run_command_wins(): void
    {
        $manifest = $this->manifest('php -d memory_limit=1G bin/launch.php --dev');

        $this->assertSame('php -d memory_limit=1G bin/launch.php --dev', $manifest->resolvedRunCommand());
    }

    public function test_run_command_round_trips_through_the_manifest_array(): void
    {
        $this->assertSame('php bin/run.php', $this->manifest('php bin/run.php')->toArray()['runCommand']);
        $this->assertSame('', $this->manifest()->toArray()['runCommand']);
    }
}
