<?php

namespace Microscrap\GFX\Vulkan;

use ScrapyardIO\Tubes\Contracts\Framebuffers\DeferredFramebuffer;
use ScrapyardIO\Tubes\Contracts\Rendering\ProvisionsHeadlessFramebuffer;
use ScrapyardIO\Tubes\Rendering\Concerns\DrawsText;
use ScrapyardIO\Tubes\Rendering\Renderer2D;

/**
 * Vulkan DrawingAPI — writes into a borrowed tubes Framebuffer (typically
 * {@see VulkanHandledFramebuffer}). Whole-surface {@see fill()} clears the CPU
 * shadow (and present clear_color when windowed); primitives go through
 * setPixel / setSegment. Windowed present uses Vk::presentFrame.
 *
 * Text uses tubes {@see DrawsText} (ClassicFont + GFXFont registry via setFont).
 * PanelIC: {@see provisionHeadlessFramebuffer()} — present flushes to IC FormatSpec.
 */
class VulkanRenderer2D extends Renderer2D implements ProvisionsHeadlessFramebuffer
{
    use DrawsText;

    public function provisionHeadlessFramebuffer(int $width, int $height): DeferredFramebuffer
    {
        return VulkanHandledFramebuffer::sized($width, $height, VulkanHandledFramebuffer::rgbaSpec());
    }

    public function drawPixel(int $x, int $y, int $color): static
    {
        $this->framebuffer()->setPixel($x, $y, $color);

        return $this;
    }

    public function drawPixels(array $pixels): static
    {
        $this->framebuffer()->setPixels($pixels);

        return $this;
    }

    public function drawSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        if ($width <= 0 || $height <= 0) {
            return $this;
        }

        $this->framebuffer()->setSegment($x, $y, $width, $height, $color);

