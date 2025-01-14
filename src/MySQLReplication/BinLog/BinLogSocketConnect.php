<?php
declare(strict_types=1);

namespace MySQLReplication\BinLog;

use MySQLReplication\BinaryDataReader\BinaryDataReader;
use MySQLReplication\Config\Config;
use MySQLReplication\Gtid\GtidCollection;
use MySQLReplication\Gtid\GtidException;
use MySQLReplication\Repository\RepositoryInterface;
use MySQLReplication\Socket\SocketException;
use MySQLReplication\Socket\SocketInterface;

class BinLogSocketConnect
{
    private const COM_BINLOG_DUMP = 0x12;
    private const COM_REGISTER_SLAVE = 0x15;
    private const COM_BINLOG_DUMP_GTID = 0x1e;

    /**
     * http://dev.mysql.com/doc/internals/en/auth-phase-fast-path.html 00 FE
     */
    private $packageOkHeader = [0, 254];
    private $binaryDataMaxLength = 16777215;
    private $checkSum = false;

    private $repository;
    private $socket;
    private $binLogCurrent;

    /**
     * @var Config
     */
    private $config;
    /**
     * @var BinLogServerInfo
     */
    private $binLogServerInfo;

    /**
     * @throws BinLogException
     * @throws GtidException
     * @throws SocketException
     */
    public function __construct(
        Config $config,
        RepositoryInterface $repository,
        SocketInterface $socket
    ) {
        $this->config = $config;
        $this->repository = $repository;
        $this->socket = $socket;
        $this->binLogCurrent = new BinLogCurrent();
    }

    public function isConnected(): bool
    {
        return $this->socket->isConnected();
    }

    public function connect(): void
    {
        $this->socket->connectToStream($this->config->getHost(), $this->config->getPort());
        $this->binLogServerInfo = BinLogServerInfo::parsePackage(
            $this->getResponse(false),
            $this->repository->getVersion()
        );
        $this->authenticate();
        $this->getBinlogStream();
    }

    public function getBinLogServerInfo(): BinLogServerInfo
    {
        return $this->binLogServerInfo;
    }

    /**
     * @throws BinLogException
     * @throws SocketException
     */
    public function getResponse(bool $checkResponse = true): string
    {
        $header = $this->socket->readFromSocket(4);
        if ('' === $header) {
            return '';
        }
        $dataLength = unpack('L', $header[0] . $header[1] . $header[2] . chr(0))[1];
        $isMaxDataLength = $dataLength === $this->binaryDataMaxLength;

        $result = $this->socket->readFromSocket($dataLength);
        if (true === $checkResponse) {
            $this->isWriteSuccessful($result);
        }

        // https://dev.mysql.com/doc/internals/en/sending-more-than-16mbyte.html
        while ($isMaxDataLength) {
            $header = $this->socket->readFromSocket(4);
            if ('' === $header) {
                return $result;
            }
            $dataLength = unpack('L', $header[0] . $header[1] . $header[2] . chr(0))[1];
            $isMaxDataLength = $dataLength === $this->binaryDataMaxLength;
            $nextResult = $this->socket->readFromSocket($dataLength);
            $result .= $nextResult;
        }

        return $result;
    }

    /**
     * @throws BinLogException
     */
    private function isWriteSuccessful(string $data): void
    {
        $head = ord($data[0]);
        if (! in_array($head, $this->packageOkHeader, true)) {
            $errorCode = unpack('v', $data[1] . $data[2])[1];
            $errorMessage = '';
            $packetLength = strlen($data);
            for ($i = 9; $i < $packetLength; ++$i) {
                $errorMessage .= $data[$i];
            }

            throw new BinLogException($errorMessage, $errorCode);
        }
    }

    /**
     * @throws BinLogException
     * @throws SocketException
     * @link http://dev.mysql.com/doc/internals/en/secure-password-authentication.html#packet-Authentication::Native41
     */
    private function authenticate(): void
    {
        $data = pack('L', self::getCapabilities());
        $data .= pack('L', $this->binaryDataMaxLength);
        $data .= chr(33);
        for ($i = 0; $i < 23; ++$i) {
            $data .= chr(0);
        }
        $result = sha1($this->config->getPassword(), true) ^ sha1(
            $this->binLogServerInfo->getSalt() . sha1(sha1($this->config->getPassword(), true), true),
            true
        );

        $data = $data . $this->config->getUser() . chr(0) . chr(strlen($result)) . $result;
        $str = pack('L', strlen($data));
        $s = $str[0] . $str[1] . $str[2];
        $data = $s . chr(1) . $data;

        $this->socket->writeToSocket($data);
        $this->getResponse();
    }

    /**
     * http://dev.mysql.com/doc/internals/en/capability-flags.html#packet-protocol::capabilityflags
     * https://github.com/siddontang/mixer/blob/master/doc/protocol.txt
     */
    private static function getCapabilities(): int
    {
        $noSchema = 1 << 4;
        $longPassword = 1;
        $longFlag = 1 << 2;
        $transactions = 1 << 13;
        $secureConnection = 1 << 15;
        $protocol41 = 1 << 9;

        return ($longPassword | $longFlag | $transactions | $protocol41 | $secureConnection | $noSchema);
    }

