---
type: Trap
title: MoltenVK ICD on macOS
description: glfwVulkanSupported is false until VK_ICD_FILENAMES points at MoltenVK_icd.json
tags: [trap, vulkan, macos, moltenvk, glfw]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-09T05:10:00Z" }
status: draft
---

# Symptom

`VulkanWindowHandler::open()` throws “GLFW reports Vulkan is not supported”.

# Cause

On Darwin, the Vulkan loader needs the MoltenVK ICD. Without `VK_ICD_FILENAMES`, `glfwVulkanSupported()` returns false even when MoltenVK is installed via Homebrew.

# Guidance

`VulkanGfxServiceProvider` re-execs the CLI **once** with:

- `DYLD_LIBRARY_PATH` prepended with `/opt/homebrew/lib` (or `/usr/local/lib`) when `libMoltenVK.dylib` is present
- `VK_ICD_FILENAMES` pointing at the Homebrew MoltenVK ICD when unset
- Guard env `MICROSCRAP_VULKAN_DYLD_READY=1` so the child does not loop

So bare `./runner metal-canvas vulkan` works without manual exports.

You can still set the vars yourself:

```bash
export VK_ICD_FILENAMES=/opt/homebrew/etc/vulkan/icd.d/MoltenVK_icd.json
export DYLD_LIBRARY_PATH=/opt/homebrew/lib
```

Install: `brew install molten-vk vulkan-loader vulkan-headers`. Requires `ext-pcntl` for the re-exec path.
