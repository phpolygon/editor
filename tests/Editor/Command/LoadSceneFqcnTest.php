<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\LoadSceneCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

/**
 * A manifest's entryScene is a fully-qualified class name; the loader keys
 * scenes by file basename. Loading by FQCN must resolve to the basename file
 * (src/Scene/<Name>.php) rather than joining the namespace path under the
 * scenes dir (which produced "Scene file not found: .../CodeRescue/Scene/...").
 *
 * The fixture scene is created once for the class so its PHP file is only ever
 * required (and its class declared) a single time in the test process.
 */
class LoadSceneFqcnTest extends TestCase
{
    private static string $projectDir = '';

    public static function setUpBeforeClass(): void
    {
        self::$projectDir = sys_get_temp_dir().'/phpolygon-loadscene-fqcn-'.uniqid();
        mkdir(self::$projectDir.'/src/Scene', 0o777, true);

        file_put_contents(
            self::$projectDir.'/src/Scene/MenuScene.php',
            <<<'PHP'
<?php
namespace LoadSceneFqcnFixture\Scene;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;
class MenuScene extends Scene {
    public function getName(): string { return 'menu'; }
    public function build(SceneBuilder $builder): void {}
}
PHP
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::rrmdir(self::$projectDir);
    }

    private function context(): EditorContext
    {
        return new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test',
                version: '1.0.0',
                engineVersion: '*',
                scenesPath: 'src/Scene',
                assetsPath: 'assets',
                psr4Roots: ['LoadSceneFqcnFixture\\' => 'src'],
                entryScene: 'LoadSceneFqcnFixture\\Scene\\MenuScene',
            ),
            components: new ComponentRegistry(),
            systems: new SystemRegistry(),
            transpiler: new SceneTranspiler(),
            projectDir: self::$projectDir,
        );
    }

    public function test_loads_scene_given_a_fully_qualified_class_name(): void
    {
        $result = (new LoadSceneCommand([
            'sceneName' => 'LoadSceneFqcnFixture\\Scene\\MenuScene',
        ]))->execute($this->context());

        $this->assertSame('menu', $result['name']);
        $this->assertArrayHasKey('entities', $result);
    }

    public function test_loads_scene_given_a_plain_basename(): void
    {
        $result = (new LoadSceneCommand(['sceneName' => 'MenuScene']))->execute($this->context());

        $this->assertSame('menu', $result['name']);
    }

    private static function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? self::rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
