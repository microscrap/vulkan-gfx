---
type: Trap
title: "GLFW enums: never Enum::CASE on duplicate values"
description: PHP 8.4 throws when accessing named cases on microscrap/glfw enums that reuse LAST/alias ints.
tags: [trap, glfw, enums, php84]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-09T07:05:00Z" }
status: draft
sources:
  - id: vulkan-input
    resource: src/VulkanInputHandler.php
    title: VulkanInputHandler key/mouse/pad mapping
---

# Trap

`microscrap/glfw` int-backed enums mirror glfw3.h, including **duplicate values** (`*_LAST`, mouse LEFT=BUTTON_1, gamepad CROSS=A, …).

On **PHP 8.4**, reading any named case on such an enum (e.g. `MouseButton::GLFW_MOUSE_BUTTON_LEFT`) throws:

`Duplicate value in enum … for cases …`

`Enum::cases()` still works.

# Do

- Iterate `Key::cases()` / `Joystick::cases()` / `GamepadButton::cases()` and use `$case->name` / `$case->value`.
- Prefer raw glfw ints for mouse buttons (0–4) and gamepad axes (0–5) when you need fixed indices.
- Never write `SomeGlfwEnum::GLFW_*_LAST` (or alias cases) in companion code.

# Related

- [VulkanInputHandler](../core/vulkan-input-handler.md)
