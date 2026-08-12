<?php

namespace Microscrap\GFX\Vulkan;

use Microscrap\Bindings\GLFW\DataObjects\GlfwWindow;
use Microscrap\Bindings\GLFW\Window;
use Microscrap\Bindings\Vulkan\DataObjects\VkInstance;
use Microscrap\Bindings\Vulkan\DataObjects\VkSwapchain;
use Microscrap\Bindings\Vulkan\Enums\VkResult;
use Microscrap\Bindings\Vulkan\Vk;
use ScrapyardIO\Tubes\Contracts\Framebuffers\DamageGranularity;
use ScrapyardIO\Tubes\Contracts\Framebuffers\DumpedBuffer;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\BitDepth;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\Endianness;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\PixelFormat;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\RenderType;
use ScrapyardIO\Tubes\Contracts\Framebuffers\FormatSpec;
use ScrapyardIO\Tubes\Framebuffers\DeferredFramebuffer;
use ScrapyardIO\Tubes\Framebuffers\PixelStore;

/**
 * Deferred Vulkan-handled framebuffer.
 *
 * Headless {@see sized()}: owns a VkInstance + CPU shadow (ext-vulkan 0.7 has no
 * offscreen image/readback yet). Windowed {@see attachedTo()}: borrows GLFW window
 * + swapchain; {@see present()} prefers {@see Vk::presentRgba8()} (full CPU shadow)
 * and falls back to {@see Vk::presentFrame()} (clear + ≤3 rects) on older builds.
 *
 * Headless PanelIC: dirty rects → {@see RenderType::PARTIAL} dumps; host RGBA
 * words pack to IC FormatSpec (fast ROW_MAJOR B16, not per-pixel PixelStore).
 */
class VulkanHandledFramebuffer extends DeferredFramebuffer
{
    protected ?VkInstance $instance = null;

    protected bool $owns_instance = true;

    protected ?GlfwWindow $native_window = null;

    protected ?VkSwapchain $swapchain = null;

    /** @var array<int, int> logical pixel colors (0xRRGGBBAA) */
    protected array $shadow = [];

    /** Packed RGBA8 bytes kept in lockstep with {@see $shadow} for presentRgba8. */
    protected string $packedRgba8 = '';

    /** Last fill color used as presentFrame clear (0xRRGGBBAA). */
    protected int $clear_color = 0;

    /**
     * Inclusive dirty rectangles [left, top, right, bottom] — coalesced at flush.
     *
     * @var array<int, array{0: int, 1: int, 2: int, 3: int}>
     */
    protected array $dirty_regions = [];

    protected int $dirty_defer_depth = 0;

    /**
     * @var array{0: int, 1: int, 2: int, 3: int}|null
     */
    protected ?array $deferred_dirty_union = null;

    /**
     * Legacy FIFO hint from setSegment/setPixel (present prefers shadow coalesce).
     *
     * @var list<array{x: int, y: int, w: int, h: int, color: int}>
     */
    protected array $present_ops = [];

    /**
     * @throws VulkanGfxException
     */
    public function __construct(
        int $width,
        int $height,
        FormatSpec $format_spec,
        ?VkInstance $instance = null,
        ?GlfwWindow $attach_to = null,
        ?VkSwapchain $swapchain = null,
        bool $owns_instance = true,
    ) {
        parent::__construct($width, $height, $format_spec);

        if ($width <= 0 || $height <= 0) {
            throw new VulkanGfxException("VulkanHandledFramebuffer size must be positive, got {$width}x{$height}.");
        }

        $this->shadow = array_fill(0, $width * $height, 0);
        $this->packedRgba8 = str_repeat("\0\0\0\0", $width * $height);
        $this->native_window = $attach_to;
        $this->swapchain = $swapchain;
        $this->owns_instance = $owns_instance;

        if (! is_null($attach_to)) {
            if (is_null($swapchain) || ! $swapchain->isValid()) {
                throw new VulkanGfxException(
                    'VulkanHandledFramebuffer::attachedTo() requires a valid VkSwapchain.'
                );
            }

            // Window path borrows instance from the handler (destroyed after FB is dropped).
            $this->instance = $instance;

            return;
        }

        if (! is_null($instance)) {
            $this->instance = $instance;

            return;
        }

        if (! extension_loaded('vulkan')) {
            throw VulkanGfxException::instanceCreationFailed('ext-vulkan is not loaded');
        }

        $created = Vk::createInstance([], 'microscrap/vulkan-gfx');
        if (! $created->isValid()) {
            throw VulkanGfxException::instanceCreationFailed(Vk::lastError());
        }

        $this->instance = $created;
        $this->owns_instance = true;
    }

