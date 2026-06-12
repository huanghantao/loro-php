<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\Container;
use Loro\Events;
use Loro\ExpandType;
use Loro\Export;
use Loro\Loro;
use Loro\LoroDoc;
use Loro\LoroText;
use Loro\PosType;
use Loro\StyleConfig;
use Loro\TextDelta;
use Loro\UniFFIException;
use Loro\Value;
use Loro\VersionVector;

final class RichTextTest extends LoroTestCase
{
    public function testMarkAndUnmark(): void
    {
        $doc = new LoroDoc();
        Loro::configureTextStyle($doc, [
            'bold' => 'after',
            'italic' => 'before',
        ]);
        $text = $doc->getText(Container::idLike('text'));

        $text->insert(0, 'Hello World!');
        Container::markText($text, 0, 5, 'bold', true);
        Container::markText($text, 6, 11, 'italic', true);

        self::assertSame([
            ['insert' => 'Hello', 'attributes' => ['bold' => true]],
            ['insert' => ' '],
            ['insert' => 'World', 'attributes' => ['italic' => true]],
            ['insert' => '!'],
        ], Loro::textDeltaToPhp($text->toDelta()));

        $beforeDelta = Loro::textDeltaToPhp($text->toDelta());
        $beforeVersion = $doc->oplogVv();

        $text->unmark(0, 12, 'missing');
        $doc->commit();

        self::assertSame($beforeDelta, Loro::textDeltaToPhp($text->toDelta()));
        self::assertTrue($beforeVersion->eq($doc->oplogVv()));
    }

    public function testRichTextEventsAndImportEventsExposeTextDelta(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText(Container::idLike('text'));
        $localDelta = null;

        $subscription = Events::subscribeContainer($text, static function ($event) use (&$localDelta): void {
            $localDelta = Loro::textDeltaToPhp($event->events[0]->diff->fields['diff']);
        });

        $text->insert(0, 'Hello World!');
        Container::markText($text, 0, 5, 'bold', true);
        $doc->commit();
        $subscription?->detach();

        self::assertSame([
            ['insert' => 'Hello', 'attributes' => ['bold' => true]],
            ['insert' => ' World!'],
        ], $localDelta);

        $doc2 = new LoroDoc();
        $text2 = $doc2->getText(Container::idLike('text'));
        $importDelta = null;
        $subscription2 = Events::subscribeContainer($text2, static function ($event) use (&$importDelta): void {
            $importDelta = Loro::textDeltaToPhp($event->events[0]->diff->fields['diff']);
        });

        $doc2->import($doc->export(Export::updates(new VersionVector())));
        $subscription2?->detach();

        self::assertSame($localDelta, $importDelta);
    }

    public function testEmojiDeletionUtf8SliceCharAtSpliceAndUpdate(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText(Container::idLike('text'));
        $text->insert(0, '012345👨‍👩‍👦6789');
        $doc->commit();

        Container::markText($text, 0, $text->lenUnicode(), 'bold', true);
        $doc->commit();
        $text->delete(6, 5);

        self::assertSame([
            ['insert' => '0123456789', 'attributes' => ['bold' => true]],
        ], Loro::textDeltaToPhp($text->toDelta()));

        $utf8 = $doc->getText(Container::idLike('utf8'));
        $utf8->insert(0, '你好');
        $utf8->insertUtf8(3, 'a');
        $utf8->insertUtf8(7, 'b');
        self::assertSame([['insert' => '你a好b']], Loro::textDeltaToPhp($utf8->toDelta()));
        $utf8->deleteUtf8(3, 4);
        self::assertSame([['insert' => '你b']], Loro::textDeltaToPhp($utf8->toDelta()));

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
        Loro::configureTextStyle($doc, [
            'bold' => 'after',
            'italic' => 'before',
            'emoji' => 'none',
        ]);
        $text = $doc->getText(Container::idLike('text'));
        $text->insert(0, 'Hello World!');
        Container::markText($text, 0, 5, 'bold', true);
        Container::markText($text, 6, 11, 'italic', true);

        self::assertSame([
            ['insert' => 'ello', 'attributes' => ['bold' => true]],
            ['insert' => ' '],
            ['insert' => 'Wo', 'attributes' => ['italic' => true]],
        ], Loro::textDeltaToPhp($text->sliceDelta(1, 8, PosType::unicode())));
        self::assertSame([], Loro::textDeltaToPhp($text->sliceDelta(5, 5, PosType::unicode())));

        $styled = $doc->getText(Container::idLike('styled'));
        $styled->applyDelta([
            TextDelta::insert('hello', ['bold' => Value::bool(true)]),
        ]);
        $styled->applyDelta([
            TextDelta::retain(2, null),
            TextDelta::retain(2, ['bold' => Value::null()]),
        ]);

        self::assertSame([
            ['insert' => 'he', 'attributes' => ['bold' => true]],
            ['insert' => 'll'],
            ['insert' => 'o', 'attributes' => ['bold' => true]],
        ], Loro::textDeltaToPhp($styled->toDelta()));
    }

    public function testCustomAndOverlappingStyles(): void
    {
        $doc = new LoroDoc();
        Loro::configureTextStyle($doc, [
            'myStyle' => 'none',
            'comment' => 'none',
        ]);
        $text = $doc->getText(Container::idLike('text'));
        $text->insert(0, 'foo');
        Container::markText($text, 0, 3, 'myStyle', 123);

        self::assertSame([
            ['insert' => 'foo', 'attributes' => ['myStyle' => 123]],
        ], Loro::textDeltaToPhp($text->toDelta()));

        $this->expectException(UniFFIException::class);
        Container::markText($text, 0, 3, 'unknownStyle', 2);
    }

