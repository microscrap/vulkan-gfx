<?php

use Microscrap\GFX\Vulkan\VulkanInputHandler;
use Microscrap\GFX\Vulkan\VulkanWindowHandler;
use ScrapyardIO\Tubes\HumanInput\EngineInput;
use ScrapyardIO\Tubes\HumanInput\Keyboard;
use ScrapyardIO\Tubes\HumanInput\Mouse;
use ScrapyardIO\Tubes\Inputs\InputHandler;

test('VulkanInputHandler extends tubes InputHandler and starts empty', function () {
    $handler = new VulkanInputHandler;

    expect($handler)->toBeInstanceOf(InputHandler::class)
        ->and($handler->window())->toBeNull()
        ->and($handler->keyboard())->toBeNull()
        ->and($handler->mouse())->toBeNull()
        ->and($handler->gamePads())->toBe([])
        ->and($handler->gameControllers())->toBe([]);

    $handler->poll();

    expect($handler->keyboard())->toBeNull()
        ->and($handler->mouse())->toBeNull();
});

test('VulkanWindowHandler owns a VulkanInputHandler before open', function () {
    $window = new VulkanWindowHandler('hi', 64, 48);

    expect($window->inputHandler())->toBeInstanceOf(VulkanInputHandler::class)
        ->and($window->inputHandler()->window())->toBeNull();
});

test('pollNative refreshes HumanInput devices after glfwPollEvents', function () {
    if (! extension_loaded('vulkan') || ! extension_loaded('glfw')) {
        $this->markTestSkipped('ext-vulkan and ext-glfw are required');
    }

    $window = new VulkanWindowHandler('vulkan-input', 64, 48);
    $window->open();

    $input = $window->inputHandler();
    expect($input->window())->toBe($window->glfwWindow())
        ->and($input->keyboard())->toBeNull()
        ->and($input->mouse())->toBeNull();

    $window->pollEvents();

    expect($input->keyboard())->toBeInstanceOf(Keyboard::class)
        ->and($input->mouse())->toBeInstanceOf(Mouse::class)
        ->and($input->mouse()->buttons())->toHaveCount(5)
        ->and($input->mouse()->isPressed('left'))->toBeFalse()
        ->and(array_key_exists('a', $input->keyboard()->keys()))->toBeTrue()
        ->and($input->keyboard()->isDown('a'))->toBeFalse()
        ->and($input->gamePads())->toBeArray()
        ->and($input->gameControllers())->toBeArray();

    $engine = new EngineInput($input);
    $engine->poll();

    expect($engine->keyboard())->toBe($input->keyboard())
        ->and($engine->mouse())->toBe($input->mouse());

    $window->close();

    expect($input->window())->toBeNull()
        ->and($input->keyboard())->toBeNull()
        ->and($input->mouse())->toBeNull();
});
