<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\ListScenesCommand;
use PHPolygon\Editor\Command\LoadSceneCommand;
use PHPolygon\Editor\Command\SaveSceneCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

/**
 * The editor can load an exported `*.scene.json` snapshot (e.g. a live game
 * world) directly as data — no PHP scene class required.
 */
class JsonSceneCommandsTest extends TestCase
{
    private string $projectDir;

    private string $scenesDir;

    private EditorContext $ctx;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon_jsonscene_'.uniqid();
        $this->scenesDir = $this->projectDir.'/src/Scenes';
        mkdir($this->scenesDir, 0777, true);

        file_put_contents($this->scenesDir.'/game_world.scene.json', json_encode([
            '_version' => 1,
            'name' => 'game_world',
            'systems' => ['App\\Systems\\DevSystem'],
            'entities' => [
                ['name' => 'client_acme', 'components' => [
                    ['_class' => 'App\\Client', 'id' => 'acme', 'label' => 'Acme Corp.'],
                ]],
                ['name' => 'office_desk', 'components' => [
                    ['_class' => 'PHPolygon\\Component\\Transform2D', 'position' => ['x' => 10, 'y' => 20]],
                    ['_class' => 'PHPolygon\\Component\\SpriteRenderer', 'width' => 96, 'height' => 64],
                ]],
            ],
        ]));

        $this->ctx = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test', version: '1.0.0', engineVersion: '*',
                scenesPath: 'src/Scenes', assetsPath: 'assets',
                psr4Roots: [], entryScene: '', defaultMode: '2d',
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

    public function test_list_includes_json_scene(): void
    {
        $scenes = (new ListScenesCommand)->execute($this->ctx)['scenes'];
        $this->assertContains('game_world', $scenes);
    }

    public function test_load_json_scene_returns_nested_components(): void
    {
        $data = (new LoadSceneCommand(['sceneName' => 'game_world']))->execute($this->ctx);

        $this->assertSame('game_world', $data['name']);
        $this->assertCount(2, $data['entities']);

        // Components arrive in the nested {_class, properties} shape.
        $desk = $data['entities'][1];
        $this->assertSame('office_desk', $desk['name']);
        $transform = $desk['components'][0];
        $this->assertSame('PHPolygon\\Component\\Transform2D', $transform['_class']);
        $this->assertSame(['x' => 10, 'y' => 20], $transform['properties']['position']);
        $this->assertArrayNotHasKey('position', $transform); // not flat anymore
    }

    public function test_save_writes_json_scene_back_as_json(): void
    {
        (new LoadSceneCommand(['sceneName' => 'game_world']))->execute($this->ctx);
        $result = (new SaveSceneCommand)->execute($this->ctx);

        $this->assertSame('json', $result['format']);
        $this->assertStringEndsWith('game_world.scene.json', str_replace('\\', '/', $result['saved']));

        // File is still valid JSON with the flat on-disk component shape.
        $reloaded = json_decode((string) file_get_contents($this->scenesDir.'/game_world.scene.json'), true);
        $this->assertSame('game_world', $reloaded['name']);
        $this->assertArrayHasKey('position', $reloaded['entities'][1]['components'][0]);
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
