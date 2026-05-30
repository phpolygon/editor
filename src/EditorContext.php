<?php

declare(strict_types=1);

namespace PHPolygon\Editor;

use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

class EditorContext
{
    public ?SceneDocument $activeDocument = null;

    /**
     * Optional persistence hook. Web-mode requests rebuild the editor
     * context per request, so {@see SceneDocument} state would otherwise
     * be lost between commands. The HTTP boot wires this to the session;
     * NativePHP (which keeps one long-lived process) can leave it null.
     *
     * @var (callable(?array<string, mixed>): void)|null
     */
    public $persistDocumentArray = null;

    /**
     * Inverse of $persistDocumentArray — restores the scene array when
     * activeDocument is requested but unset.
     *
     * @var (callable(): ?array<string, mixed>)|null
     */
    public $loadDocumentArray = null;

    public function __construct(
        public readonly ProjectManifest $manifest,
        public readonly ComponentRegistry $components,
        public readonly SystemRegistry $systems,
        public readonly SceneTranspiler $transpiler,
        public readonly string $projectDir,
    ) {}

    public function getScenesDir(): string
    {
        return $this->projectDir . DIRECTORY_SEPARATOR . $this->manifest->scenesPath;
    }

    public function getAssetsDir(): string
    {
        return $this->projectDir . DIRECTORY_SEPARATOR . $this->manifest->assetsPath;
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
                $this->activeDocument = new SceneDocument($data);
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
        $persist($this->activeDocument?->toArray());
    }

    public function setActiveDocument(?SceneDocument $document): void
    {
        $this->activeDocument = $document;
        $this->persistActiveDocument();
    }
}
