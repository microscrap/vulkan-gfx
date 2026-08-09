<?php

namespace Microscrap\GFX\Vulkan;

use Microscrap\Bindings\GLFW\DataObjects\GlfwWindow;
use Microscrap\Bindings\GLFW\Enums\Action;
use Microscrap\Bindings\GLFW\Enums\GamepadButton;
use Microscrap\Bindings\GLFW\Enums\Joystick;
use Microscrap\Bindings\GLFW\Enums\Key;
use Microscrap\Bindings\GLFW\Input;
use ScrapyardIO\Tubes\HumanInput\AnalogButton;
use ScrapyardIO\Tubes\HumanInput\AnalogStick;
use ScrapyardIO\Tubes\HumanInput\DigitalButton;
use ScrapyardIO\Tubes\HumanInput\Enums\MouseButton;
use ScrapyardIO\Tubes\HumanInput\GameController;
use ScrapyardIO\Tubes\HumanInput\GamePad;
use ScrapyardIO\Tubes\HumanInput\Keyboard;
use ScrapyardIO\Tubes\HumanInput\Mouse;
use ScrapyardIO\Tubes\Inputs\InputHandler;

/**
 * Vulkan / GLFW human-input companion for tubes {@see \ScrapyardIO\Tubes\HumanInput\EngineInput}.
 *
 * Does not own the event pump — {@see VulkanWindowHandler::pollNative()} calls
 * {@see \Microscrap\Bindings\GLFW\Window::pollEvents()} first, then {@see poll()}.
 */
class VulkanInputHandler extends InputHandler
{
    protected ?GlfwWindow $window = null;

    protected float $pending_wheel_delta = 0.0;

    public function attach(GlfwWindow $window): static
    {
        if (! is_null($this->window) && $this->window !== $window) {
            $this->detach();
        }

        $this->window = $window;
        $this->pending_wheel_delta = 0.0;

        Input::setScrollCallback($window, function (mixed $_w, float $_xoffset, float $yoffset): void {
            $this->pending_wheel_delta += $yoffset;
        });

        return $this;
    }

    public function detach(): static
    {
        if (! is_null($this->window)) {
            Input::setScrollCallback($this->window, null);
        }

        $this->window = null;
        $this->pending_wheel_delta = 0.0;
        $this->keyboard = null;
        $this->mouse = null;
        $this->game_pads = [];
        $this->game_controllers = [];

        return $this;
    }

    public function window(): ?GlfwWindow
    {
        return $this->window;
    }

    public function poll(): static
    {
        if (is_null($this->window)) {
            $this->keyboard = null;
            $this->mouse = null;
            $this->game_pads = [];
            $this->game_controllers = [];

            return $this;
        }

        $this->keyboard = $this->snapshotKeyboard($this->window);
        $this->mouse = $this->snapshotMouse($this->window);
        [$this->game_pads, $this->game_controllers] = $this->snapshotJoysticks();

        return $this;
    }

    protected function snapshotKeyboard(GlfwWindow $window): Keyboard
    {
        $keys = [];

        foreach (Key::cases() as $key) {
            // Skip aliases / sentinels (GLFW enums reuse int values; do not compare cases).
            if (in_array($key->name, ['GLFW_KEY_UNKNOWN', 'GLFW_KEY_LAST'], true)) {
                continue;
            }

            $name = $this->controlName($key->name, 'GLFW_KEY_');
            if ($name === '' || array_key_exists($name, $keys)) {
                continue;
            }

            $state = Input::getKey($window, $key->value);
            $keys[$name] = $state === Action::GLFW_PRESS->value || $state === Action::GLFW_REPEAT->value;
        }

        return new Keyboard($keys);
    }

    protected function snapshotMouse(GlfwWindow $window): Mouse
    {
        $pos = Input::getCursorPos($window);
        $x = (float) ($pos['xpos'] ?? 0.0);
        $y = (float) ($pos['ypos'] ?? 0.0);

        $buttons = [];
        foreach ($this->mouseButtonMap() as $glfwButton => $tubesButton) {
            $state = Input::getMouseButton($window, $glfwButton);
            $buttons[] = new DigitalButton(
                $tubesButton->value,
                $state === Action::GLFW_PRESS->value,
            );
        }

        $wheel = $this->pending_wheel_delta;
        $this->pending_wheel_delta = 0.0;

        return new Mouse($x, $y, $buttons, $wheel);
    }

