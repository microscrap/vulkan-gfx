# microscrap/vulkan-gfx — Vulkan Deferred framebuffer for ScrapyardIO

[![Docs](https://img.shields.io/badge/docs-0.7.x-0A7EA4?logo=readthedocs&logoColor=white)](https://scrapyard-io.projectsaturnstudios.com/ecosystem/microscrap/vulkan-gfx/0.7.x/overview)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/microscrap/vulkan-gfx.svg?label=packagist)](https://packagist.org/packages/microscrap/vulkan-gfx)
[![Tests](https://github.com/microscrap/vulkan-gfx/actions/workflows/tests.yml/badge.svg)](https://github.com/microscrap/vulkan-gfx/actions/workflows/tests.yml)
[![PHP Version Require](https://img.shields.io/badge/php-%5E8.4%7C%5E8.5%7C%5E8.6-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![ext-vulkan](https://img.shields.io/badge/ext--vulkan-%5E0.7.0-brightgreen)](https://github.com/php-io-extensions/vulkan)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**Docs:** [ScrapyardIO — microscrap/vulkan-gfx `0.7.x`](https://scrapyard-io.projectsaturnstudios.com/ecosystem/microscrap/vulkan-gfx/0.7.x/overview)

Vulkan companion for ScrapyardIO **tubes 0.7** — registers:

* Framebuffer key **`vulkan`** as a **Deferred** (`VulkanHandledFramebuffer`)
* Window key **`vulkan`** as a **`VulkanWindowHandler`** (GLFW `NO_API` + surface + swapchain)
* Human Input via **`VulkanInputHandler`** (GLFW Input after `pollEvents`; wrap with tubes `EngineInput`)
* Ships **`VulkanRenderer2D`** (full tubes `DrawingAPI` + `DrawsText`)

## Highlights

* `VulkanHandledFramebuffer` extends `DeferredFramebuffer` (not Managed / not `PixelStore`)
* **Headless** — `VkInstance` + CPU shadow canvas (**no window required**)
* **Windowed** — `Window::driver('vulkan')->…->open()`; `attachedTo` + `presentFrame` (clear + ≤3 rects from shadow)
* **`VulkanRenderer2D`** — full `DrawingAPI` + tubes `DrawsText` into the borrowed Vulkan framebuffer
* Factory keys via `extendDeferred` + `$windows->extend` — publish tags `tubes-framebuffers-vulkan` / `tubes-windows-vulkan`

> **Note:** ext-vulkan **0.7.0** has no offscreen image/readback APIs yet (Metal-gfx uses `MTLTexture`). Headless pixels live in a PHP shadow; windowed present uses `Vk::presentFrame` rect slots until a richer GPU store lands.

## Requirements

* PHP 8.4+
* **ext-vulkan** ^0.7.0
* **ext-glfw** (via `microscrap/glfw` ^0.7.0)
* `microscrap/vulkan` ^0.7.0
* `scrapyard-io/tubes` ^0.7.0 (umbrella; provides framebuffers + contracts + rendering + fonts)

## Installation

```bash
php -m | grep vulkan
composer require microscrap/vulkan-gfx:^0.7.0
php workshop vendor:publish --tag=tubes-framebuffers-vulkan
php workshop vendor:publish --tag=tubes-windows-vulkan
# or: php workshop install:gfx vulkan
```

## Usage

```php
use Microscrap\GFX\Vulkan\VulkanHandledFramebuffer;
use Microscrap\GFX\Vulkan\VulkanRenderer2D;
use Microscrap\GFX\Vulkan\VulkanWindowHandler;
use ScrapyardIO\Tubes\Core\MagicAliases\Framebuffer;
use ScrapyardIO\Tubes\Core\MagicAliases\Window;
use ScrapyardIO\Tubes\HumanInput\EngineInput;

$buffer = Framebuffer::driver('vulkan')
    ->size(320, 240)
    ->format(VulkanHandledFramebuffer::rgbaSpec())
    ->create(); // DeferredFramebuffer — headless

$buffer->setPixel(10, 10, 0xFF0000FF);
$bytes = $buffer->flush(VulkanHandledFramebuffer::rgbaSpec());

$window = Window::driver('vulkan')->title('Demo')->size(800, 600)->open();
$fb = $window->framebuffer();
$gfx = (new VulkanRenderer2D)->setFramebuffer($fb);
$gfx->fill(0x141820FF)
    ->fillCircle(400, 300, 80, 0xF0A030FF)
    ->setTextColor(0xFFFFFFFF)
    ->setCursor(40, 40)
    ->print('Vulkan');

$handler = $window->handler();
$input = $handler instanceof VulkanWindowHandler
    ? new EngineInput($handler->inputHandler())
    : null;

$window->present()->pollEvents(); // also refreshes HumanInput devices
$mouse = $input?->mouse();
```

See tubes **[Rendering](https://scrapyard-io.projectsaturnstudios.com/ecosystem/scrapyard-io/tubes/0.7.x/rendering)** and **[Fonts](https://scrapyard-io.projectsaturnstudios.com/ecosystem/scrapyard-io/tubes/0.7.x/fonts)** (if published).

## Stack

```text
ext-vulkan + microscrap/vulkan (+ glfw)
  └── microscrap/vulkan-gfx   (Deferred + Window + Renderer2D)  ← this package
        └── scrapyard-io/tubes factories + DrawingAPI + DrawsText
```

## License

MIT
