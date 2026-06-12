<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\EphemeralStore;
use Loro\EphemeralStoreEvent;
use Loro\LoroValue;

final class EphemeralStoreTest extends LoroTestCase
{
    public function testBasicSetAndGet(): void
    {
        $store = new EphemeralStore(60000);

        $store->set('key1', 'value1');
        $store->set('key2', 42);
        $store->set('key3', true);

        self::assertLoroValueEquals(LoroValue::string('value1'), $store->get('key1'));
        self::assertLoroValueEquals(LoroValue::i64(42), $store->get('key2'));
        self::assertLoroValueEquals(LoroValue::bool(true), $store->get('key3'));
        self::assertNull($store->get('nonexistent'));
    }

    public function testKeys(): void
    {
        $store = new EphemeralStore(60000);

        self::assertCount(0, $store->keys());

        $store->set('key1', 'value1');
        $store->set('key2', 'value2');
        $store->set('key3', 'value3');

        $keys = $store->keys();
        self::assertCount(3, $keys);
        self::assertContains('key1', $keys);
        self::assertContains('key2', $keys);
        self::assertContains('key3', $keys);
    }

    public function testGetAllStates(): void
    {
        $store = new EphemeralStore(60000);

        $store->set('key1', 'value1');
        $store->set('key2', 42);

        $allStates = $store->getAllStates();
        self::assertCount(2, $allStates);
        self::assertLoroValueEquals(LoroValue::string('value1'), $allStates['key1'] ?? null);
        self::assertLoroValueEquals(LoroValue::i64(42), $allStates['key2'] ?? null);
    }

    public function testDelete(): void
    {
        $store = new EphemeralStore(60000);

        $store->set('key1', 'value1');
        $store->set('key2', 'value2');

        self::assertNotNull($store->get('key1'));

        $store->delete('key1');

        self::assertNull($store->get('key1'));
        self::assertNotNull($store->get('key2'));

        $keys = $store->keys();
        self::assertCount(1, $keys);
        self::assertNotContains('key1', $keys);
        self::assertContains('key2', $keys);
    }

    public function testEphemeralEventSubscription(): void
    {
        $store = new EphemeralStore(60000);
        $receivedEvents = [];

        $subscription = $store->subscribe(static function (EphemeralStoreEvent $event) use (&$receivedEvents): void {
            $receivedEvents[] = $event;
        });

        $store->set('key1', 'value1');
        usleep(100000);

        self::assertGreaterThan(0, count($receivedEvents));

        $lastEvent = $receivedEvents[array_key_last($receivedEvents)];
        self::assertSame('Local', $lastEvent->by->variant);
        self::assertContains('key1', $lastEvent->added);

        $receivedEvents = [];

        $store->set('key1', 'updated_value');
        usleep(100000);

        self::assertGreaterThan(0, count($receivedEvents));

        $updateEvent = $receivedEvents[array_key_last($receivedEvents)];
        self::assertSame('Local', $updateEvent->by->variant);
        self::assertContains('key1', $updateEvent->updated);

        $receivedEvents = [];

        $store->delete('key1');
        usleep(100000);

        self::assertGreaterThan(0, count($receivedEvents));

        $deleteEvent = $receivedEvents[array_key_last($receivedEvents)];
        self::assertSame('Local', $deleteEvent->by->variant);
        self::assertContains('key1', $deleteEvent->removed);

        $subscription->detach();
    }

    public function testLocalUpdateSubscription(): void
    {
        $store = new EphemeralStore(60000);
        $receivedUpdates = [];

        $subscription = $store->subscribeLocalUpdate(static function (string $updateData) use (&$receivedUpdates): void {
            $receivedUpdates[] = $updateData;
        });

        $store->set('key1', 'value1');
        $store->set('key2', 42);
        usleep(100000);

        self::assertGreaterThan(0, count($receivedUpdates));

        foreach ($receivedUpdates as $updateData) {
            self::assertGreaterThan(0, strlen($updateData));
        }

        $subscription->detach();
    }

    public function testEncodeAndApply(): void
    {
        $store1 = new EphemeralStore(60000);
        $store2 = new EphemeralStore(60000);

        $store1->set('key1', 'value1');
        $store1->set('key2', 42);

        $encodedData = $store1->encodeAll();
        $store2->apply($encodedData);

        self::assertLoroValueEquals(LoroValue::string('value1'), $store2->get('key1'));
        self::assertLoroValueEquals(LoroValue::i64(42), $store2->get('key2'));

        $store2Keys = $store2->keys();
        self::assertCount(2, $store2Keys);
        self::assertContains('key1', $store2Keys);
        self::assertContains('key2', $store2Keys);
    }

    public function testEncodeSpecificKey(): void
    {
        $store = new EphemeralStore(60000);

        $store->set('key1', 'value1');
        $store->set('key2', 'value2');

        $encodedKey1 = $store->encode('key1');
        self::assertGreaterThan(0, strlen($encodedKey1));

        $newStore = new EphemeralStore(60000);
        $newStore->apply($encodedKey1);

        self::assertNotNull($newStore->get('key1'));
        self::assertNull($newStore->get('key2'));
    }

    public function testMultipleSubscriptions(): void
    {
        $store = new EphemeralStore(60000);
        $events1 = [];
        $events2 = [];

        $subscription1 = $store->subscribe(static function (EphemeralStoreEvent $event) use (&$events1): void {
            $events1[] = $event;
        });

        $subscription2 = $store->subscribe(static function (EphemeralStoreEvent $event) use (&$events2): void {
            $events2[] = $event;
        });

        $store->set('test', 'value');
        usleep(100000);

        self::assertGreaterThan(0, count($events1));
        self::assertGreaterThan(0, count($events2));

        $subscription1->detach();
        $subscription2->detach();
    }
}
