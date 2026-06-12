<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\CommitOptions;
use Loro\ContainerId;
use Loro\ContainerPath;
use Loro\ContainerType;
use Loro\CounterSpan;
use Loro\ExportMode;
use Loro\Frontiers;
use Loro\Id;
use Loro\IdSpan;
use Loro\Index;
use Loro\LoroDoc;
use Loro\LoroList;
use Loro\LoroMap;
use Loro\LoroText;
use Loro\PosType;
use Loro\VersionVector;

final class BasicBehaviorTest extends LoroTestCase
{
    public function testListMapTextAndMapSubcontainersFollowBasicExample(): void
    {
        $doc = new LoroDoc();
        $list = $doc->getList('list');
        $list->insert(0, 'A');
        $list->insert(1, 'B');
        $list->insert(2, 'C');

        $map = $doc->getMap('map');
        $map->set('key', 'value');

        self::assertEquals([
            'list' => ['A', 'B', 'C'],
            'map' => ['key' => 'value'],
        ], $doc->toJSON());

        $list->delete(0, 2);
        self::assertEquals([
            'list' => ['C'],
            'map' => ['key' => 'value'],
        ], $doc->toJSON());

        $text = $list->insertContainer(0, new LoroText());
        self::assertInstanceOf(LoroText::class, $text);
        $text->insert(0, 'Hello');
        $text->insert(0, 'Hi! ');

        self::assertEquals([
            'list' => ['Hi! Hello', 'C'],
            'map' => ['key' => 'value'],
        ], $doc->toJSON());

        $list2 = $map->setContainer('test', new LoroList());
        self::assertInstanceOf(LoroList::class, $list2);
        $list2->insert(0, 1);

        self::assertEquals([
            'list' => ['Hi! Hello', 'C'],
            'map' => ['key' => 'value', 'test' => [1]],
        ], $doc->toJSON());
    }

    public function testMapGetOrCreateContainerAndAccessors(): void
    {
        $doc = new LoroDoc();
        $map = $doc->getMap('map');

        $list = $map->getOrCreateContainer('list', new LoroList());
        self::assertInstanceOf(LoroList::class, $list);
        $list->insert(0, 1);
        $list->insert(0, 2);

        $text = $map->getOrCreateContainer('text', new LoroText());
        self::assertInstanceOf(LoroText::class, $text);
        $text->insert(0, 'Hello');

        self::assertEquals([
            'map' => ['list' => [2, 1], 'text' => 'Hello'],
        ], $doc->toJSON());

        $map->set('foo', 'bar');
        $map->set('baz', 'bar');

        $keys = $map->keys();
        sort($keys);
        self::assertSame(['baz', 'foo', 'list', 'text'], $keys);

        $values = array_map(static fn($value): mixed => $value->toJSON(), $map->values());
        self::assertContains('bar', $values);
        self::assertContains([2, 1], $values);
        self::assertContains('Hello', $values);
    }

    public function testRootContainerIdsAcceptStringsDirectly(): void
    {
        $doc = new LoroDoc();

        $text = $doc->getText('text');
        $text->insert(0, 'hello');

        $list = $doc->getList('list');
        $list->push('item');

        $map = $doc->getMap(ContainerId::root('map', ContainerType::map()));
        $map->set('ok', true);

        self::assertSame('hello', self::textString($doc->tryGetText('text')));
        self::assertSame(['item'], $doc->tryGetList('list')?->toJSON());

        $json = $doc->toJSON();
        self::assertSame('hello', $json['text']);
        self::assertSame(['item'], $json['list']);
        self::assertSame(['ok' => true], $json['map']);
    }

    public function testIncrementalSyncCanExportFromKnownVersion(): void
    {
        $docA = new LoroDoc();
        $docB = new LoroDoc();
        $listA = $docA->getList('list');
        $listA->insert(0, 'A');
        $listA->insert(1, 'B');
        $listA->insert(2, 'C');

        $docB->import($docA->export(ExportMode::updates(new VersionVector())));
        self::assertSame(['list' => ['A', 'B', 'C']], $docB->toJSON());

        $listB = $docB->getList('list');
        $listB->delete(1, 1);

        $docA->import($docB->export(ExportMode::updates($docA->oplogVv())));

        self::assertSame(['list' => ['A', 'C']], $docA->toJSON());
        self::assertSame($docA->toJSON(), $docB->toJSON());
    }

    public function testListAccessorsAndTextPositionConversion(): void
    {
        $doc = new LoroDoc();
        $list = $doc->getList('list');
        $list->insert(0, 1);
        $list->insert(1, 2);

        self::assertSame([1, 2], $list->toArray());

        $list->insertContainer(2, new LoroText());
        $value = $list->get(2);
        self::assertNotNull($value);
        self::assertTrue($value->isContainer());
        self::assertSame('Text', $value->containerType()?->variant);

        $text = $doc->getText('text');
        $text->insert(0, 'A😀BC');

        self::assertSame(0, $text->convertPos(0, PosType::unicode(), PosType::utf16()));
        self::assertSame(1, $text->convertPos(1, PosType::unicode(), PosType::utf16()));
        self::assertSame(3, $text->convertPos(2, PosType::unicode(), PosType::utf16()));
        self::assertSame(2, $text->convertPos(3, PosType::utf16(), PosType::unicode()));
        self::assertSame(1, $text->convertPos(1, PosType::unicode(), PosType::bytes()));
        self::assertNull($text->convertPos(999, PosType::unicode(), PosType::utf16()));
    }

