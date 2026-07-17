import { describe, expect, it } from 'vitest';
import { emptyShaderGraph, generateFragmentShader, VERTEX_SHADER, type ShaderGraph } from './shaderGraph';

describe('generateFragmentShader', () => {
    it('emits a valid shell for the empty graph', () => {
        const src = generateFragmentShader(emptyShaderGraph());
        expect(src).toContain('void main()');
        expect(src).toContain('varying vec2 vUv;');
        expect(src).toContain('uniform float uTime;');
        expect(src).toContain('gl_FragColor = vec4(vec3(0.8), 1.0);');
    });

    it('routes a color constant into the fragment output', () => {
        const graph: ShaderGraph = {
            nodes: [
                { id: 'fragment', type: 'fragment' },
                { id: 'col', type: 'color', params: { out: [1, 0, 0] } },
            ],
            connections: [{ from: { node: 'col', port: 'out' }, to: { node: 'fragment', port: 'color' } }],
        };
        const src = generateFragmentShader(graph);
        expect(src).toContain('vec3 n_col = vec3(1.0, 0.0, 0.0);');
        expect(src).toContain('gl_FragColor = vec4(n_col, 1.0);');
    });

    it('emits dependencies before dependents (time → sin → scale)', () => {
        const graph: ShaderGraph = {
            nodes: [
                { id: 'fragment', type: 'fragment' },
                { id: 't', type: 'time' },
                { id: 's', type: 'sin' },
                { id: 'c', type: 'color', params: { out: [0, 1, 0] } },
                { id: 'm', type: 'scale' },
            ],
            connections: [
                { from: { node: 't', port: 'out' }, to: { node: 's', port: 'x' } },
                { from: { node: 'c', port: 'out' }, to: { node: 'm', port: 'rgb' } },
                { from: { node: 's', port: 'out' }, to: { node: 'm', port: 'k' } },
                { from: { node: 'm', port: 'out' }, to: { node: 'fragment', port: 'color' } },
            ],
        };
        const src = generateFragmentShader(graph);
        const idxTime = src.indexOf('n_t = uTime');
        const idxSin = src.indexOf('n_s = sin(n_t)');
        expect(idxTime).toBeGreaterThanOrEqual(0);
        expect(idxSin).toBeGreaterThan(idxTime);
        expect(src).toContain('n_m = (n_c * n_s)');
        expect(src).toContain('gl_FragColor = vec4(n_m, 1.0);');
    });

    it('provides a vertex shader that passes uv through', () => {
        expect(VERTEX_SHADER).toContain('vUv = uv;');
        expect(VERTEX_SHADER).toContain('gl_Position');
    });
});
