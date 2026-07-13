<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Project;

use PHPolygon\Editor\Project\ProjectLoader;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPUnit\Framework\TestCase;

class ProjectLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/phpolygon_test_'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up
        $files = glob($this->tempDir.'/*');
        foreach ($files ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    public function test_load_manifest(): void
    {
        file_put_contents($this->tempDir.'/phpolygon.project.json', json_encode([
            '_format' => 1,
            'name' => 'Code Tycoon',
            'version' => '0.1.0',
            'engineVersion' => '^0.4',
            'scenesPath' => 'src/Scene',
            'assetsPath' => 'assets',
            'psr4Roots' => ['CodeTycoon\\' => 'src/'],
            'entryScene' => 'MainMenu',
        ]));

        $loader = new ProjectLoader;
        $manifest = $loader->load($this->tempDir);

        $this->assertSame('Code Tycoon', $manifest->name);
        $this->assertSame('0.1.0', $manifest->version);
        $this->assertSame('^0.4', $manifest->engineVersion);
        $this->assertSame('src/Scene', $manifest->scenesPath);
        $this->assertSame('MainMenu', $manifest->entryScene);
        $this->assertArrayHasKey('CodeTycoon\\', $manifest->psr4Roots);
        // Absent defaultMode falls back to 3d (preserves existing projects).
        $this->assertSame('3d', $manifest->defaultMode);
        // Absent live-world fields default to empty (feature stays opt-in).
        $this->assertSame('', $manifest->liveWorldScene);
        $this->assertSame('', $manifest->liveWorldCommand);
    }

    public function test_live_world_fields_are_read_and_round_trip(): void
    {
        file_put_contents($this->tempDir.'/phpolygon.project.json', json_encode([
            'name' => 'Code Tycoon',
            'liveWorldScene' => 'GameScene',
            'liveWorldCommand' => 'php bin/editor-export.php',
        ]));

        $loader = new ProjectLoader;
        $manifest = $loader->load($this->tempDir);
        $this->assertSame('GameScene', $manifest->liveWorldScene);
        $this->assertSame('php bin/editor-export.php', $manifest->liveWorldCommand);

        // Persisted and reloaded unchanged.
        $loader->save($manifest, $this->tempDir);
        $reloaded = $loader->load($this->tempDir);
        $this->assertSame('GameScene', $reloaded->liveWorldScene);
        $this->assertSame('php bin/editor-export.php', $reloaded->liveWorldCommand);
    }

    public function test_default_mode_is_read_and_validated(): void
    {
        file_put_contents($this->tempDir.'/phpolygon.project.json', json_encode([
            'name' => 'Two Dee',
            'defaultMode' => '2d',
        ]));

        $manifest = (new ProjectLoader)->load($this->tempDir);
        $this->assertSame('2d', $manifest->defaultMode);

        // Anything other than '2d' normalises to '3d'.
        file_put_contents($this->tempDir.'/phpolygon.project.json', json_encode([
            'name' => 'Junk',
            'defaultMode' => 'nonsense',
        ]));
        $this->assertSame('3d', (new ProjectLoader)->load($this->tempDir)->defaultMode);
    }

    public function test_default_mode_round_trips(): void
    {
        $manifest = new ProjectManifest(
            name: 'Test Game',
            version: '1.0.0',
            engineVersion: '*',
            scenesPath: 'scenes',
            assetsPath: 'res',
            psr4Roots: ['TestGame\\' => 'src/'],
            entryScene: 'Intro',
            defaultMode: '2d',
        );

        $loader = new ProjectLoader;
        $loader->save($manifest, $this->tempDir);

        $this->assertSame('2d', $loader->load($this->tempDir)->defaultMode);
    }

    public function test_save_and_reload(): void
    {
        $manifest = new ProjectManifest(
            name: 'Test Game',
            version: '1.0.0',
            engineVersion: '*',
            scenesPath: 'scenes',
            assetsPath: 'res',
            psr4Roots: ['TestGame\\' => 'src/'],
            entryScene: 'Intro',
        );

        $loader = new ProjectLoader;
        $loader->save($manifest, $this->tempDir);

        $loaded = $loader->load($this->tempDir);
        $this->assertSame('Test Game', $loaded->name);
        $this->assertSame('Intro', $loaded->entryScene);
    }

    public function test_missing_manifest_throws(): void
    {
        $loader = new ProjectLoader;
        $this->expectException(\RuntimeException::class);
        $loader->load($this->tempDir);
    }

    public function test_missing_name_throws(): void
    {
        file_put_contents($this->tempDir.'/phpolygon.project.json', json_encode([
            'version' => '1.0',
        ]));

        $loader = new ProjectLoader;
        $this->expectException(\RuntimeException::class);
        $loader->load($this->tempDir);
    }
}
