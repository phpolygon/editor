<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Project;

use PHPolygon\Editor\Project\ProjectImporter;
use PHPolygon\Editor\Project\ProjectLoader;
use PHPUnit\Framework\TestCase;

class ProjectImporterTest extends TestCase
{
    private string $projectDir;

    private ProjectImporter $importer;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon_import_'.uniqid();
        mkdir($this->projectDir);
        $this->importer = new ProjectImporter(new ProjectLoader);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->projectDir);
    }

    public function test_creates_manifest_when_absent(): void
    {
        $result = $this->importer->import($this->projectDir);

        $this->assertTrue($result['created']);
        $this->assertFileExists($this->projectDir.'/'.ProjectLoader::MANIFEST_FILE);
    }

    public function test_derives_name_and_psr4_roots_from_composer(): void
    {
        file_put_contents($this->projectDir.'/composer.json', json_encode([
            'name' => 'acme/space-shooter',
            'autoload' => [
                'psr-4' => [
                    'Acme\\Game\\' => 'src/',
                    'Acme\\Assets\\' => ['assets/php'],
                ],
            ],
        ]));

        $manifest = $this->importer->import($this->projectDir)['manifest'];

        $this->assertSame('Space Shooter', $manifest->name);
        $this->assertSame([
            'Acme\\Game\\' => 'src',
            'Acme\\Assets\\' => 'assets/php',
        ], $manifest->psr4Roots);
    }

    public function test_stores_psr4_roots_as_portable_forward_slash_paths(): void
    {
        // A composer.json authored on Windows may carry backslash roots. The
        // manifest must normalize them to forward slashes so it reads the same
        // on every OS and Path::join can rewrite them per platform.
        file_put_contents($this->projectDir.'/composer.json', json_encode([
            'name' => 'acme/game',
            'autoload' => [
                'psr-4' => [
                    'Acme\\Game\\' => 'src\\Game\\',
                    'Acme\\Assets\\' => 'assets\\php',
                ],
            ],
        ]));

        $manifest = $this->importer->import($this->projectDir)['manifest'];

        $this->assertSame([
            'Acme\\Game\\' => 'src/Game',
            'Acme\\Assets\\' => 'assets/php',
        ], $manifest->psr4Roots);
    }

    public function test_falls_back_to_directory_name_without_composer(): void
    {
        $manifest = $this->importer->import($this->projectDir)['manifest'];

        // basename of the temp dir is "phpolygon_import_<uniqid>" → titleized.
        $this->assertStringContainsString('Phpolygon Import', $manifest->name);
        $this->assertSame([], $manifest->psr4Roots);
    }

    public function test_creates_scene_and_asset_directories(): void
    {
        $manifest = $this->importer->import($this->projectDir)['manifest'];

        $this->assertDirectoryExists($this->projectDir.'/'.$manifest->scenesPath);
        $this->assertDirectoryExists($this->projectDir.'/'.$manifest->assetsPath);
    }

    public function test_does_not_overwrite_existing_manifest(): void
    {
        file_put_contents(
            $this->projectDir.'/'.ProjectLoader::MANIFEST_FILE,
            json_encode(['name' => 'Already Here', 'entryScene' => 'Main']),
        );

        $result = $this->importer->import($this->projectDir);

        $this->assertFalse($result['created']);
        $this->assertSame('Already Here', $result['manifest']->name);
        $this->assertSame('Main', $result['manifest']->entryScene);
    }

    public function test_rejects_missing_directory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->importer->import($this->projectDir.'/does-not-exist');
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
