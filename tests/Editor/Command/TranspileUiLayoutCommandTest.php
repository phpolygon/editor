<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\AddWidgetCommand;
use PHPolygon\Editor\Command\CreateUiLayoutCommand;
use PHPolygon\Editor\Command\TranspileUiLayoutCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TranspileUiLayoutCommandTest extends TestCase
{
    private string $projectDir;

    private EditorContext $ctx;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon_uiphp_'.uniqid();
        mkdir($this->projectDir.'/src/Ui', 0777, true);

        $this->ctx = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test', version: '1.0.0', engineVersion: '*',
                scenesPath: 'src/Scenes', assetsPath: 'assets',
                psr4Roots: ['App\\' => 'src'], entryScene: '', defaultMode: '2d',
                uiPath: 'src/Ui',
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

    public function test_transpiles_active_layout_to_php_class(): void
    {
        (new CreateUiLayoutCommand(['name' => 'main_menu']))->execute($this->ctx);
        $rootId = $this->ctx->getActiveWidgetDocument()->getRootId();
        (new AddWidgetCommand(['parentId' => $rootId, 'type' => 'Panel']))->execute($this->ctx);

        $result = (new TranspileUiLayoutCommand)->execute($this->ctx);

        $this->assertSame('MainMenuLayout', $result['className']);
        $this->assertFileExists($this->projectDir.'/src/Ui/MainMenuLayout.php');

        $php = (string) file_get_contents($this->projectDir.'/src/Ui/MainMenuLayout.php');
        $this->assertStringContainsString('namespace App\\Ui;', $php);
        $this->assertStringContainsString('final class MainMenuLayout', $php);
        $this->assertStringContainsString('public static function build(): Widget', $php);
        $this->assertStringContainsString('new VBox(', $php);
        $this->assertStringContainsString('new Panel(', $php);
        $this->assertStringContainsString('->addChild(', $php);
    }

    public function test_throws_without_active_layout(): void
    {
        $this->expectException(RuntimeException::class);
        (new TranspileUiLayoutCommand)->execute($this->ctx);
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
