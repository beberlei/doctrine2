<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use BackedEnum;
use ReflectionProperty;
use ReturnTypeWillChange;

class ReflectionEnumProperty extends ReflectionProperty
{
    /** @var ReflectionProperty */
    private $originalReflectionProperty;

    /** @var class-string<BackedEnum> */
    private $enumType;

    /**
     * @param class-string<BackedEnum> $enumType
     */
    public function __construct(ReflectionProperty $originalReflectionProperty, string $enumType)
    {
        $this->originalReflectionProperty = $originalReflectionProperty;
        $this->enumType                   = $enumType;
    }

    /**
     * {@inheritDoc}
     *
     * @param mixed $object
     *
     * @return int|string|null
     */
    #[ReturnTypeWillChange]
    public function getValue($object = null)
    {
        $enum = $this->originalReflectionProperty->getValue($object);

        if ($enum === null) {
            return null;
        }

        return $enum->value;
    }

    /**
     * @param mixed $object
     * @param mixed $value
     *
     * @return void
     */
    #[ReturnTypeWillChange]
    public function setValue($object, $value = null)
    {
        if ($value !== null) {
            $enumType = $this->enumType;
            $value    = $enumType::from($value);
        }

        $this->originalReflectionProperty->setValue($object, $value);
    }
}