    /**
     * @throws BinLogException
     * @throws GtidException
     * @throws SocketException
     */
    private function getBinlogStream(): void
    {
        $this->checkSum = $this->repository->isCheckSum();
        if ($this->checkSum) {
            $this->execute('SET @master_binlog_checksum = @@global.binlog_checksum');
        }

        if (0 !== $this->config->getHeartbeatPeriod()) {
            // master_heartbeat_period is in nanoseconds
            $this->execute('SET @master_heartbeat_period = ' . $this->config->getHeartbeatPeriod() * 1000000000);
        }

        $this->registerSlave();

        if ('' !== $this->config->getMariaDbGtid()) {
            $this->setBinLogDumpMariaGtid();
        }
        if ('' !== $this->config->getGtid()) {
            $this->setBinLogDumpGtid();
        } else {
            $this->setBinLogDump();
        }
    }

    /**
     * @throws BinLogException
     * @throws SocketException
     */
    private function execute(string $sql): void
    {
        $this->socket->writeToSocket(pack('LC', strlen($sql) + 1, 0x03) . $sql);
        $this->getResponse();
    }

    /**
     * @see https://dev.mysql.com/doc/internals/en/com-register-slave.html
     * @throws BinLogException
     * @throws SocketException
     */
    private function registerSlave(): void
    {
        $host = gethostname();
        $hostLength = strlen($host);
        $userLength = strlen($this->config->getUser());
        $passLength = strlen($this->config->getPassword());

        $data = pack('l', 18 + $hostLength + $userLength + $passLength);
        $data .= chr(self::COM_REGISTER_SLAVE);
        $data .= pack('V', $this->config->getSlaveId());
        $data .= pack('C', $hostLength);
        $data .= $host;
        $data .= pack('C', $userLength);
        $data .= $this->config->getUser();
        $data .= pack('C', $passLength);
        $data .= $this->config->getPassword();
        $data .= pack('v', $this->config->getPort());
        $data .= pack('V', 0);
        $data .= pack('V', 0);

        $this->socket->writeToSocket($data);
        $this->getResponse();
    }

    /**
     * @throws SocketException
     * @throws BinLogException
     */
    private function setBinLogDumpMariaGtid(): void
    {
        $this->execute('SET @mariadb_slave_capability = 4');
        $this->execute('SET @slave_connect_state = \'' . $this->config->getMariaDbGtid() . '\'');
        $this->execute('SET @slave_gtid_strict_mode = 0');
        $this->execute('SET @slave_gtid_ignore_duplicates = 0');

        $this->binLogCurrent->setMariaDbGtid($this->config->getMariaDbGtid());
    }

    /**
     * @see https://dev.mysql.com/doc/internals/en/com-binlog-dump-gtid.html
     * @throws BinLogException
     * @throws GtidException
     * @throws SocketException
     */
    private function setBinLogDumpGtid(): void
    {
        $collection = GtidCollection::makeCollectionFromString($this->config->getGtid());

        $data = pack('l', 26 + $collection->getEncodedLength()) . chr(self::COM_BINLOG_DUMP_GTID);
        $data .= pack('S', 0);
        $data .= pack('I', $this->config->getSlaveId());
        $data .= pack('I', 3);
        $data .= chr(0);
        $data .= chr(0);
        $data .= chr(0);
        $data .= BinaryDataReader::pack64bit(4);
        $data .= pack('I', $collection->getEncodedLength());
        $data .= $collection->getEncoded();

        $this->socket->writeToSocket($data);
        $this->getResponse();

        $this->binLogCurrent->setGtid($this->config->getGtid());
    }

    /**
     * @see https://dev.mysql.com/doc/internals/en/com-binlog-dump.html
     * @throws BinLogException
     * @throws SocketException
     */
    private function setBinLogDump(): void
    {
        $binLogCurrent = $this->binLogCurrent;
        $binFilePos = $binLogCurrent->getBinLogPosition() ?: $this->config->getBinLogPosition();
        $binFileName = $binLogCurrent->getBinFileName() ?: $this->config->getBinLogFileName();
        if (0 === $binFilePos && '' === $binFileName) {
            $masterStatusDTO = $this->repository->getMasterStatus();
            $binFilePos = $masterStatusDTO->getPosition();
            $binFileName = $masterStatusDTO->getFile();
        }

        $data = pack('i', strlen($binFileName) + 11) . chr(self::COM_BINLOG_DUMP);
        $data .= pack('I', $binFilePos);
        $data .= pack('v', 0);
        $data .= pack('I', $this->config->getSlaveId());
        $data .= $binFileName;

        $this->socket->writeToSocket($data);
        $this->getResponse();

        $binLogCurrent->setBinLogPosition($binFilePos);
        $binLogCurrent->setBinFileName($binFileName);
    }

    public function getBinLogCurrent(): BinLogCurrent
    {
        return $this->binLogCurrent;
    }

    public function getCheckSum(): bool
    {
        return $this->checkSum;
    }
}
