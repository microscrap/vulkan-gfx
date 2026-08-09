---
type: Convention
title: Registration key
description: Factory driver key stays vulkan on Deferred framebuffer and Window factories.
tags: [convention, vulkan, deferred, window]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-09T04:20:00Z" }
status: draft
---

# Rule

Use tubes:

- **`extendDeferred('vulkan', …)`** for the framebuffer (not `extendManaged`)
- **`$windows->extend('vulkan', VulkanWindowHandler::class)`** for the OS window driver

Discovery / Workshop / docs keep the slug **`vulkan`** stable on both factories.
