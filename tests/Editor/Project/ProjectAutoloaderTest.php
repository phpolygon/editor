<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Project;

use PHPolygon\Editor\Project\ProjectAutoloader;
use PHPUnit\Framework\TestCase;

class ProjectAutoloaderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon_autoload_'.uniqid();
        mkdir($this->projectDir.'/src/Sub', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->projectDir);
    }

    public function test_registers_autoloader_that_loads_project_classes(): void
    {
        $token = strtoupper(substr(md5($this->projectDir), 0, 8));
        $class = "Acme\\Game{$token}\\Sub\\Widget";

        file_put_contents(
            $this->projectDir.'/src/Sub/Widget.php',
            "<?php namespace Acme\\Game{$token}\\Sub; class Widget { public function ping(): string { return 'pong'; } }",
        );

        $this->assertFalse(class_exists($class), 'precondition: class not yet loadable');

        $autoloader = new ProjectAutoloader;
        $autoloader->register($this->projectDir, ["Acme\\Game{$token}\\" => 'src']);

        $this->assertTrue(class_exists($class));
        $this->assertSame('pong', (new $class)->ping());
    }

    public function test_ignores_missing_base_directory(): void
    {
        $autoloader = new ProjectAutoloader;
        // Must not throw when the mapped directory does not exist.
        $autoloader->register($this->projectDir, ['Ghost\\' => 'does/not/exist']);
        $this->assertFalse(class_exists('Ghost\\Nope'));
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
