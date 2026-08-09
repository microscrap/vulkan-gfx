---
type: Core
title: VulkanHandledFramebuffer
description: Tubes DeferredFramebuffer for driver key vulkan — headless VkInstance + CPU shadow; windowed presents via presentRgba8 (full shadow) or presentFrame fallback.
tags: [core, framebuffer, deferred, vulkan, headless, window]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-09T05:00:00Z" }
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

## Window-attached

`::attachedTo(GlfwWindow, FormatSpec, w, h, VkSwapchain)` — called from `VulkanWindowHandler::bindFramebuffer()`.

- Borrows window + swapchain (does **not** destroy them)
- `isHeadless() === false`
- `fill` / `setSegment` / `setPixel` update the CPU shadow
- `present()` → `Vk::presentRgba8` (packed RGBA8 shadow; RLE clearAttachment runs) when available; else `Vk::presentFrame` (clear + ≤3 rects); resizes on `OUT_OF_DATE` / `SUBOPTIMAL`

## App usage

```php
Framebuffer::driver('vulkan')->size(320, 240)->format($spec)->create(); // headless

Window::driver('vulkan')->title('Demo')->size(800, 600)->open();
$fb = $window->framebuffer(); // attached
```

# Related

- [VulkanWindowHandler](vulkan-window-handler.md)
- [presentFrame rect budget](../traps/present-frame-rect-budget.md)
- [CPU shadow until GPU store](../traps/cpu-shadow-until-gpu-store.md)

[^fb]: VulkanHandledFramebuffer
