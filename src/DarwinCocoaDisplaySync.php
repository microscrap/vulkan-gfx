<?php

namespace Microscrap\GFX\Vulkan;

use FFI;
use Microscrap\Bindings\GLFW\DataObjects\GlfwWindow;
use Microscrap\GFX\Vulkan\Enums\DlopenFlag;

/**
 * MoltenVK MAILBOX/FIFO still vsyncs on macOS. Flip CAMetalLayer.displaySyncEnabled
 * on the GLFW NSView when the app asks for vsync off.
 *
 * Apply from setVsync only (cached). Linux is a no-op. Do not poke the layer every present.
 */
final class DarwinCocoaDisplaySync
{
    private static ?FFI $glfw = null;

    private static bool $glfwHasView = false;

    private static ?FFI $objc = null;

    private static ?FFI $msgId = null;

    private static ?FFI $msgBool = null;

    private static ?FFI $msgIsKind = null;

    private static bool $unavailable = false;

    private static ?int $lastWindowPtr = null;

    private static ?bool $lastVsync = null;

    public static function apply(?GlfwWindow $window, bool $vsync): void
    {
        if (self::$unavailable || PHP_OS_FAMILY !== 'Darwin' || is_null($window) || $window->ptr <= 0 || ! extension_loaded('ffi')) {
            return;
        }

        if (self::$lastWindowPtr === $window->ptr && self::$lastVsync === $vsync) {
            return;
        }

        try {
            $glfw = self::glfw();
            $objc = self::objc();
            $view = null;

            if (self::$glfwHasView) {
                $view = $glfw->glfwGetCocoaView($window->ptr);
            }

            if (is_null($view)) {
                $nsWindow = $glfw->glfwGetCocoaWindow($window->ptr);

                if (is_null($nsWindow)) {
                    return;
                }

                $view = self::msgId()->objc_msgSend($nsWindow, $objc->sel_registerName('contentView'));
            }

            if (is_null($view)) {
                return;
            }

            $layer = self::msgId()->objc_msgSend($view, $objc->sel_registerName('layer'));

            if (is_null($layer)) {
                return;
            }

            $metalClass = $objc->objc_getClass('CAMetalLayer');

            if (is_null($metalClass)) {
                $sys = FFI::cdef('void *dlopen(const char *path, int mode);', 'libSystem.B.dylib');
                $sys->dlopen('/System/Library/Frameworks/QuartzCore.framework/QuartzCore', DlopenFlag::LAZY->value);
                $metalClass = $objc->objc_getClass('CAMetalLayer');
            }

            if (is_null($metalClass)) {
                return;
            }

            if (self::msgIsKind()->objc_msgSend($layer, $objc->sel_registerName('isKindOfClass:'), $metalClass) === 0) {
                return;
            }

            self::msgBool()->objc_msgSend(
                $layer,
                $objc->sel_registerName('setDisplaySyncEnabled:'),
                $vsync ? 1 : 0,
            );
            self::$lastWindowPtr = $window->ptr;
            self::$lastVsync = $vsync;
        } catch (\Throwable) {
            self::$unavailable = true;
        }
    }

    private static function glfw(): FFI
    {
        if (! is_null(self::$glfw)) {
            return self::$glfw;
        }

        $both = <<<'C'
void *glfwGetCocoaView(uint64_t window);
void *glfwGetCocoaWindow(uint64_t window);
C;
        $windowOnly = 'void *glfwGetCocoaWindow(uint64_t window);';
        $libs = [
            '/opt/homebrew/lib/libglfw.3.dylib',
            '/usr/local/lib/libglfw.3.dylib',
            'libglfw.3.dylib',
        ];

        foreach ($libs as $lib) {
            try {
                self::$glfw = FFI::cdef($both, $lib);
                self::$glfwHasView = true;

                return self::$glfw;
            } catch (\Throwable) {
                //
            }

            try {
                self::$glfw = FFI::cdef($windowOnly, $lib);
                self::$glfwHasView = false;

                return self::$glfw;
            } catch (\Throwable) {
                //
            }
        }

        throw new \RuntimeException('glfwGetCocoaWindow is not loadable.');
    }

    private static function objc(): FFI
    {
        return self::$objc ??= FFI::cdef(
            <<<'C'
void *objc_getClass(const char *name);
void *sel_registerName(const char *name);
C,
            '/usr/lib/libobjc.A.dylib',
        );
    }

    private static function msgId(): FFI
    {
        return self::$msgId ??= FFI::cdef(
            'void *objc_msgSend(void *self, void *op);',
            '/usr/lib/libobjc.A.dylib',
        );
    }

    private static function msgBool(): FFI
    {
        return self::$msgBool ??= FFI::cdef(
            'void objc_msgSend(void *self, void *op, uint8_t value);',
            '/usr/lib/libobjc.A.dylib',
        );
    }

    private static function msgIsKind(): FFI
    {
        return self::$msgIsKind ??= FFI::cdef(
            'uint8_t objc_msgSend(void *self, void *op, void *cls);',
            '/usr/lib/libobjc.A.dylib',
        );
    }
}
