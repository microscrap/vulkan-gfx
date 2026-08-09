---
type: Core
title: VulkanGfxServiceProvider
description: Package discovery provider; boot registers extendDeferred('vulkan') and windows->extend('vulkan').
tags: [core, provider, deferred, window]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-09T04:20:00Z" }
status: draft
sources:
  - id: provider
    resource: src/Providers/VulkanGfxServiceProvider.php
    title: VulkanGfxServiceProvider
---

# Boot

```php
$framebuffers->extendDeferred(
    'vulkan',
    fn (PendingFramebuffer $pending) => VulkanHandledFramebuffer::sized(
        $pending->widthValue(),
        $pending->heightValue(),
        $pending->hostFormatValue(),
    ),
);

$windows->extend('vulkan', VulkanWindowHandler::class);
```

**Deferred lane** for framebuffers — not `extendManaged`.

# Publish tags (console)

| Tag | Target |
|-----|--------|
| `tubes-framebuffers-vulkan` | `config/framebuffers/vulkan.php` |
| `tubes-windows-vulkan` | `config/windows/vulkan.php` |
