<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\ContainerDiff;
use Loro\DiffEvent;
use Loro\ExportMode;
use Loro\FirstCommitFromPeerPayload;
use Loro\Frontiers;
use Loro\Index;
use Loro\LoroDoc;
use Loro\LoroList;
use Loro\LoroMap;
use Loro\LoroText;
use Loro\PathItem;
use Loro\PreCommitCallbackPayload;
use Loro\TreeParentId;
use Loro\ValueOrContainer;
use Loro\VersionVector;

final class EventBehaviorTest extends LoroTestCase
{
    public function testRootSubscriptionExposesTargetAndTextDiffs(): void
    {
        $doc = new LoroDoc();
        $events = [];
        $subscription = $doc->subscribeRoot(static function (DiffEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $text = $doc->getText('text');
        $text->insert(0, '3');
        $doc->commit();

        self::assertCount(1, $events);
        self::assertSame('Local', $events[0]->triggeredBy->variant);
        self::assertEquals($text->id(), $events[0]->events[0]->target);
        self::assertSame('Text', $events[0]->events[0]->diff->variant);
        self::assertSame([['insert' => '3']], \Loro\UniFFITextStyleHelper::textDeltaToPhp($events[0]->events[0]->diff->fields['diff']));

        $text->insert(1, '12');
        $doc->commit();

        self::assertCount(2, $events);
        self::assertSame([
            ['retain' => 1],
            ['insert' => '12'],
        ], \Loro\UniFFITextStyleHelper::textDeltaToPhp($events[1]->events[0]->diff->fields['diff']));

        $subscription->unsubscribe();
    }

    public function testNestedEventPathsUseMapKeysAndListIndices(): void
    {
        $doc = new LoroDoc();
        $events = [];
        $subscription = $doc->subscribeRoot(static function (DiffEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $map = $doc->getMap('map');
        $subMap = $map->setContainer('sub', new LoroMap());
        self::assertInstanceOf(LoroMap::class, $subMap);
        $subMap->set('0', '1');
        $doc->commit();

        self::assertSame(['map', 'sub'], self::pathToPhp($events[0]->events[1]->path));

        $list = $subMap->setContainer('list', new LoroList());
        self::assertInstanceOf(LoroList::class, $list);
        $list->insert(0, '2');
        $text = $list->insertContainer(1, new LoroText());
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
        $subscription = $doc->subscribeRoot(static function (DiffEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $list = $doc->getList('list');
        $list->insert(0, '3');
        $doc->commit();

        self::assertSame('List', $events[0]->events[0]->diff->variant);
        self::assertSame([['insert' => ['3'], 'isMove' => false]], self::listDiffToPhp($events[0]->events[0]));

        $list->insert(1, '12');
        $doc->commit();
        self::assertSame([
            ['retain' => 1],
            ['insert' => ['12'], 'isMove' => false],
        ], self::listDiffToPhp($events[1]->events[0]));

        $map = $doc->getMap('map');
        $map->set('0', '3');
        $map->set('1', '2');
        $doc->commit();

        $mapDiff = $events[2]->events[0]->diff;
        self::assertSame('Map', $mapDiff->variant);
        self::assertSame(['0' => '3', '1' => '2'], self::mapUpdatedToPhp($mapDiff->fields['diff']->updated));

        $map->set('0', null);
        $map->delete('1');
        $doc->commit();

        $updated = $events[3]->events[0]->diff->fields['diff']->updated;
        self::assertArrayHasKey('0', $updated);
        self::assertArrayHasKey('1', $updated);
        self::assertInstanceOf(ValueOrContainer::class, $updated['0']);
        self::assertNull($updated['0']->toJSON());
        self::assertNull($updated['1']);

        $tree = $doc->getTree('tree');
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
        $map = $doc->getMap('map');
        $times = 0;

        $subscription = $map->subscribe(static function () use (&$times): void {
            ++$times;
        });
        self::assertNotNull($subscription);

        $subMap = $map->setContainer('sub', new LoroMap());
        self::assertInstanceOf(LoroMap::class, $subMap);
        $doc->commit();
        self::assertSame(1, $times);

        $text = $subMap->setContainer('k', new LoroText());
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
        $text1 = $doc1->getText('text');
        $text2 = $doc2->getText('text');

        $sub1 = $doc1->subscribeLocalUpdate(static function (string $update) use ($doc2): void {
            $doc2->import($update);
        });
        $sub2 = $doc2->subscribeLocalUpdate(static function (string $update) use ($doc1): void {
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

        $subscription = $doc->subscribeFirstCommitFromPeer(
            static function (FirstCommitFromPeerPayload $payload) use ($doc, &$peers): void {
                $peer = (string) $payload->peer;
                $peers[] = $peer;
                $doc->getMap('map')->set($peer, 'user-' . $peer);
            }
        );

        $list = $doc->getList('list');
        $list->insert(0, 100);
        $doc->commit();
        $list->insert(0, 200);
        $doc->commit();

        $doc->setPeerId(1);
        $list->insert(0, 300);
        $doc->commit();

        self::assertSame(['0', '1'], $peers);
        self::assertSame('user-0', $doc->getMap('map')->get('0')?->toJSON());

        $subscription->detach();
    }

    public function testPreCommitCallbackCanModifyMetadataAndReenterDocument(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(0);
        $seenPeer = null;
        $seenOrigin = null;

        $subscription = $doc->subscribePreCommit(
            static function (PreCommitCallbackPayload $payload) use ($doc, &$seenPeer, &$seenOrigin): void {
                $seenPeer = $doc->peerId();
                $seenOrigin = $payload->origin;
                $payload->modifier->setMessage('test message');
                $payload->modifier->setTimestamp(12345);
            }
        );

        $doc->setNextCommitOrigin('origin from test');
        $doc->getList('list')->insert(0, 100);
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
        $remote->getText('remote')->insert(0, 'remote');
        $remote->commit();

        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $doc->getText('local')->insert(0, 'local');

        $seenPeer = null;
        $subscription = $doc->subscribePreCommit(
            static function () use ($doc, &$seenPeer): void {
                $seenPeer = $doc->peerId();
            }
        );

        $doc->importBatch([$remote->export(ExportMode::snapshot())]);

        self::assertSame(1, $seenPeer);
        $json = $doc->toJSON();
        self::assertCount(2, $json);
        self::assertSame('local', $json['local']);
        self::assertSame('remote', $json['remote']);

        $subscription->detach();
    }

    public function testCheckoutToLatestCanReenterDocumentInPreCommitCallback(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $doc->getText('text')->insert(0, 'local');

        $seenPeer = null;
        $subscription = $doc->subscribePreCommit(
            static function () use ($doc, &$seenPeer): void {
                $seenPeer = $doc->peerId();
            }
        );

        $doc->checkoutToLatest();

        self::assertSame(1, $seenPeer);
        self::assertSame(['text' => 'local'], $doc->toJSON());

        $subscription->detach();
    }

    public function testImportAndCheckoutEventsUseTheirTriggerKinds(): void
    {
        $source = new LoroDoc();
        $source->getList('list')->insert(0, 123);
        $source->commit();

        $imported = new LoroDoc();
        $importEvents = [];
        $importSub = $imported->subscribeRoot(static function (DiffEvent $event) use (&$importEvents): void {
            $importEvents[] = $event;
        });
        $imported->import($source->export(ExportMode::updates(new VersionVector())));

        self::assertCount(1, $importEvents);
        self::assertSame('Import', $importEvents[0]->triggeredBy->variant);
        $importSub->detach();

        $checkoutEvents = [];
        $checkoutSub = $source->subscribeRoot(static function (DiffEvent $event) use (&$checkoutEvents): void {
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
        $subscription = $doc->subscribeRoot(static function (DiffEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $list = $doc->getMovableList('list');
        $list->push('a');
        $list->push('b');
        $list->push('c');
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
                    'insert' => array_map(static fn(ValueOrContainer $value): mixed => $value->toJSON(), $item->fields['insert']),
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
            $result[$key] = $value === null ? null : $value->toJSON();
        }

        return $result;
    }
}
