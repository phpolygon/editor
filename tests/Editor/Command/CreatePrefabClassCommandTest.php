<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Editor\Command\CreatePrefabClassCommand;
use PHPolygon\Editor\Command\ListCodePrefabsCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\EntityDeclaration;
use PHPolygon\Scene\Prefab;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CreatePrefabClassCommandTest extends TestCase
{
    private string $projectDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-prefabclass-'.uniqid();
        mkdir($this->projectDir);

        $this->context = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test',
                version: '0.1.0',
                engineVersion: '*',
                scenesPath: 'src/Scene',
                assetsPath: 'assets',
                psr4Roots: ['Game\\' => 'src'],
                entryScene: '',
            ),
            components: new ComponentRegistry,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: $this->projectDir,
        );

        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [
                [
                    'name' => 'Lantern',
                    'components' => [
                        [
                            '_class' => 'PHPolygon\\Component\\Transform3D',
                            'position' => ['x' => 5, 'y' => 0, 'z' => 2],
                        ],
                        [
                            '_class' => 'PHPolygon\\Component\\MeshRenderer',
                            'meshId' => 'lantern_post',
                            'materialId' => 'metal',
                        ],
                    ],
                    'children' => [
                        [
                            'name' => 'Bulb',
                            'components' => [
                                [
                                    '_class' => 'PHPolygon\\Component\\Transform3D',
                                    'position' => ['x' => 0, 'y' => 3.5, 'z' => 0],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->projectDir);
    }

    /** @param array<string, mixed> $args */
    private function create(array $args): array
    {
        return (new CreatePrefabClassCommand($args))->execute($this->context);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_writes_the_class_into_the_projects_psr4_root(): void
    {
        $result = $this->create(['entityName' => 'Lantern']);

        $this->assertTrue($result['created']);
        $this->assertSame('Game\\Prefab\\Lantern', $result['class']);
        $this->assertFileExists($this->projectDir.'/src/Prefab/Lantern.php');
    }

    public function test_generated_class_is_syntactically_valid(): void
    {
        $result = $this->create(['entityName' => 'Lantern']);

        exec('php -l '.escapeshellarg($result['path']), $out, $code);

        $this->assertSame(0, $code, "syntax error:\n".implode("\n", $out));
    }

    public function test_generated_class_extends_prefab_and_builds_a_declaration(): void
    {
        $source = $this->generated();

        $this->assertStringContainsString('class Lantern extends Prefab', $source);
        $this->assertStringContainsString(
            'public function build(SceneBuilder $builder): EntityDeclaration',
            $source,
        );
        $this->assertStringContainsString('use PHPolygon\\Scene\\Prefab;', $source);
    }

    public function test_root_placement_comes_from_the_instance_not_the_authored_transform(): void
    {
        // Baking the authored position in would pin every placement of the
        // prefab to wherever the entity happened to sit when it was saved.
        $source = $this->generated();

        $this->assertStringContainsString('position: $this->getPosition()', $source);
        $this->assertStringContainsString('rotation: $this->getRotation()', $source);
        $this->assertStringContainsString('scale: $this->getScale()', $source);
        $this->assertStringNotContainsString('new Vec3(5.0, 0.0, 2.0)', $source);
    }

    public function test_components_are_carried_over(): void
    {
        $source = $this->generated();

        $this->assertStringContainsString("new MeshRenderer(meshId: 'lantern_post'", $source);
        $this->assertStringContainsString("materialId: 'metal'", $source);
        $this->assertStringContainsString('use PHPolygon\\Component\\MeshRenderer;', $source);
    }

    public function test_children_are_nested_and_closed(): void
    {
        $source = $this->generated();

        $this->assertStringContainsString("->child('Bulb')", $source);
        $this->assertStringContainsString('->end()', $source);
        // A child's own transform IS authored data and must survive.
        $this->assertStringContainsString('3.5', $source);
    }

    /**
     * The test that actually matters: a syntax check says nothing about whether
     * the fluent chain assembles the right tree. Load the generated class and
     * run it against a real SceneBuilder.
     */
    public function test_the_generated_class_builds_the_authored_subtree(): void
    {
        $result = $this->create(['entityName' => 'Lantern', 'className' => 'RuntimeLantern']);
        require_once $result['path'];

        /** @var class-string<Prefab> $fqcn */
        $fqcn = $result['class'];
        $builder = new SceneBuilder;
        $prefab = $builder->spawn(new $fqcn);
        $declaration = $prefab->named('Lantern_7')->at(new Vec3(10, 0, -4))->place();

        $this->assertSame('Lantern_7', $declaration->getName());

        // Placement reaches the root transform from the instance.
        $transform = $this->componentOf($declaration, Transform3D::class);
        $this->assertNotNull($transform);
        $this->assertSame(10.0, $transform->position->x);
        $this->assertSame(-4.0, $transform->position->z);

        $mesh = $this->componentOf($declaration, MeshRenderer::class);
        $this->assertNotNull($mesh);
        $this->assertSame('lantern_post', $mesh->meshId);

        $children = $declaration->getChildren();
        $this->assertCount(1, $children);
        $this->assertSame('Bulb', $children[0]->getName());

        $childTransform = $this->componentOf($children[0], Transform3D::class);
        $this->assertNotNull($childTransform);
        $this->assertSame(3.5, $childTransform->position->y);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function componentOf(EntityDeclaration $declaration, string $class): ?object
    {
        foreach ($declaration->getComponents() as $component) {
            if ($component instanceof $class) {
                return $component;
            }
        }

        return null;
    }

    /**
     * The whole point of generating a class: it is placeable straight away.
     * Nothing registers it — the palette scans the project's PSR-4 roots, and
     * at runtime the engine resolves the reference through the autoloader.
     */
    public function test_a_generated_prefab_shows_up_in_the_palette(): void
    {
        $result = $this->create(['entityName' => 'Lantern', 'className' => 'PaletteLantern']);
        require_once $result['path'];

        $listed = (new ListCodePrefabsCommand)->execute($this->context)['prefabs'];

        $this->assertContains(
            'Game\\Prefab\\PaletteLantern',
            array_column($listed, 'class'),
        );
    }

    public function test_a_regenerable_file_is_replaced(): void
    {
        $this->create(['entityName' => 'Lantern']);
        $result = $this->create(['entityName' => 'Lantern']);

        $this->assertTrue($result['replaced']);
    }

    public function test_a_hand_edited_file_is_not_overwritten(): void
    {
        // The whole point of code prefabs is that you can add logic to them;
        // silently regenerating over that would be the worst possible outcome.
        $result = $this->create(['entityName' => 'Lantern']);
        file_put_contents(
            $result['path'],
            str_replace('extends Prefab', 'extends Prefab /* my own tweak */', (string) file_get_contents($result['path'])),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/edited by hand/');
        $this->create(['entityName' => 'Lantern']);
    }

    public function test_overwrite_insists_past_a_hand_edited_file(): void
    {
        $result = $this->create(['entityName' => 'Lantern']);
        file_put_contents($result['path'], "<?php\n// entirely mine\n");

        $again = $this->create(['entityName' => 'Lantern', 'overwrite' => true]);

        $this->assertTrue($again['replaced']);
        $this->assertStringContainsString('extends Prefab', (string) file_get_contents($again['path']));
    }

    public function test_a_prefab_instance_cannot_be_saved_as_a_prefab(): void
    {
        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [
                ['name' => 'Placed', 'prefab' => 'Game\\Prefab\\Lantern', 'components' => []],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already a prefab instance/');
        $this->create(['entityName' => 'Placed']);
    }

    public function test_free_form_names_become_valid_class_names(): void
    {
        $result = $this->create(['entityName' => 'Lantern', 'className' => 'street lantern-2']);

        $this->assertSame('StreetLantern2', $result['className']);
        $this->assertFileExists($this->projectDir.'/src/Prefab/StreetLantern2.php');
    }

    public function test_a_missing_entity_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->create(['entityName' => 'Nope']);
    }

    public function test_a_project_without_a_psr4_root_is_an_error(): void
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
            components: new ComponentRegistry,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: $this->projectDir,
        );
        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [['name' => 'Lantern', 'components' => []]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/PSR-4 root/');
        $this->create(['entityName' => 'Lantern']);
    }

    private function generated(): string
    {
        return (string) file_get_contents($this->create(['entityName' => 'Lantern'])['path']);
    }
}
