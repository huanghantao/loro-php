<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\Awareness;
use Loro\BinaryValue;

final class AwarenessBehaviorTest extends LoroTestCase
{
    public function testLocalStateAndEncodeApply(): void
    {
        $awareness = new Awareness(123, 30000);
        $awareness->setLocalState(['foo' => 'bar']);

        self::assertSame(['foo' => 'bar'], $awareness->getLocalStateJSON());
        self::assertSame(['foo' => 'bar'], $awareness->getState(123));
        self::assertSame([123 => ['foo' => 'bar']], $awareness->getAllStatesJSON());

        $awarenessB = new Awareness(223, 30000);
        $changed = $awarenessB->apply($awareness->encode([123]));

        self::assertSame([123], $changed->added);
        self::assertSame([], $changed->updated);
        self::assertSame(['foo' => 'bar'], $awarenessB->getState(123));
        self::assertSame([123 => ['foo' => 'bar']], $awarenessB->getAllStatesJSON());
    }

    public function testEncodeOnlyIncludesRequestedPeers(): void
    {
        $awareness = new Awareness(123, 30000);
        $awareness->setLocalState(['foo' => 'bar']);

        $awarenessB = new Awareness(223, 30000);
        $awarenessB->apply($awareness->encode([123]));
        $awarenessB->setLocalState(['new' => 'bee']);

        $awarenessC = new Awareness(323, 30000);
        $changed = $awarenessC->apply($awarenessB->encode([223]));

        self::assertSame([223], $changed->added);
        self::assertSame([], $changed->updated);
        self::assertNull($awarenessC->getState(123));
        self::assertSame([223 => ['new' => 'bee']], $awarenessC->getAllStatesJSON());
    }

    public function testNewerStateWinsOverOlderEncodedState(): void
    {
        $a = new Awareness(1, 30000);
        $b = new Awareness(2, 30000);

        $a->setLocalState(0);
        $oldBytes = $a->encode([1]);
        $a->setLocalState(1);
        $newBytes = $a->encode([1]);

        $b->apply($newBytes);
        $b->apply($oldBytes);

        self::assertSame(1, $a->getState(1));
        self::assertSame(1, $b->getState(1));
    }

    public function testRemoveOutdatedClearsExpiredPeers(): void
    {
        $awareness = new Awareness(123, 1);
        $awareness->setLocalState(['foo' => 'bar']);

        usleep(20000);
        $outdated = $awareness->removeOutdated();

        self::assertSame([123], $outdated);
        self::assertSame([], $awareness->getAllStatesJSON());
    }

    public function testBinaryStateRoundTrip(): void
    {
        $a = new Awareness(1, 30000);
        $b = new Awareness(2, 30000);

        $a->setLocalState([
            'a' => new BinaryValue("\x01\x02\x03\x04"),
            'b' => new BinaryValue("\x05\x06\x07\x08"),
        ]);

        $b->apply($a->encodeAll());
        $state = $b->getState(1);

        self::assertInstanceOf(BinaryValue::class, $state['a']);
        self::assertInstanceOf(BinaryValue::class, $state['b']);
        self::assertSame("\x01\x02\x03\x04", $state['a']->bytes);
        self::assertSame("\x05\x06\x07\x08", $state['b']->bytes);
    }

}
