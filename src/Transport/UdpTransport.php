<?php

namespace ClarionApp\WizlightBackend\Transport;

interface UdpTransport
{
    public function send(mixed $message, array $targets): void;

    public function receive(float $timeout): ?array;

    public function close(): void;
}
