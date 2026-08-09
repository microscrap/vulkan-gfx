<?php

namespace Microscrap\GFX\Vulkan\Providers;

use Fabricate\NutsAndBolts\ServiceProvider;
use Microscrap\GFX\Vulkan\DarwinVulkanLoaderEnv;
use Microscrap\GFX\Vulkan\VulkanHandledFramebuffer;
use Microscrap\GFX\Vulkan\VulkanWindowHandler;
use ScrapyardIO\Tubes\Contracts\Framebuffers\BufferFactory;
use ScrapyardIO\Tubes\Contracts\Windows\WindowFactory;
use ScrapyardIO\Tubes\Framebuffers\PendingFramebuffer;

class VulkanGfxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // macOS: re-exec CLI once with MoltenVK on DYLD before any sketch output.
        DarwinVulkanLoaderEnv::ensureForCliWindowBoot();

        if ($this->container->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/framebuffers/vulkan.php' => $this->container->configPath('framebuffers/vulkan.php'),
            ], 'tubes-framebuffers-vulkan');

            $this->publishes([
                __DIR__.'/../../config/windows/vulkan.php' => $this->container->configPath('windows/vulkan.php'),
            ], 'tubes-windows-vulkan');
        }

        $this->callAfterResolving('framebuffer', function (BufferFactory $framebuffers): void {
            $framebuffers->extendDeferred(
                'vulkan',
                fn (PendingFramebuffer $pending) => VulkanHandledFramebuffer::sized(
                    $pending->widthValue(),
                    $pending->heightValue(),
                    $pending->hostFormatValue(),
                ),
            );
        });

        $this->callAfterResolving('window', function (WindowFactory $windows): void {
            $windows->extend('vulkan', VulkanWindowHandler::class);
        });
    }
}
