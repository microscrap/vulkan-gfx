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

- Prefer `Vk::presentRgba8()` — packs the CPU shadow as RGBA8 and paints horizontal clearAttachment runs (skips clear-color pixels). `VulkanHandledFramebuffer::present()` uses this when available.
- Keep `presentFrame` + `presentOpsFromShadow()` as a fallback for older extension builds.
- Circles/text are exact in the shadow path; performance is RLE span count, not a 3-rect hard cap.
