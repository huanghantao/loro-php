<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\LoroText;
use Loro\LoroValue;
use PHPUnit\Framework\TestCase;

abstract class LoroTestCase extends TestCase
{
    protected static function assertLoroValueEquals(LoroValue $expected, ?LoroValue $actual, string $message = ''): void
    {
        self::assertNotNull($actual, $message === '' ? 'Expected LoroValue' : $message);
        self::assertSame($expected->variant, $actual->variant, $message === '' ? 'LoroValue variant mismatch' : $message);
        self::assertEquals($expected->fields, $actual->fields, $message === '' ? 'LoroValue fields mismatch' : $message);
    }

    protected static function textString(LoroText $text): string
    {
        return $text->toString();
    }
}
