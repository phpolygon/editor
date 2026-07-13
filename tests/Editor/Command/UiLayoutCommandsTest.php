<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\AddWidgetCommand;
use PHPolygon\Editor\Command\CreateUiLayoutCommand;
use PHPolygon\Editor\Command\ListUiLayoutsCommand;
use PHPolygon\Editor\Command\LoadUiLayoutCommand;
use PHPolygon\Editor\Command\RemoveWidgetCommand;
use PHPolygon\Editor\Command\ReparentWidgetCommand;
use PHPolygon\Editor\Command\SaveUiLayoutCommand;
use PHPolygon\Editor\Command\UpdateWidgetPropertyCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class UiLayoutCommandsTest extends TestCase
{
    private string $projectDir;

    private EditorContext $ctx;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon_ui_cmd_'.uniqid();
        mkdir($this->projectDir);

        $this->ctx = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test',
                version: '1.0.0',
                engineVersion: '*',
                scenesPath: 'src/Scenes',
                assetsPath: 'assets',
                psr4Roots: [],
                entryScene: '',
                defaultMode: '2d',
                uiPath: 'ui',
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

    private function uiFile(string $name): string
    {
        return $this->projectDir.'/ui/'.$name.'.ui.json';
    }

    public function test_create_writes_file_and_sets_active(): void
    {
        $result = (new CreateUiLayoutCommand(['name' => 'main_menu']))->execute($this->ctx);

        $this->assertSame('main_menu', $result['name']);
        $this->assertFileExists($this->uiFile('main_menu'));
        $this->assertNotNull($this->ctx->getActiveWidgetDocument());
    }

    public function test_create_rejects_duplicate(): void
    {
        (new CreateUiLayoutCommand(['name' => 'dup']))->execute($this->ctx);
        $this->expectException(RuntimeException::class);
        (new CreateUiLayoutCommand(['name' => 'dup']))->execute($this->ctx);
    }

    public function test_create_rejects_bad_name(): void
    {
        $this->expectException(RuntimeException::class);
        (new CreateUiLayoutCommand(['name' => '../evil']))->execute($this->ctx);
    }

    public function test_add_update_save_then_reload_roundtrip(): void
    {
        (new CreateUiLayoutCommand(['name' => 'main_menu']))->execute($this->ctx);
        $rootId = $this->ctx->getActiveWidgetDocument()->getRootId();

        $add = (new AddWidgetCommand(['parentId' => $rootId, 'type' => 'Button']))->execute($this->ctx);
        $buttonId = $add['added'];

        (new UpdateWidgetPropertyCommand(['id' => $buttonId, 'property' => 'label', 'value' => 'Play']))->execute($this->ctx);
        (new SaveUiLayoutCommand)->execute($this->ctx);

        // The persisted file carries the edit and no editor-only ids.
        $file = json_decode((string) file_get_contents($this->uiFile('main_menu')), true);
        $button = $file['root']['children'][0];
        $this->assertSame('Play', $button['label']);
        $this->assertArrayNotHasKey('_id', $button);

        // Reload from disk into a fresh document.
        $this->ctx->activeWidgetDocument = null;
        $loaded = (new LoadUiLayoutCommand(['name' => 'main_menu']))->execute($this->ctx);
        $this->assertSame('Play', $loaded['root']['children'][0]['label']);
    }

    public function test_reparent_widget(): void
    {
        (new CreateUiLayoutCommand(['name' => 'l']))->execute($this->ctx);
        $rootId = $this->ctx->getActiveWidgetDocument()->getRootId();

        $panel = (new AddWidgetCommand(['parentId' => $rootId, 'type' => 'Panel']))->execute($this->ctx)['added'];
        $label = (new AddWidgetCommand(['parentId' => $rootId, 'type' => 'Label']))->execute($this->ctx)['added'];

        $result = (new ReparentWidgetCommand(['id' => $label, 'newParentId' => $panel]))->execute($this->ctx);

        $this->assertCount(1, $result['root']['children']);
        $this->assertSame($label, $result['root']['children'][0]['children'][0]['_id']);
    }

    public function test_remove_widget(): void
    {
        (new CreateUiLayoutCommand(['name' => 'l']))->execute($this->ctx);
        $rootId = $this->ctx->getActiveWidgetDocument()->getRootId();
        $id = (new AddWidgetCommand(['parentId' => $rootId, 'type' => 'Label']))->execute($this->ctx)['added'];

        $result = (new RemoveWidgetCommand(['id' => $id]))->execute($this->ctx);
        $this->assertArrayNotHasKey('children', $result['root']);
    }

    public function test_list_layouts_sorted(): void
    {
        (new CreateUiLayoutCommand(['name' => 'zeta']))->execute($this->ctx);
        (new CreateUiLayoutCommand(['name' => 'alpha']))->execute($this->ctx);

        $result = (new ListUiLayoutsCommand)->execute($this->ctx);
        $this->assertSame(['alpha', 'zeta'], $result['layouts']);
    }

    public function test_add_without_active_throws(): void
    {
        $this->expectException(RuntimeException::class);
        (new AddWidgetCommand(['parentId' => 'w1', 'type' => 'Label']))->execute($this->ctx);
    }

    public function test_load_missing_throws(): void
    {
        $this->expectException(RuntimeException::class);
        (new LoadUiLayoutCommand(['name' => 'ghost']))->execute($this->ctx);
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
