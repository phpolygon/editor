<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Gltf;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use RuntimeException;

/**
 * Pure-PHP importer for a whole Blender-authored world exported as a
 * **glTF-binary (.glb)**. Reads the GLB directly (no three.js, no runtime
 * model loader) and reproduces the authored scene in world space, so the
 * editor can turn a Blender export into engine-native mesh + material assets.
 *
 * ### Why not the three.js path
 * The editor's existing model import bakes ONE prop and normalises it to a unit
 * cube, discarding node placement — wrong for a scene whose nodes carry real
 * world coordinates. This importer keeps every node at its baked transform.
 *
 * ### Mass scale
 * A Blender world is hundreds–thousands of nodes; one draw per node would swamp
 * the renderer. So static geometry is **batched by material**: every node's
 * mesh is transformed into world space and merged into one combined
 * {@see MeshData} per material, collapsing thousands of nodes into ~dozens of
 * meshes. Blender's tightly-packed, uncompressed export (no Draco/meshopt)
 * makes bulk `unpack()` of the accessor buffers cheap.
 *
 * Coordinates pass through 1:1 (glTF is Y-up, the engine is Y-up); no axis
 * remap is applied. This class is game-agnostic: it produces only geometry and
 * materials. Node-name gameplay conventions (terminals, NPCs, ground colliders)
 * are intentionally NOT interpreted here — that stays in the consuming game.
 */
final class GlbParser
{
    // ── loaded GLB state (per parse() call) ─────────────────────────
    private string $bin = '';
    /** @var array<string,mixed> */
    private array $gltf = [];
    /** @var array<int,array{data:string}> decoded buffers by index */
    private array $buffers = [];

    private function __construct() {}

    /**
     * Parse one GLB into batched, world-space geometry + materials.
     *
     * @param string $glbPath  Path to the `.glb` file.
     * @param string $idPrefix Namespaces the emitted batch mesh ids so several
     *                         GLBs can coexist without colliding. Materials
     *                         dedupe by name across GLBs (one Blender project =
     *                         consistent naming).
     */
    public static function parse(string $glbPath, string $idPrefix = 'glb'): GlbImportResult
    {
        return (new self())->run($glbPath, $idPrefix);
    }

    private function run(string $glbPath, string $idPrefix): GlbImportResult
    {
        $this->parseGlb($glbPath);

        /** @var array<string,Material> $materials */
        $materials = $this->translateMaterials();

        // Accumulators keyed by material id → merged world-space geometry.
        /** @var array<string,array{v:list<float>,n:list<float>,u:list<float>,i:list<int>}> */
        $batches = [];

        $scene = $this->gltf['scenes'][$this->gltf['scene'] ?? 0] ?? ['nodes' => []];
        $identity = self::matIdentity();
        foreach ($scene['nodes'] ?? [] as $rootIdx) {
            $this->walkNode((int) $rootIdx, $identity, $batches);
        }

        // Materialize each non-empty batch into a MeshData keyed by a prefixed
        // mesh id (one batch mesh per material), tracking which material each
        // mesh references and which materials are actually used.
        /** @var array<string,MeshData> $meshes */
        $meshes = [];
        /** @var array<string,Material> $usedMaterials */
        $usedMaterials = [];
        /** @var array<string,string> $meshMaterials */
        $meshMaterials = [];
        foreach ($batches as $matId => $acc) {
            if (count($acc['i']) === 0) {
                continue;
            }
            $meshId = $idPrefix . '_' . self::slug($matId);
            $meshes[$meshId] = new MeshData($acc['v'], $acc['n'], $acc['u'], $acc['i']);
            $meshMaterials[$meshId] = $matId;
            $usedMaterials[$matId] = $materials[$matId] ?? $this->translateMaterial([]);
        }

        return new GlbImportResult($meshes, $usedMaterials, $meshMaterials);
    }

    // =========================================================================
    // NODE WALK
    // =========================================================================

