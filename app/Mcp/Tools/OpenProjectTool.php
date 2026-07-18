<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use PHPolygon\Editor\Command\EditorCommandBus;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAutoloader;
use PHPolygon\Editor\Project\ProjectLoader;
use PHPolygon\Editor\Project\RecentProjects;
use PHPolygon\Editor\Registry\ComponentRegistry;

#[Name('editor_open_project')]
#[Description('Open a PHPolygon game project so subsequent editor commands operate on it. Call this FIRST, before any scene/asset/ui command — otherwise commands fail with "No active scene document" or an empty context. Argument: dir (absolute path to the project directory that contains phpolygon.project.json).')]
class OpenProjectTool extends Tool
{
    public function handle(Request $request): Response
    {
        $dir = (string) $request->get('dir', '');

        if ($dir === '' || ! is_dir($dir)) {
            return Response::error("Directory not found: {$dir}");
        }

        /** @var ProjectLoader $loader */
        $loader = app(ProjectLoader::class);
        /** @var ComponentRegistry $registry */
        $registry = app(ComponentRegistry::class);
        /** @var RecentProjects $recent */
        $recent = app(RecentProjects::class);

        try {
            $manifest = $loader->load($dir);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        // Mirror EditorApiController::loadProject so the MCP process ends up in
        // the same state an HTTP "open project" would produce.
        session(['editor_project_dir' => $dir]);
        $recent->add($dir, $manifest->name);
        app(ProjectAutoloader::class)->register($dir, $manifest->psr4Roots);

        foreach ($manifest->psr4Roots as $namespace => $path) {
            $fullPath = $dir.DIRECTORY_SEPARATOR.$path;
            if (is_dir($fullPath)) {
                $registry->scan($fullPath, $namespace);
            }
        }

        $engineComponentDir = base_path('vendor/phpolygon/phpolygon/src/Component');
        if (is_dir($engineComponentDir)) {
            $registry->scan($engineComponentDir, 'PHPolygon\\Component\\');
        }

        // Rebuild the context/bus singletons against the newly opened project.
        app()->forgetInstance(EditorContext::class);
        app()->forgetInstance(EditorCommandBus::class);

        return Response::text(json_encode([
            'opened' => true,
            'projectDir' => $dir,
            'name' => $manifest->name,
            'componentCount' => count($registry->toArray()),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'dir' => $schema->string()
                ->description('Absolute path to the game project directory (contains phpolygon.project.json).')
                ->required(),
        ];
    }
}
