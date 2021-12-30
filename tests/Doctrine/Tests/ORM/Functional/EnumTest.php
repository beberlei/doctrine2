<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\Models\Enums\Card;
use Doctrine\Tests\Models\Enums\Suit;
use Doctrine\Tests\OrmFunctionalTestCase;

use const PHP_VERSION_ID;

class EnumTest extends OrmFunctionalTestCase
{
    public function setUp(): void
    {
        if (PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Enum support requires PHP 8.1+');
        }

        parent::setUp();

        $this->setUpEntitySchema([
            Card::class,
        ]);
    }

    public function testEnumMapping(): void
    {
        $card       = new Card();
        $card->suit = Suit::Clubs;

        $this->_em->persist($card);
        $this->_em->flush();
        $this->_em->clear();

        $fetchedCard = $this->_em->find(Card::class, $card->id);

        $this->assertInstanceOf(Suit::class, $fetchedCard->suit);
        $this->assertEquals(Suit::Clubs, $fetchedCard->suit);
    }
}