    public function testMapChildContainersLargeIntsAndDeletionState(): void
    {
        $doc = new LoroDoc();
        $map = $doc->getMap('map');

        $list = $map->setContainer('key', new LoroList());
        self::assertInstanceOf(LoroList::class, $list);
        $list->insert(0, 1);

        $child = $map->get('key');
        self::assertNotNull($child);
        self::assertSame([1], $child->toJSON());

        $map->set('large', 2147483699);
        self::assertSame(2147483699, $map->get('large')?->toJSON());

        $sub = $map->setContainer('sub', new LoroMap());
        self::assertInstanceOf(LoroMap::class, $sub);
        self::assertFalse($sub->isDeleted());

        $map->set('sub', 'value');
        self::assertTrue($sub->isDeleted());
    }

    public function testInvalidObjectCannotBeConvertedToLoroValue(): void
    {
        $doc = new LoroDoc();
        $map = $doc->getMap('map');

        $this->expectException(\InvalidArgumentException::class);
        $map->set('bad', new \stdClass());
    }

    public function testPathLookupForkAndContainerExistence(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $map = $doc->getMap('map');
        $map->set('key', 1);

        self::assertSame(1, $doc->getByStrPath('map/key')?->toJSON());
        self::assertInstanceOf(LoroMap::class, $doc->getByPath([Index::key('map')])?->asLoroMap());

        $list = $map->setContainer('list', new LoroList());
        self::assertInstanceOf(LoroList::class, $list);
        $sub = $list->insertContainer(0, new LoroMap());
        self::assertInstanceOf(LoroMap::class, $sub);
        $sub->set('nested', true);

        self::assertSame(['map', 'list', 0], self::containerPathToPhp($doc->getPathToContainer($sub->id())));
        self::assertTrue($doc->hasContainer(ContainerId::root('map', ContainerType::map())));
        self::assertTrue($doc->hasContainer($sub->id()));

        $fork = $doc->fork();
        self::assertEquals($doc->toJSON(), $fork->toJSON());

        $fork->getMap('map')->set('key', 2);
        self::assertSame(1, $doc->toJSON()['map']['key']);
        self::assertSame(2, $fork->toJSON()['map']['key']);

        $doc->import($fork->export(ExportMode::snapshot()));
        self::assertSame(2, $doc->toJSON()['map']['key']);
    }

    public function testImportStatusReportsPendingAndUnblocksWhenMissingUpdateArrives(): void
    {
        $a = new LoroDoc();
        $a->setPeerId(0);
        $a->getText('text')->insert(0, 'a');

        $b = new LoroDoc();
        $b->setPeerId(1);
        $b->import($a->export(ExportMode::updates(new VersionVector())));
        $b->getText('text')->insert(1, 'b');

        $c = new LoroDoc();
        $c->setPeerId(2);
        $c->import($b->export(ExportMode::updates(new VersionVector())));
        $c->getText('text')->insert(2, 'c');

        $status = $a->import($c->export(ExportMode::updates($b->oplogVv())));
        self::assertSame([], self::spansToArrays($status->success));
        self::assertSame([2 => ['start' => 0, 'end' => 1]], self::spansToArrays($status->pending));
        self::assertSame('a', self::textString($a->getText('text')));

        $status2 = $a->import($b->export(ExportMode::updates($a->oplogVv())));
        self::assertSame([
            1 => ['start' => 0, 'end' => 1],
            2 => ['start' => 0, 'end' => 1],
        ], self::spansToArrays($status2->success));
        self::assertNull($status2->pending);
        self::assertSame('abc', self::textString($a->getText('text')));
    }

