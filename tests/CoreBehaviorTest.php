<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\Container;
use Loro\DiffEvent;
use Loro\Events;
use Loro\Export;
use Loro\Frontiers;
use Loro\Loro;
use Loro\LoroDoc;
use Loro\LoroList;
use Loro\LoroText;
use Loro\TreeParentId;
use Loro\Value;
use Loro\VersionVector;

final class CoreBehaviorTest extends LoroTestCase
{
    public function testVersionIsExposed(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', Loro::version());
    }

    public function testCounterIncrementEncodeAndEvent(): void
    {
        $doc = new LoroDoc();
        $counter = $doc->getCounter(Container::idLike('counter'));

        $counter->increment(1);
        $counter->increment(2);
        $counter->decrement(1);

        self::assertSame(2.0, $counter->getValue());

        $updates = $doc->export(Export::updates(new VersionVector()));
        $snapshot = $doc->export(Export::snapshot());
        $jsonUpdates = $doc->exportJsonUpdates(new VersionVector(), $doc->oplogVv());

        $fromUpdates = new LoroDoc();
        $fromUpdates->import($updates);
        self::assertEquals(Loro::toJson($doc), Loro::toJson($fromUpdates));

        $fromSnapshot = new LoroDoc();
        $fromSnapshot->import($snapshot);
        self::assertEquals(Loro::toJson($doc), Loro::toJson($fromSnapshot));

        $fromJson = new LoroDoc();
        $fromJson->importJsonUpdates($jsonUpdates);
        self::assertEquals(Loro::toJson($doc), Loro::toJson($fromJson));

        $eventDoc = new LoroDoc();
        $triggered = false;
        $subscription = Events::subscribeRoot($eventDoc, static function (DiffEvent $event) use (&$triggered): void {
            $triggered = true;
            $diff = $event->events[0]->diff;

            self::assertSame('Counter', $diff->variant);
            self::assertSame(-1.0, $diff->fields['diff']);
        });

        $eventCounter = $eventDoc->getCounter(Container::idLike('counter'));
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
        $text = $doc->getText(Container::idLike('text'));

        $text->insert(0, 'H');
        $doc->commit();

        $triggered = false;
        $subscription = Events::subscribeRoot($doc, static function (DiffEvent $event) use (&$triggered): void {
            self::assertContains($event->triggeredBy->variant, ['Checkout', 'Local']);
            $triggered = true;
        });

        $frontiers = $doc->oplogFrontiers();
        $text->insert(1, 'i');

        self::assertSame(['text' => 'Hi'], Loro::toJson($doc));
        self::assertFalse($doc->isDetached());

        $doc->checkout($frontiers);

        self::assertTrue($doc->isDetached());
        self::assertSame(['text' => 'H'], Loro::toJson($doc));
        self::assertTrue($triggered);

        $subscription->detach();
    }

    public function testCheckoutChineseCharactersByFrontierCounter(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText(Container::idLike('text'));

        $text->insert(0, '你好世界');
        $doc->commit();

        $ids = $doc->oplogFrontiers()->toVec();
        self::assertSame(3, $ids[0]->counter);

        $ids[0]->counter -= 1;
        $doc->checkout(Frontiers::fromIds($ids));
        self::assertSame(['text' => '你好世'], Loro::toJson($doc));

        $ids[0]->counter -= 1;
        $doc->checkout(Frontiers::fromIds($ids));
        self::assertSame(['text' => '你好'], Loro::toJson($doc));

        $ids[0]->counter -= 1;
        $doc->checkout(Frontiers::fromIds($ids));
        self::assertSame(['text' => '你'], Loro::toJson($doc));
    }

    public function testCompareFrontiersAcrossTwoClients(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText(Container::idLike('text'));
        $text->insert(0, '0');
        $doc->commit();

        $v0 = $doc->oplogFrontiers();
        $docB = new LoroDoc();
        $docB->import($doc->export(Export::updates(new VersionVector())));

        self::assertSame('Equal', $docB->cmpWithFrontiers($v0)->variant);

        $text->insert(1, '0');
        $doc->commit();
        self::assertSame('Less', $docB->cmpWithFrontiers($doc->oplogFrontiers())->variant);

        $textB = $docB->getText(Container::idLike('text'));
        $textB->insert(0, '0');
        $docB->commit();
        self::assertSame('Less', $docB->cmpWithFrontiers($doc->oplogFrontiers())->variant);

        $docB->import($doc->export(Export::updates(new VersionVector())));
        self::assertSame('Greater', $docB->cmpWithFrontiers($doc->oplogFrontiers())->variant);

        $doc->import($docB->export(Export::updates(new VersionVector())));
        self::assertSame('Equal', $docB->cmpWithFrontiers($doc->oplogFrontiers())->variant);
    }

