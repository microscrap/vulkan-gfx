<?php

namespace Microscrap\GFX\Vulkan;

/**
 * macOS MoltenVK loader env for GLFW WSI.
 *
 * Herd / bare `./runner` often start without DYLD_LIBRARY_PATH. SIP ignores
 * putenv('DYLD_LIBRARY_PATH') after process start, so CLI re-execs once with
 * Homebrew's lib on the path (from the service provider boot, before sketches).
 */
final class DarwinVulkanLoaderEnv
{
    /**
     * Ensure MoltenVK is loadable for glfwCreateWindowSurface.
     *
     * @return never|void
     */
    public static function ensureForCliWindowBoot(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return;
        }

        self::ensureIcdFilenames();

        if (PHP_SAPI !== 'cli') {
            return;
        }

        if (getenv('MICROSCRAP_VULKAN_DYLD_READY') === '1') {
            return;
        }

        $lib = self::homebrewLibDir();
        if (is_null($lib)) {
            return;
        }

        $dyld = (string) getenv('DYLD_LIBRARY_PATH');
        if (str_contains($dyld, $lib)) {
            putenv('MICROSCRAP_VULKAN_DYLD_READY=1');
            $_ENV['MICROSCRAP_VULKAN_DYLD_READY'] = '1';

            return;
        }

        if (! extension_loaded('pcntl') || ! function_exists('pcntl_exec')) {
            return;
        }

        /** @var array<int, string> $argv */
        $argv = array_values(array_map('strval', $_SERVER['argv'] ?? []));
        if ($argv === []) {
            return;
        }

        $env = self::currentEnv();
        $env['DYLD_LIBRARY_PATH'] = $dyld === '' ? $lib : $lib.':'.$dyld;
        $env['MICROSCRAP_VULKAN_DYLD_READY'] = '1';

        if (! isset($env['VK_ICD_FILENAMES']) || $env['VK_ICD_FILENAMES'] === '') {
            $icd = self::defaultIcdPath();
            if (! is_null($icd)) {
                $env['VK_ICD_FILENAMES'] = $icd;
            }
        }

        pcntl_exec(PHP_BINARY, $argv, $env);
    }

    public static function ensureIcdFilenames(): void
    {
        $existing = getenv('VK_ICD_FILENAMES');
        if (is_string($existing) && $existing !== '') {
            return;
        }

        $icd = self::defaultIcdPath();
        if (is_null($icd)) {
            return;
        }

        putenv('VK_ICD_FILENAMES='.$icd);
        $_ENV['VK_ICD_FILENAMES'] = $icd;
    }

    public static function homebrewLibDir(): ?string
    {
        foreach (['/opt/homebrew/lib', '/usr/local/lib'] as $dir) {
            if (is_dir($dir) && is_file($dir.'/libMoltenVK.dylib')) {
                return $dir;
            }
        }

        return null;
    }

    public static function defaultIcdPath(): ?string
    {
        foreach ([
            '/opt/homebrew/etc/vulkan/icd.d/MoltenVK_icd.json',
            '/usr/local/etc/vulkan/icd.d/MoltenVK_icd.json',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected static function currentEnv(): array
    {
        $env = getenv();
        if (! is_array($env)) {
            $env = [];
        }

        $out = [];
        foreach ($env as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }

        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_string($value) && ! isset($out[$key])) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
