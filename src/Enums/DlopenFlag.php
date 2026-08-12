<?php

namespace Microscrap\GFX\Vulkan\Enums;

/**
 * dlfcn.h flags used when ensuring QuartzCore is loaded on Darwin.
 */
enum DlopenFlag: int
{
    case LAZY = 1;
}
