---
okf_version: "0.2"
---

# microscrap/vulkan-gfx Knowledge Bundle

Package knowledge for `microscrap/vulkan-gfx` (Vulkan Deferred framebuffer + WindowHandler + Renderer2D over **ext-vulkan** / microscrap/vulkan / glfw, v0.7.0).
Read this index first; open only the concepts needed for the task.

**Trust rule:** Prefer `status: stable`. Treat `deprecated` as historical only. New agent-written concepts stay `status: draft` until a human verifies them.
**Placement:** This bundle lives at the **package root** only — never under `src/`.
**Scope:** Document Deferred `vulkan` framebuffer registration, headless/windowed `VulkanHandledFramebuffer`, landed `VulkanWindowHandler`, and `VulkanRenderer2D` (DrawingAPI + DrawsText). Soft Managed drivers live in tubes. Fonts live in tubes.
**Dist note:** `.okf/` and root `AGENTS.md` are `export-ignore` in `.gitattributes`.

# Orientation

* [Package (0.7)](orientation/package.md) - Composer identity; Deferred + Window keys; Renderer2D + DrawsText.

# Core

* [VulkanGfxServiceProvider](core/service-provider.md) - `extendDeferred('vulkan')` + `windows->extend('vulkan')`.
* [VulkanHandledFramebuffer](core/vulkan-handled-framebuffer.md) - Headless + window-attached Deferred buffer.
* [VulkanWindowHandler](core/vulkan-window-handler.md) - Tubes WindowHandler (GLFW NO_API WSI).
* [Vulkan VSync](core/vsync.md) - Darwin CAMetalLayer once; live packed `presentRgba8`. (`draft`)
* [VulkanInputHandler](core/vulkan-input-handler.md) - Tubes InputHandler; GLFW Input after pollEvents. (`draft`)
* [VulkanRenderer2D](core/vulkan-renderer-2d.md) - Full DrawingAPI + tubes DrawsText into borrowed FB.

# Conventions

* [Registration key](conventions/registration-key.md) - Key stays `vulkan` on Deferred + Window factories.

# Traps

* [presentFrame rect budget](traps/present-frame-rect-budget.md) - clear + ≤3 rect ops per present.
* [MoltenVK ICD on macOS](traps/moltenvk-icd-macos.md) - CLI re-exec / DYLD for MoltenVK.
* [CPU shadow until GPU store](traps/cpu-shadow-until-gpu-store.md) - ext-vulkan 0.7.0 lacks offscreen image APIs.
* [GLFW duplicate enum cases](traps/glfw-duplicate-enum-cases.md) - PHP 8.4 forbids Enum::CASE on LAST/alias ints. (`draft`)
* [FIFO VSync + slow pack → ~30fps](traps/fifo-vsync-half-rate.md) - Historical MetalCanvas half-rate; live packed buffer supersedes chunked `pack('N*')`. (`draft`)
* [MoltenVK MAILBOX still vsyncs](traps/moltenvk-display-sync.md) - Darwin `CAMetalLayer.displaySyncEnabled` in `setVsync` only. (`draft`)

# Log

* [Directory update log](log.md)
