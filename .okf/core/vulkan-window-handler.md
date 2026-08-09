---
type: Core
title: VulkanWindowHandler
description: Tubes WindowHandler for driver key vulkan — GLFW NO_API window + surface + swapchain; FormatSpec matches VulkanHandledFramebuffer.
tags: [core, window, vulkan, deferred]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-09T05:00:00Z" }
status: draft
sources:
  - id: handler
    resource: src/VulkanWindowHandler.php
    title: VulkanWindowHandler
  - id: tubes-handler
    resource: ../../scrapyard-io/tubes/src/Tubes/Windows/WindowHandler.php
    title: Abstract WindowHandler
  - id: gold-metal
    resource: ../metal-gfx/src/MetalWindowHandler.php
    title: MetalWindowHandler gold reference
  - id: gold-ogx
    resource: ../ogx/src/OpenGLWindowHandler.php
    title: OpenGLWindowHandler gold reference
---

# Role

`Microscrap\GFX\Vulkan\VulkanWindowHandler` extends tubes `WindowHandler`.[^handler]

`defineFormatSpec()` returns `VulkanHandledFramebuffer::rgbaSpec()`.

# Lifecycle (landed)

| Hook | Behavior |
|------|----------|
| `bootNative` | glfwInit (once); instance w/ `glfwGetRequiredInstanceExtensions`; visible GLFW `CLIENT_API=NO_API`; `vkCreateWindowSurface`; device/queue; swapchain |
| `bindFramebuffer` | `VulkanHandledFramebuffer::attachedTo(window, …, swapchain)` |
| `presentNative` | `$fb->present()` → `Vk::presentRgba8` (preferred) / `presentFrame` |
| `pollNative` | `Window::pollEvents()` then `VulkanInputHandler::poll()` (one pump) |
| `shouldClose` | `Window::windowShouldClose` |
| `close` | null FB **first**, then `destroyNative` (Metal/ogx ordering) |
| `destroyNative` | detach input → swapchain → device → surface → window → instance (idempotent; no `glfwTerminate`) |
| `inputHandler()` | owned `VulkanInputHandler` (attach on boot, detach on destroy) |

# Registration

Provider: `$windows->extend('vulkan', VulkanWindowHandler::class)`.

Publish: `tubes-windows-vulkan` → `config/windows/vulkan.php`.

```php
Window::driver('vulkan')->title('Demo')->size(800, 600)->open();
```

# Related

- [VulkanHandledFramebuffer](vulkan-handled-framebuffer.md)
- [VulkanInputHandler](vulkan-input-handler.md)
- [VulkanGfxServiceProvider](service-provider.md)
- [presentFrame rect budget](../traps/present-frame-rect-budget.md)

[^handler]: VulkanWindowHandler
