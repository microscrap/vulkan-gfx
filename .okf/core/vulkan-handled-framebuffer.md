---
type: Core
title: VulkanHandledFramebuffer
description: Tubes DeferredFramebuffer for driver key vulkan — headless VkInstance + CPU shadow with PanelIC dirty/PARTIAL + fast RGB565 pack; windowed presents via presentRgba8 or presentFrame fallback.
tags: [core, framebuffer, deferred, vulkan, headless, window, panelic, dirty, partial]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-11T04:30:00Z" }
status: draft
sources:
  - id: fb
    resource: src/VulkanHandledFramebuffer.php
    title: VulkanHandledFramebuffer
---

# Role

`Microscrap\GFX\Vulkan\VulkanHandledFramebuffer` **extends** `ScrapyardIO\Tubes\Framebuffers\DeferredFramebuffer`.[^fb]

**Not** a tubes Managed `PixelStore` concrete.

## Headless

`::sized($w, $h, $hostFormat)` (requires **ext-vulkan ≥ 0.7.0**):

1. `Vk::createInstance([], 'microscrap/vulkan-gfx')`
2. CPU shadow of logical pixels
3. `isHeadless() === true`; `present()` no-op
4. Dirty tracking → PanelIC `flush()`: `FULL` when whole surface dirty, else `PARTIAL` slices
5. `damageGranularity()` = pixel; `preservesContentsOnPresent()` = true (shadow retained)
6. Pack path: `packRgbaWords` (fast ROW_MAJOR B32/`N*` + B16 RGB565/`n*`|`v*`; exotic specs via `PixelStore`)

## Window-attached

`::attachedTo(GlfwWindow, FormatSpec, w, h, VkSwapchain)` — called from `VulkanWindowHandler::bindFramebuffer()`.

- Borrows window + swapchain (does **not** destroy them)
- `isHeadless() === false`
- `fill` / `setSegment` / `setPixel` keep a live packed RGBA8 buffer for `presentRgba8`. Windowed path skips the int shadow when `presentRgba8` exists (dump/getPixel stay headless). Full-surface fills use `array_fill` / `str_repeat`.
- `present()` → `Vk::presentRgba8` (packed RGBA8; RLE clearAttachment runs) when available; else `Vk::presentFrame` (clear + ≤3 rects); resizes on `OUT_OF_DATE` / `SUBOPTIMAL`
- `flush()` returns empty (window present is separate); `damageGranularity()` = wholeSurface

## Dirty / PARTIAL (PanelIC)

Same contract as ogx / sdl3-gfx:

- `dirty_regions`, `dirty_defer_depth`, `deferred_dirty_union`
- `fill()` → `markAllDirty()`
- `setPixel` / `setSegment` → inclusive bbox `markDirty`
- `deferDirty(callable)` unions marks (used by `VulkanRenderer2D` fillCircle / drawCircle)
- `flush($spec, as_array)` coalesces → FULL or PARTIAL `DumpedBuffer`s

## App usage

```php
Framebuffer::driver('vulkan')->size(320, 240)->format($spec)->create(); // headless

Window::driver('vulkan')->title('Demo')->size(800, 600)->open();
$fb = $window->framebuffer(); // attached
```

# Related

- [VulkanWindowHandler](vulkan-window-handler.md)
- [VulkanRenderer2D](vulkan-renderer-2d.md)
- [Vulkan VSync](vsync.md)
- [presentFrame rect budget](../traps/present-frame-rect-budget.md)
- [CPU shadow until GPU store](../traps/cpu-shadow-until-gpu-store.md)

[^fb]: VulkanHandledFramebuffer
