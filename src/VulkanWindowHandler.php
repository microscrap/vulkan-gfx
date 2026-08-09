<?php

namespace Microscrap\GFX\Vulkan;

use Microscrap\Bindings\GLFW\DataObjects\GlfwWindow;
use Microscrap\Bindings\GLFW\Enums\ClientApi;
use Microscrap\Bindings\GLFW\Enums\TrueFalse;
use Microscrap\Bindings\GLFW\Enums\WindowHint;
use Microscrap\Bindings\GLFW\Error;
use Microscrap\Bindings\GLFW\Init;
use Microscrap\Bindings\GLFW\Vulkan as GlfwVulkan;
use Microscrap\Bindings\GLFW\Window;
use Microscrap\Bindings\Vulkan\DataObjects\VkDevice;
use Microscrap\Bindings\Vulkan\DataObjects\VkInstance;
use Microscrap\Bindings\Vulkan\DataObjects\VkPhysicalDevice;
use Microscrap\Bindings\Vulkan\DataObjects\VkQueue;
use Microscrap\Bindings\Vulkan\DataObjects\VkSurface;
use Microscrap\Bindings\Vulkan\DataObjects\VkSwapchain;
use Microscrap\Bindings\Vulkan\Enums\VkResult;
use Microscrap\Bindings\Vulkan\Vk;
use ScrapyardIO\Tubes\Contracts\Framebuffers\DeferredFramebuffer;
use ScrapyardIO\Tubes\Contracts\Framebuffers\FormatSpec;
use ScrapyardIO\Tubes\Windows\WindowException;
use ScrapyardIO\Tubes\Windows\WindowHandler;

/**
 * Vulkan OS window driver for tubes {@see \ScrapyardIO\Tubes\Canvas\OSWindow}.
 *
 * FormatSpec matches {@see VulkanHandledFramebuffer::rgbaSpec()}.
 * Present path: CPU shadow → {@see Vk::presentFrame()} (no PHP flush).
 * Event pump: {@see pollNative()} owns glfwPollEvents, then refreshes {@see VulkanInputHandler}.
 */
class VulkanWindowHandler extends WindowHandler
{
    protected static bool $glfw_initialized = false;

    protected ?GlfwWindow $native_window = null;

    protected bool $owns_window = false;

    protected VulkanInputHandler $input_handler;

    protected ?VkInstance $instance = null;

    protected ?VkPhysicalDevice $physical = null;

    protected ?VkDevice $device = null;

    protected ?VkQueue $queue = null;

    protected ?VkSurface $surface = null;

    protected ?VkSwapchain $swapchain = null;

    public function __construct(string $title, int $width, int $height)
    {
        parent::__construct($title, $width, $height);
        $this->input_handler = new VulkanInputHandler;
    }

    protected function defineFormatSpec(): FormatSpec
    {
        return VulkanHandledFramebuffer::rgbaSpec();
    }

    protected function bootNative(): void
    {
        if (! extension_loaded('glfw')) {
            throw new WindowException('Required PHP extension [glfw] is not loaded.');
        }

        if (! extension_loaded('vulkan')) {
            throw new WindowException('Required PHP extension [vulkan] is not loaded.');
        }

        DarwinVulkanLoaderEnv::ensureForCliWindowBoot();
        DarwinVulkanLoaderEnv::ensureIcdFilenames();

        if (! static::$glfw_initialized) {
            if (! Init::init()) {
                $error = Error::getError();

                throw new WindowException(
                    'glfwInit failed for VulkanWindowHandler'
                    .((string) ($error['description'] ?? '') !== '' ? ': '.$error['description'] : ''),
                );
            }
            static::$glfw_initialized = true;
        }

        $extensions = $this->requiredInstanceExtensions();
        if ($extensions === []) {
            throw new WindowException(
                'No Vulkan instance extensions available for GLFW window surfaces.'
                .(PHP_OS_FAMILY === 'Darwin'
                    ? ' On macOS install MoltenVK and set VK_ICD_FILENAMES (and often DYLD_LIBRARY_PATH=/opt/homebrew/lib).'
                    : '')
            );
        }

        $instance = Vk::createInstance($extensions, 'microscrap/vulkan-gfx');
        if (! $instance->isValid()) {
            throw new WindowException('vkCreateInstance failed: '.Vk::lastError());
        }

        $devices = Vk::enumeratePhysicalDevices($instance);
        $physical = $devices[0] ?? null;
        if (is_null($physical) || ! $physical->isValid()) {
            Vk::destroyInstance($instance);

            throw new WindowException('No Vulkan physical devices available for VulkanWindowHandler.');
        }

        Window::defaultWindowHints();
        Window::windowHint(WindowHint::GLFW_CLIENT_API, ClientApi::GLFW_NO_API->value);
        Window::windowHint(WindowHint::GLFW_VISIBLE, TrueFalse::GLFW_TRUE->value);
        Window::windowHint(WindowHint::GLFW_RESIZABLE, TrueFalse::GLFW_TRUE->value);

        $window = Window::createWindow($this->width, $this->height, $this->title);
        if (is_null($window)) {
            $error = Error::getError();
            Vk::destroyInstance($instance);

            throw new WindowException(
                "Could not create a GLFW NO_API window ({$this->width}x{$this->height})"
                .((string) ($error['description'] ?? '') !== '' ? ': '.$error['description'] : ''),
            );
        }

        $surf = Vk::createWindowSurface($instance, $window);
        if (($surf['result'] ?? VkResult::ERROR_UNKNOWN->value) !== VkResult::SUCCESS->value
            || ! $surf['surface']->isValid()) {
            Window::destroyWindow($window);
            Vk::destroyInstance($instance);

            throw new WindowException(
                'vkCreateWindowSurface failed: result='.((string) ($surf['result'] ?? '?')).' '.Vk::lastError()
                .(PHP_OS_FAMILY === 'Darwin'
                    ? ' On macOS export DYLD_LIBRARY_PATH=/opt/homebrew/lib (MoltenVK) before starting PHP.'
                    : '')
            );
        }
        $surface = $surf['surface'];

        $queueFamily = Vk::findGraphicsPresentQueue($physical, $surface);
        if ($queueFamily < 0) {
            Vk::destroySurface($instance, $surface);
            Window::destroyWindow($window);
            Vk::destroyInstance($instance);

            throw new WindowException('No graphics+present queue family for the Vulkan surface.');
        }

        $device = Vk::createDevice($physical, $queueFamily);
        if (! $device->isValid()) {
            Vk::destroySurface($instance, $surface);
            Window::destroyWindow($window);
            Vk::destroyInstance($instance);

            throw new WindowException('vkCreateDevice failed: '.Vk::lastError());
        }

        $queue = Vk::getDeviceQueue($device, $queueFamily);
        $fb = Window::getFramebufferSize($window);
        $fbW = max(1, (int) ($fb['width'] ?? $this->width));
        $fbH = max(1, (int) ($fb['height'] ?? $this->height));

        $swapchain = Vk::createSwapchain($instance, $physical, $device, $queue, $surface, $fbW, $fbH);
        if (! $swapchain->isValid()) {
            Vk::destroyDevice($device);
            Vk::destroySurface($instance, $surface);
            Window::destroyWindow($window);
            Vk::destroyInstance($instance);

            throw new WindowException('vkCreateSwapchain failed: '.Vk::lastError());
        }

        $this->native_window = $window;
        $this->owns_window = true;
        $this->instance = $instance;
        $this->physical = $physical;
        $this->device = $device;
        $this->queue = $queue;
        $this->surface = $surface;
        $this->swapchain = $swapchain;
        $this->input_handler->attach($window);
    }

