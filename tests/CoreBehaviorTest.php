<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\DiffEvent;
use Loro\ExportMode;
use Loro\Frontiers;
use Loro\LoroDoc;
use Loro\LoroList;
use Loro\LoroText;
use Loro\TreeParentId;
use Loro\VersionVector;

final class CoreBehaviorTest extends LoroTestCase
{
    public function testVersionIsExposed(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', \Loro\getVersion());
    }

    public function testCounterIncrementEncodeAndEvent(): void
    {
        $doc = new LoroDoc();
        $counter = $doc->getCounter('counter');

        $counter->increment(1);
        $counter->increment(2);
        $counter->decrement(1);

        self::assertSame(2.0, $counter->getValue());

        $updates = $doc->export(ExportMode::updates(new VersionVector()));
        $snapshot = $doc->export(ExportMode::snapshot());
        $jsonUpdates = $doc->exportJsonUpdates(new VersionVector(), $doc->oplogVv());

        $fromUpdates = new LoroDoc();
        $fromUpdates->import($updates);
        self::assertEquals($doc->toJSON(), $fromUpdates->toJSON());

        $fromSnapshot = new LoroDoc();
        $fromSnapshot->import($snapshot);
        self::assertEquals($doc->toJSON(), $fromSnapshot->toJSON());

        $fromJson = new LoroDoc();
        $fromJson->importJsonUpdates($jsonUpdates);
        self::assertEquals($doc->toJSON(), $fromJson->toJSON());

        $eventDoc = new LoroDoc();
        $triggered = false;
        $subscription = $eventDoc->subscribeRoot(static function (DiffEvent $event) use (&$triggered): void {
            $triggered = true;
            $diff = $event->events[0]->diff;

            self::assertSame('Counter', $diff->variant);
            self::assertSame(-1.0, $diff->fields['diff']);
        });

        $eventCounter = $eventDoc->getCounter('counter');
        $eventCounter->increment(1);
        $eventCounter->increment(2);
        $eventCounter->decrement(4);
        $eventDoc->commit();
        $subscription->detach();

        self::assertTrue($triggered);
    }

    public function testSimpleCheckout(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(0);
        $text = $doc->getText('text');

        $text->insert(0, 'H');
        $doc->commit();

        $triggered = false;
        $subscription = $doc->subscribeRoot(static function (DiffEvent $event) use (&$triggered): void {
            self::assertContains($event->triggeredBy->variant, ['Checkout', 'Local']);
            $triggered = true;
        });

        $frontiers = $doc->oplogFrontiers();
        $text->insert(1, 'i');

        self::assertSame(['text' => 'Hi'], $doc->toJSON());
        self::assertFalse($doc->isDetached());

        $doc->checkout($frontiers);

        self::assertTrue($doc->isDetached());
        self::assertSame(['text' => 'H'], $doc->toJSON());
        self::assertTrue($triggered);

        $subscription->detach();
    }

    public function testCheckoutChineseCharactersByFrontierCounter(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText('text');

        $text->insert(0, '你好世界');
        $doc->commit();

        $ids = $doc->oplogFrontiers()->toVec();
        self::assertSame(3, $ids[0]->counter);

        $ids[0]->counter -= 1;
        $doc->checkout(Frontiers::fromIds($ids));
        self::assertSame(['text' => '你好世'], $doc->toJSON());

        $ids[0]->counter -= 1;
        $doc->checkout(Frontiers::fromIds($ids));
        self::assertSame(['text' => '你好'], $doc->toJSON());

        $ids[0]->counter -= 1;
        $doc->checkout(Frontiers::fromIds($ids));
        self::assertSame(['text' => '你'], $doc->toJSON());
    }

    public function testCompareFrontiersAcrossTwoClients(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText('text');
        $text->insert(0, '0');
        $doc->commit();

        $v0 = $doc->oplogFrontiers();
        $docB = new LoroDoc();
        $docB->import($doc->export(ExportMode::updates(new VersionVector())));

        self::assertSame('Equal', $docB->cmpWithFrontiers($v0)->variant);

        $text->insert(1, '0');
        $doc->commit();
        self::assertSame('Less', $docB->cmpWithFrontiers($doc->oplogFrontiers())->variant);

        $textB = $docB->getText('text');
        $textB->insert(0, '0');
        $docB->commit();
        self::assertSame('Less', $docB->cmpWithFrontiers($doc->oplogFrontiers())->variant);

        $docB->import($doc->export(ExportMode::updates(new VersionVector())));
        self::assertSame('Greater', $docB->cmpWithFrontiers($doc->oplogFrontiers())->variant);

        $doc->import($docB->export(ExportMode::updates(new VersionVector())));
        self::assertSame('Equal', $docB->cmpWithFrontiers($doc->oplogFrontiers())->variant);
    }

