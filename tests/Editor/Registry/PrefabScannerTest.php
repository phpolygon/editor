<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Registry;

use PHPolygon\Editor\Registry\PrefabScanner;
use PHPUnit\Framework\TestCase;

class PrefabScannerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-prefabscan-'.uniqid();
        mkdir($this->projectDir.'/src/Prefab', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->projectDir);
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

    /**
     * Write a class file and load it, standing in for the project autoloader
     * that has the classes available in the real editor process.
     */
    private function writeClass(string $relativePath, string $source): void
    {
        $file = $this->projectDir.'/'.$relativePath;
        $dir = dirname($file);
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($file, $source);
        require $file;
    }

    /** @param array<string, string> $roots */
    private function scan(array $roots = ['ScanFixture\\' => 'src']): array
    {
        return (new PrefabScanner)->scan($this->projectDir, $roots);
    }

    public function test_finds_prefab_classes_under_a_psr4_root(): void
    {
        $this->writeClass('src/Prefab/Lamp.php', <<<'PHP'
        <?php
        namespace ScanFixture\Prefab;

        use PHPolygon\Scene\EntityDeclaration;
        use PHPolygon\Scene\Prefab;
        use PHPolygon\Scene\SceneBuilder;

        class Lamp extends Prefab
        {
            public function build(SceneBuilder $builder): EntityDeclaration
            {
                return $builder->entity($this->getInstanceName());
            }
        }
        PHP);

        $prefabs = $this->scan();

        $this->assertCount(1, $prefabs);
        $this->assertSame('Lamp', $prefabs[0]['name']);
        $this->assertSame('ScanFixture\\Prefab\\Lamp', $prefabs[0]['class']);
    }

    public function test_ignores_classes_that_are_not_prefabs(): void
    {
        $this->writeClass('src/Prefab/NotAPrefab.php', <<<'PHP'
        <?php
        namespace ScanFixture\Prefab;

        class NotAPrefab
        {
        }
        PHP);

        $this->assertSame([], $this->scan());
    }

    public function test_ignores_abstract_prefabs(): void
    {
        // A base class the game's own prefabs extend is not placeable itself.
        $this->writeClass('src/Prefab/BaseProp.php', <<<'PHP'
        <?php
        namespace ScanFixture\Prefab;

        use PHPolygon\Scene\Prefab;

        abstract class BaseProp extends Prefab
        {
        }
        PHP);

        $this->assertSame([], $this->scan());
    }

    public function test_uses_the_prefabs_own_name(): void
    {
        $this->writeClass('src/Prefab/Bench.php', <<<'PHP'
        <?php
        namespace ScanFixture\Prefab;

        use PHPolygon\Scene\EntityDeclaration;
        use PHPolygon\Scene\Prefab;
        use PHPolygon\Scene\SceneBuilder;

        class Bench extends Prefab
        {
            public static function getName(): string
            {
                return 'Park Bench';
            }

            public function build(SceneBuilder $builder): EntityDeclaration
            {
                return $builder->entity($this->getInstanceName());
            }
        }
        PHP);

        $this->assertSame('Park Bench', $this->scan()[0]['name']);
    }

    public function test_a_throwing_get_name_still_yields_a_usable_entry(): void
    {
        // getName() is project code; one bad prefab must not blank the palette.
        $this->writeClass('src/Prefab/Cranky.php', <<<'PHP'
        <?php
        namespace ScanFixture\Prefab;

        use PHPolygon\Scene\EntityDeclaration;
        use PHPolygon\Scene\Prefab;
        use PHPolygon\Scene\SceneBuilder;

        class Cranky extends Prefab
        {
            public static function getName(): string
            {
                throw new \RuntimeException('nope');
            }

            public function build(SceneBuilder $builder): EntityDeclaration
            {
                return $builder->entity($this->getInstanceName());
            }
        }
        PHP);

        $prefabs = $this->scan();

        $this->assertSame('Cranky', $prefabs[0]['name']);
        $this->assertSame('ScanFixture\\Prefab\\Cranky', $prefabs[0]['class']);
    }

    public function test_a_project_without_the_directory_yields_nothing(): void
    {
        $this->assertSame([], $this->scan(['ScanFixture\\' => 'nowhere']));
    }

    public function test_an_unopened_project_yields_nothing(): void
    {
        $this->assertSame([], (new PrefabScanner)->scan('', ['ScanFixture\\' => 'src']));
    }
}
