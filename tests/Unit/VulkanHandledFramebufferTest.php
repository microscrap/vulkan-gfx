<?php

use Microscrap\GFX\Vulkan\VulkanHandledFramebuffer;
use ScrapyardIO\Tubes\Contracts\Framebuffers\DeferredFramebuffer;
use ScrapyardIO\Tubes\Framebuffers\DeferredFramebuffer as DeferredFramebufferAbstract;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\BitDepth;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\Endianness;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\FramebufferKind;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\PixelFormat;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\RenderType;
use ScrapyardIO\Tubes\Contracts\Framebuffers\FormatSpec;
use ScrapyardIO\Tubes\Contracts\Framebuffers\ManagedFramebuffer as ManagedFramebufferContract;
use ScrapyardIO\Tubes\Framebuffers\FramebufferManager;
use ScrapyardIO\Tubes\Framebuffers\ManagedFramebuffer;
use ScrapyardIO\Tubes\Framebuffers\PendingFramebuffer;

function vulkanRowMajor(): FormatSpec
{
    return new FormatSpec(
        PixelFormat::ROW_MAJOR,
        BitDepth::B32,
        endianness: Endianness::MSB,
    );
}

beforeEach(function (): void {
    if (! extension_loaded('vulkan')) {
        $this->markTestSkipped('ext-vulkan is not loaded');
    }
});

test('sized builds a deferred headless Vulkan-handled buffer', function () {
    $buffer = VulkanHandledFramebuffer::sized(8, 8, vulkanRowMajor());

    expect($buffer)->toBeInstanceOf(VulkanHandledFramebuffer::class)
        ->and($buffer)->toBeInstanceOf(DeferredFramebuffer::class)
        ->and($buffer)->toBeInstanceOf(DeferredFramebufferAbstract::class)
        ->and($buffer)->not->toBeInstanceOf(ManagedFramebuffer::class)
        ->and($buffer)->not->toBeInstanceOf(ManagedFramebufferContract::class)
        ->and($buffer->isHeadless())->toBeTrue()
        ->and($buffer->viewportWidth())->toBe(8)
        ->and($buffer->viewportHeight())->toBe(8)
        ->and($buffer->vkInstance())->not->toBeNull()
        ->and($buffer->vkInstance()->isValid())->toBeTrue();
});

test('extendDeferred vulkan creates via FramebufferManager driver', function () {
    $manager = new FramebufferManager;

    $manager->extendDeferred(
        'vulkan',
        fn (PendingFramebuffer $pending) => VulkanHandledFramebuffer::sized(
            $pending->widthValue(),
            $pending->heightValue(),
            $pending->hostFormatValue(),
        ),
    );

    $buffer = $manager->driver('vulkan')
        ->size(8, 8)
        ->format(vulkanRowMajor())
        ->create();

    expect($buffer)->toBeInstanceOf(VulkanHandledFramebuffer::class)
        ->and($buffer)->toBeInstanceOf(DeferredFramebuffer::class)
        ->and($manager->kindOf('vulkan'))->toBe(FramebufferKind::DEFERRED);
});

test('setPixel and flush round-trip on headless CPU shadow', function () {
    $buffer = VulkanHandledFramebuffer::sized(4, 2, vulkanRowMajor());
    $buffer->setPixel(1, 0, 0xFF0000FF);

    expect($buffer->getPixel(1, 0))->toBe(0xFF0000FF);

    $frames = $buffer->flush(vulkanRowMajor(), as_array: true);

    expect($frames)->toHaveCount(1)
        ->and($frames[0]->render_type)->toBe(RenderType::PARTIAL)
        ->and($frames[0]->origin_x)->toBe(1)
        ->and($frames[0]->origin_y)->toBe(0)
        ->and(strlen($frames[0]->raw_data))->toBe(4)
        ->and(bin2hex($frames[0]->raw_data))->toBe('ff0000ff');
});

test('markAllDirty flush emits FULL surface bytes', function () {
    $buffer = VulkanHandledFramebuffer::sized(4, 2, vulkanRowMajor());
    $buffer->fill(0x000000FF);
    $bytes = $buffer->flush(vulkanRowMajor());

    expect($bytes)->toBeString()
        ->and(strlen($bytes))->toBe(4 * 2 * 4);
});