    protected function bindFramebuffer(): DeferredFramebuffer
    {
        if (is_null($this->native_window) || is_null($this->swapchain)) {
            throw new WindowException('VulkanWindowHandler has no native window/swapchain for bindFramebuffer().');
        }

        return VulkanHandledFramebuffer::attachedTo(
            $this->native_window,
            $this->formatSpec(),
            $this->width(),
            $this->height(),
            $this->swapchain,
        );
    }

    protected function presentNative(): void
    {
        $framebuffer = $this->framebuffer();
        if (! $framebuffer instanceof VulkanHandledFramebuffer) {
            throw new WindowException('VulkanWindowHandler expected a VulkanHandledFramebuffer.');
        }

        $framebuffer->present();
    }

    protected function pollNative(): void
    {
        Window::pollEvents();
        $this->input_handler->poll();
    }

    public function shouldClose(): bool
    {
        if (is_null($this->native_window)) {
            return true;
        }

        return Window::windowShouldClose($this->native_window);
    }

    /**
     * Drop the attached framebuffer before tearing down WSI / GLFW.
     */
    public function close(): static
    {
        if (! $this->opened) {
            return $this;
        }

        $this->framebuffer = null;
        $this->destroyNative();
        $this->opened = false;

        return $this;
    }

    protected function destroyNative(): void
    {
        $this->input_handler->detach();

        if (! is_null($this->swapchain) && $this->swapchain->isValid()) {
            Vk::destroySwapchain($this->swapchain);
        }
        $this->swapchain = null;

        if (! is_null($this->device) && $this->device->isValid()) {
            Vk::destroyDevice($this->device);
        }
        $this->device = null;
        $this->queue = null;
        $this->physical = null;

        if (! is_null($this->instance) && ! is_null($this->surface) && $this->surface->isValid()) {
            Vk::destroySurface($this->instance, $this->surface);
        }
        $this->surface = null;

        if ($this->owns_window && ! is_null($this->native_window)) {
            Window::destroyWindow($this->native_window);
        }
        $this->native_window = null;
        $this->owns_window = false;

        if (! is_null($this->instance) && $this->instance->isValid()) {
            Vk::destroyInstance($this->instance);
        }
        $this->instance = null;
        // Do not glfwTerminate — other handlers / headless paths may still need GLFW.
    }

    public function glfwWindow(): ?GlfwWindow
    {
        return $this->native_window;
    }

    public function inputHandler(): VulkanInputHandler
    {
        return $this->input_handler;
    }

    public function vkSwapchain(): ?VkSwapchain
    {
        return $this->swapchain;
    }

    /**
     * @return list<string>
     */
    protected function requiredInstanceExtensions(): array
    {
        if (GlfwVulkan::vulkanSupported()) {
            /** @var list<string> $extensions */
            $extensions = GlfwVulkan::getRequiredInstanceExtensions();

            return $extensions;
        }

        // glfwVulkanSupported() often stays false on Herd/macOS unless DYLD_LIBRARY_PATH
        // was set at process start. createInstance still works with the Metal surface pair.
        if (PHP_OS_FAMILY === 'Darwin') {
            return ['VK_KHR_surface', 'VK_EXT_metal_surface'];
        }

        return [];
    }
}
