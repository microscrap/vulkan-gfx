---
type: Trap
title: presentFrame rect budget
description: Legacy presentFrame is clear + ≤3 rects; prefer presentRgba8 for Renderer2D text/circles
tags: [trap, vulkan, window, present]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-09T06:10:00Z" }
status: draft
---

# Symptom

Soft `Renderer2D` paints (`fillCircle`, `DrawsText`) used to become solid AABBs / white HUD blocks on windowed Vulkan.

# Cause

ext-vulkan `presentFrame` only supports a clear color plus **three** colored rectangles. Glyphs and circles are many `setPixel` / `setSegment` ops.

# Guidance

- Prefer `Vk::presentRgba8()` from the **live packed RGBA8** buffer (updated on `fill` / `setSegment` / `setPixel`; full-surface `str_repeat`). Do not re-pack the int shadow every frame. `VulkanHandledFramebuffer::present()` uses this when available.
- Keep `presentFrame` + `presentOpsFromShadow()` as a fallback for older extension builds (fallback still needs the int shadow).
- Circles/text are exact in the packed path; performance is RLE span count, not a 3-rect hard cap.

# Related

- [Vulkan VSync](../core/vsync.md)
- [VulkanHandledFramebuffer](../core/vulkan-handled-framebuffer.md)
- [FIFO VSync + slow pack](fifo-vsync-half-rate.md)
