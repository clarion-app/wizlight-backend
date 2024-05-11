<?php

namespace ClarionApp\WizlightBackend;

use ClarionApp\WizlightBackend\LightColor;

class RGBColor extends LightColor
{
    private int $r;
    private int $g;
    private int $b;

    public function __construct(int $r, int $g, int $b)
    {
        $this->r = $r;
        $this->g = $g;
        $this->b = $b;
    }

    public function getType(): int
    {
        return self::RGB;
    }

    public function getValue(): array
    {
        return [$this->r, $this->g, $this->b];
    }
}