    /**
     * @return array{0: list<GamePad>, 1: list<GameController>}
     */
    protected function snapshotJoysticks(): array
    {
        $pads = [];
        $controllers = [];

        $seen_jids = [];

        foreach (Joystick::cases() as $joystick) {
            if ($joystick->name === 'GLFW_JOYSTICK_LAST') {
                continue;
            }

            $jid = $joystick->value;
            if (isset($seen_jids[$jid]) || ! Input::joystickPresent($jid)) {
                continue;
            }
            $seen_jids[$jid] = true;

            $name = Input::getJoystickName($jid);
            if ($name === '') {
                $name = 'joystick-'.$jid;
            }

            if (Input::joystickIsGamepad($jid)) {
                $controller = $this->snapshotGamepad($jid, $name);
                if (! is_null($controller)) {
                    $controllers[] = $controller;
                }

                continue;
            }

            $pads[] = $this->snapshotRawPad($jid, $name);
        }

        return [$pads, $controllers];
    }

    protected function snapshotGamepad(int $jid, string $name): ?GameController
    {
        $state = Input::getGamepadState($jid);
        $buttons = $state['buttons'] ?? null;
        $axes = $state['axes'] ?? null;
        if (! is_array($buttons) || ! is_array($axes)) {
            return null;
        }

        // Axis indices from glfw3.h — avoid Enum::CASE on duplicate-backed GLFW enums (PHP 8.4).
        $controls = [
            new AnalogStick(
                'left',
                $this->clampAxis((float) ($axes[0] ?? 0.0)),
                $this->clampAxis((float) ($axes[1] ?? 0.0)),
            ),
            new AnalogStick(
                'right',
                $this->clampAxis((float) ($axes[2] ?? 0.0)),
                $this->clampAxis((float) ($axes[3] ?? 0.0)),
            ),
            new AnalogButton(
                'left_trigger',
                $this->triggerToUnit((float) ($axes[4] ?? -1.0)),
            ),
            new AnalogButton(
                'right_trigger',
                $this->triggerToUnit((float) ($axes[5] ?? -1.0)),
            ),
        ];

        $seen_buttons = [];
        foreach (GamepadButton::cases() as $button) {
            if (in_array($button->name, [
                'GLFW_GAMEPAD_BUTTON_LAST',
                'GLFW_GAMEPAD_BUTTON_CROSS',
                'GLFW_GAMEPAD_BUTTON_CIRCLE',
                'GLFW_GAMEPAD_BUTTON_SQUARE',
                'GLFW_GAMEPAD_BUTTON_TRIANGLE',
            ], true)) {
                continue;
            }

            $index = $button->value;
            if (isset($seen_buttons[$index])) {
                continue;
            }
            $seen_buttons[$index] = true;

            $pressed = ((int) ($buttons[$index] ?? Action::GLFW_RELEASE->value)) === Action::GLFW_PRESS->value;
            $controls[] = new DigitalButton($this->controlName($button->name, 'GLFW_GAMEPAD_BUTTON_'), $pressed);
        }

        return new GameController($name, $controls);
    }

    protected function snapshotRawPad(int $jid, string $name): GamePad
    {
        $raw = Input::getJoystickButtons($jid);
        $controls = [];

        foreach ($raw as $index => $state) {
            $controls[] = new DigitalButton(
                'button_'.$index,
                ((int) $state) === Action::GLFW_PRESS->value,
            );
        }

        return new GamePad($name, $controls);
    }

    /**
     * GLFW mouse button ints (glfw3.h) → tubes MouseButton.
     * Do not touch Microscrap GLFW MouseButton cases — that enum has duplicate values.
     *
     * @return array<int, MouseButton>
     */
    protected function mouseButtonMap(): array
    {
        return [
            0 => MouseButton::LEFT,
            1 => MouseButton::RIGHT,
            2 => MouseButton::MIDDLE,
            3 => MouseButton::X1,
            4 => MouseButton::X2,
        ];
    }

    protected function controlName(string $enum_name, string $prefix): string
    {
        if (! str_starts_with($enum_name, $prefix)) {
            return strtolower($enum_name);
        }

        return strtolower(substr($enum_name, strlen($prefix)));
    }

    protected function clampAxis(float $value): float
    {
        return max(-1.0, min(1.0, $value));
    }

    /**
     * GLFW gamepad triggers are typically -1 (released) … 1 (fully pressed).
     */
    protected function triggerToUnit(float $value): float
    {
        return max(0.0, min(1.0, ($value + 1.0) / 2.0));
    }
}
