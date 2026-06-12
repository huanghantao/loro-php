<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\Cursor;
use Loro\ExportMode;
use Loro\Frontiers;
use Loro\Id;
use Loro\LoroDoc;
use Loro\Side;
use Loro\VersionVector;

final class CursorAndForkBehaviorTest extends LoroTestCase
{
    public function testListCursorTracksStablePositionThroughInsertAndDelete(): void
    {
        $doc = new LoroDoc();
        $list = $doc->getList('list');

        $list->insert(0, 'a');
        $cursor = $list->getCursor(0, Side::left());
        self::assertNotNull($cursor);

        $list->insert(1, 'b');
        $pos = $doc->getCursorPos($cursor);
        self::assertSame(0, $pos->current->pos);
        self::assertSame('Left', $pos->current->side->variant);
        self::assertNull($pos->update);

        $list->insert(0, 'c');
        $pos = $doc->getCursorPos($cursor);
        self::assertSame(1, $pos->current->pos);
        self::assertSame('Left', $pos->current->side->variant);
        self::assertNull($pos->update);

        $list->delete(1, 1);
        $pos = $doc->getCursorPos($cursor);
        self::assertSame(1, $pos->current->pos);
        self::assertSame('Left', $pos->current->side->variant);
        self::assertNotNull($pos->update);
    }

    public function testCursorFromAnotherDocumentCannotBeResolved(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText('text');
        $text->insert(0, 'hello');
        $cursor = $text->getCursor(2, Side::middle());
        self::assertNotNull($cursor);

        $this->expectException(\Throwable::class);
        (new LoroDoc())->getCursorPos($cursor);
    }

    public function testCursorEncodeDecodeRoundTrips(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText('text');
        $text->insert(0, 'hello');

        $cursor = $text->getCursor(2, Side::middle());
        self::assertNotNull($cursor);

        $decoded = Cursor::decode($cursor->encode());
        $pos = $doc->getCursorPos($decoded);

        self::assertSame(2, $pos->current->pos);
        self::assertSame('Middle', $pos->current->side->variant);
        self::assertNull($pos->update);
    }

    public function testForkAtCanBranchFromEarlierFrontiersAndMergeBack(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(0);
        $text = $doc->getText('text');
        $text->insert(0, 'Hello, world!');

        $branch = $doc->forkAt(Frontiers::fromIds([new Id(0, 5)]));
        $branch->setPeerId(1);
        $branch->getText('text')->insert(6, ' Alice!');

        $doc->checkoutToLatest();
        $doc->import($branch->export(ExportMode::updates(new VersionVector())));

        self::assertSame('Hello, world! Alice!', self::textString($text));
    }

    public function testForkAtInvalidFrontiersThrows(): void
    {
        $doc = new LoroDoc();

        $this->expectException(\Throwable::class);
        $doc->forkAt(Frontiers::fromIds([new Id(9, 9)]));
    }

    public function testGetContainerByIdReturnsLiveHandle(): void
    {
        $doc = new LoroDoc();
        $map = $doc->getMap('map');
        $map->set('ab', 123);

        $handle = $doc->getContainer($map->id());
        self::assertNotNull($handle);
        $map2 = $handle->asLoroMap();
        self::assertNotNull($map2);
        self::assertEquals($map->toJSON(), $map2->toJSON());

        $map2->set('0', 12);
        self::assertSame(12, $map->get('0')?->toJSON());
    }
}
