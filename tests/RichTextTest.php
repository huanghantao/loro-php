<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\ExpandType;
use Loro\ExportMode;
use Loro\LoroDoc;
use Loro\LoroValue;
use Loro\LoroText;
use Loro\PosType;
use Loro\StyleConfig;
use Loro\TextDelta;
use Loro\UniFFIException;
use Loro\VersionVector;

final class RichTextTest extends LoroTestCase
{
    public function testMarkAndUnmark(): void
    {
        $doc = new LoroDoc();
        $doc->configTextStyle([
            'bold' => 'after',
            'italic' => 'before',
        ]);
        $text = $doc->getText('text');

        $text->insert(0, 'Hello World!');
        $text->mark(0, 5, 'bold', true);
        $text->mark(6, 11, 'italic', true);

        self::assertSame([
            ['insert' => 'Hello', 'attributes' => ['bold' => true]],
            ['insert' => ' '],
            ['insert' => 'World', 'attributes' => ['italic' => true]],
            ['insert' => '!'],
        ], $text->toDeltaJSON());

        $beforeDelta = $text->toDeltaJSON();
        $beforeVersion = $doc->oplogVv();

        $text->unmark(0, 12, 'missing');
        $doc->commit();

        self::assertSame($beforeDelta, $text->toDeltaJSON());
        self::assertTrue($beforeVersion->eq($doc->oplogVv()));
    }

    public function testRichTextEventsAndImportEventsExposeTextDelta(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText('text');
        $localDelta = null;

        $subscription = $text->subscribe(static function ($event) use (&$localDelta): void {
            $localDelta = \Loro\UniFFITextStyleHelper::textDeltaToPhp($event->events[0]->diff->fields['diff']);
        });

        $text->insert(0, 'Hello World!');
        $text->mark(0, 5, 'bold', true);
        $doc->commit();
        $subscription?->detach();

        self::assertSame([
            ['insert' => 'Hello', 'attributes' => ['bold' => true]],
            ['insert' => ' World!'],
        ], $localDelta);

        $doc2 = new LoroDoc();
        $text2 = $doc2->getText('text');
        $importDelta = null;
        $subscription2 = $text2->subscribe(static function ($event) use (&$importDelta): void {
            $importDelta = \Loro\UniFFITextStyleHelper::textDeltaToPhp($event->events[0]->diff->fields['diff']);
        });

        $doc2->import($doc->export(ExportMode::updates(new VersionVector())));
        $subscription2?->detach();

        self::assertSame($localDelta, $importDelta);
    }

    public function testEmojiDeletionUtf8SliceCharAtSpliceAndUpdate(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText('text');
        $text->insert(0, '012345👨‍👩‍👦6789');
        $doc->commit();

        $text->mark(0, $text->lenUnicode(), 'bold', true);
        $doc->commit();
        $text->delete(6, 5);

        self::assertSame([
            ['insert' => '0123456789', 'attributes' => ['bold' => true]],
        ], $text->toDeltaJSON());

        $utf8 = $doc->getText('utf8');
        $utf8->insert(0, '你好');
        $utf8->insertUtf8(3, 'a');
        $utf8->insertUtf8(7, 'b');
        self::assertSame([['insert' => '你a好b']], $utf8->toDeltaJSON());
        $utf8->deleteUtf8(3, 4);
        self::assertSame([['insert' => '你b']], $utf8->toDeltaJSON());

        self::assertSame('你', $utf8->slice(0, 1));
        self::assertSame('b', $utf8->charAt(1));
        self::assertSame('b', $utf8->splice(1, 1, '我'));
        self::assertSame('你我', self::textString($utf8));

        $utf8->update('Hello World Bro😊', new \Loro\UpdateOptions(null, false));
        self::assertSame('Hello World Bro😊', self::textString($utf8));
    }

    public function testSliceDeltaAndApplyDeltaWithStyleRemoval(): void
    {
        $doc = new LoroDoc();
        $doc->configTextStyle([
            'bold' => 'after',
            'italic' => 'before',
            'emoji' => 'none',
        ]);
        $text = $doc->getText('text');
        $text->insert(0, 'Hello World!');
        $text->mark(0, 5, 'bold', true);
        $text->mark(6, 11, 'italic', true);

        self::assertSame([
            ['insert' => 'ello', 'attributes' => ['bold' => true]],
            ['insert' => ' '],
            ['insert' => 'Wo', 'attributes' => ['italic' => true]],
        ], $text->sliceDeltaJSON(1, 8, PosType::unicode()));
        self::assertSame([], $text->sliceDeltaJSON(5, 5, PosType::unicode()));

        $styled = $doc->getText('styled');
        $styled->applyDelta([
            TextDelta::insert('hello', ['bold' => LoroValue::bool(true)]),
        ]);
        $styled->applyDelta([
            TextDelta::retain(2, null),
            TextDelta::retain(2, ['bold' => LoroValue::null()]),
        ]);

        self::assertSame([
            ['insert' => 'he', 'attributes' => ['bold' => true]],
            ['insert' => 'll'],
            ['insert' => 'o', 'attributes' => ['bold' => true]],
        ], $styled->toDeltaJSON());
    }

