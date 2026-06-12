<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\ExportMode;
use Loro\LoroDoc;
use Loro\TreeParentId;
use Loro\UndoManager;
use Loro\UndoOrRedo;
use Loro\UpdateOptions;
use Loro\VersionVector;

final class UndoManagerBehaviorTest extends LoroTestCase
{
    public function testBasicTextUndoAndRedo(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $undo = new UndoManager($doc);
        $undo->setMaxUndoSteps(100);
        $undo->setMergeInterval(0);

        self::assertFalse($undo->canRedo());
        self::assertFalse($undo->canUndo());

        $text = $doc->getText('text');
        $text->insert(0, 'hello');
        $doc->commit();
        $text->insert(5, ' world!');
        $doc->commit();

        self::assertTrue($undo->canUndo());
        self::assertFalse($undo->canRedo());

        self::assertTrue($undo->undo());
        self::assertSame(['text' => 'hello'], $doc->toJSON());
        self::assertTrue($undo->canRedo());

        self::assertTrue($undo->undo());
        self::assertSame(['text' => ''], $doc->toJSON());
        self::assertFalse($undo->canUndo());

        self::assertTrue($undo->redo());
        self::assertSame(['text' => 'hello'], $doc->toJSON());
        self::assertTrue($undo->redo());
        self::assertSame(['text' => 'hello world!'], $doc->toJSON());
        self::assertFalse($undo->canRedo());
    }

    public function testMaxUndoStepsAndRemoteUpdate(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $text = $doc->getText('text');
        $undo = new UndoManager($doc);
        $undo->setMaxUndoSteps(3);
        $undo->setMergeInterval(0);

        $text->insert(0, 'A');
        $doc->commit();

        $remote = new LoroDoc();
        $remote->setPeerId(2);
        $remote->import($doc->export(ExportMode::snapshot()));
        $remote->getText('text')->insert(0, 'R');
        $remote->commit();

        $doc->import($remote->export(ExportMode::updates(new VersionVector())));
        self::assertTrue($undo->undo());
        self::assertSame('R', self::textString($text));

        for ($i = 0; $i < 4; $i++) {
            $text->insert($text->lenUnicode(), (string) $i);
            $doc->commit();
        }

        self::assertSame(['text' => 'R0123'], $doc->toJSON());
    }

    public function testSkipExcludedOriginPrefixes(): void
    {
        $doc = new LoroDoc();
        $undo = new UndoManager($doc);
        $undo->setMaxUndoSteps(100);
        $undo->setMergeInterval(0);
        $undo->addExcludeOriginPrefix('sys:');

        $text = $doc->getText('text');
        $text->insert(0, 'hello');
        $doc->commit();
        $text->insert(0, '1');
        $doc->setNextCommitOrigin('sys:test');
        $doc->commit();
        $text->insert(2, '2');
        $doc->setNextCommitOrigin('sys:test');
        $doc->commit();
        $text->insert(4, '3');
        $doc->setNextCommitOrigin('sys:test');
        $doc->commit();
        $text->insert(8, ' world!');
        $doc->commit();
        $text->insert(0, 'Alice ');
        $doc->commit();

        self::assertSame(['text' => 'Alice 1h2e3llo world!'], $doc->toJSON());

        $undo->undo();
        self::assertSame(['text' => '1h2e3llo world!'], $doc->toJSON());
        $undo->undo();
        self::assertSame(['text' => '1h2e3llo'], $doc->toJSON());
        $undo->undo();
        self::assertSame(['text' => '123'], $doc->toJSON());
        self::assertFalse($undo->canUndo());

        self::assertTrue($undo->redo());
        self::assertSame(['text' => '1h2e3llo'], $doc->toJSON());
        self::assertTrue($undo->redo());
        self::assertSame(['text' => '1h2e3llo world!'], $doc->toJSON());
        self::assertTrue($undo->redo());
        self::assertSame(['text' => 'Alice 1h2e3llo world!'], $doc->toJSON());
        self::assertFalse($undo->redo());
    }

