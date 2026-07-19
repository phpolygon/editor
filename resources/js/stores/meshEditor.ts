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
    listMeshAssets,
    loadMeshAsset,
    deleteMeshAsset,
    renameMeshAsset,
    listMeshes,
    getMesh,
} from '@/bridge/commands';
import { computeNormals, flipNormals, type RawMeshData } from '@/mesh/editMesh';
import { importMeshFile } from '@/mesh/importMesh';
import type { MeshData, MeshListEntry } from '@/types';

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

    // Vertex-editing: `editedMesh` is a mutable baked copy of the current mesh;
    // while in edit mode the graph is inert and this raw geometry is the source.
    const editMode = ref(false);
    const editedMesh = ref<RawMeshData | null>(null);

    function starterGraph(): ProceduralGraphData {
        return addNode(emptyGraph(), createNode('box', 'box'));
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
        editMode.value = false;
        editedMesh.value = null;
        name.value = 'mesh';
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
        const data: MeshData = await getMesh(id);
        editedMesh.value = {
            vertices: [...data.vertices],
            normals: [...(data.normals ?? [])],
            uvs: [...(data.uvs ?? [])],
            indices: [...data.indices],
        };
        name.value = id;
        loadedAssetName.value = null; // not one of our saved assets — Save makes a copy
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
        await refreshAssets();
        return saved;
    }

    /** Load a saved mesh asset back into the editor (graph or baked raw). */
    async function load(assetName: string) {
        const data = await loadMeshAsset(assetName);
        name.value = data.name;
        loadedAssetName.value = data.name;
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

    /** Import an external 3D file (OBJ/STL/glTF) as an editable raw mesh. */
    async function importFile(file: File) {
        const raw = await importMeshFile(file);
        editedMesh.value = raw;
        name.value = file.name.replace(/\.[^.]+$/, '') || 'imported';
        loadedAssetName.value = null; // a fresh import isn't a saved asset yet
        editMode.value = true;
    }

    /** Save the current mesh — raw geometry in edit mode, otherwise the graph. */
    async function saveCurrent() {
        const meshName = name.value.trim() || 'mesh';
        if (editMode.value && editedMesh.value) {
            const saved = await saveRawMesh(meshName, editedMesh.value);
            name.value = saved.name;
            loadedAssetName.value = saved.name;
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
        enterEditMode,
        exitEditMode,
        updateEditedVertices,
        flipEditedNormals,
        importFile,
    };
});