    public function testImportBatchMergesPendingChunks(): void
    {
        $doc1 = new LoroDoc();
        $doc1->setPeerId(1);
        $doc1->getText('text')->insert(0, 'Hello world!');

        $doc2 = new LoroDoc();
        $doc2->setPeerId(2);
        $doc2->getText('text')->insert(0, 'Hello world!');

        $blob11 = $doc1->export(ExportMode::updatesInRange([new IdSpan(1, new CounterSpan(0, 5))]));
        $blob12 = $doc1->export(ExportMode::updatesInRange([new IdSpan(1, new CounterSpan(5, 7))]));
        $blob13 = $doc1->export(ExportMode::updatesInRange([new IdSpan(1, new CounterSpan(6, 12))]));

        $blob21 = $doc2->export(ExportMode::updatesInRange([new IdSpan(2, new CounterSpan(0, 5))]));
        $blob22 = $doc2->export(ExportMode::updatesInRange([new IdSpan(2, new CounterSpan(5, 7))]));
        $blob23 = $doc2->export(ExportMode::updatesInRange([new IdSpan(2, new CounterSpan(6, 12))]));

        $newDoc = new LoroDoc();
        $status = $newDoc->importBatch([$blob11, $blob13, $blob21, $blob23]);

        self::assertSame([
            1 => ['start' => 0, 'end' => 5],
            2 => ['start' => 0, 'end' => 5],
        ], self::spansToArrays($status->success));
        self::assertSame([
            1 => ['start' => 6, 'end' => 12],
            2 => ['start' => 6, 'end' => 12],
        ], self::spansToArrays($status->pending));

        $status2 = $newDoc->importBatch([$blob12, $blob22]);
        self::assertSame([
            1 => ['start' => 5, 'end' => 12],
            2 => ['start' => 5, 'end' => 12],
        ], self::spansToArrays($status2->success));
        self::assertNull($status2->pending);
        self::assertSame('Hello world!Hello world!', self::textString($newDoc->getText('text')));
    }

    public function testCommitOptionsPendingLengthAndChangedContainers(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(0);

        self::assertSame(0, $doc->getPendingTxnLen());
        $doc->getText('text')->insert(0, 'H');
        $doc->getMap('map')->set('key', 'H');
        self::assertSame(2, $doc->getPendingTxnLen());

        $doc->setNextCommitOptions(new CommitOptions('test origin', false, 123, 'test message'));
        $doc->commit();

        $change = $doc->getChange(new Id(0, 0));
        self::assertNotNull($change);
        self::assertSame('test message', $change->message);
        self::assertSame(123, $change->timestamp);
        self::assertSame(2, $change->len);
        self::assertSame(0, $doc->getPendingTxnLen());

        $changed = array_map(static fn(ContainerId $id): string => self::containerIdName($id), $doc->getChangedContainersIn(new Id(0, 0), 2));
        sort($changed);
        self::assertSame(['map:Map', 'text:Text'], $changed);
    }

    public function testDiffApplyAndDeletedMapEntries(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);

        $text = $doc->getText('text');
        $text->insert(0, 'Hello');
        $doc->commit();

        $map = $doc->getMap('map');
        $map->set('key1', 'value1');
        $map->set('key2', 42);
        $list = $doc->getList('list');
        $list->push('item1');
        $list->push('item2');
        $list->delete(1, 1);
        $doc->commit();

        $diff = $doc->diff(new Frontiers(), $doc->oplogFrontiers());
        $doc2 = new LoroDoc();
        $doc2->applyDiff($diff);
        self::assertEquals($doc->toJSON(), $doc2->toJSON());

        $beforeDelete = $doc->oplogFrontiers();
        $map->delete('key1');
        $map->delete('key2');
        $doc->commit();

        $deletedOnly = new LoroDoc();
        $deletedOnly->applyDiff($doc->diff($beforeDelete, $doc->oplogFrontiers()));
        self::assertSame(['map' => []], $deletedOnly->toJSON());
    }

    public function testRootContainersCanBeDeletedAndHiddenWhenEmpty(): void
    {
        $doc = new LoroDoc();
        $doc->getMap('map');
        $doc->getMap('m');
        $doc->getText('text');

        $doc->deleteRootContainer(ContainerId::root('map', ContainerType::map()));
        $doc->deleteRootContainer(ContainerId::root('text', ContainerType::text()));

        self::assertSame(['m' => []], $doc->toJSON());

        $snapshot = $doc->export(ExportMode::snapshot());
        $newDoc = new LoroDoc();
        $newDoc->import($snapshot);
        self::assertSame(['m' => []], $newDoc->toJSON());

        $newDoc->setHideEmptyRootContainers(true);
        self::assertSame([], $newDoc->toJSON());
    }

    /**
     * @param array<ContainerPath>|null $path
     *
     * @return array<int, int|string>
     */
    private static function containerPathToPhp(?array $path): array
    {
        self::assertNotNull($path);

        return array_map(static fn(ContainerPath $item): int|string => self::indexToPhp($item->path), $path);
    }

    private static function indexToPhp(Index $index): int|string
    {
        return match ($index->variant) {
            'Key' => $index->fields['key'],
            'Seq' => $index->fields['index'],
            'Node' => json_encode($index->fields['target'], JSON_THROW_ON_ERROR),
            default => throw new \UnexpectedValueException('Unexpected path index ' . $index->variant),
        };
    }

    /**
     * @param array<int|string, CounterSpan>|null $spans
     *
     * @return array<int|string, array{start: int, end: int}>|null
     */
    private static function spansToArrays(?array $spans): ?array
    {
        if ($spans === null) {
            return null;
        }

        ksort($spans);
        $result = [];
        foreach ($spans as $peer => $span) {
            $result[$peer] = ['start' => $span->start, 'end' => $span->end];
        }

        return $result;
    }

    private static function containerIdName(ContainerId $id): string
    {
        self::assertSame('Root', $id->variant);

        return $id->fields['name'] . ':' . $id->fields['containerType']->variant;
    }
}
