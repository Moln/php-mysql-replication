<?php
declare(strict_types=1);

namespace MySQLReplication\Event\DTO;

use MySQLReplication\Definitions\ConstEventsNames;
use MySQLReplication\Util\JsonSerializableTrait;

class FormatDescriptionEventDTO extends EventDTO
{
    use JsonSerializableTrait;

    private $type = ConstEventsNames::FORMAT_DESCRIPTION;

    public function getType(): string
    {
        return $this->type;
    }

    public function __toString(): string
    {
        return PHP_EOL .
            '=== Event ' . $this->getType() . ' === ' . PHP_EOL .
            'Date: ' . $this->eventInfo->getDateTime() . PHP_EOL .
            'Log position: ' . $this->eventInfo->getPos() . PHP_EOL .
            'Event size: ' . $this->eventInfo->getSize() . PHP_EOL;
    }
}