    /**
     * @param array<int,float> $parent row-major 4x4 (length-16)
     * @param array<string,array{v:list<float>,n:list<float>,u:list<float>,i:list<int>}> $batches
     */
    private function walkNode(int $nodeIdx, array $parent, array &$batches): void
    {
        $node = $this->gltf['nodes'][$nodeIdx] ?? null;
        if ($node === null) {
            return;
        }
        $world = self::matMul($parent, $this->nodeMatrix($node));

        if (isset($node['mesh'])) {
            $this->bakeMesh((int) $node['mesh'], $world, $batches);
        }

        foreach ($node['children'] ?? [] as $childIdx) {
            $this->walkNode((int) $childIdx, $world, $batches);
        }
    }

    /**
     * Transform a mesh's primitives into world space and append them to the
     * per-material batch.
     *
     * @param array<int,float> $world
     * @param array<string,array{v:list<float>,n:list<float>,u:list<float>,i:list<int>}> $batches
     */
    private function bakeMesh(int $meshIdx, array $world, array &$batches): void
    {
        $mesh = $this->gltf['meshes'][$meshIdx] ?? null;
        if ($mesh === null) {
            return;
        }
        $nm = self::normalMatrix($world);

        foreach ($mesh['primitives'] ?? [] as $prim) {
            $attr = $prim['attributes'] ?? [];
            if (!isset($attr['POSITION'])) {
                continue;
            }
            $pos = $this->readAccessor((int) $attr['POSITION']);
            $vcount = intdiv(count($pos), 3);
            if ($vcount === 0) {
                continue;
            }
            $nrm = isset($attr['NORMAL']) ? $this->readAccessor((int) $attr['NORMAL']) : [];
            $uv = isset($attr['TEXCOORD_0']) ? $this->readAccessor((int) $attr['TEXCOORD_0']) : [];
            $idx = isset($prim['indices'])
                ? $this->readAccessor((int) $prim['indices'])
                : range(0, $vcount - 1);

            $matId = $this->materialId($prim['material'] ?? null);

            if (!isset($batches[$matId])) {
                $batches[$matId] = ['v' => [], 'n' => [], 'u' => [], 'i' => []];
            }
            $this->appendPrimitive($batches[$matId], $pos, $nrm, $uv, $idx, $vcount, $world, $nm);
        }
    }

    /**
     * Append one primitive's world-transformed verts/normals/uvs/indices to an
     * accumulator, offsetting indices by the accumulator's current vertex count.
     *
     * @param array{v:list<float>,n:list<float>,u:list<float>,i:list<int>} $acc
     * @param list<float> $pos
     * @param list<float> $nrm
     * @param list<float> $uv
     * @param list<int>   $idx
     * @param array<int,float> $world
     * @param array<int,float> $nm
     */
    private function appendPrimitive(
        array &$acc,
        array $pos,
        array $nrm,
        array $uv,
        array $idx,
        int $vcount,
        array $world,
        array $nm,
    ): void {
        $base = intdiv(count($acc['v']), 3);

        $hasN = count($nrm) >= $vcount * 3;
        $hasU = count($uv) >= $vcount * 2;

        for ($i = 0; $i < $vcount; $i++) {
            $x = $pos[$i * 3];
            $y = $pos[$i * 3 + 1];
            $z = $pos[$i * 3 + 2];
            $acc['v'][] = $world[0] * $x + $world[1] * $y + $world[2] * $z + $world[3];
            $acc['v'][] = $world[4] * $x + $world[5] * $y + $world[6] * $z + $world[7];
            $acc['v'][] = $world[8] * $x + $world[9] * $y + $world[10] * $z + $world[11];

            if ($hasN) {
                $nx = $nrm[$i * 3];
                $ny = $nrm[$i * 3 + 1];
                $nz = $nrm[$i * 3 + 2];
                $tx = $nm[0] * $nx + $nm[1] * $ny + $nm[2] * $nz;
                $ty = $nm[3] * $nx + $nm[4] * $ny + $nm[5] * $nz;
                $tz = $nm[6] * $nx + $nm[7] * $ny + $nm[8] * $nz;
                $len = sqrt($tx * $tx + $ty * $ty + $tz * $tz);
                if ($len > 1e-8) {
                    $tx /= $len; $ty /= $len; $tz /= $len;
                }
                $acc['n'][] = $tx;
                $acc['n'][] = $ty;
                $acc['n'][] = $tz;
            } else {
                $acc['n'][] = 0.0;
                $acc['n'][] = 1.0;
                $acc['n'][] = 0.0;
            }

            if ($hasU) {
                $acc['u'][] = $uv[$i * 2];
                $acc['u'][] = $uv[$i * 2 + 1];
            } else {
                $acc['u'][] = 0.0;
                $acc['u'][] = 0.0;
            }
        }

        foreach ($idx as $vi) {
            $acc['i'][] = $base + (int) $vi;
        }
    }

