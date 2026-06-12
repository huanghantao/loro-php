<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\DiffEvent;
use Loro\ExportMode;
use Loro\Frontiers;
use Loro\LoroDoc;
use Loro\LoroList;
use Loro\LoroMovableList;
use Loro\LoroValue;
use Loro\PosType;
use Loro\TextDelta;
use Loro\UndoManager;
use Loro\UndoOrRedo;
use Loro\VersionVector;

final class LoroTest extends LoroTestCase
{
    public function testEvent(): void
    {
        $doc = new LoroDoc();
        $num = 0;
        $sub = $doc->subscribeRoot(static function (DiffEvent $event) use (&$num): void {
            $num++;
        });

        $list = $doc->getList('list');
        $list->insert(0, 123);
        $doc->commit();
        $sub->detach();

        self::assertSame(1, $num);
    }

    public function testOptional(): void
    {
        $doc = new LoroDoc();

        $list = $doc->getList('list');
        $list->insert(0, null);

        $map = $doc->getMap('map');
        $map->set('key', null);

        $movableList = $doc->getMovableList('movableList');
        $movableList->insert(0, null);
        $movableList->set(0, null);

        $doc->commit();

        self::assertLoroValueEquals(LoroValue::null(), $list->get(0)?->asValue());
        self::assertLoroValueEquals(LoroValue::null(), $map->get('key')?->asValue());
        self::assertLoroValueEquals(LoroValue::null(), $movableList->get(0)?->asValue());
    }

    public function testText(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText('text');
        $text->insert(0, 'abc');
        $text->delete(0, 1);

        self::assertSame('bc', self::textString($text));
    }

    public function testMovableList(): void
    {
        $doc = new LoroDoc();
        $movableList = $doc->getMovableList('movableList');

        self::assertTrue($movableList->isAttached());
        self::assertFalse((new LoroMovableList())->isAttached());
    }

    public function testMap(): void
    {
        $doc = new LoroDoc();
        $map = $doc->getMap('map');

        $map->getOrCreateContainer('list', new LoroList());
        $map->set('key', 'value');

        self::assertLoroValueEquals(LoroValue::string('value'), $map->get('key')?->asValue());
    }

    public function testSync(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(0);

        $text = $doc->getText('text');
        $text->insert(0, 'abc');
        $text->delete(0, 1);
        self::assertSame('bc', self::textString($text));

        $doc2 = new LoroDoc();
        $doc2->setPeerId(1);

        $text2 = $doc2->getText('text');
        $text2->insert(0, '123');

        $doc2->import($doc->export(ExportMode::snapshot()));
        $doc2->importBatch([
            $doc->exportSnapshot(),
            $doc->export(ExportMode::updates(new VersionVector())),
        ]);

        self::assertSame('bc123', self::textString($text2));
    }

    public function testCheckout(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText('text');
        $text->insert(0, 'abc');
        $text->delete(0, 1);

        $startFrontiers = $doc->oplogFrontiers();
        $doc->checkout($startFrontiers);
        $doc->checkoutToLatest();

        self::assertInstanceOf(Frontiers::class, $startFrontiers);
    }

    public function testUndo(): void
    {
        $doc = new LoroDoc();
        $undoManager = new UndoManager($doc);

        $n = 0;
        $undoManager->setOnPopHandler(static function (UndoOrRedo $undoOrRedo, mixed $span, mixed $item) use (&$n): void {
            $n++;
        });

        $text = $doc->getText('text');
        $text->insert(0, 'abc');
        $doc->commit();
        $text->delete(0, 1);
        $doc->commit();

        self::assertSame('bc', self::textString($text));

        $undoManager->undo();

        self::assertSame('abc', self::textString($text));
        self::assertSame(1, $n);
    }

    public function testApplyDelta(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText('text');
        $text->insert(0, 'abc');
        $text->applyDelta([
            TextDelta::delete(1),
            TextDelta::retain(2, null),
            TextDelta::insert('def', null),
        ]);

        self::assertSame('bcdef', self::textString($text));
    }

    public function testTextUtf16AndPosConversion(): void
    {
        $emoji = "\u{1F600}";
        $doc = new LoroDoc();
        $text = $doc->getText('text');
        $text->insert(0, 'A' . $emoji . 'C');

        $utf16Pos = $text->convertPos(1, PosType::unicode(), PosType::utf16());
        self::assertSame(1, $utf16Pos);

        $text->insertUtf16($utf16Pos, 'B');
        self::assertSame('AB' . $emoji . 'C', self::textString($text));

        self::assertSame('B', $text->sliceUtf16(1, 3));

        $delta = $text->sliceDelta(1, 3, PosType::unicode());
        self::assertCount(1, $delta);
        self::assertSame('Insert', $delta[0]->variant);
        self::assertSame('B' . $emoji, $delta[0]->fields['insert'] ?? null);
        self::assertTrue(($delta[0]->fields['attributes'] ?? null) === null || !array_key_exists('bold', $delta[0]->fields['attributes']));
    }

    public function testOrigin(): void
    {
        $localDoc = new LoroDoc();
        $remoteDoc = new LoroDoc();

        $localDoc->setPeerId(1);
        $localMap = $localDoc->getMap('properties');
        $localMap->set('x', '42');

        $snapshot = $localDoc->exportSnapshot();

        $remoteDoc->setPeerId(2);
        $expectedOriginString = 'expectedOriginString';
        $events = 0;

        $subscription = $remoteDoc->subscribeRoot(static function (DiffEvent $event) use ($expectedOriginString, &$events): void {
            $events++;
            self::assertSame($expectedOriginString, $event->origin);
            self::assertSame('Import', $event->triggeredBy->variant);
        });

        $remoteDoc->importWith($snapshot, $expectedOriginString);
        $subscription->detach();

        self::assertSame(1, $events);
    }
}
