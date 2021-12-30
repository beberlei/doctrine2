<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Cache;

use Doctrine\Common\Cache\Psr6\CacheItem;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use PackageVersions\Versions;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;

use function array_map;
use function dirname;
use function file_exists;
use function file_put_contents;
use function filemtime;
use function get_debug_type;
use function in_array;
use function is_dir;
use function mkdir;
use function opcache_invalidate;
use function scandir;
use function serialize;
use function sprintf;
use function str_replace;
use function strpos;
use function unlink;
use function var_export;

class PhpMetadataCache implements CacheItemPoolInterface
{
    /** @var string */
    private $cacheDir;

    /** @var bool */
    private $debug;

    /** @var string */
    private $prefix;

    public function __construct(string $cacheDir, bool $debug = false, string $prefix = '')
    {
        $this->cacheDir = $cacheDir;
        $this->debug    = $debug;
        $this->prefix   = $prefix;
    }

    /** @return CacheItem */
    public function getItem(string $key)
    {
        $file = $this->convertKeyToFilename($key);

        if (! file_exists($file)) {
            return new CacheItem($key, null, false);
        }

        [$value, $version] = require $file;

        if (! ($value instanceof ClassMetadataInfo)) {
            return new CacheItem($key, null, false);
        }

        if ($version !== Versions::getVersion('doctrine/orm')) {
            return new CacheItem($key, null, false);
        }

        if ($this->debug) {
            $reflClass = new ReflectionClass($value->name);

            if (filemtime($file) < filemtime($reflClass->getFilename())) {
                return new CacheItem($key, null, false);
            }
        }

        return new CacheItem($key, $value, true);
    }

    /**
     * @param array<string> $keys
     *
     * @return array<CacheItem>|\Traversable<CacheItem>
     */
    public function getItems(array $keys = [])
    {
        return array_map(function (string $key) {
            return $this->getItem($key);
        }, $keys);
    }

    /** @return bool */
    public function hasItem(string $key)
    {
        $file = $this->convertKeyToFilename($key);

        return file_exists($file);
    }

    /** @return bool */
    public function save(CacheItemInterface $item)
    {
        $file = $this->convertKeyToFilename($item->getKey());
        $data = $item->get();

        if (! is_dir($this->cacheDir) && is_dir(dirname($this->cacheDir))) {
            @mkdir($this->cacheDir, 0755);
        }

        if (! ($data instanceof ClassMetadataInfo)) {
            throw new \InvalidArgumentException(sprintf(
                'PhpMetadataCache only works for Doctrine\ORM\Mapping\ClassMetadataInfo instances, %s given.',
                get_debug_type($data)
            ));
        }

        $class                  = new \ReflectionClass(ClassMetadataInfo::class);
        $namingStrategyProperty = $class->getProperty('namingStrategy');
        $namingStrategyProperty->setAccessible(true);

        $namingStrategy = serialize($namingStrategyProperty->getValue($data));

        $content = "<?php\n\n\$classMetadata = new \Doctrine\ORM\Mapping\ClassMetadata('" . $data->name . "', unserialize('" . $namingStrategy . "'));\n";

        $skipProperties      = ['reflClass', 'reflFields', 'namingStrategy'];
        $serializeProperties = ['idGenerator', 'instantiator'];

        foreach ($class->getProperties() as $property) {
            $key = $property->getName();
            $property->setAccessible(true);
            $value = $property->getValue($data);

            if (in_array($key, $serializeProperties)) {
                $content .= sprintf("\$classMetadata->%s = unserialize('%s');\n", $key, serialize($value));
            } elseif (in_array($key, $skipProperties)) {
                continue;
            } else {
                $content .= sprintf("\$classMetadata->%s = %s;\n", $key, var_export($value, true));
            }
        }

        $content .= "return array(\$classMetadata, '" . Versions::getVersion('doctrine/orm') . "');";

        file_put_contents($file, $content);
        opcache_invalidate($file);

        return true;
    }

    private function convertKeyToFilename(string $key): string
    {
        $fileName = str_replace(['\\', '$'], ['.', ''], $key);

        $this->assertValidFilePath($fileName);

        return $this->cacheDir . '/' . $this->prefix . $fileName . '.php';
    }

    private function assertValidFilePath(string $id): void
    {
        if (strpos($id, '..') !== false) {
            throw new \RuntimeException('Invalid file path given, contains double dots.');
        }
    }

    /** @return bool */
    public function clear()
    {
        $dir = $this->cacheDir;

        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                if ($this->prefix && strpos($file, $this->prefix) !== 0) {
                    continue;
                }

                unlink($dir . '/' . $file);
            }
        }

        return true;
    }

    /** @return bool */
    public function deleteItem(string $key)
    {
        $file = $this->convertKeyToFilename($key);

        @unlink($file);

        return true;
    }

    /**
     * @param array<string> $keys
     *
     * @return bool
     */
    public function deleteItems(array $keys)
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    /** @return bool */
    public function saveDeferred(CacheItemInterface $item)
    {
        return true;
    }

    /** @return bool */
    public function commit()
    {
        return true;
    }
}
