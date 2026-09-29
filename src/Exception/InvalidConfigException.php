<?php

declare(strict_types=1);

namespace Qinii\WechatCore\Exception;

use InvalidArgumentException;

/**
 * Thrown when resolved configuration does not match the expected shape.
 */
final class InvalidConfigException extends InvalidArgumentException
{
    public static function expectedArray(
        string $source,
        string $name,
        mixed $value,
    ): self {
        return new self(sprintf(
            'The %s for "%s" must be an array<string, mixed>; %s given.',
            $source,
            $name,
            get_debug_type($value),
        ));
    }
}