    public function testMovableListBasicsAndSync(): void
    {
        $doc = new LoroDoc();
        $list = $doc->getMovableList('list');

        self::assertSame(0, $list->len());
        $list->push('a');
        self::assertSame(1, $list->len());
        self::assertSame('a', $list->get(0)?->toJSON());
        self::assertSame('a', $list->pop()?->toJSON());
        self::assertSame(0, $list->len());

        $list->push('a');
        $list->push('b');
        $list->push('c');
        $list->set(2, 'd');
        $list->mov(0, 1);

        $doc2 = new LoroDoc();
        $list2 = $doc2->getMovableList('list');
        $doc2->import($doc->export(ExportMode::updates(new VersionVector())));

        self::assertSame(['b', 'a', 'd'], $list2->toJSON());
    }

    public function testMovableListSubContainersAndConcurrentSet(): void
    {
        $doc = new LoroDoc();
        $list = $doc->getMovableList('list');
        $list->push('a');
        $list->push('b');
        $list->push('c');

        $subList = $list->insertContainer(1, new LoroList());
        $subList->push('d');
        $subList->push('e');
        $subList->push('f');

        self::assertSame(['a', ['d', 'e', 'f'], 'b', 'c'], $doc->toJSON()['list']);

        $list->mov(1, 0);
        self::assertSame([['d', 'e', 'f'], 'a', 'b', 'c'], $doc->toJSON()['list']);

        $list->mov(0, 3);
        self::assertSame(['a', 'b', 'c', ['d', 'e', 'f']], $doc->toJSON()['list']);

        $docA = new LoroDoc();
        $docA->setPeerId(0);
        $listA = $docA->getMovableList('list');
        $listA->push('a');
        $listA->push('b');
        $listA->push('c');

        $docB = new LoroDoc();
        $docB->setPeerId(1);
        $listB = $docB->getMovableList('list');
        $docB->import($docA->export(ExportMode::updates(new VersionVector())));

        $listA->set(1, 'fromA');
        $listB->set(1, 'fromB');

        $docB->import($docA->export(ExportMode::updates(new VersionVector())));
        $docA->import($docB->export(ExportMode::updates(new VersionVector())));

        self::assertSame(['a', 'fromB', 'c'], $docA->toJSON()['list']);
        self::assertSame(['a', 'fromB', 'c'], $docB->toJSON()['list']);
    }

    public function testMovableListConcurrentMoveKeepsLength(): void
    {
        $docA = new LoroDoc();
        $docA->setPeerId(0);
        $listA = $docA->getMovableList('list');
        $listA->push('a');
        $listA->push('b');
        $listA->push('c');

        $docB = new LoroDoc();
        $docB->setPeerId(1);
        $listB = $docB->getMovableList('list');
        $docB->import($docA->export(ExportMode::updates(new VersionVector())));

        $listA->mov(0, 1);
        $listB->mov(0, 1);

        $docB->import($docA->export(ExportMode::updates(new VersionVector())));

        self::assertSame(3, $listB->len());
        self::assertSame(['b', 'a', 'c'], $docB->toJSON()['list']);
    }

    public function testMovableListCanBeInsertedIntoListAsAttachedContainer(): void
    {
        $doc = new LoroDoc();
        $list = $doc->getMovableList('list');
        $list->push('a');
        $list->push('b');
        $list->push('c');

        $parent = $doc->getList('parent');
        $newList = $parent->insertContainer(0, $list);

        self::assertSame([['a', 'b', 'c']], $doc->toJSON()['parent']);

        $newList->mov(0, 1);
        self::assertSame([['b', 'a', 'c']], $doc->toJSON()['parent']);

        $list->mov(0, 2);
        self::assertSame([['b', 'a', 'c']], $doc->toJSON()['parent']);
    }

    public function testTreeCreateMoveDeleteAndMeta(): void
    {
        $doc = new LoroDoc();
        $tree = $doc->getTree('root');
        $tree->enableFractionalIndex(0);

        $root = $tree->create(TreeParentId::root());
        $child = $tree->create(TreeParentId::node($root));
        $child2 = $tree->createAt(TreeParentId::node($root), 0);

        self::assertEquals($root, $tree->parent($child)->fields['id']);
        self::assertEquals([$child2, $child], $tree->children(TreeParentId::node($root)));
        self::assertTrue($tree->contains($child));

        $tree->mov($child2, TreeParentId::node($child));

        self::assertEquals($child, $tree->parent($child2)->fields['id']);
        self::assertEquals([$child2], $tree->children(TreeParentId::node($child)));

        $meta = $tree->getMeta($root);
        $meta->set('a', 123);
        self::assertSame(123, $meta->get('a')?->toJSON());

        $tree->delete($child);

        self::assertTrue($tree->contains($child));
        self::assertTrue($tree->isNodeDeleted($child));
        self::assertCount(3, $tree->nodes());
    }

    public function testListCanSetContainerAtPosition(): void
    {
        $doc = new LoroDoc();
        $list = $doc->getMovableList('list');

        $list->insert(0, 100);
        $text = $list->setContainer(0, new LoroText());
        $text->insert(0, 'Hello');

        self::assertSame(['Hello'], $doc->toJSON()['list']);
    }
}