    public function testUndoOriginAndCallbacks(): void
    {
        $doc = new LoroDoc();
        $undo = new UndoManager($doc);
        $undo->setMergeInterval(0);

        $undoOrigins = [];
        $subscription = $doc->subscribeRoot(static function ($event) use (&$undoOrigins): void {
            if ($event->origin === 'undo') {
                $undoOrigins[] = $event->origin;
            }
        });

        $pushReturn = null;
        $expectedValue = null;
        $pushTimes = 0;
        $popTimes = 0;

        $undo->setOnPopHandler(static function (UndoOrRedo $undoOrRedo, mixed $span, mixed $item) use (&$expectedValue, &$popTimes): void {
            self::assertSame('Undo', $undoOrRedo->variant);
            self::assertSame('I64', $item->value->variant);
            self::assertSame($expectedValue, $item->value->fields['value']);
            self::assertSame([], $item->cursors);
            $popTimes++;
        });
        $undo->setOnPushHandler(static function () use (&$pushReturn, &$pushTimes): array {
            $pushTimes++;

            return ['value' => $pushReturn, 'cursors' => []];
        });

        $text = $doc->getText('text');
        $text->insert(0, 'hello');
        $pushReturn = 1;
        $doc->commit();
        $text->insert(5, ' world');
        $pushReturn = 2;
        $doc->commit();

        self::assertSame(2, $pushTimes);
        self::assertSame(0, $popTimes);

        $expectedValue = 2;
        $undo->undo();
        self::assertSame(3, $pushTimes);
        self::assertSame(1, $popTimes);
        self::assertSame(['undo'], $undoOrigins);

        $expectedValue = 1;
        $undo->undo();
        self::assertSame(4, $pushTimes);
        self::assertSame(2, $popTimes);

        $subscription->detach();
    }

    public function testTopUndoAndRedoValuesTrackCallbackMetadata(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $undo = new UndoManager($doc);
        $undo->setMergeInterval(0);

        $lastCommitLabel = null;
        $lastPoppedLabel = null;

        $undo->setOnPushHandler(
            static function (UndoOrRedo $undoOrRedo) use (&$lastCommitLabel, &$lastPoppedLabel): array {
                return [
                    'value' => $undoOrRedo->variant === 'Undo' ? $lastCommitLabel : $lastPoppedLabel,
                    'cursors' => [],
                ];
            }
        );
        $undo->setOnPopHandler(
            static function (UndoOrRedo $undoOrRedo, mixed $span, mixed $meta) use (&$lastPoppedLabel): void {
                self::assertSame('Undo', $undoOrRedo->variant);
                self::assertSame('String', $meta->value->variant);
                $lastPoppedLabel = $meta->value->fields['value'];
            }
        );

        $text = $doc->getText('text');
        $text->insert(0, 'A');
        $lastCommitLabel = 'Insert A';
        $doc->commit();

        self::assertSame('Insert A', $undo->topUndoValueJSON());
        self::assertSame('Insert A', $undo->topUndoMetaValueJSON());
        self::assertNull($undo->topRedoValue());

        $text->insert(1, 'B');
        $lastCommitLabel = 'Insert B';
        $doc->commit();

        self::assertSame('Insert B', $undo->topUndoValueJSON());

        self::assertTrue($undo->undo());
        self::assertSame('Insert B', $undo->topRedoValueJSON());
        self::assertSame('Insert B', $undo->topRedoMetaValueJSON());
        self::assertSame('Insert A', $undo->topUndoValueJSON());
    }

    public function testGroupedLocalChangesAndRemoteConflictSplitting(): void
    {
        $doc = new LoroDoc();
        $undo = new UndoManager($doc);
        $text = $doc->getText('text');

        $undo->groupStart();
        $text->update('hello', new UpdateOptions(null, false));
        $doc->commit();
        $text->update('world', new UpdateOptions(null, false));
        $doc->commit();
        $undo->groupEnd();

        $undo->undo();
        self::assertSame('', self::textString($text));

        $doc2 = new LoroDoc();
        $undo2 = new UndoManager($doc2);
        $text2 = $doc2->getText('text');

        $undo2->groupStart();
        $text2->update('hello', new UpdateOptions(null, false));
        $doc2->commit();
        $text2->update('hello world', new UpdateOptions(null, false));
        $doc2->commit();

        $remote = new LoroDoc();
        $remote->import($doc2->export(ExportMode::snapshot()));
        $remote->getText('text')->update('hello world world', new UpdateOptions(null, false));
        $remote->commit();

        $doc2->import($remote->export(ExportMode::updates(new VersionVector())));
        $text2->update('hello world world world', new UpdateOptions(null, false));
        $doc2->commit();
        $undo2->groupEnd();

        $undo2->undo();
        self::assertSame('hello world world', self::textString($text2));
    }

    public function testUndoTreeMove(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $undo = new UndoManager($doc);
        $undo->setMergeInterval(0);
        $undo->setMaxUndoSteps(100);

        $tree = $doc->getTree('tree');
        $tree->enableFractionalIndex(3);

        $a = $tree->createAt(TreeParentId::root(), 0);
        $tree->getMeta($a)->set('title', 'a');
        $doc->commit();

        $b = $tree->createAt(TreeParentId::root(), 1);
        $tree->getMeta($b)->set('title', 'b');
        $doc->commit();
        $before = $doc->toJSON();

        $tree->movTo($a, TreeParentId::root(), 1);
        $doc->commit();
        $undo->undo();

        self::assertEquals($before, $doc->toJSON());
    }
}