    // =========================================================================
    // MATERIALS
    // =========================================================================

    /**
     * Translate every glTF material into an engine {@see Material}, keyed by the
     * same stable id {@see materialId()} assigns to primitives.
     *
     * @return array<string,Material>
     */
    private function translateMaterials(): array
    {
        $out = [];
        foreach ($this->gltf['materials'] ?? [] as $i => $mat) {
            $id = $this->materialId($i);
            if (!isset($out[$id])) {
                $out[$id] = $this->translateMaterial($mat);
            }
        }
        return $out;
    }

    /** Stable material id: `gltf_mat_<name>` (shared across GLBs), else index-based. */
    private function materialId(int|string|null $index): string
    {
        if ($index === null || !isset($this->gltf['materials'][$index])) {
            return 'gltf_default';
        }
        $mat = $this->gltf['materials'][$index];
        $name = (string) ($mat['name'] ?? '');
        return $name !== '' ? 'gltf_mat_' . self::slug($name) : 'gltf_mat_' . $index;
    }

    /** @param array<string,mixed> $mat */
    private function translateMaterial(array $mat): Material
    {
        $pbr = $mat['pbrMetallicRoughness'] ?? [];
        $base = $pbr['baseColorFactor'] ?? [0.8, 0.8, 0.8, 1.0];
        $albedo = new Color(
            (float) ($base[0] ?? 0.8),
            (float) ($base[1] ?? 0.8),
            (float) ($base[2] ?? 0.8),
            (float) ($base[3] ?? 1.0),
        );
        $metallic = (float) ($pbr['metallicFactor'] ?? 1.0);
        $roughness = (float) ($pbr['roughnessFactor'] ?? 1.0);

        $emiss = $mat['emissiveFactor'] ?? [0.0, 0.0, 0.0];
        $strength = (float) ($mat['extensions']['KHR_materials_emissive_strength']['emissiveStrength'] ?? 1.0);
        $emission = new Color(
            min(1.0, (float) ($emiss[0] ?? 0.0) * $strength),
            min(1.0, (float) ($emiss[1] ?? 0.0) * $strength),
            min(1.0, (float) ($emiss[2] ?? 0.0) * $strength),
            1.0,
        );

        $alpha = 1.0;
        if (($mat['alphaMode'] ?? 'OPAQUE') === 'BLEND') {
            $alpha = (float) ($base[3] ?? 1.0);
        }

        return new Material(
            albedo: $albedo,
            roughness: max(0.04, min(1.0, $roughness)),
            metallic: max(0.0, min(1.0, $metallic)),
            emission: $emission,
            alpha: $alpha,
        );
    }

    // =========================================================================
    // GLB PARSING
    // =========================================================================

