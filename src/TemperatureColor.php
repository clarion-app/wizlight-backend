<?php

namespace ClarionApp\WizlightBackend;

use ClarionApp\WizlightBackend\LightColor;

class TemperatureColor extends LightColor
{
    private int $temp;

    public function __construct(int $temp)
    {
        $this->temp = $temp;
    }

    public function getType(): int
    {
        return self::Temperature;
    }

    public function getValue(): int
    {
        return $this->temp;
    }
}