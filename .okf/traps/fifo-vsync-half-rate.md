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

1. **Slow pack** — old `packRgba8Shadow()` concatenated four `chr()` bytes per pixel (~50ms at 800×600). Then `pack('N*', …)` chunks (~5–7 ms at 960×720). **Current:** live packed RGBA8 updated on draw (full-surface `str_repeat`); `packRgba8Shadow()` returns that buffer (`packMs` ~0, verified 2026-08-12).
2. **FIFO VSync** — ext-vulkan `createSwapchain` used `VK_PRESENT_MODE_FIFO_KHR`. When a frame exceeds one refresh, acquire/present waits an extra vblank → ~half rate. Same class of bug as cuda-gfx before `swapInterval(0)`. On Darwin, MAILBOX still vsyncs until [MoltenVK display sync](moltenvk-display-sync.md) runs from `setVsync`.

# Guidance

- Keep a live packed RGBA8 buffer — never per-pixel string concat, and do not re-`pack('N*')` the int shadow every present.
- ext-vulkan prefers **MAILBOX → IMMEDIATE → FIFO**; app `FramePacer` owns numbered caps. VSync OFF + Uncapped must be allowed to exceed the panel refresh.
- Rebuild/reload **ext-vulkan** after present-mode C changes (`php-io-extensions/vulkan`).

# Related

- [Vulkan VSync](../core/vsync.md)
- [presentFrame rect budget](present-frame-rect-budget.md)
- [CPU shadow until GPU store](cpu-shadow-until-gpu-store.md)
- [MoltenVK MAILBOX still vsyncs](moltenvk-display-sync.md)
