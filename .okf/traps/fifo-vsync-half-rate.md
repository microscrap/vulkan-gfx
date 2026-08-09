---
type: Trap
title: "FIFO VSync + slow pack → ~30fps"
description: Hard FIFO present mode plus PHP shadow pack can lock MetalCanvas near half refresh.
tags: [trap, vulkan, vsync, fps, present]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-09T07:15:00Z" }
status: draft
sources:
  - id: cuda-parity
    resource: ../cuda-gfx/.okf/log.md
    title: cuda-gfx swapInterval(0) half-rate fix
  - id: pack
    resource: src/VulkanHandledFramebuffer.php
    title: packRgba8Shadow
  - id: ext
    resource: ../../../OfficialScrapyardIO/php-io-extensions/vulkan/src/vulkan-api.c
    title: createSwapchain presentMode
---

# Symptom

`./runner metal-canvas vulkan` HUD shows ~30–32fps while `--fps=60`.

# Cause

1. **Slow pack** — old `packRgba8Shadow()` concatenated four `chr()` bytes per pixel (~50ms at 800×600). Fixed with `pack('N*', …)` chunks (~5ms).
2. **FIFO VSync** — ext-vulkan `createSwapchain` used `VK_PRESENT_MODE_FIFO_KHR`. When a frame exceeds one refresh (~16.6ms @60Hz), acquire/present waits an extra vblank → ~30fps. Same class of bug as cuda-gfx before `swapInterval(0)`.

# Guidance

- Keep shadow packing on `pack()` / binary paths — never per-pixel string concat.
- ext-vulkan prefers **MAILBOX → IMMEDIATE → FIFO**; sketch `FramePaceNode` owns the 60fps sleep.
- Rebuild/reload **ext-vulkan** after present-mode C changes (`php-io-extensions/vulkan`).

# Related

- [presentFrame rect budget](present-frame-rect-budget.md)
- [CPU shadow until GPU store](cpu-shadow-until-gpu-store.md)
