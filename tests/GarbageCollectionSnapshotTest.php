<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\ExportMode;
use Loro\LoroDoc;

final class GarbageCollectionSnapshotTest extends LoroTestCase
{
    public function testShallowSnapshotCanReceiveLaterUpdates(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $list = $doc->getList('list');
        $list->insert(0, 'A');
        $list->insert(1, 'B');
        $list->insert(2, 'C');

        $bytes = $doc->export(ExportMode::shallowSnapshot($doc->oplogFrontiers()));
        $newDoc = new LoroDoc();
        $newDoc->import($bytes);
        self::assertEquals($doc->toJSON(), $newDoc->toJSON());

        $list->delete(1, 1);
        $doc->getMap('map')->set('key', 'value');

        $updatedBytes = $doc->export(ExportMode::updates($newDoc->stateVv()));
        $newDoc->import($updatedBytes);

        self::assertEquals($doc->toJSON(), $newDoc->toJSON());
    }

    public function testShallowSnapshotRejectsOutdatedUpdates(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $list = $doc->getList('list');
        $list->insert(0, 'A');

        $docB = $doc->fork();
        $version = $docB->stateVv();
        $docB->getList('list')->insert(1, 'C');
        $updates = $docB->export(ExportMode::updates($version));

        $list->insert(1, 'B');
        $list->insert(2, 'C');
        $doc->commit();

        $gcDoc = new LoroDoc();
        $gcDoc->import($doc->export(ExportMode::shallowSnapshot($doc->oplogFrontiers())));

        $this->expectException(\Throwable::class);
        $gcDoc->import($updates);
    }

    public function testCanForkAShallowSnapshot(): void
    {
        $docA = new LoroDoc();
        $listA = $docA->getList('list');
        $listA->insert(0, 'A');
        $listA->insert(1, 'B');
        $listA->insert(2, 'C');

        $docB = new LoroDoc();
        $docB->import($docA->export(ExportMode::shallowSnapshot($docA->oplogFrontiers())));

        $docC = $docB->fork();

        self::assertEquals($docB->toJSON(), $docC->toJSON());
    }
}