    public function __destruct()
    {
        $this->swapchain = null;
        $this->native_window = null;

        if ($this->owns_instance && ! is_null($this->instance) && $this->instance->isValid()) {
            Vk::destroyInstance($this->instance);
        }

        $this->instance = null;
    }

    /**
     * Headless factory: VkInstance + CPU shadow canvas (no window).
     *
     * @throws VulkanGfxException
     */
    public static function sized(int $width, int $height, FormatSpec $host_format): static
    {
        return new static($width, $height, $host_format);
    }

    /**
     * Window-bound factory: borrows GLFW window + swapchain owned by {@see VulkanWindowHandler}.
     *
     * @throws VulkanGfxException
     */
    public static function attachedTo(
        GlfwWindow $window,
        FormatSpec $format_spec,
        int $width,
        int $height,
        VkSwapchain $swapchain,
    ): static {
        return new static(
            $width,
            $height,
            $format_spec,
            instance: null,
            attach_to: $window,
            swapchain: $swapchain,
            owns_instance: false,
        );
    }

    public static function rgbaSpec(): FormatSpec
    {
        return new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32, endianness: Endianness::MSB);
    }

    public function vkInstance(): ?VkInstance
    {
        return $this->instance;
    }

    public function nativeWindow(): ?GlfwWindow
    {
        return $this->native_window;
    }

    public function vkSwapchain(): ?VkSwapchain
    {
        return $this->swapchain;
    }

    public function isHeadless(): bool
    {
        return is_null($this->native_window);
    }

    /**
     * Engine clear of the CPU shadow (and present clear color when windowed).
     */
    public function fill(int $color): static
    {
        $n = $this->width * $this->height;
        if ($this->writesCpuShadow()) {
            $this->shadow = array_fill(0, $n, $color);
        }
        $this->packedRgba8 = str_repeat(pack('N', $color), $n);
        $this->clear_color = $color;
        $this->present_ops = [];
        $this->markAllDirty();

        return $this;
    }

    /**
     * Headless: no-op. Windowed: presentRgba8 when available, else ≤3-rect presentFrame.
     *
     * @throws VulkanGfxException
     */
    public function present(): static
    {
        if ($this->isHeadless()) {
            return $this;
        }

        if (is_null($this->swapchain) || ! $this->swapchain->isValid()) {
            throw new VulkanGfxException('VulkanHandledFramebuffer::present() has no valid swapchain.');
        }

        if (is_null($this->native_window)) {
            throw new VulkanGfxException('VulkanHandledFramebuffer::present() has no native window.');
        }

        [$scaleX, $scaleY] = $this->contentScale();
        [$cr, $cg, $cb, $ca] = $this->rgbaFloats($this->clear_color);

        // Prefer full-shadow present (glyphs + circles). Falls back to ≤3 AABB rects
        // on older ext-vulkan builds without presentRgba8.
        if (method_exists(\Vulkan\Vk\Vk::class, 'presentRgba8')) {
            $pixels = $this->packRgba8Shadow();
            $rc = Vk::presentRgba8(
                $this->swapchain,
                $pixels,
                $this->width,
                $this->height,
                $scaleX,
                $scaleY,
                $cr,
                $cg,
                $cb,
                $ca,
            );
        } else {
            $ops = $this->presentOpsFromShadow();
            $menu = $this->scaledOp($ops[0] ?? null, $scaleX, $scaleY);
            $inner = $this->scaledOp($ops[1] ?? null, $scaleX, $scaleY);
            $accent = $this->scaledOp($ops[2] ?? null, $scaleX, $scaleY);

            $rc = Vk::presentFrame(
                $this->swapchain,
                $cr,
                $cg,
                $cb,
                $ca,
                $menu['draw'],
                $menu['x'],
                $menu['y'],
                $menu['w'],
                $menu['h'],
                $menu['r'],
                $menu['g'],
                $menu['b'],
                $menu['a'],
                $inner['draw'],
                $inner['x'],
                $inner['y'],
                $inner['w'],
                $inner['h'],
                $inner['r'],
                $inner['g'],
                $inner['b'],
                $inner['a'],
                $accent['draw'],
                $accent['x'],
                $accent['y'],
                $accent['w'],
                $accent['h'],
                $accent['r'],
                $accent['g'],
                $accent['b'],
                $accent['a'],
            );
        }

        if ($rc === VkResult::ERROR_OUT_OF_DATE_KHR->value || $rc === VkResult::SUBOPTIMAL_KHR->value) {
            $fb = Window::getFramebufferSize($this->native_window);
            Vk::resizeSwapchain(
                $this->swapchain,
                max(1, (int) ($fb['width'] ?? $this->width)),
                max(1, (int) ($fb['height'] ?? $this->height)),
            );

            return $this;
        }

        if ($rc !== VkResult::SUCCESS->value) {
            throw new VulkanGfxException(
                'Vk::presentFrame failed: result='.$rc.' '.Vk::lastError()
            );
        }

        return $this;
    }

    public function getPixel(int $x, int $y): int
    {
        if (($x < 0) || ($y < 0) || ($x >= $this->width) || ($y >= $this->height)) {
            return 0;
        }

        return $this->shadow[($y * $this->width) + $x] ?? 0;
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        if (($x < 0) || ($y < 0) || ($x >= $this->width) || ($y >= $this->height)) {
            return $this;
        }

        $this->writeShadowPixel($x, $y, $value);
        $this->writePackedPixel($x, $y, $value);
        $this->queuePresentOp($x, $y, 1, 1, $value);
        $this->markDirty($x, $y, $x, $y);

        return $this;
    }

    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        if (($width <= 0) || ($height <= 0)) {
            return $this;
        }

        $x1 = min($this->width, $x + $width);
        $y1 = min($this->height, $y + $height);
        $x0 = max(0, $x);
        $y0 = max(0, $y);
        $cw = $x1 - $x0;
        $ch = $y1 - $y0;

        if ($cw <= 0 || $ch <= 0) {
            return $this;
        }

        $this->writeShadowRect($x0, $y0, $x1, $y1, $color);
        $this->writePackedRect($x0, $y0, $cw, $ch, $color);
        $this->queuePresentOp($x0, $y0, $cw, $ch, $color);
        $this->markDirty($x0, $y0, $x1 - 1, $y1 - 1);

        return $this;
    }

    public function dump(?int $layer = null): string
    {
        return $this->packRgbaWords($this->shadow, $this->width, $this->height, $this->host_format);
    }

    /**
     * @return string|array<int, DumpedBuffer>
     */
    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        // Window present is separate ({@see present()}); match ogx/sdl3 empty flush.
        if (! $this->isHeadless()) {
            return $as_array ? [] : '';
        }

        if ($this->dirty_regions === []) {
            return $as_array ? [] : '';
        }

        $regions = $this->coalesceDirtyRegions($this->dirty_regions);
        $this->dirty_regions = [];

        $whole = count($regions) === 1
            && $regions[0][0] === 0
            && $regions[0][1] === 0
            && $regions[0][2] === ($this->width - 1)
            && $regions[0][3] === ($this->height - 1);

        if ($whole) {
            $bytes = $this->packRgbaWords($this->shadow, $this->width, $this->height, $spec);

            if (! $as_array) {
                return $bytes;
            }

            return [
                new DumpedBuffer(
                    RenderType::FULL,
                    $spec,
                    $bytes,
                    width: $this->width,
                    height: $this->height,
                ),
            ];
        }

        $updates = [];

        foreach ($regions as [$left, $top, $right, $bottom]) {
            $regionW = ($right - $left) + 1;
            $regionH = ($bottom - $top) + 1;
            $words = $this->sliceShadow($left, $top, $regionW, $regionH);
            $bytes = $this->packRgbaWords($words, $regionW, $regionH, $spec);

            $updates[] = new DumpedBuffer(
                RenderType::PARTIAL,
                $spec,
                $bytes,
                origin_x: $left,
                origin_y: $top,
                width: $regionW,
                height: $regionH,
            );
        }

        if (! $as_array) {
            $joined = '';
            foreach ($updates as $frame) {
                $joined .= $frame->raw_data;
            }

            return $joined;
        }

        return $updates;
    }

    /**
     * @param  callable(): void  $draw
     */
    public function deferDirty(callable $draw): static
    {
        $this->dirty_defer_depth++;

        try {
            $draw();
        } finally {
            $this->dirty_defer_depth--;

            if ($this->dirty_defer_depth === 0 && ! is_null($this->deferred_dirty_union)) {
                [$left, $top, $right, $bottom] = $this->deferred_dirty_union;
                $this->deferred_dirty_union = null;
                $this->markDirty($left, $top, $right, $bottom);
            }
        }

        return $this;
    }

    public function damageGranularity(): DamageGranularity
    {
        if ($this->isHeadless()) {
            return DamageGranularity::pixel($this->width, $this->height);
        }

        return DamageGranularity::wholeSurface($this->width, $this->height);
    }

    public function preservesContentsOnPresent(): bool
    {
        // CPU shadow survives; headless PanelIC can erase/prime for PARTIAL.
        return $this->isHeadless();
    }

    public function markAllDirty(): static
    {
        $this->deferred_dirty_union = null;
        $this->dirty_regions = [[0, 0, $this->width - 1, $this->height - 1]];

        return $this;
    }

    protected function markDirty(int $left, int $top, int $right, int $bottom): void
    {
        $left = max(0, $left);
        $top = max(0, $top);
        $right = min($this->width - 1, $right);
        $bottom = min($this->height - 1, $bottom);

        if (($left > $right) || ($top > $bottom)) {
            return;
        }

        if ($this->dirty_defer_depth > 0) {
            if (is_null($this->deferred_dirty_union)) {
                $this->deferred_dirty_union = [$left, $top, $right, $bottom];

                return;
            }

            $this->deferred_dirty_union[0] = min($this->deferred_dirty_union[0], $left);
            $this->deferred_dirty_union[1] = min($this->deferred_dirty_union[1], $top);
            $this->deferred_dirty_union[2] = max($this->deferred_dirty_union[2], $right);
            $this->deferred_dirty_union[3] = max($this->deferred_dirty_union[3], $bottom);

            return;
        }

        $this->dirty_regions[] = [$left, $top, $right, $bottom];
    }

    /**
     * @param  array<int, array{0: int, 1: int, 2: int, 3: int}>  $regions
     * @return array<int, array{0: int, 1: int, 2: int, 3: int}>
     */
    protected function coalesceDirtyRegions(array $regions): array
    {
        if ($regions === []) {
            return [];
        }

        $pending = array_values($regions);
        $merged = [];

        while ($pending !== []) {
            [$left, $top, $right, $bottom] = array_shift($pending);
            $grew = true;

            while ($grew) {
                $grew = false;
                $next = [];

                foreach ($pending as $rect) {
                    [$rl, $rt, $rr, $rb] = $rect;
                    $overlaps = ! ($rr < $left - 1 || $rl > $right + 1 || $rb < $top - 1 || $rt > $bottom + 1);

                    if ($overlaps) {
                        $left = min($left, $rl);
                        $top = min($top, $rt);
                        $right = max($right, $rr);
                        $bottom = max($bottom, $rb);
                        $grew = true;
                    } else {
                        $next[] = $rect;
                    }
                }

                $pending = $next;
            }

            $merged[] = [$left, $top, $right, $bottom];
        }

        return $merged;
    }

    /**
     * @return array<int, int>
     */
    protected function sliceShadow(int $x, int $y, int $width, int $height): array
    {
        $words = [];

        for ($row = 0; $row < $height; $row++) {
            $src = (($y + $row) * $this->width) + $x;
            for ($col = 0; $col < $width; $col++) {
                $words[] = $this->shadow[$src + $col] ?? 0;
            }
        }

        return $words;
    }

    /**
     * Pack shadow / region words (0xRRGGBBAA) into a target FormatSpec byte stream.
     *
     * @param  array<int, int>  $words
     */
    protected function packRgbaWords(array $words, int $width, int $height, FormatSpec $spec): string
    {
        if (
            $spec->pixel_format === PixelFormat::ROW_MAJOR
            && $spec->bit_depth === BitDepth::B32
            && ($spec->endianness ?? Endianness::MSB) === Endianness::MSB
        ) {
            return $this->packWordChunks($words, 'N*');
        }

        if (
            $spec->pixel_format === PixelFormat::ROW_MAJOR
            && $spec->bit_depth === BitDepth::B16
        ) {
            $msb = ($spec->endianness ?? Endianness::MSB) !== Endianness::LSB;
            $packed = [];

            foreach ($words as $word) {
                $r = ($word >> 24) & 0xFF;
                $g = ($word >> 16) & 0xFF;
                $b = ($word >> 8) & 0xFF;
                $packed[] = (($r & 0xF8) << 8) | (($g & 0xFC) << 3) | ($b >> 3);
            }

            return $this->packWordChunks($packed, $msb ? 'n*' : 'v*');
        }

        $temp = new PixelStore($width, $height, $spec, 1);
        $i = 0;

        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $width; $col++) {
                $temp->setPixel($col, $row, $words[$i] ?? 0);
                $i++;
            }
        }

        return $temp->dump();
    }

    /**
     * @param  array<int, int>  $words
     */
    protected function packWordChunks(array $words, string $format): string
    {
        if ($words === []) {
            return '';
        }

        $bytes = '';
        $chunkSize = 512;

        for ($offset = 0, $count = count($words); $offset < $count; $offset += $chunkSize) {
            $chunk = array_slice($words, $offset, $chunkSize);
            $bytes .= pack($format, ...$chunk);
        }

        return $bytes;
    }

    /**
     * @return array{0: float, 1: float}
     */
    protected function contentScale(): array
    {
        $fb = Window::getFramebufferSize($this->native_window);
        $fbW = max(1, (int) ($fb['width'] ?? $this->width));
        $fbH = max(1, (int) ($fb['height'] ?? $this->height));

        return [
            $fbW / max(1, $this->width),
            $fbH / max(1, $this->height),
        ];
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int, color: int}|null  $op
     * @return array{draw: bool, x: int, y: int, w: int, h: int, r: float, g: float, b: float, a: float}
     */
    protected function scaledOp(?array $op, float $scaleX, float $scaleY): array
    {
        if (is_null($op)) {
            return [
                'draw' => false,
                'x' => 0,
                'y' => 0,
                'w' => 0,
                'h' => 0,
                'r' => 0.0,
                'g' => 0.0,
                'b' => 0.0,
                'a' => 1.0,
            ];
        }

        [$r, $g, $b, $a] = $this->rgbaFloats($op['color']);

        return [
            'draw' => true,
            'x' => (int) round($op['x'] * $scaleX),
            'y' => (int) round($op['y'] * $scaleY),
            'w' => max(1, (int) round($op['w'] * $scaleX)),
            'h' => max(1, (int) round($op['h'] * $scaleY)),
            'r' => $r,
            'g' => $g,
            'b' => $b,
            'a' => $a,
        ];
    }

    protected function queuePresentOp(int $x, int $y, int $w, int $h, int $color): void
    {
        if ($this->isHeadless()) {
            return;
        }

        // Fast-path hint only; present() prefers presentOpsFromShadow().
        if (count($this->present_ops) >= 3) {
            array_shift($this->present_ops);
        }

        $this->present_ops[] = [
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
            'color' => $color,
        ];
    }

    /**
     * Coalesce non-clear shadow pixels into ≤3 presentFrame rects.
     *
     * Groups by color (pixel count desc). Skips a region whose AABB is mostly
     * inside an already-chosen larger region so a sparse outline (e.g. white
     * drawCircle) does not paint over a filled circle as a solid white square.
     *
     * @return list<array{x: int, y: int, w: int, h: int, color: int}>
     */
    protected function presentOpsFromShadow(): array
    {
        $clear = $this->clear_color;
        /** @var array<int, array{count: int, x0: int, y0: int, x1: int, y1: int}> $regions */
        $regions = [];

        $w = $this->width;
        $h = $this->height;

        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                $color = $this->shadow[$row + $x] ?? 0;
                if ($color === $clear) {
                    continue;
                }

                if (! isset($regions[$color])) {
                    $regions[$color] = [
                        'count' => 0,
                        'x0' => $x,
                        'y0' => $y,
                        'x1' => $x,
                        'y1' => $y,
                    ];
                }

                $region = &$regions[$color];
                $region['count']++;
                $region['x0'] = min($region['x0'], $x);
                $region['y0'] = min($region['y0'], $y);
                $region['x1'] = max($region['x1'], $x);
                $region['y1'] = max($region['y1'], $y);
                unset($region);
            }
        }

        if ($regions === []) {
            return [];
        }

        uasort(
            $regions,
            static fn (array $a, array $b): int => $b['count'] <=> $a['count'],
        );

        $ops = [];
        /** @var list<array{x0: int, y0: int, x1: int, y1: int}> $chosenBounds */
        $chosenBounds = [];

        foreach ($regions as $color => $region) {
            if (count($ops) >= 3) {
                break;
            }

            $aabbArea = ($region['x1'] - $region['x0'] + 1) * ($region['y1'] - $region['y0'] + 1);
            $density = $region['count'] / max(1, $aabbArea);

            $skip = false;
            foreach ($chosenBounds as $bound) {
                // Nested outline (drawCircle) shares nearly the same AABB as a
                // fill but is sparse — do not paint it as a solid covering rect.
                if ($this->aabbContainedFraction($region, $bound) >= 0.85) {
                    $skip = true;
                    break;
                }

                if ($density < 0.25 && $this->aabbIntersects($region, $bound)) {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                continue;
            }

            $ops[] = [
                'x' => $region['x0'],
                'y' => $region['y0'],
                'w' => $region['x1'] - $region['x0'] + 1,
                'h' => $region['y1'] - $region['y0'] + 1,
                'color' => (int) $color,
            ];
            $chosenBounds[] = [
                'x0' => $region['x0'],
                'y0' => $region['y0'],
                'x1' => $region['x1'],
                'y1' => $region['y1'],
            ];
        }

        return $ops;
    }

    /**
     * @param  array{x0: int, y0: int, x1: int, y1: int}  $a
     * @param  array{x0: int, y0: int, x1: int, y1: int}  $b
     */
    protected function aabbIntersects(array $a, array $b): bool
    {
        return $a['x0'] <= $b['x1']
            && $a['x1'] >= $b['x0']
            && $a['y0'] <= $b['y1']
            && $a['y1'] >= $b['y0'];
    }

    /**
     * @param  array{x0: int, y0: int, x1: int, y1: int}  $inner
     * @param  array{x0: int, y0: int, x1: int, y1: int}  $outer
     */
    protected function aabbContainedFraction(array $inner, array $outer): float
    {
        $ix0 = max($inner['x0'], $outer['x0']);
        $iy0 = max($inner['y0'], $outer['y0']);
        $ix1 = min($inner['x1'], $outer['x1']);
        $iy1 = min($inner['y1'], $outer['y1']);

        if ($ix1 < $ix0 || $iy1 < $iy0) {
            return 0.0;
        }

        $inter = ($ix1 - $ix0 + 1) * ($iy1 - $iy0 + 1);
        $area = ($inner['x1'] - $inner['x0'] + 1) * ($inner['y1'] - $inner['y0'] + 1);

        if ($area <= 0) {
            return 0.0;
        }

        return $inter / $area;
    }

    /**
     * Return live packed RGBA8. Rebuild from the int shadow only if the byte
     * buffer is the wrong size (headless / first frame).
     */
    protected function packRgba8Shadow(): string
    {
        $expected = $this->width * $this->height * 4;
        if ($expected > 0 && strlen($this->packedRgba8) === $expected) {
            return $this->packedRgba8;
        }

        $n = $this->width * $this->height;
        if ($n < 1) {
            return '';
        }

        $chunk = 16384;
        if ($n <= $chunk) {
            return pack('N*', ...$this->shadow);
        }

        $bytes = '';
        for ($i = 0; $i < $n; $i += $chunk) {
            $bytes .= pack('N*', ...array_slice($this->shadow, $i, $chunk));
        }

        $this->packedRgba8 = $bytes;

        return $bytes;
    }

    protected function writesCpuShadow(): bool
    {
        return $this->isHeadless() || ! method_exists(Vk::class, 'presentRgba8');
    }

    protected function writeShadowPixel(int $x, int $y, int $color): void
    {
        if (! $this->writesCpuShadow()) {
            return;
        }

        $this->shadow[($y * $this->width) + $x] = $color;
    }

    protected function writeShadowRect(int $x0, int $y0, int $x1, int $y1, int $color): void
    {
        if (! $this->writesCpuShadow()) {
            return;
        }

        $cw = $x1 - $x0;
        $ch = $y1 - $y0;
        $n = $this->width * $this->height;

        if ($x0 === 0 && $y0 === 0 && $cw === $this->width && $ch === $this->height) {
            $this->shadow = array_fill(0, $n, $color);

            return;
        }

        $w = $this->width;
        for ($py = $y0; $py < $y1; $py++) {
            $row = $py * $w;
            for ($px = $x0; $px < $x1; $px++) {
                $this->shadow[$row + $px] = $color;
            }
        }
    }

    protected function writePackedPixel(int $x, int $y, int $color): void
    {
        $pixel = pack('N', $color);
        $off = (($y * $this->width) + $x) * 4;
        $this->packedRgba8[$off] = $pixel[0];
        $this->packedRgba8[$off + 1] = $pixel[1];
        $this->packedRgba8[$off + 2] = $pixel[2];
        $this->packedRgba8[$off + 3] = $pixel[3];
    }

    protected function writePackedRect(int $x0, int $y0, int $cw, int $ch, int $color): void
    {
        $n = $this->width * $this->height;
        $pixel = pack('N', $color);

        if ($x0 === 0 && $y0 === 0 && $cw === $this->width && $ch === $this->height) {
            $this->packedRgba8 = str_repeat($pixel, $n);

            return;
        }

        $row = str_repeat($pixel, $cw);
        $rowBytes = $cw * 4;
        $stride = $this->width * 4;

        for ($py = $y0; $py < $y0 + $ch; $py++) {
            $off = ($py * $stride) + ($x0 * 4);
            for ($i = 0; $i < $rowBytes; $i++) {
                $this->packedRgba8[$off + $i] = $row[$i];
            }
        }
    }

    /**
     * Unpack 0xRRGGBBAA into float RGBA for presentFrame.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    protected function rgbaFloats(int $color): array
    {
        return [
            (($color >> 24) & 0xFF) / 255.0,
            (($color >> 16) & 0xFF) / 255.0,
            (($color >> 8) & 0xFF) / 255.0,
            ($color & 0xFF) / 255.0,
        ];
    }
}
