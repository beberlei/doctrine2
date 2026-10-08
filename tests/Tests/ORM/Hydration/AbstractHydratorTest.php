<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Hydration;

use BackedEnum;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Tests\Models\Enums\AccessLevel;
use Doctrine\Tests\Models\Enums\UserStatus;
use Doctrine\Tests\Models\Hydration\SimpleEntity;
use Doctrine\Tests\OrmFunctionalTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;

use function iterator_to_array;

#[CoversClass(AbstractHydrator::class)]
class AbstractHydratorTest extends OrmFunctionalTestCase
{
    private EventManager $eventManager;
    private Result&Stub $mockResult;
    private ResultSetMapping&Stub $mockResultMapping;
    private DummyHydrator $hydrator;

    protected function setUp(): void
    {
        parent::setUp();

        $stubConnection             = $this->createStub(Connection::class);
        $stubEntityManagerInterface = $this->createStub(EntityManagerInterface::class);
        $this->eventManager         = new EventManager();
        $this->mockResult           = $this->createStub(Result::class);
        $this->mockResultMapping    = $this->createStub(ResultSetMapping::class);

        $stubConnection
            ->method('getDatabasePlatform')
            ->willReturn($this->createStub(AbstractPlatform::class));
        $stubEntityManagerInterface
            ->method('getEventManager')
            ->willReturn($this->eventManager);
        $stubEntityManagerInterface
            ->method('getConnection')
            ->willReturn($stubConnection);
        $this->mockResult
            ->method('fetchAssociative')
            ->willReturn(false);

        $this->hydrator = new DummyHydrator($stubEntityManagerInterface);
    }

    /**
     * Verify that the number of added events to the event listener from the abstract hydrator class is equal to the
     * number of removed events
     */
    #[Group('DDC-3146')]
    #[Group('#1515')]
    public function testOnClearEventListenerIsDetachedOnCleanup(): void
    {
        $iterator = $this->hydrator->toIterable($this->mockResult, $this->mockResultMapping);
        iterator_to_array($iterator);
        self::assertTrue($this->hydrator->hasListener);
        self::assertFalse($this->eventManager->hasListeners(Events::onClear));
    }

    #[Group('#6623')]
    public function testHydrateAllRegistersAndClearsAllAttachedListeners(): void
    {
        $this->hydrator->hydrateAll($this->mockResult, $this->mockResultMapping);
        self::assertTrue($this->hydrator->hasListener);
        self::assertFalse($this->eventManager->hasListeners(Events::onClear));
    }

    #[Group('#8482')]
    public function testHydrateAllClearsAllAttachedListenersEvenOnError(): void
    {
        $this->hydrator->throwException = true;

        $this->expectException(LogicException::class);
        $this->hydrator->hydrateAll($this->mockResult, $this->mockResultMapping);
        self::assertTrue($this->hydrator->hasListener);
        self::assertFalse($this->eventManager->hasListeners(Events::onClear));
    }

    public function testHydrateAllReturnsRowResultsAndResetsState(): void
    {
        $hydrator = new RowStateHydrator($this->_em);

        $result = $hydrator->hydrateAll($this->createRowsResult([['v' => 1], ['v' => 2]]), $this->mockResultMapping);

        self::assertSame([1, 2], $result);
        self::assertSame(RowStateHydrator::EMPTY_STATE, $hydrator->state());
    }

    public function testToIterableYieldsEachRowResultAndResetsState(): void
    {
        $hydrator = new RowStateHydrator($this->_em);
        $states   = [];

        foreach ($hydrator->toIterable($this->createRowsResult([['v' => 1], ['v' => 2]]), $this->mockResultMapping) as $value) {
            $states[$value] = $hydrator->state();
        }

        self::assertSame([
            1 => ['result' => [], 'idTemplate' => ['a' => ''], 'rowId' => ['a' => '|1'], 'nonemptyComponents' => ['a' => true]],
            2 => ['result' => [], 'idTemplate' => ['a' => ''], 'rowId' => ['a' => '|2'], 'nonemptyComponents' => ['a' => true]],
        ], $states);
        self::assertSame(RowStateHydrator::EMPTY_STATE, $hydrator->state());
    }

