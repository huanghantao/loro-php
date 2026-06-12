<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\ExportMode;
use Loro\LoroDoc;

final class IssueRegressionTest extends LoroTestCase
{
    public function testCheckoutAroundConcurrentEditsKeepsEachDocsLatestState(): void
    {
        $doc1 = new LoroDoc();
        $doc1->setPeerId(0);
        $text1 = $doc1->getText('text');

        $doc2 = new LoroDoc();
        $doc2->setPeerId(1);
        $text2 = $doc2->getText('text');

        $text1->insert(0, 'T');
        $doc1->commit();
        self::sync($doc1, $doc2);

        $frontiers1AfterSync = $doc1->stateFrontiers();
        $frontiers2AfterSync = $doc2->stateFrontiers();

        $text1->insert(1, 'A');
        $doc1->commit();
        $text2->insert(1, 'B');
        $doc2->commit();

        $doc1->checkout($frontiers1AfterSync);
        $doc2->checkout($frontiers2AfterSync);
        self::assertSame('T', self::textString($text1));
        self::assertSame('T', self::textString($text2));

        $doc1->checkoutToLatest();
        $doc2->checkoutToLatest();
        self::assertSame('TA', self::textString($text1));
        self::assertSame('TB', self::textString($text2));

        $frontiers1BeforeSecondB = $doc1->stateFrontiers();
        $frontiers2BeforeSecondB = $doc2->stateFrontiers();
        $text2->insert(2, 'B');
        $doc2->commit();

        $doc1->checkout($frontiers1BeforeSecondB);
        $doc2->checkout($frontiers2BeforeSecondB);
        self::assertSame('TA', self::textString($text1));
        self::assertSame('TB', self::textString($text2));
    }

    private static function sync(LoroDoc $left, LoroDoc $right): void
    {
        $left->import($right->export(ExportMode::updates($left->stateVv())));
        $right->import($left->export(ExportMode::updates($right->stateVv())));
    }
}
