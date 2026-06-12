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

    public static function toPhp(LoroValue|ValueOrContainer $value): mixed
    {
        if ($value instanceof ValueOrContainer) {
            return self::valueOrContainerToPhp($value);
        }

        return match ($value->variant) {
            'Null' => null,
            'Bool', 'String' => $value->fields['value'],
            'Double' => $value->fields['value'],
            'I64' => (int) $value->fields['value'],
            'Binary' => new BinaryValue($value->fields['value']),
            'List' => array_map(self::toPhp(...), $value->fields['value']),
            'Map' => self::mapToPhp($value->fields['value']),
            'Container' => $value->fields['value'],
            default => throw new \UnexpectedValueException(
                'Cannot convert LoroValue variant ' . $value->variant . ' to PHP'
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

    private static function mapToPhp(array $value): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            $map[$key] = self::toPhp($item);
        }

        return $map;
    }

    private static function valueOrContainerToPhp(ValueOrContainer $value): mixed
    {
        if (($loroValue = $value->asValue()) !== null) {
            return self::toPhp($loroValue);
        }

        if (($text = $value->asLoroText()) !== null) {
            return $text->slice(0, $text->lenUnicode());
        }

        if (($counter = $value->asLoroCounter()) !== null) {
            return self::normalizeNumber($counter->getValue());
        }

        if (($list = $value->asLoroList()) !== null) {
            return self::toPhp($list->getDeepValue());
        }

        if (($movableList = $value->asLoroMovableList()) !== null) {
            return self::toPhp($movableList->getDeepValue());
        }

        if (($map = $value->asLoroMap()) !== null) {
            return self::toPhp($map->getDeepValue());
        }

        if (($tree = $value->asLoroTree()) !== null) {
            return self::toPhp($tree->getValue());
        }

        if (($containerId = $value->asContainer()) !== null) {
            return $containerId;
        }

        if (($unknown = $value->asLoroUnknown()) !== null) {
            return $unknown->id();
        }

        throw new \UnexpectedValueException('Cannot convert empty ValueOrContainer to PHP');
    }

    private static function normalizeNumber(float $value): float|int
    {
        $intValue = (int) $value;

        return (float) $intValue === $value ? $intValue : $value;
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
