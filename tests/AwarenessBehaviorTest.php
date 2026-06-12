<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\Awareness;
use Loro\AwarenessState;
use Loro\BinaryValue;

final class AwarenessBehaviorTest extends LoroTestCase
{
    public function testLocalStateAndEncodeApply(): void
    {
        $awareness = new Awareness(123, 30000);
        AwarenessState::setLocalState($awareness, ['foo' => 'bar']);

        self::assertSame(['foo' => 'bar'], AwarenessState::getLocalState($awareness));
        self::assertSame(['foo' => 'bar'], AwarenessState::getState($awareness, 123));
        self::assertSame([123 => ['foo' => 'bar']], AwarenessState::getAllStates($awareness));

        $awarenessB = new Awareness(223, 30000);
        $changed = $awarenessB->apply($awareness->encode([123]));

        self::assertSame([123], $changed->added);
        self::assertSame([], $changed->updated);
        self::assertSame(['foo' => 'bar'], AwarenessState::getState($awarenessB, 123));
        self::assertSame([123 => ['foo' => 'bar']], AwarenessState::getAllStates($awarenessB));
    }

    public function testEncodeOnlyIncludesRequestedPeers(): void
    {
        $awareness = new Awareness(123, 30000);
        AwarenessState::setLocalState($awareness, ['foo' => 'bar']);

        $awarenessB = new Awareness(223, 30000);
        $awarenessB->apply($awareness->encode([123]));
        AwarenessState::setLocalState($awarenessB, ['new' => 'bee']);

        $awarenessC = new Awareness(323, 30000);
        $changed = $awarenessC->apply($awarenessB->encode([223]));

        self::assertSame([223], $changed->added);
        self::assertSame([], $changed->updated);
        self::assertNull(AwarenessState::getState($awarenessC, 123));
        self::assertSame([223 => ['new' => 'bee']], AwarenessState::getAllStates($awarenessC));
    }

    public function testNewerStateWinsOverOlderEncodedState(): void
    {
        $a = new Awareness(1, 30000);
        $b = new Awareness(2, 30000);

        AwarenessState::setLocalState($a, 0);
        $oldBytes = $a->encode([1]);
        AwarenessState::setLocalState($a, 1);
        $newBytes = $a->encode([1]);

        $b->apply($newBytes);
        $b->apply($oldBytes);

        self::assertSame(1, AwarenessState::getState($a, 1));
        self::assertSame(1, AwarenessState::getState($b, 1));
    }

    public function testRemoveOutdatedClearsExpiredPeers(): void
    {
        $awareness = new Awareness(123, 1);
        AwarenessState::setLocalState($awareness, ['foo' => 'bar']);

        usleep(20000);
        $outdated = $awareness->removeOutdated();

        self::assertSame([123], $outdated);
        self::assertSame([], AwarenessState::getAllStates($awareness));
    }

    public function testBinaryStateRoundTrip(): void
    {
        $a = new Awareness(1, 30000);
        $b = new Awareness(2, 30000);

        AwarenessState::setLocalState($a, [
            'a' => new BinaryValue("\x01\x02\x03\x04"),
            'b' => new BinaryValue("\x05\x06\x07\x08"),
        ]);

        $b->apply($a->encodeAll());
        $state = AwarenessState::getState($b, 1);

        self::assertInstanceOf(BinaryValue::class, $state['a']);
        self::assertInstanceOf(BinaryValue::class, $state['b']);
        self::assertSame("\x01\x02\x03\x04", $state['a']->bytes);
        self::assertSame("\x05\x06\x07\x08", $state['b']->bytes);
    }

}
