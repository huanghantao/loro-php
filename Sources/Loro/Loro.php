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

        return new UndoItemMeta(Value::toLoroValue($result), []);
    }
}

final class Loro
{
    public static function export(LoroDoc $doc, ExportMode $mode): string
    {
        return $doc->export($mode);
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
}
