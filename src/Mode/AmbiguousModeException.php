<?php

namespace ClarionApp\WizlightBackend\Mode;

/**
 * Thrown when a request changes two or more mode-owned field groups
 * without specifying which mode applies.
 */
class AmbiguousModeException extends \RuntimeException
{
    public function __construct(
        public readonly array $changedGroups,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $names = implode(', ', $changedGroups);
        $msg = $message ?: sprintf(
            'Request changes both %s; send active_mode to say which applies',
            $names
        );
        parent::__construct($msg, $code, $previous);
    }
}
