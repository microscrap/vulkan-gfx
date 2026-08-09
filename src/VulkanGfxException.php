<?php

namespace Microscrap\GFX\Vulkan;

use ScrapyardIO\Tubes\Contracts\Framebuffers\FramebufferException;

class VulkanGfxException extends FramebufferException
{
    public static function instanceCreationFailed(string $detail = ''): static
    {
        $suffix = $detail !== '' ? " ({$detail})" : '';

        return new static(
            'Could not create a VkInstance for a headless VulkanHandledFramebuffer.'.$suffix
        );
    }
}
