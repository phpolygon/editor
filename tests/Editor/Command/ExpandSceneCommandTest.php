<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\ExpandSceneCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

class ExpandSceneCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/phpolygon-editor-expand-' . uniqid();
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->projectDir . '/*') ?: []);
        @rmdir($this->projectDir);
    }

    /**
     * @param list<array<string, mixed>> $entities
     */
    private function context(string $expandCommand, array $entities): EditorContext
    {
        $context = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test',
                version: '0.1.0',
                engineVersion: '*',
                scenesPath: 'src/Scene',
                assetsPath: 'assets',
                psr4Roots: [],
                entryScene: '',
                expandCommand: $expandCommand,
            ),
            components: new ComponentRegistry(),
            systems: new SystemRegistry(),
            transpiler: new SceneTranspiler(),
            projectDir: $this->projectDir,
        );
        $context->activeDocument = new SceneDocument(['name' => 'test', 'entities' => $entities]);

        return $context;
    }

    /** A fixture expand command that writes a fixed geometry bundle to $argv[2]. */
    private function fixtureExpandCommand(): string
    {
        $php = <<<'PHP'
<?php
$bundle = [
  'entities' => [[
    'name' => 'Terminal_Base',
    'components' => [['_class' => 'PHPolygon\\Component\\MeshRenderer', 'meshId' => 'terminal_base', 'materialId' => 'terminal_body']],
  ]],
  'meshes' => ['terminal_base' => ['id' => 'terminal_base', 'version' => 1, 'vertices' => [0,0,0], 'normals' => [0,1,0], 'uvs' => [0,0], 'indices' => [0], 'vertexCount' => 1, 'triangleCount' => 0]],
  'materials' => ['terminal_body' => ['id' => 'terminal_body', 'albedo' => ['r'=>0.1,'g'=>0.1,'b'=>0.2,'a'=>1.0], 'roughness'=>0.4, 'metallic'=>0.6, 'emission'=>['r'=>0,'g'=>0,'b'=>0,'a'=>1], 'alpha'=>1.0, 'shader'=>'default', 'albedoTexture'=>null, 'clearcoat'=>0.0, 'clearcoatRoughness'=>0.0, 'normalIntensity'=>1.0, 'useEnvironmentMap'=>true, 'normalPattern'=>'none', 'surfacePattern'=>'none']],
];
file_put_contents($argv[2], json_encode($bundle));
PHP;
        file_put_contents($this->projectDir . '/expand.php', $php);

        return 'php expand.php';
    }

    /** @return list<array<string, mixed>> */
    private function terminalRefEntities(): array
    {
        return [[
            'name' => 'Terminal',
            'prefab' => 'CodeRescue\\Prefab\\TerminalPrefabDef',
            'components' => [['_class' => 'CodeRescue\\Component\\TerminalDesign', 'variant' => 'php']],
        ]];
    }

    public function testExpandsPrefabRefsIntoGeometryAndCachesAssets(): void
    {
        $context = $this->context($this->fixtureExpandCommand(), $this->terminalRefEntities());

        $result = (new ExpandSceneCommand())->execute($context);

        self::assertTrue($result['expanded'] ?? false);
        $names = array_map(static fn (array $e): mixed => $e['name'] ?? null, $result['entities']);
        self::assertContains('Terminal_Base', $names);

        // Meshes/materials from the bundle are cached for get_mesh / get_material.
        // Read via $context->projectDir (EditorContext normalises the path), the
        // same value ExpandSceneCommand wrote with and get_mesh reads with.
        self::assertNotNull(ProjectAssetCache::mesh($context->projectDir, 'terminal_base'));
        self::assertNotNull(ProjectAssetCache::material($context->projectDir, 'terminal_body'));

        // The document still holds the authored REFERENCE (preview is read-only).
        $entity = $context->activeDocument->getEntity('Terminal');
        self::assertSame('CodeRescue\\Prefab\\TerminalPrefabDef', $entity['prefab']);
    }

    public function testSceneWithoutPrefabRefsIsReturnedAsIs(): void
    {
        $entities = [['name' => 'Plain', 'components' => [['_class' => 'PHPolygon\\Component\\Transform3D']]]];
        $context = $this->context($this->fixtureExpandCommand(), $entities);

        $result = (new ExpandSceneCommand())->execute($context);

        self::assertArrayNotHasKey('expanded', $result);
        $names = array_map(static fn (array $e): mixed => $e['name'] ?? null, $result['entities']);
        self::assertSame(['Plain'], $names);
    }

    public function testEmptyExpandCommandFallsBackToAuthoredScene(): void
    {
        $context = $this->context('', $this->terminalRefEntities());

        $result = (new ExpandSceneCommand())->execute($context);

        self::assertArrayNotHasKey('expanded', $result);
        $names = array_map(static fn (array $e): mixed => $e['name'] ?? null, $result['entities']);
        self::assertSame(['Terminal'], $names);
    }

    public function testFailingExpandCommandFallsBackToAuthoredScene(): void
    {
        $context = $this->context('php does-not-exist.php', $this->terminalRefEntities());

        $result = (new ExpandSceneCommand())->execute($context);

        self::assertArrayNotHasKey('expanded', $result);
        $names = array_map(static fn (array $e): mixed => $e['name'] ?? null, $result['entities']);
        self::assertSame(['Terminal'], $names);
    }
}
