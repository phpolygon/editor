import { defineStore } from 'pinia';
import { ref } from 'vue';
import {
    emptyGraph,
    addNode,
    createNode,
    uniqueNodeId,
    validate,
    type GraphNode,
    type ProceduralGraphData,
} from '@/prefab/graph';
import {
    evaluateProceduralMesh,
    saveMesh,
    saveRawMesh,
    saveMaterial,
    saveTexture,
    listMeshAssets,
    loadMeshAsset,
    deleteMeshAsset,
    renameMeshAsset,
    listMeshes,
    getMesh,
} from '@/bridge/commands';
import { computeNormals, flipNormals, type RawMeshData } from '@/mesh/editMesh';
import { importMeshParts } from '@/mesh/importMesh';
import type { EntityMeshLink, EntityMeshTarget } from '@/scene/entityAssets';
import { setMesh } from '@/three/meshCache';
import { useSceneStore } from '@/stores/scene';
import type { MeshData, MeshListEntry } from '@/types';

/** Cache version for meshes applied back to an entity; only has to rise. */
let appliedVersion = 0;

/**
 * State for the mesh editor workspace: a standalone procedural-mesh node graph
 * (independent of any entity), its evaluated preview, and validation. The graph
 * uses the same format + evaluator as the ProceduralMesh component, so meshes
 * built here can later be saved as assets or attached to entities.
 */
