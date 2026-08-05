<?php

namespace ClarionApp\WizlightBackend\Scenes;

/**
 * Readonly value object for a single scene entry in the catalogue.
 */
readonly class SceneDefinition
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $animated,
        public array $classes,
    ) {
    }
}