    private function parseGlb(string $path): void
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("GLB not found: {$path}");
        }
        if (strlen($raw) < 12 || substr($raw, 0, 4) !== 'glTF') {
            throw new RuntimeException("Not a GLB file: {$path}");
        }
        $len = strlen($raw);
        $offset = 12; // magic(4) + version(4) + length(4)
        $json = null;
        $bin = '';
        while ($offset + 8 <= $len) {
            /** @var array{len:int,type:string} $h */
            $h = unpack('Vlen/Z4type', substr($raw, $offset, 8)) ?: [];
            $chunkLen = (int) ($h['len'] ?? 0);
            $chunkType = substr($raw, $offset + 4, 4);
            $data = substr($raw, $offset + 8, $chunkLen);
            if ($chunkType === 'JSON') {
                $json = $data;
            } elseif ($chunkType === "BIN\0" || rtrim($chunkType, "\0") === 'BIN') {
                $bin = $data;
            }
            $offset += 8 + $chunkLen;
        }
        if ($json === null) {
            throw new RuntimeException("GLB has no JSON chunk: {$path}");
        }
        /** @var array<string,mixed>|null $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid GLB JSON: {$path}");
        }
        $this->gltf = $decoded;
        $this->bin = $bin;

        // Resolve buffers: buffer 0 without a uri is the embedded BIN chunk;
        // data: URIs are base64-decoded; external .bin files are read alongside.
        $this->buffers = [];
        foreach ($this->gltf['buffers'] ?? [] as $bi => $buf) {
            $uri = $buf['uri'] ?? null;
            if ($uri === null) {
                $this->buffers[$bi] = ['data' => $this->bin];
            } elseif (str_starts_with((string) $uri, 'data:')) {
                $comma = strpos((string) $uri, ',');
                $this->buffers[$bi] = ['data' => base64_decode(substr((string) $uri, $comma + 1)) ?: ''];
            } else {
                $side = dirname($path) . '/' . rawurldecode((string) $uri);
                $this->buffers[$bi] = ['data' => (string) @file_get_contents($side)];
            }
        }
    }

    /**
     * Read an accessor into a flat numeric array (floats for vertex attributes,
     * ints for indices). Handles the componentTypes Blender emits; assumes
     * tightly-packed bufferViews, with a per-element fallback if a byteStride
     * is declared.
     *
     * @return list<float>|list<int>
     */
    private function readAccessor(int $index): array
    {
        $acc = $this->gltf['accessors'][$index] ?? null;
        if ($acc === null) {
            return [];
        }
        $count = (int) ($acc['count'] ?? 0);
        $compType = (int) ($acc['componentType'] ?? 5126);
        $type = (string) ($acc['type'] ?? 'SCALAR');
        $numComp = match ($type) {
            'SCALAR' => 1, 'VEC2' => 2, 'VEC3' => 3, 'VEC4' => 4,
            'MAT2' => 4, 'MAT3' => 9, 'MAT4' => 16, default => 1,
        };
        [$fmt, $compSize] = match ($compType) {
            5120 => ['c', 1], // byte
            5121 => ['C', 1], // ubyte
            5122 => ['v', 2], // short (read as ushort; rare for our data)
            5123 => ['v', 2], // ushort
            5125 => ['V', 4], // uint
            5126 => ['g', 4], // float (LE)
            default => ['g', 4],
        };

        $bvIdx = $acc['bufferView'] ?? null;
        if ($bvIdx === null) {
            return array_fill(0, $count * $numComp, $fmt === 'g' ? 0.0 : 0);
        }
        $bv = $this->gltf['bufferViews'][$bvIdx];
        $bufData = $this->buffers[(int) ($bv['buffer'] ?? 0)]['data'] ?? '';
        $baseOffset = (int) ($bv['byteOffset'] ?? 0) + (int) ($acc['byteOffset'] ?? 0);
        $stride = (int) ($bv['byteStride'] ?? 0);
        $elemSize = $compSize * $numComp;

        $out = [];
        if ($stride === 0 || $stride === $elemSize) {
            $slice = substr($bufData, $baseOffset, $count * $elemSize);
            /** @var array<int,int|float> $vals */
            $vals = unpack($fmt . '*', $slice) ?: [];
            $out = array_values($vals);
        } else {
            for ($e = 0; $e < $count; $e++) {
                $slice = substr($bufData, $baseOffset + $e * $stride, $elemSize);
                /** @var array<int,int|float> $vals */
                $vals = unpack($fmt . $numComp, $slice) ?: [];
                foreach ($vals as $v) {
                    $out[] = $v;
                }
            }
        }
        return $out;
    }

    // =========================================================================
    // MATH (row-major 4x4 as flat length-16 array)
    // =========================================================================

    /** @param array<string,mixed> $node @return array<int,float> */
    private function nodeMatrix(array $node): array
    {
        if (isset($node['matrix'])) {
            // glTF stores column-major; transpose to our row-major convention.
            $m = array_map('floatval', $node['matrix']);
            return [
                $m[0], $m[4], $m[8], $m[12],
                $m[1], $m[5], $m[9], $m[13],
                $m[2], $m[6], $m[10], $m[14],
                $m[3], $m[7], $m[11], $m[15],
            ];
        }
        $t = $node['translation'] ?? [0.0, 0.0, 0.0];
        $r = $node['rotation'] ?? [0.0, 0.0, 0.0, 1.0]; // x,y,z,w
        $s = $node['scale'] ?? [1.0, 1.0, 1.0];
        return self::composeTRS(
            (float) $t[0], (float) $t[1], (float) $t[2],
            (float) $r[0], (float) $r[1], (float) $r[2], (float) $r[3],
            (float) $s[0], (float) $s[1], (float) $s[2],
        );
    }

    /** @return array<int,float> */
    private static function composeTRS(
        float $tx, float $ty, float $tz,
        float $qx, float $qy, float $qz, float $qw,
        float $sx, float $sy, float $sz,
    ): array {
        $x2 = $qx + $qx; $y2 = $qy + $qy; $z2 = $qz + $qz;
        $xx = $qx * $x2; $xy = $qx * $y2; $xz = $qx * $z2;
        $yy = $qy * $y2; $yz = $qy * $z2; $zz = $qz * $z2;
        $wx = $qw * $x2; $wy = $qw * $y2; $wz = $qw * $z2;

        $r00 = 1.0 - ($yy + $zz); $r01 = $xy - $wz;         $r02 = $xz + $wy;
        $r10 = $xy + $wz;         $r11 = 1.0 - ($xx + $zz); $r12 = $yz - $wx;
        $r20 = $xz - $wy;         $r21 = $yz + $wx;         $r22 = 1.0 - ($xx + $yy);

        // M = T * R * S (row-major).
        return [
            $r00 * $sx, $r01 * $sy, $r02 * $sz, $tx,
            $r10 * $sx, $r11 * $sy, $r12 * $sz, $ty,
            $r20 * $sx, $r21 * $sy, $r22 * $sz, $tz,
            0.0, 0.0, 0.0, 1.0,
        ];
    }

    /** @return array<int,float> */
    private static function matIdentity(): array
    {
        return [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1];
    }

    /**
     * @param array<int,float> $a
     * @param array<int,float> $b
     * @return array<int,float>
     */
    private static function matMul(array $a, array $b): array
    {
        $o = [];
        for ($r = 0; $r < 4; $r++) {
            for ($c = 0; $c < 4; $c++) {
                $o[$r * 4 + $c] =
                    $a[$r * 4 + 0] * $b[0 * 4 + $c] +
                    $a[$r * 4 + 1] * $b[1 * 4 + $c] +
                    $a[$r * 4 + 2] * $b[2 * 4 + $c] +
                    $a[$r * 4 + 3] * $b[3 * 4 + $c];
            }
        }
        return $o;
    }

    /**
     * Inverse-transpose of the upper-left 3x3 (for normals). Returns a length-9
     * row-major 3x3. Falls back to the plain 3x3 if singular.
     *
     * @param array<int,float> $m
     * @return array<int,float>
     */
    private static function normalMatrix(array $m): array
    {
        $a = $m[0]; $b = $m[1]; $c = $m[2];
        $d = $m[4]; $e = $m[5]; $f = $m[6];
        $g = $m[8]; $h = $m[9]; $i = $m[10];
        $det = $a * ($e * $i - $f * $h) - $b * ($d * $i - $f * $g) + $c * ($d * $h - $e * $g);
        if (abs($det) < 1e-12) {
            return [$a, $b, $c, $d, $e, $f, $g, $h, $i];
        }
        $inv = 1.0 / $det;
        $i00 = ($e * $i - $f * $h) * $inv;
        $i01 = ($c * $h - $b * $i) * $inv;
        $i02 = ($b * $f - $c * $e) * $inv;
        $i10 = ($f * $g - $d * $i) * $inv;
        $i11 = ($a * $i - $c * $g) * $inv;
        $i12 = ($c * $d - $a * $f) * $inv;
        $i20 = ($d * $h - $e * $g) * $inv;
        $i21 = ($b * $g - $a * $h) * $inv;
        $i22 = ($a * $e - $b * $d) * $inv;
        return [$i00, $i10, $i20, $i01, $i11, $i21, $i02, $i12, $i22];
    }

    private static function slug(string $value): string
    {
        $s = preg_replace('/[^A-Za-z0-9_]+/', '_', $value) ?? '';
        return trim($s, '_');
    }
}
