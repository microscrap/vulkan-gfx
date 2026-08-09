---
type: Core
title: VulkanInputHandler
description: Tubes InputHandler for Vulkan/GLFW — keyboard, mouse, pads after pollEvents.
tags: [core, input, vulkan, glfw, human-input]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-09T07:00:00Z" }
status: draft
sources:
  - id: handler
    resource: src/VulkanInputHandler.php
    title: VulkanInputHandler
  - id: window
    resource: src/VulkanWindowHandler.php
    title: VulkanWindowHandler pollNative fan-out
  - id: tubes
    resource: ../../scrapyard-io/tubes/.okf/core/input-handler.md
    title: tubes InputHandler matrix
---

# Role

`Microscrap\GFX\Vulkan\VulkanInputHandler` extends tubes `InputHandler`.[^handler]

It snapshots GLFW input into tubes device models (`Keyboard`, `Mouse`, `GamePad`, `GameController`). It does **not** call `glfwPollEvents` — the window handler owns the pump.

# Lifecycle

| Hook | Behavior |
|------|----------|
| `attach(GlfwWindow)` | Bind window; install scroll callback (accumulates wheel delta) |
| `poll()` | Read keys / mouse / joysticks via `Microscrap\Bindings\GLFW\Input` |
| `detach()` | Clear callback + device snapshots |

# Window wiring

`VulkanWindowHandler` constructs one handler, `attach`s after native window create, and in `pollNative()`:[^window]

1. `Window::pollEvents()`
2. `$this->input_handler->poll()`

`inputHandler()` exposes the companion for `new EngineInput($handler)`.

# Device mapping

| GLFW | Tubes |
|------|-------|
| `getKey` | `Keyboard` keys named from enum (`GLFW_KEY_A` → `a`) |
| cursor + mouse buttons 1–5 | `Mouse` + `DigitalButton` (`left`/`right`/`middle`/`x1`/`x2`) |
| scroll callback | `Mouse::wheelDelta()` (consumed each poll) |
| `joystickIsGamepad` + `getGamepadState` | `GameController` (left/right sticks, triggers as analog, face/dpad buttons) |
| raw joystick buttons | `GamePad` (digital only — no sticks) |

# Related

- [VulkanWindowHandler](vulkan-window-handler.md)
- tubes [InputHandler](../../scrapyard-io/tubes/.okf/core/input-handler.md) (via path checkout)

[^handler]: VulkanInputHandler
[^window]: VulkanWindowHandler::pollNative
