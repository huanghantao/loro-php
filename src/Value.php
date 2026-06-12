<?php

declare(strict_types=1);

namespace Loro;

final class ValueBox extends LoroValueLike
{
    public function __construct(private LoroValue $value) {}

    public function asLoroValue(): LoroValue
    {
        return $this->value;
    }
}

final class BinaryValue
{
    public function __construct(public string $bytes) {}
}

final class Value
{
    public static function like(mixed $value): LoroValueLike
    {
        return $value instanceof LoroValueLike
            ? $value
            : new ValueBox(self::toLoroValue($value));
    }

    public static function toLoroValue(mixed $value): LoroValue
    {
        if ($value instanceof LoroValueLike) {
            return $value->asLoroValue();
        }

        if ($value instanceof LoroValue) {
            return $value;
        }

        if ($value instanceof BinaryValue) {
            return LoroValue::binary($value->bytes);
        }

        if ($value instanceof ContainerId) {
            return LoroValue::container($value);
        }

        if ($containerId = self::containerIdFromObject($value)) {
            return LoroValue::container($containerId);
        }

        return match (true) {
            $value === null => LoroValue::null(),
            is_bool($value) => LoroValue::bool($value),
            is_int($value) => LoroValue::i64($value),
            is_float($value) => LoroValue::double($value),
            is_string($value) => LoroValue::string($value),
            is_array($value) => self::arrayToLoroValue($value),
            default => throw new \InvalidArgumentException(
                'Cannot convert ' . get_debug_type($value) . ' to LoroValue'
            ),
        };
    }

    public static function null(): LoroValue
    {
        return LoroValue::null();
    }

    public static function bool(bool $value): LoroValue
    {
        return LoroValue::bool($value);
    }

    public static function double(float $value): LoroValue
    {
        return LoroValue::double($value);
    }

    public static function int(int $value): LoroValue
    {
        return LoroValue::i64($value);
    }

    public static function binary(string $bytes): LoroValue
    {
        return LoroValue::binary($bytes);
    }

    public static function string(string $value): LoroValue
    {
        return LoroValue::string($value);
    }

    public static function list(array $value): LoroValue
    {
        return LoroValue::list_(array_map(self::toLoroValue(...), $value));
    }

    public static function map(array $value): LoroValue
    {
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = self::toLoroValue($item);
        }

        return LoroValue::map($map);
    }

    public static function container(ContainerId $value): LoroValue
    {
        return LoroValue::container($value);
    }

    private static function arrayToLoroValue(array $value): LoroValue
    {
        return array_is_list($value)
            ? self::list($value)
            : self::map($value);
    }

    private static function containerIdFromObject(mixed $value): ?ContainerId
    {
        return match (true) {
            $value instanceof LoroText,
            $value instanceof LoroMap,
            $value instanceof LoroList,
            $value instanceof LoroMovableList,
            $value instanceof LoroTree,
            $value instanceof LoroCounter,
            $value instanceof LoroUnknown => $value->id(),
            default => null,
        };
    }
}
