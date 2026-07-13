// Guarantees a working `localStorage` global in the test environment.
//
// Node 24+ ships an experimental built-in `localStorage` global that is
// unavailable unless the runtime is started with `--localstorage-file`. That
// undefined built-in shadows happy-dom's `window.localStorage`, so bare
// `localStorage.…` calls in stores/tests throw "Cannot read properties of
// undefined". We install a dependable Storage implementation on globalThis
// (preferring happy-dom's if it actually works).

class MemoryStorage implements Storage {
    private store = new Map<string, string>();

    get length(): number {
        return this.store.size;
    }

    clear(): void {
        this.store.clear();
    }

    getItem(key: string): string | null {
        return this.store.has(key) ? (this.store.get(key) as string) : null;
    }

    setItem(key: string, value: string): void {
        this.store.set(key, String(value));
    }

    removeItem(key: string): void {
        this.store.delete(key);
    }

    key(index: number): string | null {
        return Array.from(this.store.keys())[index] ?? null;
    }
}

function isUsable(candidate: unknown): candidate is Storage {
    try {
        const s = candidate as Storage | undefined;
        if (!s || typeof s.setItem !== 'function') return false;
        s.setItem('__probe__', '1');
        s.removeItem('__probe__');
        return true;
    } catch {
        return false;
    }
}

const win = (globalThis as { window?: Window }).window;
const usable: Storage = isUsable(win?.localStorage) ? (win!.localStorage as Storage) : new MemoryStorage();

Object.defineProperty(globalThis, 'localStorage', {
    value: usable,
    writable: true,
    configurable: true,
});
