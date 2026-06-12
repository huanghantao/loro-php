<?php
declare(strict_types=1);

namespace Loro;

final class RootContainerIdLike extends ContainerIdLike
{
    public function __construct(private string $name)
    {
    }

    public function asContainerId(ContainerType $ty): ContainerId
    {
        return ContainerId::root($this->name, $ty);
    }
}

final class FixedContainerIdLike extends ContainerIdLike
{
    public function __construct(private ContainerId $id)
    {
    }

    public function asContainerId(ContainerType $ty): ContainerId
    {
        return $this->id;
    }
}

final class Container
{
    public static function idLike(string|ContainerId|ContainerIdLike $id): ContainerIdLike
    {
        return match (true) {
            $id instanceof ContainerIdLike => $id,
            $id instanceof ContainerId => new FixedContainerIdLike($id),
            default => new RootContainerIdLike($id),
        };
    }

    public static function id(string $name, ContainerType $type): ContainerId
    {
        return ContainerId::root($name, $type);
    }

    public static function value(
        LoroText|LoroMap|LoroList|LoroMovableList|LoroTree|LoroCounter|LoroUnknown|ContainerId $container
    ): LoroValue {
        return $container instanceof ContainerId
            ? LoroValue::container($container)
            : LoroValue::container($container->id());
    }

    public static function insertMapValue(LoroMap $map, string $key, mixed $value): void
    {
        $map->insert($key, Value::like($value));
    }

    public static function insertListValue(LoroList|LoroMovableList $list, int $pos, mixed $value): void
    {
        $list->insert($pos, Value::like($value));
    }

    public static function pushListValue(LoroList|LoroMovableList $list, mixed $value): void
    {
        $list->push(Value::like($value));
    }

    public static function setMovableListValue(LoroMovableList $list, int $pos, mixed $value): void
    {
        $list->set($pos, Value::like($value));
    }

    public static function insertMapContainer(LoroMap $map, string $key, object $child): object
    {
        return match (true) {
            $child instanceof LoroCounter => $map->insertCounterContainer($key, $child),
            $child instanceof LoroList => $map->insertListContainer($key, $child),
            $child instanceof LoroMap => $map->insertMapContainer($key, $child),
            $child instanceof LoroMovableList => $map->insertMovableListContainer($key, $child),
            $child instanceof LoroText => $map->insertTextContainer($key, $child),
            $child instanceof LoroTree => $map->insertTreeContainer($key, $child),
            default => self::unsupportedChild($child),
        };
    }

    public static function getOrCreateMapContainer(LoroMap $map, string $key, object $child): object
    {
        return match (true) {
            $child instanceof LoroCounter => $map->getOrCreateCounterContainer($key, $child),
            $child instanceof LoroList => $map->getOrCreateListContainer($key, $child),
            $child instanceof LoroMap => $map->getOrCreateMapContainer($key, $child),
            $child instanceof LoroMovableList => $map->getOrCreateMovableListContainer($key, $child),
            $child instanceof LoroText => $map->getOrCreateTextContainer($key, $child),
            $child instanceof LoroTree => $map->getOrCreateTreeContainer($key, $child),
            default => self::unsupportedChild($child),
        };
    }

    public static function insertListContainer(LoroList|LoroMovableList $list, int $pos, object $child): object
    {
        return match (true) {
            $child instanceof LoroCounter => $list->insertCounterContainer($pos, $child),
            $child instanceof LoroList => $list->insertListContainer($pos, $child),
            $child instanceof LoroMap => $list->insertMapContainer($pos, $child),
            $child instanceof LoroMovableList => $list->insertMovableListContainer($pos, $child),
            $child instanceof LoroText => $list->insertTextContainer($pos, $child),
            $child instanceof LoroTree => $list->insertTreeContainer($pos, $child),
            default => self::unsupportedChild($child),
        };
    }

    public static function pushListContainer(LoroList|LoroMovableList $list, object $child): object
    {
        return self::insertListContainer($list, $list->len(), $child);
    }

    public static function setMovableListContainer(LoroMovableList $list, int $pos, object $child): object
    {
        return match (true) {
            $child instanceof LoroCounter => $list->setCounterContainer($pos, $child),
            $child instanceof LoroList => $list->setListContainer($pos, $child),
            $child instanceof LoroMap => $list->setMapContainer($pos, $child),
            $child instanceof LoroMovableList => $list->setMovableListContainer($pos, $child),
            $child instanceof LoroText => $list->setTextContainer($pos, $child),
            $child instanceof LoroTree => $list->setTreeContainer($pos, $child),
            default => self::unsupportedChild($child),
        };
    }

    public static function markText(LoroText $text, int $from, int $to, string $key, mixed $value): void
    {
        $text->mark($from, $to, $key, Value::like($value));
    }

    public static function markTextUtf8(LoroText $text, int $from, int $to, string $key, mixed $value): void
    {
        $text->markUtf8($from, $to, $key, Value::like($value));
    }

    public static function markTextUtf16(LoroText $text, int $from, int $to, string $key, mixed $value): void
    {
        $text->markUtf16($from, $to, $key, Value::like($value));
    }

    public static function setAwarenessLocalState(Awareness $awareness, mixed $value): void
    {
        $awareness->setLocalState(Value::like($value));
    }

    private static function unsupportedChild(object $child): never
    {
        throw new \InvalidArgumentException(
            'Expected a Loro container child, got ' . get_debug_type($child)
        );
    }
}
