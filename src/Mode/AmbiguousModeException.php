<?php

namespace ClarionApp\WizlightBackend\Mode;

/**
 * Thrown when a request changes two or more mode-owned field groups
 * without specifying which mode applies.
 */
class AmbiguousModeException extends \RuntimeException
{
    /**
     * Human-readable labels for the mode constants, matching the frontend's
     * ModeSwitch segments and contracts/mode-and-scene-api.md's example.
     */
    private const LABELS = [
        'rgb' => 'colour',
        'warmth' => 'warmth',
        'white_channels' => 'white channels',
        'scene' => 'scene',
    ];

    public function __construct(
        public readonly array $changedGroups,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $names = self::joinWithAnd(array_map(
            fn (string $group) => self::LABELS[$group] ?? $group,
            $changedGroups
        ));
        $msg = $message ?: sprintf(
            'Request changes both %s; send active_mode to say which applies',
            $names
        );
        parent::__construct($msg, $code, $previous);
    }

    private static function joinWithAnd(array $labels): string
    {
        if (count($labels) <= 1) {
            return implode('', $labels);
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ' and ' . $last;
    }
}
