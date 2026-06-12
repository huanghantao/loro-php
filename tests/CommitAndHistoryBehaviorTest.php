<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\CommitOptions;
use Loro\CounterSpan;
use Loro\ExportMode;
use Loro\Frontiers;
use Loro\Id;
use Loro\IdSpan;
use Loro\LoroDoc;
use Loro\LoroList;
use Loro\LoroText;
use Loro\UpdateOptions;
use Loro\VersionRange;

final class CommitAndHistoryBehaviorTest extends LoroTestCase
{
    public function testNextCommitOptionsCanBeClearedAndEmptyCommitOriginDoesNotLeak(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $text = $doc->getText('text');

        $doc->setNextCommitOptions(new CommitOptions('will clear', false, 123, 'will clear'));
        $doc->clearNextCommitOptions();
        $text->insert(0, 'x');
        $doc->commit();

        $change = $doc->getChange(new Id(1, 0));
        self::assertNotNull($change);
        self::assertNull($change->message);
        self::assertSame(0, $change->timestamp);

        $origins = [];
        $subscription = $doc->subscribeRoot(static function ($event) use (&$origins): void {
            $origins[] = $event->origin;
        });

        $doc->commitWith(new CommitOptions('empty-origin', false, null, null));
        $text->insert(1, 'y');
        $doc->commit();

        self::assertSame([''], $origins);
        $subscription->unsubscribe();
    }

    public function testRecordTimestampAndMergeIntervalAffectChangeMetadata(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $text = $doc->getText('text');

        $text->insert(0, 'hi');
        $doc->commit();
        self::assertSame(0, $doc->getChange(new Id(1, 0))?->timestamp);

        $doc->setRecordTimestamp(true);
        $text->insert(0, 'yo');
        $doc->commit();
        self::assertGreaterThan(0, $doc->getChange(new Id(1, 2))?->timestamp);

        $merged = new LoroDoc();
        $merged->setPeerId(1);
        $mergedText = $merged->getText('text');
        $mergedText->insert(0, '1');
        $merged->commitWith(new CommitOptions(null, false, 110, null));
        $mergedText->insert(0, '1');
        $merged->commitWith(new CommitOptions(null, false, 120, null));
        self::assertSame(1, $merged->lenChanges());

        $split = new LoroDoc();
        $split->setPeerId(1);
        $split->setChangeMergeInterval(9);
        $splitText = $split->getText('text');
        $splitText->insert(0, '1');
        $split->commitWith(new CommitOptions(null, false, 110, null));
        $splitText->insert(0, '1');
        $split->commitWith(new CommitOptions(null, false, 120, null));
        self::assertSame(2, $split->lenChanges());
    }

    public function testFindSpansBetweenVersionsAndExportJsonInIdSpan(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $text = $doc->getText('text');

        $text->insert(0, 'Hello');
        $doc->commitWith(new CommitOptions(null, false, 100, 'a'));
        $frontiers1 = $doc->oplogFrontiers();

        $text->insert(5, ' World');
        $doc->commitWith(new CommitOptions(null, false, 200, 'b'));
        $frontiers2 = $doc->oplogFrontiers();

        $spans = $doc->findIdSpansBetween($frontiers1, $frontiers2);
        self::assertSame([], $spans->retreat);
        self::assertSame(5, $spans->forward[1]->start);
        self::assertSame(11, $spans->forward[1]->end);

        $json = $doc->exportJsonInIdSpanCommitted(new IdSpan(1, $spans->forward[1]));
        self::assertCount(1, $json);
        $change = json_decode($json[0], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('5@1', $change['id']);
        self::assertSame(200, $change['timestamp']);
        self::assertSame('b', $change['msg']);
        self::assertSame([
            'type' => 'insert',
            'pos' => 5,
            'text' => ' World',
        ], $change['ops'][0]['content']);

        $reverse = $doc->findIdSpansBetween($frontiers2, $frontiers1);
        self::assertSame([], $reverse->forward);
        self::assertSame(5, $reverse->retreat[1]->start);
        self::assertSame(11, $reverse->retreat[1]->end);
    }

    public function testExportJsonInIdSpanCommitsPendingOpsThroughHelper(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $doc->getText('text')->insert(0, 'Hello');

        self::assertSame(5, $doc->getPendingTxnLen());

        $json = $doc->exportJsonInIdSpanCommitted(new IdSpan(1, new CounterSpan(0, 5)));
        self::assertSame(0, $doc->getPendingTxnLen());
        self::assertCount(1, $json);

        $change = json_decode($json[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('0@1', $change['id']);
        self::assertSame([
            'type' => 'insert',
            'pos' => 0,
            'text' => 'Hello',
        ], $change['ops'][0]['content']);
    }

    public function testRevertToFrontiersCanRestoreChildContainers(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $list = $doc->getList('list');
        $list->insert(0, 'item1');
        $list->insert(1, 'item2');
        $text = $list->insertContainer(2, new LoroText());
        self::assertInstanceOf(LoroText::class, $text);
        $text->insert(0, 'Hello');
        $withText = $doc->stateFrontiers();

        $text->delete(0, 5);
        $list->clear();
        $empty = $doc->stateFrontiers();
        $doc->commit();

        self::assertSame(['list' => []], $doc->toJSON());

        $doc->revertTo($withText);
        self::assertSame(['list' => ['item1', 'item2', 'Hello']], $doc->toJSON());

        $doc->revertTo($empty);
        self::assertSame(['list' => []], $doc->toJSON());
    }

    public function testRedactJsonUpdatesRemovesSensitiveTextAndMapValues(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);

        $text = $doc->getText('text');
        $text->insert(0, 'Sensitive information');
        $doc->commit();

        $doc->getMap('map')->set('password', 'secret123');
        $doc->getMap('map')->set('public', 'public information');
        $doc->commit();

        $json = $doc->exportJsonUpdates(new \Loro\VersionVector(), $doc->oplogVv());

        $textRange = new VersionRange();
        $textRange->insert(1, 0, 21);
        $redactedText = $doc->redactJsonUpdates($json, $textRange);
        $redactedTextDoc = new LoroDoc();
        $redactedTextDoc->importJsonUpdates($redactedText);

        self::assertSame('���������������������', $redactedTextDoc->toJSON()['text']);
        self::assertSame('secret123', $redactedTextDoc->toJSON()['map']['password']);

        $mapRange = new VersionRange();
        $mapRange->insert(1, 21, 22);
        $redactedMap = $doc->redactJsonUpdates($json, $mapRange);
        $redactedMapDoc = new LoroDoc();
        $redactedMapDoc->importJsonUpdates($redactedMap);

        self::assertNull($redactedMapDoc->toJSON()['map']['password']);
        self::assertSame('public information', $redactedMapDoc->toJSON()['map']['public']);
    }

    public function testTravelChangeAncestorsCanStartFromExportedEventSpan(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $doc->getText('text')->update('Hello', new UpdateOptions(null, false));
        $doc->commit();

        $seen = 0;
        $doc->travelChangeAncestors([new Id(1, 0)], static function () use (&$seen): bool {
            ++$seen;

            return true;
        });

        self::assertSame(1, $seen);
    }
}
