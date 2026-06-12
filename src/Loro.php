<?php

declare(strict_types=1);

namespace Loro;

final class Export
{
    public static function snapshot(): ExportMode
    {
        return ExportMode::snapshot();
    }

    public static function updates(VersionVector $from): ExportMode
    {
        return ExportMode::updates($from);
    }

    public static function updatesInRange(array $spans): ExportMode
    {
        return ExportMode::updatesInRange($spans);
    }

    public static function shallowSnapshot(Frontiers $frontiers): ExportMode
    {
        return ExportMode::shallowSnapshot($frontiers);
    }

    public static function stateOnly(?Frontiers $frontiers = null): ExportMode
    {
        return ExportMode::stateOnly($frontiers);
    }

    public static function snapshotAt(Frontiers $frontiers): ExportMode
    {
        return ExportMode::snapshotAt($frontiers);
    }
}

final class ChangeAncestorsTravelerCallback extends ChangeAncestorsTraveler
{
    private mixed $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function travel(ChangeMeta $change): bool
    {
        return (bool) ($this->callback)($change);
    }
}

final class OnPopCallback extends OnPop
{
    private mixed $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function onPop(UndoOrRedo $undoOrRedo, CounterSpan $span, UndoItemMeta $undoMeta): void
    {
        ($this->callback)($undoOrRedo, $span, $undoMeta);
    }
}

final class OnPushCallback extends OnPush
{
    private mixed $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function onPush(UndoOrRedo $undoOrRedo, CounterSpan $span, ?DiffEvent $diffEvent): UndoItemMeta
    {
        $result = ($this->callback)($undoOrRedo, $span, $diffEvent);

        if ($result instanceof UndoItemMeta) {
            return $result;
        }

        if (is_array($result) && array_key_exists('value', $result)) {
            return new UndoItemMeta(
                Value::toLoroValue($result['value']),
                $result['cursors'] ?? []
            );
        }

        return new UndoItemMeta(Value::toLoroValue($result), []);
    }
}

final class Loro
{
    public static function version(): string
    {
        return UniFFIRuntime::liftString(
            UniFFIRuntime::rustCall('uniffi_loro_ffi_fn_func_get_version')
        );
    }

    public static function toJson(LoroDoc $doc): mixed
    {
        return Value::toPhp($doc->getDeepValue());
    }

    public static function jsonpath(LoroDoc $doc, string $path): array
    {
        return array_map(Value::toPhp(...), $doc->jsonpath($path));
    }

    public static function configureTextStyle(LoroDoc $doc, array $styles): void
    {
        $config = new StyleConfigMap();
        foreach ($styles as $name => $expand) {
            $config->insert((string) $name, new StyleConfig(self::expandType($expand)));
        }

        $doc->configTextStyle($config);
    }

    public static function textDeltaToPhp(array $delta): array
    {
        return array_map(static function (TextDelta $item): array {
            return match ($item->variant) {
                'Retain' => self::deltaItem('retain', $item->fields['retain'], $item->fields['attributes'] ?? null),
                'Insert' => self::deltaItem('insert', $item->fields['insert'], $item->fields['attributes'] ?? null),
                'Delete' => ['delete' => $item->fields['delete']],
                default => throw new \UnexpectedValueException('Unexpected TextDelta variant ' . $item->variant),
            };
        }, $delta);
    }

    public static function export(LoroDoc $doc, ExportMode $mode): string
    {
        return $doc->export($mode);
    }

    public static function exportJsonInIdSpan(LoroDoc $doc, IdSpan $idSpan): array
    {
        if ($doc->getPendingTxnLen() > 0) {
            $doc->commit();
        }

        return $doc->exportJsonInIdSpan($idSpan);
    }

    public static function travelChangeAncestors(LoroDoc $doc, array $ids, callable $callback): void
    {
        $doc->travelChangeAncestors($ids, new ChangeAncestorsTravelerCallback($callback));
    }

    public static function setUndoOnPop(UndoManager $manager, ?callable $callback): void
    {
        $manager->setOnPop($callback === null ? null : new OnPopCallback($callback));
    }

    public static function setUndoOnPush(UndoManager $manager, ?callable $callback): void
    {
        $manager->setOnPush($callback === null ? null : new OnPushCallback($callback));
    }

    private static function expandType(string|ExpandType $expand): ExpandType
    {
        if ($expand instanceof ExpandType) {
            return $expand;
        }

        return match ($expand) {
            'before' => ExpandType::before(),
            'after' => ExpandType::after(),
            'both' => ExpandType::both(),
            'none' => ExpandType::none(),
            default => throw new \InvalidArgumentException('Unknown text style expand type: ' . $expand),
        };
    }

    private static function deltaItem(string $key, int|string $value, ?array $attributes): array
    {
        $item = [$key => $value];
        if ($attributes !== null && $attributes !== []) {
            $item['attributes'] = Value::toPhp(Value::map($attributes));
        }

        return $item;
    }
}
