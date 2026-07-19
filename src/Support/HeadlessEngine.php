<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Support;

use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Support\Facades\Facade;
use Throwable;

/**
 * Boot a headless PHPolygon engine once per process so in-process game code
 * (a scene's build(), a prefab's build()) can resolve engine facades — Locale
 * for prompts/labels, Textures for baked textures, etc.
 *
 * Without this, loading a PHP scene whose build() touches a facade fails with
 * "Facade engine not set. Call Facade::setEngine($engine)…" — which is what a
 * project with a facade-using entry scene (e.g. a main menu that calls
 * Locale::t) hits the moment the editor opens it and auto-loads that scene.
 *
 * Headless boot is cheap and side-effect-free: NullTextureManager,
 * NullRenderer3D, no window, no audio backend, no vio extension required (see
 * Engine::__construct). It mirrors what the game's out-of-process expandCommand
 * ({@see scripts/expand-scene.php}) already does per invocation; here it makes
 * the same facades available on the editor's in-process scene-load path.
 */
final class HeadlessEngine
{
    private static bool $booted = false;

    /** Ensure a facade engine is available; boots a headless one if none is set. */
    public static function ensure(): void
    {
        if (self::$booted) {
            return;
        }

        // A whole-world build() (e.g. a full city scene) allocates far more than
        // the editor process's default 128M memory_limit — the game runs it with
        // the CLI's unbounded limit. Lift ours so in-process scene loading does
        // not fatal with "Allowed memory size exhausted" mid-build. Only ever
        // raises: a project that already set a higher (or unlimited) limit keeps
        // it.
        self::liftMemoryLimit(2048 * 1024 * 1024);

        // Reuse an engine a prior command in this (long-lived MCP) process set.
        try {
            Facade::getEngine();
            self::$booted = true;

            return;
        } catch (Throwable) {
            // none set yet — boot one below
        }

        Facade::setEngine(new Engine(new EngineConfig(headless: true, skipSplash: true)));
        self::$booted = true;
    }

    /** Raise memory_limit to at least $bytes; leaves a higher/unlimited limit untouched. */
    private static function liftMemoryLimit(int $bytes): void
    {
        $current = self::parseBytes((string) ini_get('memory_limit'));
        if ($current === -1) {
            return; // already unlimited
        }
        if ($current < $bytes) {
            ini_set('memory_limit', (string) $bytes);
        }
    }

    /** Parse a PHP shorthand byte value (e.g. "128M", "1G", "-1") into bytes; -1 = unlimited. */
    private static function parseBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
