<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\Container;
use Loro\ContainerDiff;
use Loro\DiffEvent;
use Loro\Events;
use Loro\Export;
use Loro\FirstCommitFromPeerPayload;
use Loro\Frontiers;
use Loro\Index;
use Loro\Loro;
use Loro\LoroDoc;
use Loro\LoroList;
use Loro\LoroMap;
use Loro\LoroText;
use Loro\PathItem;
use Loro\PreCommitCallbackPayload;
use Loro\TreeParentId;
use Loro\Value;
use Loro\ValueOrContainer;
use Loro\VersionVector;

final class EventBehaviorTest extends LoroTestCase
{
    public function testRootSubscriptionExposesTargetAndTextDiffs(): void
    {
        $doc = new LoroDoc();
        $events = [];
        $subscription = Events::subscribeRoot($doc, static function (DiffEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $text = $doc->getText(Container::idLike('text'));
        $text->insert(0, '3');
        $doc->commit();

        self::assertCount(1, $events);
        self::assertSame('Local', $events[0]->triggeredBy->variant);
        self::assertEquals($text->id(), $events[0]->events[0]->target);
        self::assertSame('Text', $events[0]->events[0]->diff->variant);
        self::assertSame([['insert' => '3']], Loro::textDeltaToPhp($events[0]->events[0]->diff->fields['diff']));

        $text->insert(1, '12');
        $doc->commit();

        self::assertCount(2, $events);
        self::assertSame([
            ['retain' => 1],
            ['insert' => '12'],
        ], Loro::textDeltaToPhp($events[1]->events[0]->diff->fields['diff']));

        $subscription->unsubscribe();
    }

    public function testNestedEventPathsUseMapKeysAndListIndices(): void
    {
        $doc = new LoroDoc();
        $events = [];
        $subscription = Events::subscribeRoot($doc, static function (DiffEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $map = $doc->getMap(Container::idLike('map'));
        $subMap = Container::insertMapContainer($map, 'sub', new LoroMap());
        self::assertInstanceOf(LoroMap::class, $subMap);
        Container::insertMapValue($subMap, '0', '1');
        $doc->commit();

        self::assertSame(['map', 'sub'], self::pathToPhp($events[0]->events[1]->path));

        $list = Container::insertMapContainer($subMap, 'list', new LoroList());
        self::assertInstanceOf(LoroList::class, $list);
        Container::insertListValue($list, 0, '2');
        $text = Container::insertListContainer($list, 1, new LoroText());
        self::assertInstanceOf(LoroText::class, $text);
        $doc->commit();

        $text->insert(0, '3');
        $doc->commit();

        self::assertSame(['map', 'sub', 'list', 1], self::pathToPhp($events[2]->events[0]->path));

        $subscription->unsubscribe();
    }

    public function testListMapAndTreeDiffsAreAvailableFromEvents(): void
    {
        $doc = new LoroDoc();
        $events = [];
        $subscription = Events::subscribeRoot($doc, static function (DiffEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $list = $doc->getList(Container::idLike('list'));
        Container::insertListValue($list, 0, '3');
        $doc->commit();

        self::assertSame('List', $events[0]->events[0]->diff->variant);
        self::assertSame([['insert' => ['3'], 'isMove' => false]], self::listDiffToPhp($events[0]->events[0]));

        Container::insertListValue($list, 1, '12');
        $doc->commit();
        self::assertSame([
            ['retain' => 1],
            ['insert' => ['12'], 'isMove' => false],
        ], self::listDiffToPhp($events[1]->events[0]));

        $map = $doc->getMap(Container::idLike('map'));
        Container::insertMapValue($map, '0', '3');
        Container::insertMapValue($map, '1', '2');
        $doc->commit();

        $mapDiff = $events[2]->events[0]->diff;
        self::assertSame('Map', $mapDiff->variant);
        self::assertSame(['0' => '3', '1' => '2'], self::mapUpdatedToPhp($mapDiff->fields['diff']->updated));

        Container::insertMapValue($map, '0', null);
        $map->delete('1');
        $doc->commit();

        $updated = $events[3]->events[0]->diff->fields['diff']->updated;
        self::assertArrayHasKey('0', $updated);
        self::assertArrayHasKey('1', $updated);
        self::assertInstanceOf(ValueOrContainer::class, $updated['0']);
        self::assertNull(Value::toPhp($updated['0']));
        self::assertNull($updated['1']);

        $tree = $doc->getTree(Container::idLike('tree'));
        $tree->create(TreeParentId::root());
        $doc->commit();

        $treeEvent = $events[4]->events[0];
        self::assertEquals($tree->id(), $treeEvent->target);
        self::assertSame('Tree', $treeEvent->diff->variant);

        $subscription->detach();
    }

    public function testContainerSubscriptionsAreDeepAndCanBeUnsubscribed(): void
    {
        $doc = new LoroDoc();
        $map = $doc->getMap(Container::idLike('map'));
        $times = 0;

        $subscription = Events::subscribeContainer($map, static function () use (&$times): void {
            ++$times;
        });
        self::assertNotNull($subscription);

        $subMap = Container::insertMapContainer($map, 'sub', new LoroMap());
        self::assertInstanceOf(LoroMap::class, $subMap);
        $doc->commit();
        self::assertSame(1, $times);

        $text = Container::insertMapContainer($subMap, 'k', new LoroText());
        self::assertInstanceOf(LoroText::class, $text);
        $doc->commit();
        self::assertSame(2, $times);

        $text->insert(0, '123');
        $doc->commit();
        self::assertSame(3, $times);

        $subscription->unsubscribe();
        $text->insert(0, 'ignored');
        $doc->commit();
        self::assertSame(3, $times);
    }

    public function testLocalUpdateSubscriptionsCanSyncAndUnsubscribe(): void
    {
        $doc1 = new LoroDoc();
        $doc2 = new LoroDoc();
        $text1 = $doc1->getText(Container::idLike('text'));
        $text2 = $doc2->getText(Container::idLike('text'));

        $sub1 = Events::subscribeLocalUpdate($doc1, static function (string $update) use ($doc2): void {
            $doc2->import($update);
        });
        $sub2 = Events::subscribeLocalUpdate($doc2, static function (string $update) use ($doc1): void {
            $doc1->import($update);
        });

        $text1->insert(0, 'Hello');
        $doc1->commit();
        self::assertSame('Hello', self::textString($text2));

        $text2->insert(5, ' World');
        $doc2->commit();
        self::assertSame('Hello World', self::textString($text1));

        $sub1->unsubscribe();
        $text1->insert(11, '!');
        $doc1->commit();
        self::assertSame('Hello World', self::textString($text2));

        $sub2->unsubscribe();
    }

    public function testFirstCommitFromPeerCallbackCanMutateDocument(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(0);
        $peers = [];

        $subscription = Events::subscribeFirstCommitFromPeer(
            $doc,
            static function (FirstCommitFromPeerPayload $payload) use ($doc, &$peers): void {
                $peer = (string) $payload->peer;
                $peers[] = $peer;
                Container::insertMapValue($doc->getMap(Container::idLike('map')), $peer, 'user-' . $peer);
            }
        );

        $list = $doc->getList(Container::idLike('list'));
        Container::insertListValue($list, 0, 100);
        $doc->commit();
        Container::insertListValue($list, 0, 200);
        $doc->commit();

        $doc->setPeerId(1);
        Container::insertListValue($list, 0, 300);
        $doc->commit();

        self::assertSame(['0', '1'], $peers);
        self::assertSame('user-0', Value::toPhp($doc->getMap(Container::idLike('map'))->get('0')));

        $subscription->detach();
    }

    public function testPreCommitCallbackCanModifyMetadataAndReenterDocument(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(0);
        $seenPeer = null;
        $seenOrigin = null;

        $subscription = Events::subscribePreCommit(
            $doc,
            static function (PreCommitCallbackPayload $payload) use ($doc, &$seenPeer, &$seenOrigin): void {
                $seenPeer = $doc->peerId();
                $seenOrigin = $payload->origin;
                $payload->modifier->setMessage('test message');
                $payload->modifier->setTimestamp(12345);
            }
        );

        $doc->setNextCommitOrigin('origin from test');
        Container::insertListValue($doc->getList(Container::idLike('list')), 0, 100);
        $doc->commit();

        $change = $doc->getChange($doc->oplogFrontiers()->toVec()[0]);
        self::assertNotNull($change);
        self::assertSame(0, $seenPeer);
        self::assertSame('origin from test', $seenOrigin);
        self::assertSame('test message', $change->message);
        self::assertSame(12345, $change->timestamp);

        $subscription->detach();
    }

    public function testImportBatchCanReenterDocumentInPreCommitCallback(): void
    {
        $remote = new LoroDoc();
        $remote->getText(Container::idLike('remote'))->insert(0, 'remote');
        $remote->commit();

        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $doc->getText(Container::idLike('local'))->insert(0, 'local');

        $seenPeer = null;
        $subscription = Events::subscribePreCommit(
            $doc,
            static function () use ($doc, &$seenPeer): void {
                $seenPeer = $doc->peerId();
            }
        );

        $doc->importBatch([$remote->export(Export::snapshot())]);

        self::assertSame(1, $seenPeer);
        $json = Loro::toJson($doc);
        self::assertCount(2, $json);
        self::assertSame('local', $json['local']);
        self::assertSame('remote', $json['remote']);

        $subscription->detach();
    }

    public function testCheckoutToLatestCanReenterDocumentInPreCommitCallback(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $doc->getText(Container::idLike('text'))->insert(0, 'local');

        $seenPeer = null;
        $subscription = Events::subscribePreCommit(
            $doc,
            static function () use ($doc, &$seenPeer): void {
                $seenPeer = $doc->peerId();
            }
        );

        $doc->checkoutToLatest();

        self::assertSame(1, $seenPeer);
        self::assertSame(['text' => 'local'], Loro::toJson($doc));

        $subscription->detach();
    }

    public function testImportAndCheckoutEventsUseTheirTriggerKinds(): void
    {
        $source = new LoroDoc();
        Container::insertListValue($source->getList(Container::idLike('list')), 0, 123);
        $source->commit();

        $imported = new LoroDoc();
        $importEvents = [];
        $importSub = Events::subscribeRoot($imported, static function (DiffEvent $event) use (&$importEvents): void {
            $importEvents[] = $event;
        });
        $imported->import($source->export(Export::updates(new VersionVector())));

        self::assertCount(1, $importEvents);
        self::assertSame('Import', $importEvents[0]->triggeredBy->variant);
        $importSub->detach();

        $checkoutEvents = [];
        $checkoutSub = Events::subscribeRoot($source, static function (DiffEvent $event) use (&$checkoutEvents): void {
            $checkoutEvents[] = $event;
        });
        $source->checkout(new Frontiers());

        self::assertCount(1, $checkoutEvents);
        self::assertSame('Checkout', $checkoutEvents[0]->triggeredBy->variant);
        $checkoutSub->detach();
    }

    public function testMovableListSubscriptionEmitsListDiff(): void
    {
        $doc = new LoroDoc();
        $events = [];
        $subscription = Events::subscribeRoot($doc, static function (DiffEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $list = $doc->getMovableList(Container::idLike('list'));
        Container::pushListValue($list, 'a');
        Container::pushListValue($list, 'b');
        Container::pushListValue($list, 'c');
        $doc->commit();

        self::assertCount(1, $events);
        self::assertSame('Local', $events[0]->triggeredBy->variant);
        self::assertSame([['insert' => ['a', 'b', 'c'], 'isMove' => false]], self::listDiffToPhp($events[0]->events[0]));

        $subscription->detach();
    }

    /**
     * @param array<PathItem> $path
     *
     * @return array<int, int|string>
     */
    private static function pathToPhp(array $path): array
    {
        return array_map(static fn(PathItem $item): int|string => self::indexToPhp($item->index), $path);
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

    private static function listDiffToPhp(ContainerDiff $event): array
    {
        self::assertSame('List', $event->diff->variant);

        return array_map(static function ($item): array {
            return match ($item->variant) {
                'Insert' => [
                    'insert' => array_map(static fn(ValueOrContainer $value): mixed => Value::toPhp($value), $item->fields['insert']),
                    'isMove' => $item->fields['isMove'],
                ],
                'Delete' => ['delete' => $item->fields['delete']],
                'Retain' => ['retain' => $item->fields['retain']],
                default => throw new \UnexpectedValueException('Unexpected list diff ' . $item->variant),
            };
        }, $event->diff->fields['diff']);
    }

    /**
     * @param array<string, ValueOrContainer|null> $updated
     *
     * @return array<string, mixed>
     */
    private static function mapUpdatedToPhp(array $updated): array
    {
        ksort($updated);
        $result = [];
        foreach ($updated as $key => $value) {
            $result[$key] = $value === null ? null : Value::toPhp($value);
        }

        return $result;
    }
}
