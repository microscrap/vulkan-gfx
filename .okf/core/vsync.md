---
type: Core
title: Vulkan VSync
description: "setVsync — Darwin CAMetalLayer.displaySyncEnabled once; Linux no-op until ext-vulkan presentMode. Windowed present is live packed presentRgba8."
resource: src/VulkanWindowHandler.php
tags: [core, vsync, vulkan, darwin, moltenvk]
generated: { by: "cursor-agent/grok-4.6", at: "2026-08-12T19:35:00Z" }
status: draft
---

# Role

`VulkanWindowHandler::setVsync(bool)` stores the requested flag. Hardware vsync is a **floor at the panel refresh**. Sleep in the app (`FramePacer`) can only **cap**. ext-vulkan `createSwapchain` still has no presentMode argument.

| Platform | Unlock (VSync off) | Lock (VSync on) |
|----------|--------------------|-----------------|
| macOS | `DarwinCocoaDisplaySync` sets `CAMetalLayer.displaySyncEnabled` **once** (cached per window + flag) | layer display sync on |
| Linux | Helper is a no-op (`PHP_OS_FAMILY !== Darwin`) | no-op until presentMode lands |

Do **not** poke the layer every present. See [MoltenVK MAILBOX still vsyncs](../traps/moltenvk-display-sync.md).

Windowed `present()` prefers `Vk::presentRgba8` from a **live packed RGBA8** buffer (full-surface `str_repeat`; skip per-frame `pack('N*')` of the int shadow). Windowed int-shadow loops are skipped when `presentRgba8` exists. Verified 2026-08-12: `packMs` ~0 (was ~7 ms); VSync OFF + Uncapped menu 127–169 Hz on a 120 Hz panel (`vkPresentMs` ~2.4 ms, not a vblank wait).

VSync OFF + Uncapped must be allowed to exceed the panel refresh. Numbered caps belong to the app `FramePacer`.

# Related

- [VulkanWindowHandler](vulkan-window-handler.md)
- [VulkanHandledFramebuffer](vulkan-handled-framebuffer.md)
- [MoltenVK MAILBOX still vsyncs](../traps/moltenvk-display-sync.md)
- [FIFO VSync + slow pack](../traps/fifo-vsync-half-rate.md)
- [CPU shadow until GPU store](../traps/cpu-shadow-until-gpu-store.md)