    public function testHydrateAllResetsStateEvenOnError(): void
    {
        $hydrator = new RowStateHydrator($this->_em);

        try {
            $hydrator->hydrateAll($this->createRowsResult([['v' => 1], ['v' => 'fail']]), $this->mockResultMapping);
            self::fail('Expected LogicException');
        } catch (LogicException) {
        }

        self::assertSame(RowStateHydrator::EMPTY_STATE, $hydrator->state());
    }

    /** @param list<array<string, mixed>> $rows */
    private function createRowsResult(array $rows): Result&Stub
    {
        $result = $this->createStub(Result::class);
        $result
            ->method('fetchAssociative')
            ->willReturn(...[...$rows, false]);

        return $result;
    }

    public function testEnumCastsIntegerBackedEnumValues(): void
    {
        $accessLevel = $this->hydrator->buildEnumForTesting('2', AccessLevel::class);
        $userStatus  = $this->hydrator->buildEnumForTesting('active', UserStatus::class);

        self::assertSame(AccessLevel::User, $accessLevel);
        self::assertSame(UserStatus::Active, $userStatus);
    }

    public function testEnumCastsIntegerBackedEnumArrayValues(): void
    {
        $accessLevels = $this->hydrator->buildEnumForTesting(['1', '2'], AccessLevel::class);
        $userStatus   = $this->hydrator->buildEnumForTesting(['active', 'inactive'], UserStatus::class);

        self::assertSame([AccessLevel::Admin, AccessLevel::User], $accessLevels);
        self::assertSame([UserStatus::Active, UserStatus::Inactive], $userStatus);
    }

    public function testToIterableIfYieldAndBreakBeforeFinishAlwaysCleansUp(): void
    {
        $this->setUpEntitySchema([SimpleEntity::class]);

        $entity1 = new SimpleEntity();
        $this->_em->persist($entity1);
        $entity2 = new SimpleEntity();
        $this->_em->persist($entity2);

        $this->_em->flush();
        $this->_em->clear();

        $evm = $this->_em->getEventManager();

        $q = $this->_em->createQuery('SELECT e.id FROM ' . SimpleEntity::class . ' e');

        // select two entities, but do no iterate
        $q->toIterable();
        self::assertCount(0, $evm->getListeners(Events::onClear));

        // select two entities, but abort after first record
        foreach ($q->toIterable() as $result) {
            self::assertCount(1, $evm->getListeners(Events::onClear));
            break;
        }

        self::assertCount(0, $evm->getListeners(Events::onClear));
    }
}

class DummyHydrator extends AbstractHydrator
{
    public bool $throwException = false;
    public bool $hasListener    = false;

    public function buildEnumForTesting(mixed $value, string $enumType): BackedEnum|array
    {
        return $this->buildEnum($value, $enumType);
    }

    /** @return array{} */
    protected function hydrateAllData(): array
    {
        if ($this->throwException) {
            throw new LogicException();
        }

        return [];
    }

    public function prepare(): void
    {
        $this->hasListener = $this->em->getEventManager()->hasListeners(Events::onClear);
    }
}

class RowStateHydrator extends AbstractHydrator
{
    public const array EMPTY_STATE = ['result' => [], 'idTemplate' => [], 'rowId' => [], 'nonemptyComponents' => []];

    /** @return array{result: mixed[], idTemplate: array<string, string>, rowId: array<string, string>, nonemptyComponents: array<string, bool>} */
    public function state(): array
    {
        return [
            'result' => $this->result,
            'idTemplate' => $this->idTemplate,
            'rowId' => $this->rowId,
            'nonemptyComponents' => $this->nonemptyComponents,
        ];
    }

    protected function prepare(): void
    {
        $this->idTemplate = ['a' => ''];
    }

    /** @return mixed[] */
    protected function hydrateAllData(): array
    {
        while ($row = $this->statement()->fetchAssociative()) {
            $this->hydrateRowData($row);
        }

        return $this->takeResult();
    }

    /** @param mixed[] $row */
    protected function hydrateRowData(array $row): void
    {
        if ($row['v'] === 'fail') {
            throw new LogicException();
        }

        $this->rowId              = ['a' => $this->idTemplate['a'] . '|' . $row['v']];
        $this->nonemptyComponents = ['a' => true];
        $this->result[]           = $row['v'];
    }
}
