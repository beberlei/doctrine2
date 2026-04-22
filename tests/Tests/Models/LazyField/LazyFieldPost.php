<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\LazyField;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'lazy_field_post')]
class LazyFieldPost
{
    #[Id]
    #[GeneratedValue]
    #[Column]
    public int $id;

    #[Column]
    public string $title;

    #[Column(lazy: true)]
    public string $body;
}
