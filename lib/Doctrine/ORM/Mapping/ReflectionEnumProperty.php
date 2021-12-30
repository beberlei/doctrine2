<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use ReflectionProperty;
use ReturnTypeWillChange;
use UnitEnum;

class ReflectionEnumProperty extends ReflectionProperty
{
    /** @var ReflectionProperty */
    private $originalReflectionProperty;

    /** @var class-string<UnitEnum> */
    private $enumType;

    public function __construct(ReflectionProperty $originalReflectionProperty, string $enumType)
    {
        $this->originalReflectionProperty = $originalReflectionProperty;
        $this->enumType                   = $enumType;
    }

    /**
     * {@inheritDoc}
     *
     * @return mixed
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