    public function testCustomAndOverlappingStyles(): void
    {
        $doc = new LoroDoc();
        $doc->configTextStyle([
            'myStyle' => 'none',
            'comment' => 'none',
        ]);
        $text = $doc->getText('text');
        $text->insert(0, 'foo');
        $text->mark(0, 3, 'myStyle', 123);

        self::assertSame([
            ['insert' => 'foo', 'attributes' => ['myStyle' => 123]],
        ], $text->toDeltaJSON());

        $this->expectException(UniFFIException::class);
        $text->mark(0, 3, 'unknownStyle', 2);
    }

    public function testOverlappedStylesCanSharePrefixes(): void
    {
        $doc = new LoroDoc();
        $doc->configTextStyle(['comment' => 'none']);
        $text = $doc->getText('text');
        $text->insert(0, 'The fox jumped.');
        $text->mark(0, 7, 'comment:alice', 'Hi');
        $text->mark(4, 14, 'comment:bob', 'Jump');

        self::assertEquals([
            ['insert' => 'The ', 'attributes' => ['comment:alice' => 'Hi']],
            ['insert' => 'fox', 'attributes' => ['comment:alice' => 'Hi', 'comment:bob' => 'Jump']],
            ['insert' => ' jumped', 'attributes' => ['comment:bob' => 'Jump']],
            ['insert' => '.'],
        ], $text->toDeltaJSON());
    }

    public function testDefaultTextStyleConfigAllowsUnknownMarks(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText('text');
        $text->insert(0, 'Hello');

        $this->expectException(UniFFIException::class);
        $text->mark(0, 5, 'size', true);
    }

    public function testDefaultTextStyleConfigCanBeEnabled(): void
    {
        $doc = new LoroDoc();
        $doc->configDefaultTextStyle(new StyleConfig(ExpandType::before()));
        $text = $doc->getText('text');
        $text->insert(0, 'Hello');
        $text->mark(0, 5, 'size', true);

        self::assertSame([
            ['insert' => 'Hello', 'attributes' => ['size' => true]],
        ], $text->toDeltaJSON());
    }

    public function testDetachedTextKeepsContentAndStylesWhenInserted(): void
    {
        $text = new \Loro\LoroText();
        $text->insert(0, 'Hello');
        $text->mark(0, 5, 'bold', true);

        self::assertSame([
            ['insert' => 'Hello', 'attributes' => ['bold' => true]],
        ], $text->toDeltaJSON());

        $doc = new LoroDoc();
        $map = $doc->getMap('map');
        $attached = $map->setContainer('text', $text);
        self::assertInstanceOf(\Loro\LoroText::class, $attached);

        self::assertSame(['map' => ['text' => 'Hello']], $doc->toJSON());
        self::assertSame([
            ['insert' => 'Hello', 'attributes' => ['bold' => true]],
        ], $attached->toDeltaJSON());

        $text->insert(0, 'Detached ');
        $attached->insert(0, 'Attached ');

        self::assertSame('Detached Hello', self::textString($text));
        self::assertSame(['map' => ['text' => 'Attached Hello']], $doc->toJSON());
    }

    public function testDetachedUnicodeTextKeepsStyleWhenInserted(): void
    {
        $text = new LoroText();
        $text->insert(0, '你好吗');
        $text->mark(0, 3, 'bold', true);

        $doc = new LoroDoc();
        $map = $doc->getMap('map');
        $attached = $map->setContainer('text', $text);
        self::assertInstanceOf(LoroText::class, $attached);

        self::assertSame(['map' => ['text' => '你好吗']], $doc->toJSON());
        self::assertSame([
            ['insert' => '你好吗', 'attributes' => ['bold' => true]],
        ], $attached->toDeltaJSON());
    }

    public function testDetachedPartialStylesArePreservedWhenInserted(): void
    {
        $text = new LoroText();
        $text->insert(0, 'abcDEF');
        $text->mark(0, 3, 'bold', true);

        $doc = new LoroDoc();
        $list = $doc->getList('list');
        $attached = $list->insertContainer(0, $text);
        self::assertInstanceOf(LoroText::class, $attached);

        self::assertSame([
            ['insert' => 'abc', 'attributes' => ['bold' => true]],
            ['insert' => 'DEF'],
        ], $attached->toDeltaJSON());
    }

    public function testAttachedTextCanBeCopiedWithoutSharingFutureEdits(): void
    {
        $doc = new LoroDoc();
        $source = $doc->getText('source');
        $source->insert(0, 'root');

        $list = $doc->getList('list');
        $list->insertContainer(0, $source);
        $copied = $list->get(0)?->asLoroText();
        self::assertInstanceOf(LoroText::class, $copied);

        $source->insert(4, '-updated');

        self::assertSame('root-updated', self::textString($source));
        self::assertSame('root', self::textString($copied));
        $json = $doc->toJSON();
        self::assertSame('root-updated', $json['source']);
        self::assertSame(['root'], $json['list']);
    }

    public function testAttachedTextFromAnotherDocumentCannotBeInserted(): void
    {
        $docA = new LoroDoc();
        $textA = $docA->getText('text');
        $textA->insert(0, 'cross');

        $docB = new LoroDoc();
        $listB = $docB->getList('list');

        $this->expectException(\InvalidArgumentException::class);
        $listB->insertContainer(0, $textA);
    }

    public function testApplyEmptyDeltaIsNoop(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText('text');
        $text->insert(0, 'hello');

        $text->applyDelta([]);

        self::assertSame([['insert' => 'hello']], $text->toDeltaJSON());
    }
}
