<?php

declare(strict_types=1);

namespace PHPolygon\Editor;

use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\Support\Path;
use PHPolygon\Editor\UI\WidgetDocument;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPolygon\UI\PanelLayout;

class EditorContext
{
    public ?SceneDocument $activeDocument = null;

    public ?WidgetDocument $activeWidgetDocument = null;

    public ?PanelLayout $activePanelLayout = null;

    /** @var (callable(?array<string, mixed>): void)|null */
    public $persistPanelLayoutArray = null;

    /** @var (callable(): ?array<string, mixed>)|null */
    public $loadPanelLayoutArray = null;

    /**
     * Session persistence hooks for the active UI layout, mirroring the scene
     * document hooks. Web-mode requests rebuild the context per request, so
     * the edited widget tree would otherwise be lost between commands.
     *
     * @var (callable(?array<string, mixed>): void)|null
     */
    public $persistWidgetArray = null;

    /** @var (callable(): ?array<string, mixed>)|null */
    public $loadWidgetArray = null;

    /**
     * Optional persistence hook. Every request rebuilds the editor context, so
     * {@see SceneDocument} state would otherwise be lost between commands. The
     * HTTP boot wires this to the session.
     *
     * Receives the document's FULL state ({@see SceneDocument::toState()}),
     * history included — persisting only the scene array is what used to make
     * undo a no-op: the next request rebuilt the document with empty stacks.
     *
     * @var (callable(?array<string, mixed>): void)|null
     */
    public $persistDocumentArray = null;

    /**
     * Inverse of $persistDocumentArray — restores the document when
     * activeDocument is requested but unset.
     *
     * @var (callable(): ?array<string, mixed>)|null
     */
    public $loadDocumentArray = null;

    /** Absolute project root, normalized to the OS directory separator. */
    public readonly string $projectDir;

    public function __construct(
        public readonly ProjectManifest $manifest,
        public readonly ComponentRegistry $components,
        public readonly SystemRegistry $systems,
        public readonly SceneTranspiler $transpiler,
        string $projectDir,
    ) {
        $this->projectDir = Path::normalize($projectDir);
    }

    public function getScenesDir(): string
    {
        return Path::join($this->projectDir, $this->manifest->scenesPath);
    }

    public function getAssetsDir(): string
    {
        return Path::join($this->projectDir, $this->manifest->assetsPath);
    }

    public function getUiDir(): string
    {
        return Path::join($this->projectDir, $this->manifest->uiPath);
    }

    /**
     * Lazy-restore the active UI layout from the persistence hook. Ids in the
     * restored tree are preserved so the frontend's widget references stay
     * valid across requests.
     */
    public function getActiveWidgetDocument(): ?WidgetDocument
    {
        if ($this->activeWidgetDocument === null && $this->loadWidgetArray !== null) {
            $loader = $this->loadWidgetArray;
            $data = $loader();
            if (is_array($data) && is_array($data['root'] ?? null)) {
                $name = is_string($data['name'] ?? null) ? $data['name'] : 'untitled';
                $this->activeWidgetDocument = new WidgetDocument($name, $data['root']);
            }
        }

        return $this->activeWidgetDocument;
    }

    public function persistActiveWidgetDocument(): void
    {
        if ($this->persistWidgetArray === null) {
            return;
        }
        $persist = $this->persistWidgetArray;
        $persist($this->activeWidgetDocument?->toArray());
    }

    public function setActiveWidgetDocument(?WidgetDocument $document): void
    {
        $this->activeWidgetDocument = $document;
        $this->persistActiveWidgetDocument();
    }

    /** Directory of data-driven panel layouts (`*.layout.json`), per manifest. */
    public function getPanelLayoutsDir(): string
    {
        return Path::join($this->projectDir, $this->manifest->panelLayoutsPath);
    }

    public function getActivePanelLayout(): ?PanelLayout
    {
        if ($this->activePanelLayout === null && $this->loadPanelLayoutArray !== null) {
            $loader = $this->loadPanelLayoutArray;
            $data = $loader();
            if (is_array($data)) {
                $this->activePanelLayout = PanelLayout::fromArray($data);
            }
        }

        return $this->activePanelLayout;
    }

    public function persistActivePanelLayout(): void
    {
        if ($this->persistPanelLayoutArray === null) {
            return;
        }
        $persist = $this->persistPanelLayoutArray;
        $persist($this->activePanelLayout?->toArray());
    }

    public function setActivePanelLayout(?PanelLayout $layout): void
    {
        $this->activePanelLayout = $layout;
        $this->persistActivePanelLayout();
    }

    /**
     * Lazy-load the active document from the persistence hook if one is
     * wired and no in-memory document exists yet. Idempotent.
     */
    public function getActiveDocument(): ?SceneDocument
    {
        if ($this->activeDocument === null && $this->loadDocumentArray !== null) {
            $loader = $this->loadDocumentArray;
            $data = $loader();
            if (is_array($data)) {
                // fromState() also accepts a bare scene array, so a session
                // written by an older build still loads (without history).
                $this->activeDocument = SceneDocument::fromState($data);
            }
        }

        return $this->activeDocument;
    }

    /**
     * Flush the active document to the persistence hook (called by
     * mutating commands so a subsequent web request sees the same state).
     */
    public function persistActiveDocument(): void
    {
        if ($this->persistDocumentArray === null) {
            return;
        }
        $persist = $this->persistDocumentArray;
        $persist($this->activeDocument?->toState());
    }

    public function setActiveDocument(?SceneDocument $document): void
    {
        $this->activeDocument = $document;
        $this->persistActiveDocument();
    }
}
