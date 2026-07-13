<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\AddLayoutElementCommand;
use PHPolygon\Editor\Command\CreatePanelLayoutCommand;
use PHPolygon\Editor\Command\ListPanelLayoutsCommand;
use PHPolygon\Editor\Command\LoadPanelLayoutCommand;
use PHPolygon\Editor\Command\RemoveLayoutElementCommand;
use PHPolygon\Editor\Command\RenameLayoutElementCommand;
use PHPolygon\Editor\Command\SavePanelLayoutCommand;
use PHPolygon\Editor\Command\UpdateLayoutElementCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PanelLayoutCommandsTest extends TestCase
{
    private string $projectDir;

    private EditorContext $ctx;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon_panelcmd_'.uniqid();
        mkdir($this->projectDir, 0777, true);

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

    private function file(string $name): string
    {
        return $this->projectDir.'/assets/ui/'.$name.'.layout.json';
    }

    public function test_create_writes_file_and_lists(): void
    {
        (new CreatePanelLayoutCommand(['name' => 'main_menu']))->execute($this->ctx);

        $this->assertFileExists($this->file('main_menu'));
        $this->assertContains('main_menu', (new ListPanelLayoutsCommand)->execute($this->ctx)['layouts']);
    }

    public function test_element_crud_and_roundtrip(): void
    {
        (new CreatePanelLayoutCommand(['name' => 'main_menu']))->execute($this->ctx);

        $r = (new AddLayoutElementCommand(['id' => 'play', 'x' => 10, 'y' => 20, 'width' => 200, 'height' => 48]))->execute($this->ctx);
        $this->assertSame('play', $r['added']);
        $this->assertSame(10.0, $r['elements']['play']['x']);

        (new UpdateLayoutElementCommand(['id' => 'play', 'props' => ['label' => 'menu.play', 'x' => 55]]))->execute($this->ctx);
        (new RenameLayoutElementCommand(['oldId' => 'play', 'newId' => 'start']))->execute($this->ctx);

        (new SavePanelLayoutCommand)->execute($this->ctx);

        // Reload from disk into a fresh document.
        $this->ctx->activePanelLayout = null;
        $data = (new LoadPanelLayoutCommand(['name' => 'main_menu']))->execute($this->ctx);
        $this->assertArrayHasKey('start', $data['elements']);
        $this->assertArrayNotHasKey('play', $data['elements']);
        $this->assertSame('menu.play', $data['elements']['start']['label']);
        $this->assertEquals(55, $data['elements']['start']['x']);
    }

    public function test_remove_element(): void
    {
        (new CreatePanelLayoutCommand(['name' => 'l']))->execute($this->ctx);
        (new AddLayoutElementCommand(['id' => 'a']))->execute($this->ctx);
        $data = (new RemoveLayoutElementCommand(['id' => 'a']))->execute($this->ctx);
        $this->assertArrayNotHasKey('a', $data['elements']);
    }

    public function test_add_without_active_throws(): void
    {
        $this->expectException(RuntimeException::class);
        (new AddLayoutElementCommand(['id' => 'x']))->execute($this->ctx);
    }

    public function test_create_rejects_duplicate(): void
    {
        (new CreatePanelLayoutCommand(['name' => 'dup']))->execute($this->ctx);
        $this->expectException(RuntimeException::class);
        (new CreatePanelLayoutCommand(['name' => 'dup']))->execute($this->ctx);
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
