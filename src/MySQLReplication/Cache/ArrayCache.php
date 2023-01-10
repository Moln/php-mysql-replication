<?php
declare(strict_types=1);

namespace MySQLReplication\Cache;

use Composer\InstalledVersions;
use Psr\SimpleCache\CacheInterface;

// phpcs:ignoreFile
if (class_exists(InstalledVersions::class) && version_compare(InstalledVersions::getVersion("psr/simple-cache"), '3.0.0', ">=")) {
    class ArrayCache implements CacheInterface
    {
        use ArrayCacheTrait;

        /**
         * @inheritDoc
         */
        public function get(string $key, $default = null): mixed
        {
            return $this->has($key) ? $this->tableMapCache[$key] : $default;
        }

        /**
         * @inheritDoc
         */
        public function getMultiple(iterable $keys, $default = null): iterable
        {
            $data = [];
            foreach ($keys as $key) {
                if ($this->has($key)) {
                    $data[$key] = $this->tableMapCache[$key];
                }
            }

            return [] !== $data ? $data : $default;
        }
    }
} else {
    class ArrayCache implements CacheInterface
    {
        use ArrayCacheTrait;

        /**
         * @inheritDoc
         */
        public function get($key, $default = null)
        {
            return $this->has($key) ? $this->tableMapCache[$key] : $default;
        }

        /**
         * @inheritDoc
         */
        public function getMultiple($keys, $default = null)
        {
            $data = [];
            foreach ($keys as $key) {
                if ($this->has($key)) {
                    $data[$key] = $this->tableMapCache[$key];
                }
            }

            return [] !== $data ? $data : $default;
        }
    }
}

trait ArrayCacheTrait
{
    private $tableMapCache = [];

    /**
     * @var int
     */
    private $tableCacheSize;

    public function __construct(int $tableCacheSize = 128)
    {
        $this->tableCacheSize = $tableCacheSize;
    }


    /**
     * @inheritDoc
     */
    public function has($key): bool
    {
        return isset($this->tableMapCache[$key]);
    }

    /**
     * @inheritDoc
     */
    public function clear(): bool
    {
        $this->tableMapCache = [];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function set($key, $value, $ttl = null): bool
    {
        // automatically clear table cache to save memory
        if (count($this->tableMapCache) > $this->tableCacheSize) {
            $this->tableMapCache = array_slice(
                $this->tableMapCache,
                (int)($this->tableCacheSize / 2),
                null,
                true
            );
        }

        $this->tableMapCache[$key] = $value;

        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete($key): bool
    {
        unset($this->tableMapCache[$key]);

        return true;
    }
}
