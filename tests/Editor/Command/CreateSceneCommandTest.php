<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\CreateSceneCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Authoring a new scene in the editor produces a PHP Scene class (the editor
 * is the source; PHP is the generated artifact).
 */
class CreateSceneCommandTest extends TestCase
{
    private string $projectDir;

    private string $scenesDir;

    private EditorContext $ctx;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon_newscene_'.uniqid();
        $this->scenesDir = $this->projectDir.'/src/Scenes';
        mkdir($this->scenesDir, 0777, true);

        $this->ctx = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test', version: '1.0.0', engineVersion: '*',
                scenesPath: 'src/Scenes', assetsPath: 'assets',
                psr4Roots: ['App\\' => 'src'], entryScene: '', defaultMode: '2d',
            ),
            components: new ComponentRegistry,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: $this->projectDir,
        );
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->projectDir);
    }

    public function test_writes_php_class_with_derived_name_and_namespace(): void
    {
        $data = (new CreateSceneCommand(['name' => 'main_level']))->execute($this->ctx);

        $this->assertSame('main_level', $data['name']);
        $this->assertSame('App\\Scenes\\MainLevel', $data['_scene']);

        $php = (string) file_get_contents($this->scenesDir.'/MainLevel.php');
        $this->assertStringContainsString('namespace App\\Scenes;', $php);
        $this->assertStringContainsString('class MainLevel extends Scene', $php);
        $this->assertStringContainsString("return 'main_level'", $php);
    }

    public function test_sets_active_document(): void
    {
        (new CreateSceneCommand(['name' => 'intro']))->execute($this->ctx);
        $doc = $this->ctx->getActiveDocument();
        $this->assertNotNull($doc);
        $this->assertSame('intro', $doc->getName());
    }

    public function test_rejects_duplicate(): void
    {
        (new CreateSceneCommand(['name' => 'dup']))->execute($this->ctx);
        $this->expectException(RuntimeException::class);
        (new CreateSceneCommand(['name' => 'dup']))->execute($this->ctx);
    }

    public function test_rejects_bad_name(): void
    {
        $this->expectException(RuntimeException::class);
        (new CreateSceneCommand(['name' => '../evil']))->execute($this->ctx);
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
