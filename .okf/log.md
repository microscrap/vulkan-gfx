# OKF log

## 2026-08-09

- **FPS**: MetalCanvas vulkan ~32fps — slow `packRgba8Shadow` string concat (~50ms@800×600) + hard FIFO VSync. Pack now `pack('N*')` chunks; ext-vulkan prefers MAILBOX/IMMEDIATE. Trap [fifo-vsync-half-rate](traps/fifo-vsync-half-rate.md).
- **Human Input**: `VulkanInputHandler` (tubes `InputHandler`) snapshots GLFW keyboard/mouse/pads; `VulkanWindowHandler::pollNative` runs `pollEvents` then input `poll()`. Accessor `inputHandler()` for `EngineInput`. Pest `VulkanInputHandlerTest`. Concept [vulkan-input-handler](core/vulkan-input-handler.md). Trap [glfw-duplicate-enum-cases](traps/glfw-duplicate-enum-cases.md) (PHP 8.4 + glfw LAST aliases).
- **Present**: Windowed `present()` prefers `Vk::presentRgba8` (full CPU shadow → RLE clearAttachments) so glyphs/circles survive; ≤3-rect `presentFrame` remains a fallback. Trap [present-frame-rect-budget](traps/present-frame-rect-budget.md) rewritten.
- **Fonts / DrawsText**: `VulkanRenderer2D` implements full `DrawingAPI` + tubes `DrawsText` (ClassicFont / GFXFont via `setFont`). Geometry into CPU shadow; Pest for print + bounds. Docs/README/AGENTS/ecosystem updated — no Font classes in this package.
- **Fix (historical)**: Windowed `present()` rebuilt ≤3 rects from the CPU shadow under the old presentFrame budget (superseded by presentRgba8).
- **Docs**: Documented stub `VulkanRenderer2D` (tubes `Renderer2D` / DrawingAPI). OKF + README + ecosystem pages. (Superseded by Fonts / DrawsText above.)
- **Fix**: `DarwinVulkanLoaderEnv` — CLI re-exec once with Homebrew `DYLD_LIBRARY_PATH` + MoltenVK ICD (provider boot) so bare `./runner metal-canvas vulkan` works on macOS without manual exports.
- **Land**: `VulkanWindowHandler` native boot (GLFW `NO_API` + surface + device + swapchain) and `VulkanHandledFramebuffer::attachedTo` / windowed `present()` via `Vk::presentFrame`. Trap [present-frame-rect-budget](traps/present-frame-rect-budget.md) replaces window-attach-later. Pest open/present/poll/close when ext-vulkan+ext-glfw loaded.
- **Update**: Documented `VulkanWindowHandler` stub + provider `windows->extend('vulkan')` + publish tag `tubes-windows-vulkan`. (Superseded by Land above.)

## 2026-08-08

- Initial bundle for `microscrap/vulkan-gfx` **0.7.0** — Deferred `vulkan` / `VulkanHandledFramebuffer` headless (`VkInstance` + CPU shadow). Pattern cloned from `microscrap/metal-gfx`. Window attach deferred to tubes OSWindows. GPU store waits on ext-vulkan offscreen image APIs.
