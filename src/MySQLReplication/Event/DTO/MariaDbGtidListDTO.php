<?php
declare(strict_types=1);

namespace MySQLReplication\Event\DTO;

use MySQLReplication\Definitions\ConstEventsNames;
use MySQLReplication\Event\EventInfo;

class MariaDbGtidListDTO extends EventDTO
{
    private $type = ConstEventsNames::MARIADB_GTID_LIST;
    private $mariaDbGtidList;

    public function __construct(
        EventInfo $eventInfo,
        string $mariaDbGtidList
    ) {
        parent::__construct($eventInfo);

        $this->mariaDbGtidList = $mariaDbGtidList;
    }

    public function __toString(): string
    {
        return PHP_EOL .
            '=== Event ' . $this->getType() . ' === ' . PHP_EOL .
            'Date: ' . $this->eventInfo->getDateTime() . PHP_EOL .
            'Log position: ' . $this->eventInfo->getPos() . PHP_EOL .
            'Event size: ' . $this->eventInfo->getSize() . PHP_EOL .
            'Global Transaction ID: ' . $this->mariaDbGtidList . PHP_EOL;
    }


    public function getType(): string
    {
        return $this->type;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return get_object_vars($this);
    }

    public function getMariaDbGtidList(): string
    {
        return $this->mariaDbGtidList;
    }

}
