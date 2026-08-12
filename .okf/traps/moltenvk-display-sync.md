---
type: Trap
title: "MoltenVK MAILBOX still vsyncs on macOS"
description: "CAMetalLayer.displaySyncEnabled stays YES under MAILBOX/FIFO; glfwSwapInterval does not apply to Vulkan."
tags: [trap, vulkan, vsync, darwin, moltenvk]
generated: { by: "cursor-agent/grok-4.6", at: "2026-08-12T18:55:00Z" }
status: draft
---

# Trap

ext-vulkan `createSwapchain` has no presentMode argument. MoltenVK MAILBOX still sets `CAMetalLayer.displaySyncEnabled = YES`, so present floors at the display refresh. Tetriminos VSync OFF + Uncapped cannot beat that with sleep.

# Guidance

- `DarwinCocoaDisplaySync::apply($glfwWindow, false)` uses `glfwGetCocoaView` / `glfwGetCocoaWindow` + `setDisplaySyncEnabled:`
- Only send that selector after `isKindOfClass:CAMetalLayer` — a plain `CALayer` will abort
- Apply from `setVsync` **only** (cached per window + flag). Do not poke the layer every present. Linux is a no-op.
- `FramePacer` still owns the numbered cap after the layer unlocks. VSync OFF + Uncapped must be able to exceed the panel refresh.

# Related

- [VulkanWindowHandler](../core/vulkan-window-handler.md)
- [Vulkan VSync](../core/vsync.md)
- [FIFO VSync + slow pack](fifo-vsync-half-rate.md)
