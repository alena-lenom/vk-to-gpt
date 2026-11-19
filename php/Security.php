<?php
declare(strict_types=1);

namespace VkToGpt;

function authorized(mixed $configured, mixed $header): bool
{
    // RFC 6750 bearer syntax; reject empty, whitespace and malformed configuration.
    return is_string($configured) && preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $configured) === 1
        && is_string($header) && preg_match('/^Bearer ([A-Za-z0-9._~+\/-]+=*)$/iD', $header, $match) === 1
        && hash_equals($configured, $match[1]);
}