    public function testOverlappedStylesCanSharePrefixes(): void
    {
        $doc = new LoroDoc();
        Loro::configureTextStyle($doc, ['comment' => 'none']);
        $text = $doc->getText(Container::idLike('text'));
        $text->insert(0, 'The fox jumped.');
        Container::markText($text, 0, 7, 'comment:alice', 'Hi');
        Container::markText($text, 4, 14, 'comment:bob', 'Jump');

        self::assertEquals([
            ['insert' => 'The ', 'attributes' => ['comment:alice' => 'Hi']],
            ['insert' => 'fox', 'attributes' => ['comment:alice' => 'Hi', 'comment:bob' => 'Jump']],
            ['insert' => ' jumped', 'attributes' => ['comment:bob' => 'Jump']],
            ['insert' => '.'],
        ], Loro::textDeltaToPhp($text->toDelta()));
    }

    public function testDefaultTextStyleConfigAllowsUnknownMarks(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText(Container::idLike('text'));
        $text->insert(0, 'Hello');

        $this->expectException(UniFFIException::class);
        Container::markText($text, 0, 5, 'size', true);
    }

    public function testDefaultTextStyleConfigCanBeEnabled(): void
    {
        $doc = new LoroDoc();
        $doc->configDefaultTextStyle(new StyleConfig(ExpandType::before()));
        $text = $doc->getText(Container::idLike('text'));
        $text->insert(0, 'Hello');
        Container::markText($text, 0, 5, 'size', true);

        self::assertSame([
            ['insert' => 'Hello', 'attributes' => ['size' => true]],
        ], Loro::textDeltaToPhp($text->toDelta()));
    }

    public function testDetachedTextKeepsContentAndStylesWhenInserted(): void
    {
        $text = new \Loro\LoroText();
        $text->insert(0, 'Hello');
        Container::markText($text, 0, 5, 'bold', true);

        self::assertSame([
            ['insert' => 'Hello', 'attributes' => ['bold' => true]],
        ], Loro::textDeltaToPhp($text->toDelta()));

        $doc = new LoroDoc();
        $map = $doc->getMap(Container::idLike('map'));
        $attached = Container::insertMapContainer($map, 'text', $text);
        self::assertInstanceOf(\Loro\LoroText::class, $attached);

        self::assertSame(['map' => ['text' => 'Hello']], Loro::toJson($doc));
        self::assertSame([
            ['insert' => 'Hello', 'attributes' => ['bold' => true]],
        ], Loro::textDeltaToPhp($attached->toDelta()));

        $text->insert(0, 'Detached ');
        $attached->insert(0, 'Attached ');

        self::assertSame('Detached Hello', self::textString($text));
        self::assertSame(['map' => ['text' => 'Attached Hello']], Loro::toJson($doc));
    }

    public function testDetachedUnicodeTextKeepsStyleWhenInserted(): void
    {
        $text = new LoroText();
        $text->insert(0, '你好吗');
        Container::markText($text, 0, 3, 'bold', true);

        $doc = new LoroDoc();
        $map = $doc->getMap(Container::idLike('map'));
        $attached = Container::insertMapContainer($map, 'text', $text);
        self::assertInstanceOf(LoroText::class, $attached);

        self::assertSame(['map' => ['text' => '你好吗']], Loro::toJson($doc));
        self::assertSame([
            ['insert' => '你好吗', 'attributes' => ['bold' => true]],
        ], Loro::textDeltaToPhp($attached->toDelta()));
    }

    public function testDetachedPartialStylesArePreservedWhenInserted(): void
    {
        $text = new LoroText();
        $text->insert(0, 'abcDEF');
        Container::markText($text, 0, 3, 'bold', true);

        $doc = new LoroDoc();
        $list = $doc->getList(Container::idLike('list'));
        $attached = Container::insertListContainer($list, 0, $text);
        self::assertInstanceOf(LoroText::class, $attached);

        self::assertSame([
            ['insert' => 'abc', 'attributes' => ['bold' => true]],
            ['insert' => 'DEF'],
        ], Loro::textDeltaToPhp($attached->toDelta()));
    }

    public function testAttachedTextCanBeCopiedWithoutSharingFutureEdits(): void
    {
        $doc = new LoroDoc();
        $source = $doc->getText(Container::idLike('source'));
        $source->insert(0, 'root');

        $list = $doc->getList(Container::idLike('list'));
        Container::insertListContainer($list, 0, $source);
        $copied = $list->get(0)?->asLoroText();
        self::assertInstanceOf(LoroText::class, $copied);

        $source->insert(4, '-updated');

        self::assertSame('root-updated', self::textString($source));
        self::assertSame('root', self::textString($copied));
        $json = Loro::toJson($doc);
        self::assertSame('root-updated', $json['source']);
        self::assertSame(['root'], $json['list']);
    }

    public function testAttachedTextFromAnotherDocumentCannotBeInserted(): void
    {
        $docA = new LoroDoc();
        $textA = $docA->getText(Container::idLike('text'));
        $textA->insert(0, 'cross');

        $docB = new LoroDoc();
        $listB = $docB->getList(Container::idLike('list'));

        $this->expectException(\InvalidArgumentException::class);
        Container::insertListContainer($listB, 0, $textA);
    }

    public function testApplyEmptyDeltaIsNoop(): void
    {
        $doc = new LoroDoc();
        $text = $doc->getText(Container::idLike('text'));
        $text->insert(0, 'hello');

        $text->applyDelta([]);

        self::assertSame([['insert' => 'hello']], Loro::textDeltaToPhp($text->toDelta()));
    }
}
