/**
 * Minimal Web Audio playback for previewing synthesized samples. A single
 * shared AudioContext is created lazily on first use (from a user gesture, so
 * autoplay policy is satisfied) and resumed if the browser suspended it.
 */
let ctx: AudioContext | null = null;
let current: AudioBufferSourceNode | null = null;

function context(): AudioContext {
    if (!ctx) {
        const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
        ctx = new Ctor();
    }
    return ctx;
}

/** Play mono float samples; resolves when playback finishes or is superseded. */
export function playSamples(samples: Float32Array, sampleRate = 44100): Promise<void> {
    const c = context();
    if (c.state === 'suspended') void c.resume();

    stop();

    const buffer = c.createBuffer(1, samples.length, sampleRate);
    buffer.copyToChannel(samples as Float32Array<ArrayBuffer>, 0);

    const source = c.createBufferSource();
    source.buffer = buffer;
    source.connect(c.destination);
    current = source;

    return new Promise<void>((resolve) => {
        source.onended = () => {
            if (current === source) current = null;
            resolve();
        };
        source.start();
    });
}

/** Stop any in-flight preview. */
export function stop(): void {
    if (current) {
        try {
            current.stop();
        } catch {
            // already stopped
        }
        current = null;
    }
}