export const useMeshEditorStore = defineStore('meshEditor', () => {
    const graph = ref<ProceduralGraphData>(starterGraph());
    const name = ref('mesh');
    const preview = ref<MeshData | null>(null);
    const error = ref<string | null>(null);
    const evaluating = ref(false);
    const assets = ref<{ name: string; path: string }[]>([]);

    // Meshes the project/engine already knows (MeshRegistry + per-project asset
    // cache), as opposed to the editor's own saved .mesh.json graphs. These are
    // read-only sources you can pull into the editor as baked geometry.
    const projectMeshes = ref<MeshListEntry[]>([]);

    // The saved asset currently open in the editor (null = new / unsaved). Lets
    // the UI show what's being edited and lets "Save" overwrite it in place.
    const loadedAssetName = ref<string | null>(null);

    // What that asset holds on disk — a procedural graph or baked geometry.
    // Applying to an entity bakes, so this tells us when writing under the same
    // name would silently throw a saved graph away.
    const loadedAssetKind = ref<'graph' | 'raw' | null>(null);

    // Vertex-editing: `editedMesh` is a mutable baked copy of the current mesh;
    // while in edit mode the graph is inert and this raw geometry is the source.
    const editMode = ref(false);
    const editedMesh = ref<RawMeshData | null>(null);

    // Set when the editor was opened from an entity's inspector: it remembers
    // which component the mesh came from so `applyToEntity()` can write the
    // result back instead of leaving the edit stranded in a standalone asset.
    const linkedEntity = ref<EntityMeshLink | null>(null);

    function starterGraph(): ProceduralGraphData {
        return addNode(emptyGraph(), createNode('box', 'box'));
    }

    /** Detach graph data from whatever owns it (scene state, another store). */
    function cloneNodes(nodes: GraphNode[]): GraphNode[] {
        return JSON.parse(JSON.stringify(nodes)) as GraphNode[];
    }

    function clearEntityLink() {
        linkedEntity.value = null;
    }

    function setGraph(next: ProceduralGraphData) {
        graph.value = next;
    }

    function addNodeOfType(type: string) {
        const id = uniqueNodeId(graph.value, type);
        graph.value = addNode(graph.value, createNode(type, id));
    }

    function reset() {
        graph.value = starterGraph();
        preview.value = null;
        error.value = null;
        loadedAssetName.value = null;
        loadedAssetKind.value = null;
        editMode.value = false;
        editedMesh.value = null;
        name.value = 'mesh';
        linkedEntity.value = null;
    }

    /** Evaluate the current graph into MeshData via the backend. */
    async function evaluate() {
        const result = validate(graph.value);
        if (!result.ok) {
            error.value = result.errors[0] ?? 'Invalid graph';
            return;
        }
        evaluating.value = true;
        error.value = null;
        try {
            preview.value = await evaluateProceduralMesh(graph.value.nodes, graph.value.output, '');
        } catch (e: any) {
            error.value = e?.message ?? 'Evaluation failed';
        } finally {
            evaluating.value = false;
        }
    }

    async function refreshAssets() {
        try {
            assets.value = (await listMeshAssets()).meshes;
        } catch {
            assets.value = [];
        }
    }

    /** Registered project/engine meshes (from list_meshes), sorted by id. */
    async function refreshProjectMeshes() {
        try {
            projectMeshes.value = (await listMeshes()).meshes;
        } catch {
            projectMeshes.value = [];
        }
    }

    /**
     * Pull a registered project mesh into the editor as baked, editable
     * geometry. It has no generating graph, so it opens straight into
     * vertex-edit mode; saving writes a new .mesh.json asset (the source mesh
     * is read-only and untouched).
     */
    async function loadProjectMesh(id: string) {
        linkedEntity.value = null;
        const data: MeshData = await getMesh(id);
        editedMesh.value = {
            vertices: [...data.vertices],
            normals: [...(data.normals ?? [])],
            uvs: [...(data.uvs ?? [])],
            indices: [...data.indices],
        };
        name.value = id;
        loadedAssetName.value = null; // not one of our saved assets — Save makes a copy
        loadedAssetKind.value = null;
        editMode.value = true;
    }

    /** Save the current graph as a reusable mesh asset. */
    async function save() {
        const result = validate(graph.value);
        if (!result.ok) {
            error.value = result.errors[0] ?? 'Invalid graph';
            throw new Error(error.value);
        }
        const saved = await saveMesh(name.value.trim() || 'mesh', graph.value.nodes, graph.value.output);
        name.value = saved.name;
        loadedAssetName.value = saved.name;
        loadedAssetKind.value = 'graph';
        await refreshAssets();
        return saved;
    }

    /** Load a saved mesh asset back into the editor (graph or baked raw). */
    async function load(assetName: string) {
        linkedEntity.value = null;
        const data = await loadMeshAsset(assetName);
        name.value = data.name;
        loadedAssetName.value = data.name;
        loadedAssetKind.value = data.raw ? 'raw' : 'graph';
        if (data.raw) {
            editedMesh.value = {
                vertices: [...data.raw.vertices],
                normals: [...data.raw.normals],
                uvs: [...data.raw.uvs],
                indices: [...data.raw.indices],
            };
            editMode.value = true;
        } else {
            editMode.value = false;
            editedMesh.value = null;
            graph.value = { nodes: data.nodes as GraphNode[], output: data.output };
            await evaluate();
        }
    }

    /**
     * Open the mesh of a scene entity in this workspace, and remember where it
     * came from so `applyToEntity()` can write the result back.
     *
     * A ProceduralMesh carries its graph on the component, so it opens as a
     * graph. A MeshRenderer only references geometry by id: that id is either
     * one of the editor's own saved assets (which round-trips as a graph or as
     * baked geometry) or a mesh the project/engine registered, which comes in
     * as read-only baked geometry. A renderer with no mesh yet starts from the
     * default box, so applying gives the entity its first mesh.
     */
    async function openForEntity(target: EntityMeshTarget) {
        error.value = null;
        linkedEntity.value = null;

        if (target.kind === 'graph') {
            editMode.value = false;
            editedMesh.value = null;
            loadedAssetName.value = null;
            loadedAssetKind.value = null;
            name.value = target.meshId || target.entity;
            graph.value =
                target.nodes.length > 0
                    ? { nodes: cloneNodes(target.nodes), output: target.output }
                    : starterGraph();
            await evaluate();
        } else if (target.meshId !== '') {
            await refreshAssets();
            if (assets.value.some((a) => a.name === target.meshId)) {
                await load(target.meshId);
            } else {
                await loadProjectMesh(target.meshId);
            }
        } else {
            reset();
            name.value = target.entity;
            await evaluate();
        }

        linkedEntity.value = {
            entity: target.entity,
            componentClass: target.componentClass,
            kind: target.kind,
        };
    }

    /** `base`, or `base_2`, `base_3`… — the first name no asset is using. */
    function freeAssetName(base: string): string {
        if (!assets.value.some((a) => a.name === base)) return base;
        let i = 2;
        while (assets.value.some((a) => a.name === `${base}_${i}`)) i++;
        return `${base}_${i}`;
    }

    /** The current mesh as plain geometry: the edited copy, else the preview. */
    function bakedMesh(): RawMeshData | null {
        if (editMode.value && editedMesh.value) {
            const m = editedMesh.value;
            return { vertices: [...m.vertices], normals: [...m.normals], uvs: [...m.uvs], indices: [...m.indices] };
        }
        const p = preview.value;
        if (!p) return null;
        return {
            vertices: [...p.vertices],
            normals: [...(p.normals ?? [])],
            uvs: [...(p.uvs ?? [])],
            indices: [...p.indices],
        };
    }

    /**
     * Write the mesh currently open in the editor back onto the entity it was
     * opened from.
     *
     * A ProceduralMesh gets the graph itself, so the entity keeps generating
     * its geometry. A MeshRenderer gets baked geometry saved as a mesh asset,
     * referenced by id.
     */
    async function applyToEntity(): Promise<{ kind: 'graph' | 'asset'; meshId?: string }> {
        const link = linkedEntity.value;
        if (!link) throw new Error('No entity is linked to the mesh editor');

        const scene = useSceneStore();

        if (link.kind === 'graph') {
            if (editMode.value) {
                throw new Error(
                    'Vertex edits cannot be written back to a ProceduralMesh — save the mesh and point a MeshRenderer at it instead.',
                );
            }
            const result = validate(graph.value);
            if (!result.ok) {
                error.value = result.errors[0] ?? 'Invalid graph';
                throw new Error(error.value);
            }
            await scene.updateProperty(link.entity, link.componentClass, 'nodes', cloneNodes(graph.value.nodes));
            await scene.updateProperty(link.entity, link.componentClass, 'output', graph.value.output);
            return { kind: 'graph' };
        }

        const baked = bakedMesh();
        if (!baked) throw new Error('Nothing to apply — the mesh has not been evaluated yet');

        // Applying writes baked geometry, so writing under the name of an open
        // procedural asset would throw its node graph away. Keep the graph and
        // put the bake next to it.
        const base = name.value.trim() || link.entity;
        const target =
            loadedAssetKind.value === 'graph' && loadedAssetName.value === base
                ? freeAssetName(`${base}_baked`)
                : base;

        const saved = await saveRawMesh(target, baked);
        name.value = saved.name;
        loadedAssetName.value = saved.name;
        loadedAssetKind.value = 'raw';
        await refreshAssets();

        // The viewport caches geometry by mesh id, and the id usually does not
        // change — without seeding the cache it would keep drawing the shape
        // from before the edit.
        setMesh(saved.name, {
            id: saved.name,
            version: ++appliedVersion,
            ...baked,
            vertexCount: baked.vertices.length / 3,
            triangleCount: baked.indices.length / 3,
        });

        await scene.updateProperty(link.entity, link.componentClass, 'meshId', saved.name);
        // Re-pull the hierarchy so the viewport re-syncs even when the id it
        // already had is the one we just rewrote.
        await scene.refreshHierarchy();

        return { kind: 'asset', meshId: saved.name };
    }

    /** Bake the current evaluated mesh into an editable raw copy. */
    function enterEditMode() {
        const p = preview.value;
        if (!p) return;
        editedMesh.value = {
            vertices: [...p.vertices],
            normals: [...(p.normals ?? [])],
            uvs: [...(p.uvs ?? [])],
            indices: [...p.indices],
        };
        editMode.value = true;
    }

    function exitEditMode() {
        editMode.value = false;
        editedMesh.value = null;
    }

    /** Replace the edited vertex positions and recompute normals. */
    function updateEditedVertices(vertices: number[]) {
        if (!editedMesh.value) return;
        editedMesh.value = {
            ...editedMesh.value,
            vertices,
            normals: computeNormals(vertices, editedMesh.value.indices),
        };
    }

    function flipEditedNormals() {
        if (editedMesh.value) editedMesh.value = flipNormals(editedMesh.value);
    }

    /**
     * Import an external 3D file. OBJ/STL come in as a single editable raw mesh;
     * glTF/GLB are split per material, and each sub-mesh, its translated material
     * and any base-colour texture are saved straight to the project's assets. The
     * first sub-mesh is opened for viewing. Returns how much was imported so the
     * caller can report it. */
    async function importFile(file: File): Promise<{ meshes: number; materials: number }> {
        linkedEntity.value = null;
        const parts = await importMeshParts(file);

        const savedMeshes: { name: string; mesh: RawMeshData }[] = [];
        let materialCount = 0;

        for (const part of parts) {
            if (part.material) {
                if (part.texture) {
                    const tex = await saveTexture(part.texture.suggestedName, part.texture.dataUrl);
                    part.material.albedoTexture = tex.relativePath;
                }
                part.material.id = part.name;
                await saveMaterial(part.material);
                materialCount++;
            }
            const saved = await saveRawMesh(part.name, part.mesh);
            savedMeshes.push({ name: saved.name, mesh: part.mesh });
        }

        await refreshAssets();

        // Open the first sub-mesh for viewing; it (and the rest) are already saved.
        const first = savedMeshes[0];
        editedMesh.value = first.mesh;
        name.value = first.name;
        loadedAssetName.value = first.name;
        loadedAssetKind.value = 'raw';
        editMode.value = true;

        return { meshes: savedMeshes.length, materials: materialCount };
    }

    /** Save the current mesh — raw geometry in edit mode, otherwise the graph. */
    async function saveCurrent() {
        const meshName = name.value.trim() || 'mesh';
        if (editMode.value && editedMesh.value) {
            const saved = await saveRawMesh(meshName, editedMesh.value);
            name.value = saved.name;
            loadedAssetName.value = saved.name;
            loadedAssetKind.value = 'raw';
            await refreshAssets();
            return saved;
        }
        return save();
    }

    /** Save under a new name (Save As), leaving the original asset untouched. */
    async function saveAs(newName: string) {
        name.value = newName.trim() || 'mesh';
        return saveCurrent();
    }

    /** Delete a saved asset; if it's the one open, mark the editor as unsaved. */
    async function deleteAsset(assetName: string) {
        await deleteMeshAsset(assetName);
        if (loadedAssetName.value === assetName) loadedAssetName.value = null;
        await refreshAssets();
    }

    /** Rename a saved asset, keeping it open if it was the one being edited. */
    async function renameAsset(assetName: string, newName: string) {
        const result = await renameMeshAsset(assetName, newName);
        if (loadedAssetName.value === assetName) {
            loadedAssetName.value = result.name;
            name.value = result.name;
        }
        await refreshAssets();
        return result;
    }

    return {
        graph,
        name,
        preview,
        error,
        evaluating,
        assets,
        projectMeshes,
        loadedAssetName,
        editMode,
        editedMesh,
        linkedEntity,
        setGraph,
        addNodeOfType,
        reset,
        evaluate,
        refreshAssets,
        refreshProjectMeshes,
        loadProjectMesh,
        save,
        saveCurrent,
        saveAs,
        deleteAsset,
        renameAsset,
        load,
        openForEntity,
        applyToEntity,
        clearEntityLink,
        enterEditMode,
        exitEditMode,
        updateEditedVertices,
        flipEditedNormals,
        importFile,
    };
});
