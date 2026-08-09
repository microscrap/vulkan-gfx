---
type: Core
title: VulkanRenderer2D
description: DrawingAPI + DrawsText — borrows VulkanHandledFramebuffer; fill clears CPU shadow
tags: [core, rendering, drawing, vulkan, fonts]
generated: { by: cursor-agent/grok-4.5, at: "2026-08-09T06:00:00Z" }
status: draft
sources:
  - id: renderer
    resource: src/VulkanRenderer2D.php
    title: VulkanRenderer2D
  - id: tubes
    resource: ../../scrapyard-io/tubes/src/Tubes/Rendering/Renderer2D.php
    title: Tubes Renderer2D
  - id: draws-text
    resource: ../../scrapyard-io/tubes/src/Tubes/Rendering/Concerns/DrawsText.php
    title: DrawsText
---

# Role

`Microscrap\GFX\Vulkan\VulkanRenderer2D` extends tubes `Renderer2D` and implements the full `DrawingAPI`.[^renderer] Presentation owns the framebuffer; this class **borrows** it via `setFramebuffer(&$fb)`.

# Vulkan-aware behavior

| Call | Path |
|------|------|
| `fill($color)` | `VulkanHandledFramebuffer::fill` → CPU shadow (+ present clear_color) |
| pixels / segments / lines | `setPixel` / `setSegment` into the shadow |
| circles / ellipses / triangles / roundrects | Midpoint / scanline into the shadow |
| Text (`setFont` / `print` / …) | tubes `DrawsText` → `drawPixel` / `fillRect` into Vulkan FB |
| Present | Not this class — WindowHandler / `framebuffer()->present()` → `Vk::presentRgba8` (or `presentFrame` fallback) |

# Text

Uses `ScrapyardIO\Tubes\Rendering\Concerns\DrawsText`:[^draws-text]

- Default / `setFont('classic')` / `setFont(null)` → built-in `ClassicFont` 5×7
- `setFont($gfxFont)` or registry slug → custom `GFXFont` (string slugs need Font manager)

Do **not** reimplement glyph rasterization in vulkan-gfx. Fonts live in tubes (`Font::extend` / FontManager).

# Usage

```php
$window = Window::driver('vulkan')->title('Vulkan')->size(800, 600)->open();
$fb = $window->framebuffer();
$gfx = (new VulkanRenderer2D)->setFramebuffer($fb);
$gfx->fill(0x141820FF)
    ->fillCircle(400, 300, 80, 0xF0A030FF)
    ->setTextColor(0xFFFFFFFF)
    ->setCursor(40, 40)
    ->print('Vulkan');
$window->present()->pollEvents();
```

Windowed present uses full-shadow `presentRgba8` when the extension supports it — see [present-frame-rect-budget](../traps/present-frame-rect-budget.md).

# Related

- Tubes [Rendering](../../scrapyard-io/tubes/.okf/core/rendering.md) / [Fonts](../../scrapyard-io/tubes/.okf/core/fonts.md)
- [VulkanHandledFramebuffer](vulkan-handled-framebuffer.md)
- [VulkanWindowHandler](vulkan-window-handler.md)

[^renderer]: VulkanRenderer2D
[^draws-text]: DrawsText
