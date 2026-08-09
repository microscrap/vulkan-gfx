# Agent guidelines — microscrap/vulkan-gfx

## Knowledge Bundle (OKF)

This package ships an Open Knowledge Format bundle at [`.okf/`](.okf/) (excluded from Composer dist via `.gitattributes` `export-ignore`).

Before changing GFX/framebuffer code **for this package**:

1. Read [`.okf/index.md`](.okf/index.md) first (progressive disclosure).
2. Open only the linked concepts needed for the task.
3. Prefer `status: stable` concepts; treat `deprecated` as historical only. New/changed concepts stay `status: draft` until a human verifies them.
4. When you learn something durable about **this package**, update the affected `.okf` concept(s) and append `.okf/log.md`.
5. Keep the `.okf` bundle at the **package root** only — do not nest extra `.okf` folders under `src/`.
6. Bindings knowledge → `microscrap/vulkan`. Tubes factory/PixelStore → `scrapyard-io/tubes`. Extension build → `php-io-extensions/vulkan`.
7. **Always** keep the `.okf/` bundle current when changing API or registration; append `.okf/log.md`.
8. **NEVER** commit or push `vendor/`.

## Package rules (quick) — 0.7.x

- Composer: `microscrap/vulkan-gfx` **0.7.0**. PHP `^8.4|^8.5|^8.6`.
- Depends on `microscrap/vulkan`, `microscrap/glfw` `^0.7.0`, `ext-vulkan`, `scrapyard-io/tubes` (umbrella replaces `tubes/*`), `fabricate/nuts-and-bolts` (`^0.7.0`).
- Provider registers **`extendDeferred('vulkan', …)`** and **`$windows->extend('vulkan', VulkanWindowHandler::class)`**. Soft Managed = tubes `full`/`dirty`/`page` only.
- `VulkanHandledFramebuffer` extends tubes **`DeferredFramebuffer`**.
- `VulkanWindowHandler` — GLFW `CLIENT_API=NO_API` + surface + swapchain; `pollNative` owns events then refreshes `VulkanInputHandler`; `close()` drops FB before WSI teardown.
- `VulkanInputHandler` — tubes `InputHandler` over `microscrap/glfw` `Input` (not raw vulkan binding). Wire only via window poll; wrap with tubes `EngineInput`.
- `VulkanRenderer2D` implements tubes `DrawingAPI` against a borrowed framebuffer (CPU shadow fill).
- Text: `use DrawsText` (tubes concern) — do not reimplement glyph rasterization in vulkan-gfx.
- **Headless** = `VkInstance` + CPU shadow. **Windowed** = `attachedTo(window, …, swapchain)`; `present()` → `Vk::presentRgba8` (full shadow) or `presentFrame` ≤3-rect fallback.
- **FPS**: never pack the CPU shadow with per-pixel string concat; prefer MAILBOX/IMMEDIATE present (ext-vulkan). FIFO + slow frames → ~30fps (see `.okf/traps/fifo-vsync-half-rate.md`).
- macOS MoltenVK: provider boot re-execs CLI once with `DYLD_LIBRARY_PATH` when needed (`DarwinVulkanLoaderEnv`).
- Publish tags: `tubes-framebuffers-vulkan`, `tubes-windows-vulkan`.
- Never model `vulkan` as a PHP Managed `PixelStore` concrete.
- Registration key stays **`vulkan`** on both factories. Namespace `Microscrap\GFX\Vulkan\`.
- Prefer `is_null($var)` over `$var === null`. Enums UPPERCASE; no class-level constants.
