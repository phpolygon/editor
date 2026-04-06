import { onMounted, onUnmounted } from 'vue';

type ShortcutMap = Record<string, () => void>;

function buildKey(e: KeyboardEvent): string {
    const parts: string[] = [];
    if (e.ctrlKey || e.metaKey) parts.push('ctrl');
    if (e.shiftKey) parts.push('shift');
    if (e.altKey) parts.push('alt');

    const key = e.key.toLowerCase();
    if (!['control', 'meta', 'shift', 'alt'].includes(key)) {
        parts.push(key);
    }

    return parts.join('+');
}

export function useKeyboardShortcuts(shortcuts: ShortcutMap) {
    function onKeydown(e: KeyboardEvent) {
        // Don't trigger shortcuts when typing in inputs
        const tag = (e.target as HTMLElement).tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

        const key = buildKey(e);
        const handler = shortcuts[key];
        if (handler) {
            e.preventDefault();
            handler();
        }
    }

    onMounted(() => window.addEventListener('keydown', onKeydown));
    onUnmounted(() => window.removeEventListener('keydown', onKeydown));
}
