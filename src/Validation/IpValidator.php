<?php

namespace ClarionApp\WizlightBackend\Validation;

class IpValidator
{
    public static function isPrivateIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        // RFC 1918: 10.0.0.0/8
        if (str_starts_with($ip, '10.')) {
            return true;
        }

        // RFC 1918: 172.16.0.0/12
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip)) {
            return true;
        }

        // RFC 1918: 192.168.0.0/16
        if (str_starts_with($ip, '192.168.')) {
            return true;
        }

        return false;
    }
}
