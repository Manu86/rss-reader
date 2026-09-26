<?php

declare(strict_types=1);

namespace App\Security;

final class IpAddressValidator
{
    /** @var list<string> */
    private const BLOCKED_IPV4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** @var list<string> */
    private const BLOCKED_IPV6 = [
        '::/96',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '100::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    public function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        $blockedRanges = strlen($packed) === 4 ? self::BLOCKED_IPV4 : self::BLOCKED_IPV6;
        foreach ($blockedRanges as $range) {
            if ($this->belongsToCidr($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    private function belongsToCidr(string $packedAddress, string $cidr): bool
    {
        [$network, $prefixText] = explode('/', $cidr, 2);
        $packedNetwork = inet_pton($network);
        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedAddress)) {
            return false;
        }
        $prefix = (int) $prefixText;
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0
            && substr($packedAddress, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($packedAddress[$wholeBytes]) & $mask)
            === (ord($packedNetwork[$wholeBytes]) & $mask);
    }
}
