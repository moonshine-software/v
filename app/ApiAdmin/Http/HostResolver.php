<?php

declare(strict_types=1);

namespace App\ApiAdmin\Http;

use RuntimeException;

class HostResolver
{
    /** @return list<string> */
    public function resolve(string $host, int $depth = 0): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if ($depth > 8) {
            throw new RuntimeException('Upstream DNS alias limit exceeded.');
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
        $addresses = [];
        foreach ($records === false ? [] : $records as $record) {
            if (is_string($record['ip'] ?? null)) {
                $addresses[] = $record['ip'];
            } elseif (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            } elseif (is_string($record['target'] ?? null)) {
                $addresses = [...$addresses, ...$this->resolve($record['target'], $depth + 1)];
            }
        }

        if ($addresses === []) {
            throw new RuntimeException('Upstream hostname could not be resolved.');
        }

        return $addresses
            |> array_unique(...)
            |> array_values(...);
    }
}
