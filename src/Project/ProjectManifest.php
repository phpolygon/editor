<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Project;

class ProjectManifest
{
    /**
     * @param  array<string, string>  $psr4Roots  Namespace => relative path
     * @param  '2d'|'3d'  $defaultMode  Editor viewport mode a scene opens in when
     *                                  the user has no saved per-scene preference.
     * @param  string  $liveWorldScene  Name of the scene whose entities come from
     *                                  the running game's World rather than a
     *                                  declarative build() (e.g. "GameScene").
     * @param  string  $liveWorldCommand  Shell command the editor runs (cwd =
     *                                    project dir) to regenerate that scene's
     *                                    `*.scene.json` snapshot headlessly; the
     *                                    output path is appended as one argument.
     * @param  string  $prefabsCommand  Shell command the editor runs (cwd = project
     *                                  dir) to list the game's editor-placeable
     *                                  code prefabs as JSON ({prefabs:[{name,class,
     *                                  variants?}]}) on stdout. Empty = the game
     *                                  exposes no code prefabs.
     * @param  string  $expandCommand  Shell command the editor runs (cwd = project
     *                                 dir; input + output scene paths appended) to
     *                                 expand a code-prefab scene into geometry for
     *                                 preview: it prints a bundle {entities, meshes,
     *                                 materials}. Empty = no geometry preview.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $engineVersion,
        public readonly string $scenesPath,
        public readonly string $assetsPath,
        public readonly array $psr4Roots,
        public readonly string $entryScene,
        public readonly string $defaultMode = '3d',
        public readonly string $uiPath = 'ui',
        public readonly string $panelLayoutsPath = 'assets/ui',
        public readonly string $liveWorldScene = '',
        public readonly string $liveWorldCommand = '',
        public readonly string $prefabsCommand = '',
        public readonly string $expandCommand = '',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            '_format' => 1,
            'name' => $this->name,
            'version' => $this->version,
            'engineVersion' => $this->engineVersion,
            'scenesPath' => $this->scenesPath,
            'assetsPath' => $this->assetsPath,
            'psr4Roots' => $this->psr4Roots,
            'entryScene' => $this->entryScene,
            'defaultMode' => $this->defaultMode,
            'uiPath' => $this->uiPath,
            'panelLayoutsPath' => $this->panelLayoutsPath,
            'liveWorldScene' => $this->liveWorldScene,
            'liveWorldCommand' => $this->liveWorldCommand,
            'prefabsCommand' => $this->prefabsCommand,
            'expandCommand' => $this->expandCommand,
        ];
    }
}
