import { promises as fs } from 'fs';
import { mkdtempSync } from 'fs';
import * as path from 'path';
import * as os from 'os';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const FIXTURE_DIR = path.resolve(__dirname, '../fixtures/sample-project');

export async function setupTempProject(): Promise<string> {
    const tmpDir = mkdtempSync(path.join(os.tmpdir(), 'phpolygon-e2e-'));
    await copyDir(FIXTURE_DIR, tmpDir);
    return tmpDir;
}

export async function teardownTempProject(dir: string): Promise<void> {
    if (!dir.startsWith(os.tmpdir())) return; // safety
    await fs.rm(dir, { recursive: true, force: true });
}

async function copyDir(src: string, dest: string): Promise<void> {
    await fs.mkdir(dest, { recursive: true });
    const entries = await fs.readdir(src, { withFileTypes: true });
    for (const entry of entries) {
        const s = path.join(src, entry.name);
        const d = path.join(dest, entry.name);
        if (entry.isDirectory()) {
            await copyDir(s, d);
        } else {
            await fs.copyFile(s, d);
        }
    }
}
