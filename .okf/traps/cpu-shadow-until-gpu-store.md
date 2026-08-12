---
type: Trap
title: CPU shadow until GPU store
description: Headless pixels are PHP-owned until ext-vulkan gains offscreen image/readback APIs.
tags: [trap, vulkan, headless, shadow]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-09T03:30:00Z" }
status: draft
---

# Symptom

Expecting GPU-backed pixel storage like `metal-gfx` `MTLTexture`.

# Cause

ext-vulkan **0.7.0** exposes instance/device/swapchain/present — not offscreen image create/read/write. `VulkanHandledFramebuffer` therefore keeps a CPU int shadow for the tubes Framebuffer pixel API (headless dump/getPixel) while still owning a `VkInstance`.

Windowed `presentRgba8` uses a **live packed RGBA8** string updated on `fill` / `setSegment` / `setPixel` (full-surface `str_repeat`). Do not re-`pack('N*')` the int shadow every frame. When `presentRgba8` exists, windowed draws skip the int-shadow loops.

# Guidance

Do not pretend the shadow is a Managed `PixelStore` lane. When the extension adds offscreen images, migrate storage and update this trap + [VulkanHandledFramebuffer](../core/vulkan-handled-framebuffer.md).

# Related

- [VulkanHandledFramebuffer](../core/vulkan-handled-framebuffer.md)
- [Vulkan VSync](../core/vsync.md)
- [presentFrame rect budget](present-frame-rect-budget.md)
