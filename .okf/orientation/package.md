---
type: Orientation
title: Package (0.7)
description: microscrap/vulkan-gfx 0.7.0 — Vulkan companion; Deferred framebuffer + WindowHandler + Renderer2D.
tags: [vulkan-gfx, microscrap, deferred, window, rendering]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-09T06:00:00Z" }
status: draft
---

# Identity

| Field | Value |
|-------|--------|
| Composer | `microscrap/vulkan-gfx` **0.7.0** |
| PHP | `^8.4\|^8.5\|^8.6` |
| Requires | `ext-vulkan`, `ext-glfw` (via glfw), `microscrap/vulkan`, `microscrap/glfw`, `scrapyard-io/tubes`, `fabricate/nuts-and-bolts` |
| Namespace | `Microscrap\GFX\Vulkan\` |
| Role | Deferred `VulkanHandledFramebuffer` + `VulkanWindowHandler` (GLFW NO_API WSI) + `VulkanInputHandler` + `VulkanRenderer2D` |
| VSync | `setVsync` — Darwin `CAMetalLayer.displaySyncEnabled` once; Linux no-op. Windowed present is live packed `presentRgba8`. |

# Lanes / factories

| Factory | Key | Implementation |
|---------|-----|----------------|
| Framebuffer Deferred | `vulkan` | `VulkanHandledFramebuffer` (headless or window-attached) |
| Window | `vulkan` | `VulkanWindowHandler` (visible NO_API window + swapchain present) |
| Managed (tubes) | `full` / `dirty` / `page` | tubes `PixelStore` |

# Rendering

| Class | Status |
|-------|--------|
| `VulkanRenderer2D` | Full tubes `DrawingAPI` + `use DrawsText` — geometry into borrowed Vulkan FB |

Not a factory slug. Bind via `setFramebuffer($fb)` (reference). Fonts: tubes FontManager / `Font::extend` — do not add Font classes here.

# Exemplars

- `microscrap/metal-gfx` — `MetalHandledFramebuffer` + `MetalWindowHandler` + `MetalRenderer2D` + DrawsText
- `microscrap/ogx` — `OgxFramebuffer` + `OpenGLWindowHandler` + `OpenGLRenderer2D` + DrawsText
- `microscrap/sdl3-gfx` — `Sdl3Framebuffer` + `SDL3WindowHandler` + stub `SDL3Renderer2D`
