<?php

use Microscrap\GFX\Vulkan\VulkanHandledFramebuffer;
use Microscrap\GFX\Vulkan\VulkanWindowHandler;
use ScrapyardIO\Tubes\Canvas\OSWindow;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\BitDepth;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\PixelFormat;
use ScrapyardIO\Tubes\Windows\WindowHandler;
use ScrapyardIO\Tubes\Windows\WindowManager;

test('VulkanWindowHandler defines rgba FormatSpec at construct', function () {
    $handler = new VulkanWindowHandler('demo', 320, 240);

    expect($handler)->toBeInstanceOf(WindowHandler::class)
        ->and($handler->title())->toBe('demo')
        ->and($handler->width())->toBe(320)
        ->and($handler->height())->toBe(240)
        ->and($handler->isOpen())->toBeFalse()
        ->and($handler->formatSpec()->bit_depth)->toBe(BitDepth::B32)
        ->and($handler->formatSpec()->pixel_format)->toBe(PixelFormat::ROW_MAJOR)
        ->and($handler->formatSpec()->bit_depth)->toBe(VulkanHandledFramebuffer::rgbaSpec()->bit_depth);
});

test('WindowManager extend vulkan returns a VulkanWindowHandler via create', function () {
    $manager = new WindowManager;
    $manager->extend('vulkan', VulkanWindowHandler::class);

    $window = $manager->driver('vulkan')
        ->title('vk')
        ->size(640, 480)
        ->create();

    expect($window->title())->toBe('vk')
        ->and($window->width())->toBe(640)
        ->and($window->height())->toBe(480)
        ->and($window->formatSpec()->bit_depth)->toBe(BitDepth::B32);
});

test('VulkanWindowHandler open present poll close updates a visible window path', function () {
    if (! extension_loaded('vulkan') || ! extension_loaded('glfw')) {
        $this->markTestSkipped('ext-vulkan and ext-glfw are required');
    }

    $handler = new VulkanWindowHandler('vulkan-test', 64, 48);
    $handler->open();

    expect($handler->isOpen())->toBeTrue()
        ->and($handler->glfwWindow())->not->toBeNull()
        ->and($handler->vkSwapchain())->not->toBeNull();

    $fb = $handler->framebuffer();
    expect($fb)->toBeInstanceOf(VulkanHandledFramebuffer::class)
        ->and($fb->isHeadless())->toBeFalse()
        ->and($fb->nativeWindow())->toBe($handler->glfwWindow());

    $fb->fill(0xFF203040)->setPixel(10, 10, 0xFFFFFFFF);
    expect($fb->getPixel(10, 10))->toBe(0xFFFFFFFF);

    $handler->present()->pollEvents();

    expect($handler->shouldClose())->toBeFalse();

    $handler->close();
    expect($handler->isOpen())->toBeFalse()
        ->and($handler->glfwWindow())->toBeNull()
        ->and($handler->vkSwapchain())->toBeNull();
});

test('OSWindow wraps VulkanWindowHandler and open works', function () {
    if (! extension_loaded('vulkan') || ! extension_loaded('glfw')) {
        $this->markTestSkipped('ext-vulkan and ext-glfw are required');
    }

    $window = new OSWindow(new VulkanWindowHandler('canvas', 80, 60));
    $window->open();

    expect($window->title())->toBe('canvas')
        ->and($window->width())->toBe(80)
        ->and($window->height())->toBe(60)
        ->and($window->formatSpec())->toEqual(VulkanHandledFramebuffer::rgbaSpec())
        ->and($window->framebuffer())->toBeInstanceOf(VulkanHandledFramebuffer::class)
        ->and($window->framebuffer()->isHeadless())->toBeFalse();

    $window->framebuffer()->fill(0x112233FF)->setSegment(4, 4, 8, 8, 0xF0A030FF);
    $window->present()->pollEvents();
    $window->close();
});

test('WindowManager extend vulkan creates and opens OSWindow', function () {
    if (! extension_loaded('vulkan') || ! extension_loaded('glfw')) {
        $this->markTestSkipped('ext-vulkan and ext-glfw are required');
    }

    $manager = new WindowManager;
    $manager->extend('vulkan', VulkanWindowHandler::class);

    $window = $manager->driver('vulkan')
        ->title('mgr')
        ->size(96, 72)
        ->open();

    expect($window)->toBeInstanceOf(OSWindow::class)
        ->and($window->isOpen())->toBeTrue()
        ->and($window->framebuffer()->isHeadless())->toBeFalse();

    $window->close();
});
