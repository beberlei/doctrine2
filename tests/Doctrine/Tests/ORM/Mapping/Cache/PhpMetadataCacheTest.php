<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping\Cache;

use Doctrine\Common\Cache\Psr6\CacheItem;
use Doctrine\ORM\Mapping\Cache\PhpMetadataCache;
use Doctrine\Tests\OrmFunctionalTestCase;

use function array_map;
use function array_merge;
use function str_replace;
use function sys_get_temp_dir;

class PhpMetadataCacheTest extends OrmFunctionalTestCase
{
    /** @var PhpMetadataCache */
    private $cache;

    public function setUp(): void
    {
        $this->cache = new PhpMetadataCache(sys_get_temp_dir() . '/dcmetatest');

        parent::setUp();
    }

    public function testLoadedMetadataEqualUncached(): void
    {
        $classes   = array_merge(self::$modelSets['cms'], self::$modelSets['company'], self::$modelSets['ddc117'], self::$modelSets['cache']);
        $metadatas = array_map(function (string $className) {
            return $this->_em->getClassMetadata($className);
        }, $classes);

        foreach ($metadatas as $metadata) {
            $key = str_replace('\\', '.', $metadata->name);
            $this->cache->save(new CacheItem($key, $metadata, false));

            $cached             = $this->cache->getItem($key)->get();
            $cached->reflClass  = $metadata->reflClass;
            $cached->reflFields = $metadata->reflFields;

            $this->assertEquals($metadata, $cached);
        }
    }

    public function tearDown(): void
    {
        $this->cache->clear();

        parent::tearDown();
    }
}