        return $this;
    }

    public function drawHorizontalLine(int $x, int $y, int $w, int $color): static
    {
        if ($w <= 0) {
            return $this;
        }

        return $this->drawSegment($x, $y, $w, 1, $color);
    }

    public function drawVerticalLine(int $x, int $y, int $h, int $color): static
    {
        if ($h <= 0) {
            return $this;
        }

        return $this->drawSegment($x, $y, 1, $h, $color);
    }

    public function drawLine(int $x0, int $y0, int $x1, int $y1, int $color): static
    {
        if ($x0 === $x1) {
            $top = min($y0, $y1);

            return $this->drawVerticalLine($x0, $top, abs($y1 - $y0) + 1, $color);
        }

        if ($y0 === $y1) {
            $left = min($x0, $x1);

            return $this->drawHorizontalLine($left, $y0, abs($x1 - $x0) + 1, $color);
        }

        $steep = abs($y1 - $y0) > abs($x1 - $x0);
        if ($steep) {
            [$x0, $y0] = [$y0, $x0];
            [$x1, $y1] = [$y1, $x1];
        }
        if ($x0 > $x1) {
            [$x0, $x1] = [$x1, $x0];
            [$y0, $y1] = [$y1, $y0];
        }

        $dx = $x1 - $x0;
        $dy = abs($y1 - $y0);
        $err = intdiv($dx, 2);
        $y_step = $y0 < $y1 ? 1 : -1;
        $y = $y0;

        for ($x = $x0; $x <= $x1; $x++) {
            if ($steep) {
                $this->drawPixel($y, $x, $color);
            } else {
                $this->drawPixel($x, $y, $color);
            }
            $err -= $dy;
            if ($err < 0) {
                $y += $y_step;
                $err += $dx;
            }
        }

        return $this;
    }

    public function drawLines(array $lines): static
    {
        foreach ($lines as $line) {
            [$x0, $y0, $x1, $y1, $color] = $line;
            $this->drawLine($x0, $y0, $x1, $y1, $color);
        }

        return $this;
    }

    public function drawRect(int $x, int $y, int $w, int $h, int $color): static
    {
        if ($w <= 0 || $h <= 0) {
            return $this;
        }

        $this->drawHorizontalLine($x, $y, $w, $color);
        if ($h > 1) {
            $this->drawHorizontalLine($x, $y + $h - 1, $w, $color);
        }
        $this->drawVerticalLine($x, $y, $h, $color);
        if ($w > 1) {
            $this->drawVerticalLine($x + $w - 1, $y, $h, $color);
        }

        return $this;
    }

    public function fill(int $color): static
    {
        // VulkanHandledFramebuffer::fill → CPU shadow (+ present clear_color).
        $this->framebuffer()->fill($color);

        return $this;
    }

    public function fillRect(int $x, int $y, int $w, int $h, int $color): static
    {
        return $this->drawSegment($x, $y, $w, $h, $color);
    }

    public function drawRoundRect(int $x, int $y, int $w, int $h, int $r, int $color): static
    {
        if ($w <= 0 || $h <= 0) {
            return $this;
        }

        $r = max(0, min($r, intdiv(min($w, $h), 2)));
        if ($r === 0) {
            return $this->drawRect($x, $y, $w, $h, $color);
        }

        $this->drawHorizontalLine($x + $r, $y, $w - 2 * $r, $color);
        $this->drawHorizontalLine($x + $r, $y + $h - 1, $w - 2 * $r, $color);
        $this->drawVerticalLine($x, $y + $r, $h - 2 * $r, $color);
        $this->drawVerticalLine($x + $w - 1, $y + $r, $h - 2 * $r, $color);

        $this->drawCircleQuadrant($x + $r, $y + $r, $r, $color, true, false, false, true);
        $this->drawCircleQuadrant($x + $w - 1 - $r, $y + $r, $r, $color, false, true, false, true);
        $this->drawCircleQuadrant($x + $r, $y + $h - 1 - $r, $r, $color, true, false, true, false);
        $this->drawCircleQuadrant($x + $w - 1 - $r, $y + $h - 1 - $r, $r, $color, false, true, true, false);

        return $this;
    }

    public function fillRoundRect(int $x, int $y, int $w, int $h, int $r, int $color): static
    {
        if ($w <= 0 || $h <= 0) {
            return $this;
        }

        $r = max(0, min($r, intdiv(min($w, $h), 2)));
        if ($r === 0) {
            return $this->fillRect($x, $y, $w, $h, $color);
        }

        $this->fillRect($x + $r, $y, $w - 2 * $r, $h, $color);
        $this->fillRect($x, $y + $r, $r, $h - 2 * $r, $color);
        $this->fillRect($x + $w - $r, $y + $r, $r, $h - 2 * $r, $color);

        $this->fillCircleQuadrant($x + $r, $y + $r, $r, $color, true, false, false, true);
        $this->fillCircleQuadrant($x + $w - 1 - $r, $y + $r, $r, $color, false, true, false, true);
        $this->fillCircleQuadrant($x + $r, $y + $h - 1 - $r, $r, $color, true, false, true, false);
        $this->fillCircleQuadrant($x + $w - 1 - $r, $y + $h - 1 - $r, $r, $color, false, true, true, false);

        return $this;
    }

    public function drawCircle(int $x0, int $y0, int $r, int $color): static
    {
        if ($r < 0) {
            return $this;
        }
        if ($r === 0) {
            return $this->drawPixel($x0, $y0, $color);
        }

        $draw = function () use ($x0, $y0, $r, $color): void {
            $x = 0;
            $y = $r;
            $d = 3 - 2 * $r;

            while ($y >= $x) {
                $this->drawPixel($x0 + $x, $y0 + $y, $color);
                $this->drawPixel($x0 + $y, $y0 + $x, $color);
                $this->drawPixel($x0 - $x, $y0 + $y, $color);
                $this->drawPixel($x0 - $y, $y0 + $x, $color);
                $this->drawPixel($x0 + $x, $y0 - $y, $color);
                $this->drawPixel($x0 + $y, $y0 - $x, $color);
                $this->drawPixel($x0 - $x, $y0 - $y, $color);
                $this->drawPixel($x0 - $y, $y0 - $x, $color);

                $x++;
                if ($d > 0) {
                    $y--;
                    $d = $d + 4 * ($x - $y) + 10;
                } else {
                    $d = $d + 4 * $x + 6;
                }
            }
        };

        $fb = $this->framebuffer();
        if ($fb instanceof VulkanHandledFramebuffer) {
            $fb->deferDirty($draw);
        } else {
            $draw();
        }

        return $this;
    }

    public function fillCircle(int $x0, int $y0, int $r, int $color): static
    {
        if ($r < 0) {
            return $this;
        }
        if ($r === 0) {
            return $this->drawPixel($x0, $y0, $color);
        }

        $draw = function () use ($x0, $y0, $r, $color): void {
            $x = 0;
            $y = $r;
            $d = 3 - 2 * $r;

            while ($y >= $x) {
                $this->drawHorizontalLine($x0 - $x, $y0 + $y, $x * 2 + 1, $color);
                $this->drawHorizontalLine($x0 - $x, $y0 - $y, $x * 2 + 1, $color);
                $this->drawHorizontalLine($x0 - $y, $y0 + $x, $y * 2 + 1, $color);
                $this->drawHorizontalLine($x0 - $y, $y0 - $x, $y * 2 + 1, $color);

                $x++;
                if ($d > 0) {
                    $y--;
                    $d = $d + 4 * ($x - $y) + 10;
                } else {
                    $d = $d + 4 * $x + 6;
                }
            }
        };

        // One dirty bbox for the circle — not one rect per Bresenham hline.
        $fb = $this->framebuffer();
        if ($fb instanceof VulkanHandledFramebuffer) {
            $fb->deferDirty($draw);
        } else {
            $draw();
        }

        return $this;
    }

    public function drawEllipse(int $x0, int $y0, int $rw, int $rh, int $color): static
    {
        if ($rw < 0 || $rh < 0) {
            return $this;
        }
        if ($rw === 0 && $rh === 0) {
            return $this->drawPixel($x0, $y0, $color);
        }
        if ($rw === 0) {
            return $this->drawVerticalLine($x0, $y0 - $rh, $rh * 2 + 1, $color);
        }
        if ($rh === 0) {
            return $this->drawHorizontalLine($x0 - $rw, $y0, $rw * 2 + 1, $color);
        }

        $a2 = $rw * $rw;
        $b2 = $rh * $rh;
        $x = 0;
        $y = $rh;
        $px = 0;
        $py = 2 * $a2 * $y;
        $p = (int) round($b2 - ($a2 * $rh) + (0.25 * $a2));

        $this->plotEllipsePoints($x0, $y0, $x, $y, $color);
        while ($px < $py) {
            $x++;
            $px += 2 * $b2;
            if ($p < 0) {
                $p += $b2 + $px;
            } else {
                $y--;
                $py -= 2 * $a2;
                $p += $b2 + $px - $py;
            }
            $this->plotEllipsePoints($x0, $y0, $x, $y, $color);
        }

        $p = (int) round($b2 * ($x + 0.5) * ($x + 0.5) + $a2 * ($y - 1) * ($y - 1) - $a2 * $b2);
        while ($y > 0) {
            $y--;
            $py -= 2 * $a2;
            if ($p > 0) {
                $p += $a2 - $py;
            } else {
                $x++;
                $px += 2 * $b2;
                $p += $a2 - $py + $px;
            }
            $this->plotEllipsePoints($x0, $y0, $x, $y, $color);
        }

        return $this;
    }

    public function fillEllipse(int $x0, int $y0, int $rw, int $rh, int $color): static
    {
        if ($rw < 0 || $rh < 0) {
            return $this;
        }
        if ($rw === 0 && $rh === 0) {
            return $this->drawPixel($x0, $y0, $color);
        }
        if ($rw === 0) {
            return $this->drawVerticalLine($x0, $y0 - $rh, $rh * 2 + 1, $color);
        }
        if ($rh === 0) {
            return $this->drawHorizontalLine($x0 - $rw, $y0, $rw * 2 + 1, $color);
        }

        for ($y = -$rh; $y <= $rh; $y++) {
            $yy = $y * $y;
            $span = (int) floor($rw * sqrt(max(0.0, 1.0 - ($yy / (float) ($rh * $rh)))));
            $this->drawHorizontalLine($x0 - $span, $y0 + $y, $span * 2 + 1, $color);
        }

        return $this;
    }

    public function drawTriangle(int $x0, int $y0, int $x1, int $y1, int $x2, int $y2, int $color): static
    {
        return $this->drawLine($x0, $y0, $x1, $y1, $color)
            ->drawLine($x1, $y1, $x2, $y2, $color)
            ->drawLine($x2, $y2, $x0, $y0, $color);
    }

    public function fillTriangle(int $x0, int $y0, int $x1, int $y1, int $x2, int $y2, int $color): static
    {
        // Sort vertices by y.
        if ($y0 > $y1) {
            [$x0, $x1] = [$x1, $x0];
            [$y0, $y1] = [$y1, $y0];
        }
        if ($y1 > $y2) {
            [$x1, $x2] = [$x2, $x1];
            [$y1, $y2] = [$y2, $y1];
        }
        if ($y0 > $y1) {
            [$x0, $x1] = [$x1, $x0];
            [$y0, $y1] = [$y1, $y0];
        }

        if ($y0 === $y2) {
            $min_x = min($x0, $x1, $x2);
            $max_x = max($x0, $x1, $x2);

            return $this->drawHorizontalLine($min_x, $y0, $max_x - $min_x + 1, $color);
        }

        $total_height = $y2 - $y0;
        for ($i = 0; $i <= $total_height; $i++) {
            $second_half = ($i > ($y1 - $y0)) || ($y1 === $y0);
            $segment_height = $second_half ? ($y2 - $y1) : ($y1 - $y0);
            if ($segment_height === 0) {
                continue;
            }
            $alpha = $i / $total_height;
            $beta = $second_half
                ? (($i - ($y1 - $y0)) / $segment_height)
                : ($i / $segment_height);
            $ax = (int) round($x0 + ($x2 - $x0) * $alpha);
            $bx = $second_half
                ? (int) round($x1 + ($x2 - $x1) * $beta)
                : (int) round($x0 + ($x1 - $x0) * $beta);
            if ($ax > $bx) {
                [$ax, $bx] = [$bx, $ax];
            }
            $this->drawHorizontalLine($ax, $y0 + $i, $bx - $ax + 1, $color);
        }

        return $this;
    }

    protected function plotEllipsePoints(int $x0, int $y0, int $x, int $y, int $color): void
    {
        $this->drawPixel($x0 + $x, $y0 + $y, $color);
        $this->drawPixel($x0 - $x, $y0 + $y, $color);
        $this->drawPixel($x0 + $x, $y0 - $y, $color);
        $this->drawPixel($x0 - $x, $y0 - $y, $color);
    }

    protected function drawCircleQuadrant(
        int $cx,
        int $cy,
        int $radius,
        int $color,
        bool $left,
        bool $right,
        bool $bottom,
        bool $top,
    ): void {
        $x = 0;
        $y = $radius;
        $d = 3 - 2 * $radius;

        while ($y >= $x) {
            if ($right && $bottom) {
                $this->drawPixel($cx + $x, $cy + $y, $color);
                $this->drawPixel($cx + $y, $cy + $x, $color);
            }
            if ($left && $bottom) {
                $this->drawPixel($cx - $x, $cy + $y, $color);
                $this->drawPixel($cx - $y, $cy + $x, $color);
            }
            if ($right && $top) {
                $this->drawPixel($cx + $x, $cy - $y, $color);
                $this->drawPixel($cx + $y, $cy - $x, $color);
            }
            if ($left && $top) {
                $this->drawPixel($cx - $x, $cy - $y, $color);
                $this->drawPixel($cx - $y, $cy - $x, $color);
            }

            $x++;
            if ($d > 0) {
                $y--;
                $d = $d + 4 * ($x - $y) + 10;
            } else {
                $d = $d + 4 * $x + 6;
            }
        }
    }

    protected function fillCircleQuadrant(
        int $cx,
        int $cy,
        int $radius,
        int $color,
        bool $left,
        bool $right,
        bool $bottom,
        bool $top,
    ): void {
        $x = 0;
        $y = $radius;
        $d = 3 - 2 * $radius;

        while ($y >= $x) {
            if ($bottom) {
                if ($right) {
                    $this->drawHorizontalLine($cx, $cy + $y, $x + 1, $color);
                    $this->drawHorizontalLine($cx, $cy + $x, $y + 1, $color);
                }
                if ($left) {
                    $this->drawHorizontalLine($cx - $x, $cy + $y, $x + 1, $color);
                    $this->drawHorizontalLine($cx - $y, $cy + $x, $y + 1, $color);
                }
            }
            if ($top) {
                if ($right) {
                    $this->drawHorizontalLine($cx, $cy - $y, $x + 1, $color);
                    $this->drawHorizontalLine($cx, $cy - $x, $y + 1, $color);
                }
                if ($left) {
                    $this->drawHorizontalLine($cx - $x, $cy - $y, $x + 1, $color);
                    $this->drawHorizontalLine($cx - $y, $cy - $x, $y + 1, $color);
                }
            }

            $x++;
            if ($d > 0) {
                $y--;
                $d = $d + 4 * ($x - $y) + 10;
            } else {
                $d = $d + 4 * $x + 6;
            }
        }
    }
}