    public function testMovableListBasicsAndSync(): void
    {
        $doc = new LoroDoc();
        $list = $doc->getMovableList(Container::idLike('list'));

        self::assertSame(0, $list->len());
        Container::pushListValue($list, 'a');
        self::assertSame(1, $list->len());
        self::assertSame('a', Value::toPhp($list->get(0)));
        self::assertSame('a', Value::toPhp($list->pop()));
        self::assertSame(0, $list->len());

        Container::pushListValue($list, 'a');
        Container::pushListValue($list, 'b');
        Container::pushListValue($list, 'c');
        Container::setMovableListValue($list, 2, 'd');
        $list->mov(0, 1);

        $doc2 = new LoroDoc();
        $list2 = $doc2->getMovableList(Container::idLike('list'));
        $doc2->import($doc->export(Export::updates(new VersionVector())));

        self::assertSame(['b', 'a', 'd'], Value::toPhp($list2->getDeepValue()));
    }

    public function testMovableListSubContainersAndConcurrentSet(): void
    {
        $doc = new LoroDoc();
        $list = $doc->getMovableList(Container::idLike('list'));
        Container::pushListValue($list, 'a');
        Container::pushListValue($list, 'b');
        Container::pushListValue($list, 'c');

        $subList = Container::insertListContainer($list, 1, new LoroList());
        Container::pushListValue($subList, 'd');
        Container::pushListValue($subList, 'e');
        Container::pushListValue($subList, 'f');

        self::assertSame(['a', ['d', 'e', 'f'], 'b', 'c'], Loro::toJson($doc)['list']);

        $list->mov(1, 0);
        self::assertSame([['d', 'e', 'f'], 'a', 'b', 'c'], Loro::toJson($doc)['list']);

        $list->mov(0, 3);
        self::assertSame(['a', 'b', 'c', ['d', 'e', 'f']], Loro::toJson($doc)['list']);

        $docA = new LoroDoc();
        $docA->setPeerId(0);
        $listA = $docA->getMovableList(Container::idLike('list'));
        Container::pushListValue($listA, 'a');
        Container::pushListValue($listA, 'b');
        Container::pushListValue($listA, 'c');

        $docB = new LoroDoc();
        $docB->setPeerId(1);
        $listB = $docB->getMovableList(Container::idLike('list'));
        $docB->import($docA->export(Export::updates(new VersionVector())));

        Container::setMovableListValue($listA, 1, 'fromA');
        Container::setMovableListValue($listB, 1, 'fromB');

        $docB->import($docA->export(Export::updates(new VersionVector())));
        $docA->import($docB->export(Export::updates(new VersionVector())));

        self::assertSame(['a', 'fromB', 'c'], Loro::toJson($docA)['list']);
        self::assertSame(['a', 'fromB', 'c'], Loro::toJson($docB)['list']);
    }

    public function testMovableListConcurrentMoveKeepsLength(): void
    {
        $docA = new LoroDoc();
        $docA->setPeerId(0);
        $listA = $docA->getMovableList(Container::idLike('list'));
        Container::pushListValue($listA, 'a');
        Container::pushListValue($listA, 'b');
        Container::pushListValue($listA, 'c');

        $docB = new LoroDoc();
        $docB->setPeerId(1);
        $listB = $docB->getMovableList(Container::idLike('list'));
        $docB->import($docA->export(Export::updates(new VersionVector())));

        $listA->mov(0, 1);
        $listB->mov(0, 1);

        $docB->import($docA->export(Export::updates(new VersionVector())));

        self::assertSame(3, $listB->len());
        self::assertSame(['b', 'a', 'c'], Loro::toJson($docB)['list']);
    }

    public function testMovableListCanBeInsertedIntoListAsAttachedContainer(): void
    {
        $doc = new LoroDoc();
        $list = $doc->getMovableList(Container::idLike('list'));
        Container::pushListValue($list, 'a');
        Container::pushListValue($list, 'b');
        Container::pushListValue($list, 'c');

        $parent = $doc->getList(Container::idLike('parent'));
        $newList = Container::insertListContainer($parent, 0, $list);

        self::assertSame([['a', 'b', 'c']], Loro::toJson($doc)['parent']);

        $newList->mov(0, 1);
        self::assertSame([['b', 'a', 'c']], Loro::toJson($doc)['parent']);

        $list->mov(0, 2);
        self::assertSame([['b', 'a', 'c']], Loro::toJson($doc)['parent']);
    }

    public function testTreeCreateMoveDeleteAndMeta(): void
    {
        $doc = new LoroDoc();
        $tree = $doc->getTree(Container::idLike('root'));
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
        Container::insertMapValue($meta, 'a', 123);
        self::assertSame(123, Value::toPhp($meta->get('a')));

        $tree->delete($child);

        self::assertTrue($tree->contains($child));
        self::assertTrue($tree->isNodeDeleted($child));
        self::assertCount(3, $tree->nodes());
    }

    public function testListCanSetContainerAtPosition(): void
    {
        $doc = new LoroDoc();
        $list = $doc->getMovableList(Container::idLike('list'));

        Container::insertListValue($list, 0, 100);
        $text = Container::setMovableListContainer($list, 0, new LoroText());
        $text->insert(0, 'Hello');

        self::assertSame(['Hello'], Loro::toJson($doc)['list']);
    }
}
