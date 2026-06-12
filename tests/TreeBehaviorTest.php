<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\Container;
use Loro\DiffEvent;
use Loro\Events;
use Loro\LoroDoc;
use Loro\TreeId;
use Loro\TreeParentId;
use Loro\Value;

final class TreeBehaviorTest extends LoroTestCase
{
    public function testCreateAtMoveBeforeAfterAndMoveToMaintainOrder(): void
    {
        $doc = new LoroDoc();
        $tree = $doc->getTree('root');
        $tree->enableFractionalIndex(0);

        $root = $tree->create(TreeParentId::root());
        $child = $tree->create(TreeParentId::node($root));
        $child2 = $tree->createAt(TreeParentId::node($root), 0);

        self::assertEquals([$root], $tree->roots());
        self::assertEquals([$child2, $child], $tree->children(TreeParentId::node($root)));
        self::assertSame(2, $tree->childrenNum(TreeParentId::node($root)));
        self::assertSame('80', $tree->fractionalIndex($root));

        $tree->movAfter($child2, $child);
        self::assertEquals([$child, $child2], $tree->children(TreeParentId::node($root)));

        $tree->movBefore($child2, $child);
        self::assertEquals([$child2, $child], $tree->children(TreeParentId::node($root)));

        $tree->movTo($child2, TreeParentId::node($child), 0);
        self::assertEquals($child, $tree->parent($child2)->fields['id']);
        self::assertEquals([$child2], $tree->children(TreeParentId::node($child)));
        self::assertEquals([$child], $tree->children(TreeParentId::node($root)));
    }

    public function testDeleteKeepsNodeAddressableAndRemovesItFromVisibleChildren(): void
    {
        $doc = new LoroDoc();
        $tree = $doc->getTree('root');
        $root = $tree->create(TreeParentId::root());
        $child = $tree->create(TreeParentId::node($root));
        $child2 = $tree->create(TreeParentId::node($root));

        Container::insertMapValue($tree->getMeta($child), 'name', 'child');
        self::assertSame('child', Value::toPhp($tree->getMeta($child)->get('name')));
        self::assertTrue($tree->contains($child));

        $tree->delete($child);

        self::assertTrue($tree->contains($child));
        self::assertTrue($tree->isNodeDeleted($child));
        self::assertEquals([$child2], $tree->children(TreeParentId::node($root)));
        self::assertContainsTreeId($child, $tree->nodes());
        self::assertContainsTreeId($child2, $tree->nodes());
    }

    public function testValueWithMetaIncludesNodeMetadataAndParentShape(): void
    {
        $doc = new LoroDoc();
        $tree = $doc->getTree('root');
        $root = $tree->create(TreeParentId::root());
        $child = $tree->create(TreeParentId::node($root));

        Container::insertMapValue($tree->getMeta($root), 'name', 'root');
        Container::insertMapValue($tree->getMeta($child), 'name', 'child');

        $value = Value::toPhp($tree->getValueWithMeta());

        self::assertCount(1, $value);
        self::assertNull($value[0]['parent']);
        self::assertSame('root', $value[0]['meta']['name']);
        self::assertSame(0, $value[0]['index']);
        self::assertSame(self::treeIdToString($root), $value[0]['id']);

        self::assertCount(1, $value[0]['children']);
        self::assertSame(self::treeIdToString($root), $value[0]['children'][0]['parent']);
        self::assertSame('child', $value[0]['children'][0]['meta']['name']);
        self::assertSame(0, $value[0]['children'][0]['index']);
        self::assertSame(self::treeIdToString($child), $value[0]['children'][0]['id']);
        self::assertSame([], $value[0]['children'][0]['children']);
    }

    public function testValueWithMetaHidesDeletedNodesAndReindexesSiblings(): void
    {
        $doc = new LoroDoc();
        $tree = $doc->getTree('root');
        $root = $tree->create(TreeParentId::root());
        $child = $tree->create(TreeParentId::node($root));
        $sibling = $tree->create(TreeParentId::node($root));

        Container::insertMapValue($tree->getMeta($child), 'name', 'child');
        Container::insertMapValue($tree->getMeta($sibling), 'name', 'sibling');
        $tree->delete($child);

        $value = Value::toPhp($tree->getValueWithMeta());

        self::assertTrue($tree->isNodeDeleted($child));
        self::assertCount(1, $value[0]['children']);
        self::assertSame(self::treeIdToString($sibling), $value[0]['children'][0]['id']);
        self::assertSame('sibling', $value[0]['children'][0]['meta']['name']);
        self::assertSame(0, $value[0]['children'][0]['index']);
    }

    public function testTreeSubscriptionExposesMoveOldParentAndIndex(): void
    {
        $doc = new LoroDoc();
        $tree = $doc->getTree('root');
        $root = $tree->create(TreeParentId::root());
        $child = $tree->create(TreeParentId::node($root));
        $child2 = $tree->create(TreeParentId::node($root));
        $doc->commit();

        $events = [];
        $subscription = Events::subscribeContainer($tree, static function (DiffEvent $event) use (&$events): void {
            $events[] = $event;
        });
        self::assertNotNull($subscription);

        $tree->mov($child2, TreeParentId::node($child));
        $doc->commit();

        self::assertCount(1, $events);
        $diff = $events[0]->events[0]->diff;
        self::assertSame('Tree', $diff->variant);

        $item = $diff->fields['diff']->diff[0];
        self::assertEquals($child2, $item->target);
        self::assertSame('Move', $item->action->variant);
        self::assertSame(1, $item->action->fields['oldIndex']);
        self::assertEquals($root, $item->action->fields['oldParent']->fields['id']);
        self::assertEquals($child, $item->action->fields['parent']->fields['id']);
        self::assertSame(0, $item->action->fields['index']);

        $subscription->unsubscribe();
    }

    public function testMovingNodeUnderItsDescendantThrows(): void
    {
        $doc = new LoroDoc();
        $tree = $doc->getTree('root');
        $root = $tree->create(TreeParentId::root());
        $child = $tree->create(TreeParentId::node($root));

        $this->expectException(\Throwable::class);
        $tree->mov($root, TreeParentId::node($child));
    }

    /**
     * @param array<TreeId> $nodes
     */
    private static function assertContainsTreeId(TreeId $needle, array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node == $needle) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail('TreeId was not found');
    }

    private static function treeIdToString(TreeId $id): string
    {
        return $id->counter . '@' . $id->peer;
    }
}
