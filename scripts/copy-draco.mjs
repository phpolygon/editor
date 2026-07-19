// Copies the glTF DRACO decoder (WASM wrapper + module) out of the pinned
// three.js package into public/draco/, so DRACO-compressed glTF/GLB imports
// decode fully offline (no gstatic CDN dependency). Runs on postinstall and
// prebuild; safe to re-run. See resources/js/mesh/importMesh.ts (setDecoderPath).
import { copyFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const src = join(root, 'node_modules', 'three', 'examples', 'jsm', 'libs', 'draco', 'gltf');
const dest = join(root, 'public', 'draco');

const files = ['draco_decoder.wasm', 'draco_wasm_wrapper.js'];

if (!existsSync(join(src, files[0]))) {
    // three not installed yet (e.g. running before deps) — nothing to do.
    process.exit(0);
}

mkdirSync(dest, { recursive: true });
for (const file of files) {
    copyFileSync(join(src, file), join(dest, file));
}
console.log(`[copy-draco] copied ${files.length} decoder file(s) to public/draco/`);
