import { describe, expect, it } from 'vitest';
import { encodeWav, arrayBufferToBase64 } from './wav';

function fourcc(view: DataView, offset: number): string {
    return String.fromCharCode(
        view.getUint8(offset),
        view.getUint8(offset + 1),
        view.getUint8(offset + 2),
        view.getUint8(offset + 3),
    );
}

describe('encodeWav', () => {
    it('writes a valid PCM16 header with the right sizes', () => {
        const samples = new Float32Array([0, 0.5, -0.5, 1, -1]);
        const buf = encodeWav(samples, 44100);
        const view = new DataView(buf);

        expect(buf.byteLength).toBe(44 + samples.length * 2);
        expect(fourcc(view, 0)).toBe('RIFF');
        expect(fourcc(view, 8)).toBe('WAVE');
        expect(fourcc(view, 12)).toBe('fmt ');
        expect(view.getUint16(20, true)).toBe(1); // PCM
        expect(view.getUint16(22, true)).toBe(1); // mono
        expect(view.getUint32(24, true)).toBe(44100);
        expect(view.getUint16(34, true)).toBe(16); // bits per sample
        expect(fourcc(view, 36)).toBe('data');
        expect(view.getUint32(40, true)).toBe(samples.length * 2);
    });

    it('clamps and quantizes samples to int16', () => {
        const view = new DataView(encodeWav(new Float32Array([1, -1]), 8000));
        expect(view.getInt16(44, true)).toBe(0x7fff);
        expect(view.getInt16(46, true)).toBe(-0x8000);
    });

    it('base64-encodes the buffer', () => {
        const b64 = arrayBufferToBase64(encodeWav(new Float32Array([0, 0.1, -0.2]), 8000));
        expect(typeof b64).toBe('string');
        expect(b64.length).toBeGreaterThan(0);
    });
});
