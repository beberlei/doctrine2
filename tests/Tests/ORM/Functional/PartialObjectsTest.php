<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmFunctionalTestCase;

use function array_keys;
use function var_dump;

class PartialObjectsTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('cms');

        parent::setUp();
    }

    public function testPartialObjectProxyLoadedChangeset(): void
    {
        $user           = new CmsUser();
        $user->name     = 'Alice';
        $user->username = 'alice';
        $user->status   = 'developer';

        $address          = new CmsAddress();
        $address->country = 'Germany';
        $address->city    = 'Berlin';
        $address->zip     = '12345';

        $user->address = $address; // inverse side
        $address->user = $user; // owning side!

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $dql  = 'SELECT PARTIAL u.{id, name} FROM ' . CmsUser::class . ' u WHERE u.username = ?1';
        $user = $this->_em->createQuery($dql)->setParameter(1, 'alice')->getSingleResult();

        var_dump(array_keys($this->_em->getUnitOfWork()->getOriginalEntityData($user)));

        $user->name     = 'Bob';
        $user->username = 'bob';

        var_dump(array_keys($this->_em->getUnitOfWork()->getOriginalEntityData($user)));
        $this->assertEquals('Bob', $user->name);
    }
}
