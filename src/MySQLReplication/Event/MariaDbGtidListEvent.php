<?php
declare(strict_types=1);

namespace MySQLReplication\Event;

use MySQLReplication\Event\DTO\MariaDbGtidListDTO;

class MariaDbGtidListEvent extends EventCommon
{
    public function makeMariaDbGTIDListDTO(): MariaDbGtidListDTO
    {
        $gtid_count = $this->binaryDataReader->readUInt32();
        $gtid_list = [];
        for($i=0;$i < $gtid_count;$i++) {
            $domainId = $this->binaryDataReader->readUInt32();
            $serverId = $this->binaryDataReader->readUInt32();
            $seq = $this->binaryDataReader->readUInt64();
            array_push($gtid_list,$domainId . '-' . $serverId . '-' . $seq);
        }
        $mariaDbGtidList = implode(',', $gtid_list);

        //$this->eventInfo->getBinLogCurrent()->setMariaDbGtid($mariaDbGtid);

        return new MariaDbGtidListDTO(
            $this->eventInfo,
            $mariaDbGtidList
        );
    }
}