test('flush to RGB565 packs RGBA words correctly and emits PARTIAL for local dirty', function () {
    $buffer = VulkanHandledFramebuffer::sized(8, 8, vulkanRowMajor());
    $buffer->fill(0x000000FF);
    $buffer->flush(vulkanRowMajor(), as_array: true);

    $rgb565 = new FormatSpec(
        PixelFormat::ROW_MAJOR,
        BitDepth::B16,
        endianness: Endianness::MSB,
    );

    $buffer->setSegment(2, 3, 3, 2, 0xFF0000FF);
    $frames = $buffer->flush($rgb565, as_array: true);

    expect($frames)->toHaveCount(1)
        ->and($frames[0]->render_type)->toBe(RenderType::PARTIAL)
        ->and($frames[0]->origin_x)->toBe(2)
        ->and($frames[0]->origin_y)->toBe(3)
        ->and($frames[0]->width)->toBe(3)
        ->and($frames[0]->height)->toBe(2)
        ->and(bin2hex($frames[0]->raw_data))->toBe(str_repeat('f800', 6));
});

test('headless damageGranularity is pixel-perfect for PanelIC partial', function () {
    $buffer = VulkanHandledFramebuffer::sized(16, 16, vulkanRowMajor());

    expect($buffer->damageGranularity()->coversWholeSurface())->toBeFalse()
        ->and($buffer->damageGranularity()->isPixelPerfect())->toBeTrue();
});

test('packRgba8Shadow emits big-endian RGBA for presentRgba8', function () {
    $buffer = VulkanHandledFramebuffer::sized(2, 1, vulkanRowMajor());
    $buffer->fill(0x141820FF)->setPixel(1, 0, 0xF0A030FF);

    $method = new ReflectionMethod(VulkanHandledFramebuffer::class, 'packRgba8Shadow');
    $bytes = $method->invoke($buffer);

    expect($bytes)->toBe(
        pack('N*', 0x141820FF, 0xF0A030FF)
    );
});

test('fill clears the headless shadow canvas', function () {
    $buffer = VulkanHandledFramebuffer::sized(2, 2, vulkanRowMajor());
    $buffer->fill(0x00FF00FF);

    expect($buffer->getPixel(0, 0))->toBe(0x00FF00FF)
        ->and($buffer->getPixel(1, 1))->toBe(0x00FF00FF);
});

test('presentOpsFromShadow keeps dominant fill over nested outline AABB', function () {
    $buffer = VulkanHandledFramebuffer::sized(32, 32, vulkanRowMajor());
    $bg = 0x141820FF;
    $accent = 0xF0A030FF;
    $dot = 0xFFFFFFFF;

    $buffer->fill($bg);
    // Filled disk (many setSegment-style rows) + sparse outline pixels.
    for ($y = 8; $y <= 23; $y++) {
        $half = (int) floor(sqrt(max(0, 64 - (($y - 15.5) ** 2))));
        $buffer->setSegment(16 - $half, $y, max(1, $half * 2), 1, $accent);
    }
    foreach ([[16, 8], [16, 23], [8, 16], [23, 16], [11, 11], [21, 11], [11, 21], [21, 21]] as [$x, $y]) {
        $buffer->setPixel($x, $y, $dot);
    }

    $method = new ReflectionMethod(VulkanHandledFramebuffer::class, 'presentOpsFromShadow');
    $method->setAccessible(true);
    /** @var list<array{x: int, y: int, w: int, h: int, color: int}> $ops */
    $ops = $method->invoke($buffer);

    expect($ops)->not->toBeEmpty()
        ->and($ops[0]['color'])->toBe($accent)
        ->and(array_column($ops, 'color'))->not->toContain($dot);
});

test('attachedTo requires a valid swapchain from VulkanWindowHandler', function () {
    if (! extension_loaded('glfw')) {
        $this->markTestSkipped('ext-glfw is required for window attach');
    }

    // Prefer the WindowHandler path — attachedTo alone cannot invent a swapchain.
    $handler = new \Microscrap\GFX\Vulkan\VulkanWindowHandler('attach-test', 32, 24);
    $handler->open();

    $fb = $handler->framebuffer();
    expect($fb)->toBeInstanceOf(VulkanHandledFramebuffer::class)
        ->and($fb->isHeadless())->toBeFalse()
        ->and($fb->nativeWindow())->not->toBeNull()
        ->and($fb->vkSwapchain())->not->toBeNull();

    $handler->close();
});
