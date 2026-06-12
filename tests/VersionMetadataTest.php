<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\CounterSpan;
use Loro\Export;
use Loro\Frontiers;
use Loro\Id;
use Loro\IdSpan;
use Loro\LoroDoc;
use Loro\VersionRange;
use Loro\VersionVector;

use function Loro\decodeImportBlobMeta;

final class VersionMetadataTest extends LoroTestCase
{
    public function testVersionVectorToFrontiers(): void
    {
        $a = new LoroDoc();
        $a->setPeerId(0);
        $b = new LoroDoc();
        $b->setPeerId(1);

        $a->getText('text')->insert(0, 'ha');
        $b->getText('text')->insert(0, 'yo');
        $a->import($b->export(Export::updates(new VersionVector())));
        $a->getText('text')->insert(0, 'k');
        $a->commit();

        $vvHashmap = $a->oplogVv()->toHashmap();
        ksort($vvHashmap);
        self::assertSame([0 => 3, 1 => 2], $vvHashmap);
        self::assertTrue($a->vvToFrontiers($a->oplogVv())->eq($a->oplogFrontiers()));
        self::assertEquals([new Id(0, 2)], $a->oplogFrontiers()->toVec());
    }

    public function testVersionVectorOperations(): void
    {
        $base = new VersionVector();
        $base->setEnd(new Id(1, 3));
        $base->setLast(new Id(2, 1));

        $baseHashmap = $base->toHashmap();
        ksort($baseHashmap);
        self::assertSame([1 => 3, 2 => 2], $baseHashmap);
        self::assertTrue($base->includesId(new Id(1, 2)));
        self::assertFalse($base->includesId(new Id(1, 3)));

        $target = new VersionVector();
        $target->setEnd(new Id(1, 5));
        $target->setEnd(new Id(2, 2));
        $target->setEnd(new Id(3, 2));

        $missing = $base->getMissingSpan($target);
        usort($missing, static fn($a, $b): int => $a->peer <=> $b->peer);

        self::assertCount(2, $missing);
        self::assertSame(1, $missing[0]->peer);
        self::assertSame(3, $missing[0]->counter->start);
        self::assertSame(5, $missing[0]->counter->end);
        self::assertSame(3, $missing[1]->peer);
        self::assertSame(0, $missing[1]->counter->start);
        self::assertSame(2, $missing[1]->counter->end);
        self::assertSame('Less', $base->partialCmp($target)?->variant);
    }

    public function testVersionVectorEncodeDecodeAndVersionRangeOperations(): void
    {
        $vv = new VersionVector();
        $vv->setEnd(new Id(1, 5));
        $vv->setEnd(new Id(2, 3));

        $decoded = VersionVector::decode($vv->encode());
        self::assertTrue($vv->eq($decoded));

        $range = VersionRange::fromVv($vv);
        $peers = $range->getPeers();
        sort($peers);
        self::assertSame([1, 2], $peers);
        self::assertSame(0, $range->get(1)?->start);
        self::assertSame(5, $range->get(1)?->end);
        self::assertTrue($range->containsId(new Id(1, 4)));
        self::assertFalse($range->containsId(new Id(1, 5)));
        self::assertTrue($range->containsIdSpan(new IdSpan(1, new CounterSpan(2, 5))));
        self::assertTrue($range->hasOverlapWith(new IdSpan(1, new CounterSpan(4, 8))));
        self::assertFalse($range->hasOverlapWith(new IdSpan(3, new CounterSpan(0, 1))));

        $range->extendsToIncludeIdSpan(new IdSpan(3, new CounterSpan(2, 4)));
        self::assertSame(2, $range->get(3)?->start);
        self::assertSame(4, $range->get(3)?->end);

        $ranges = $range->getAllRanges();
        usort($ranges, static fn($a, $b): int => $a->peer <=> $b->peer);
        self::assertSame([
            [1, 0, 5],
            [2, 0, 3],
            [3, 2, 4],
        ], array_map(static fn($item): array => [$item->peer, $item->start, $item->end], $ranges));
    }

    public function testFrontiersEncodeDecodeAndCompare(): void
    {
        $frontiers = Frontiers::fromIds([
            new Id(1, 2),
            new Id(2, 3),
        ]);

        $decoded = Frontiers::decode($frontiers->encode());

        self::assertTrue($frontiers->eq($decoded));
        $ids = $decoded->toVec();
        usort($ids, static fn(Id $a, Id $b): int => [$a->peer, $a->counter] <=> [$b->peer, $b->counter]);
        self::assertEquals([new Id(1, 2), new Id(2, 3)], $ids);
    }

    public function testImportBlobMetadataForUpdateAndSnapshot(): void
    {
        $doc0 = new LoroDoc();
        $doc0->setPeerId(0);
        $doc0->getText('text')->insert(0, '0');
        $doc0->commit();

        $updateMeta = decodeImportBlobMeta($doc0->export(Export::updates(new VersionVector())), false);

        self::assertSame(1, $updateMeta->changeNum);
        self::assertNull($updateMeta->partialStartVv->getLast(0));
        self::assertSame(0, $updateMeta->partialEndVv->getLast(0));
        self::assertSame(0, $updateMeta->startTimestamp);
        self::assertSame(0, $updateMeta->endTimestamp);
        self::assertSame('update', $updateMeta->mode);
        self::assertTrue($updateMeta->startFrontiers->isEmpty());

        $doc1 = new LoroDoc();
        $doc1->setPeerId(1);
        $doc1->getText('text')->insert(0, '123');
        $doc1->import($doc0->export(Export::updates(new VersionVector())));

        $snapshotMeta = decodeImportBlobMeta($doc1->export(Export::snapshot()), false);

        self::assertSame(2, $snapshotMeta->changeNum);
        self::assertSame(0, $snapshotMeta->partialEndVv->getLast(0));
        self::assertSame(2, $snapshotMeta->partialEndVv->getLast(1));
        self::assertSame('snapshot', $snapshotMeta->mode);
        self::assertTrue($snapshotMeta->startFrontiers->isEmpty());
    }
}
