<?php

namespace MySQLReplication\Util;

use function get_object_vars;

/**
 * Provide implementation of {@see JsonSerializable::jsonSerialize()} which dumps all properties (public or private).
 */
trait JsonSerializableTrait
{
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
