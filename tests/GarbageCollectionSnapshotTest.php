<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\Container;
use Loro\Export;
use Loro\Loro;
use Loro\LoroDoc;

final class GarbageCollectionSnapshotTest extends LoroTestCase
{
    public function testShallowSnapshotCanReceiveLaterUpdates(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $list = $doc->getList('list');
        Container::insertListValue($list, 0, 'A');
        Container::insertListValue($list, 1, 'B');
        Container::insertListValue($list, 2, 'C');

        $bytes = $doc->export(Export::shallowSnapshot($doc->oplogFrontiers()));
        $newDoc = new LoroDoc();
        $newDoc->import($bytes);
        self::assertEquals(Loro::toJson($doc), Loro::toJson($newDoc));

        $list->delete(1, 1);
        Container::insertMapValue($doc->getMap('map'), 'key', 'value');

        $updatedBytes = $doc->export(Export::updates($newDoc->stateVv()));
        $newDoc->import($updatedBytes);

        self::assertEquals(Loro::toJson($doc), Loro::toJson($newDoc));
    }

    public function testShallowSnapshotRejectsOutdatedUpdates(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(1);
        $list = $doc->getList('list');
        Container::insertListValue($list, 0, 'A');

        $docB = $doc->fork();
        $version = $docB->stateVv();
        Container::insertListValue($docB->getList('list'), 1, 'C');
        $updates = $docB->export(Export::updates($version));

        Container::insertListValue($list, 1, 'B');
        Container::insertListValue($list, 2, 'C');
        $doc->commit();

        $gcDoc = new LoroDoc();
        $gcDoc->import($doc->export(Export::shallowSnapshot($doc->oplogFrontiers())));

        $this->expectException(\Throwable::class);
        $gcDoc->import($updates);
    }

    public function testCanForkAShallowSnapshot(): void
    {
        $docA = new LoroDoc();
        $listA = $docA->getList('list');
        Container::insertListValue($listA, 0, 'A');
        Container::insertListValue($listA, 1, 'B');
        Container::insertListValue($listA, 2, 'C');

        $docB = new LoroDoc();
        $docB->import($docA->export(Export::shallowSnapshot($docA->oplogFrontiers())));

        $docC = $docB->fork();

        self::assertEquals(Loro::toJson($docB), Loro::toJson($docC));
    }
}
