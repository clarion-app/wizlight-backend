<?php

namespace ClarionApp\WizlightBackend;

abstract class LightColor
{
    public const RGB = 1;
    public const Temperature = 2;

    abstract public function getType(): int;
}