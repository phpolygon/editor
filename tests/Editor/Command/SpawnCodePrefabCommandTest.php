<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\SpawnCodePrefabCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

class SpawnCodePrefabCommandTest extends TestCase
{
    private EditorContext $context;

    protected function setUp(): void
    {
        $this->context = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test',
                version: '0.1.0',
                engineVersion: '*',
                scenesPath: 'src/Scene',
                assetsPath: 'assets',
                psr4Roots: [],
                entryScene: '',
            ),
            components: new ComponentRegistry(),
            systems: new SystemRegistry(),
            transpiler: new SceneTranspiler(),
            projectDir: sys_get_temp_dir(),
        );
        $this->context->activeDocument = new SceneDocument(['name' => 'test', 'entities' => []]);
    }

    public function testSpawnsPrefabReferenceWithAuthoredComponents(): void
    {
        $result = (new SpawnCodePrefabCommand([
            'class' => 'CodeRescue\\Prefab\\TerminalPrefabDef',
            'name' => 'Terminal',
            'components' => [
                ['_class' => 'CodeRescue\\Component\\TerminalDesign', 'variant' => 'rust'],
                ['_class' => 'PHPolygon\\Component\\Transform3D', 'position' => ['x' => 3.0, 'y' => 0.0, 'z' => -4.0]],
            ],
        ]))->execute($this->context);

        self::assertSame('Terminal', $result['spawned']);
        self::assertSame('CodeRescue\\Prefab\\TerminalPrefabDef', $result['prefab']);

        $entity = $this->context->activeDocument->getEntity('Terminal');
        self::assertNotNull($entity);
        // Stored as a REFERENCE + authored overrides, NOT inlined geometry.
        self::assertSame('CodeRescue\\Prefab\\TerminalPrefabDef', $entity['prefab']);
        $classes = array_map(static fn (array $c): mixed => $c['_class'] ?? null, $entity['components']);
        self::assertContains('CodeRescue\\Component\\TerminalDesign', $classes);
        self::assertContains('PHPolygon\\Component\\Transform3D', $classes);
        self::assertNotContains('PHPolygon\\Component\\MeshRenderer', $classes);
    }

    public function testPrefabFieldSurvivesToArrayForSaving(): void
    {
        (new SpawnCodePrefabCommand([
            'class' => 'CodeRescue\\Prefab\\TerminalPrefabDef',
            'name' => 'Terminal',
            'components' => [['_class' => 'CodeRescue\\Component\\TerminalDesign', 'variant' => 'php']],
        ]))->execute($this->context);

        $data = $this->context->activeDocument->toArray();
        self::assertSame('CodeRescue\\Prefab\\TerminalPrefabDef', $data['entities'][0]['prefab']);
    }

    public function testUniqueNamesOnRepeatedSpawns(): void
    {
        $args = ['class' => 'CodeRescue\\Prefab\\TerminalPrefabDef', 'name' => 'Terminal'];
        $a = (new SpawnCodePrefabCommand($args))->execute($this->context);
        $b = (new SpawnCodePrefabCommand($args))->execute($this->context);

        self::assertSame('Terminal', $a['spawned']);
        self::assertSame('Terminal_2', $b['spawned']);
    }

    public function testMissingClassThrows(): void
    {
        $this->expectExceptionMessage("Missing 'class'");
        (new SpawnCodePrefabCommand([]))->execute($this->context);
    }
}
