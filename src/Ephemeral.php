<?php

declare(strict_types=1);

namespace Loro;

final class EphemeralSubscriberCallback extends EphemeralSubscriber
{
    private mixed $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function onEphemeralEvent(EphemeralStoreEvent $event): void
    {
        ($this->callback)($event);
    }
}

final class LocalEphemeralListenerCallback extends LocalEphemeralListener
{
    private mixed $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function onEphemeralUpdate(string $update): void
    {
        ($this->callback)($update);
    }
}

final class Ephemeral
{
    public static function subscriber(callable $callback): EphemeralSubscriber
    {
        return new EphemeralSubscriberCallback($callback);
    }

    public static function localUpdateListener(callable $callback): LocalEphemeralListener
    {
        return new LocalEphemeralListenerCallback($callback);
    }

    public static function set(EphemeralStore $store, string $key, mixed $value): void
    {
        $store->set($key, Value::like($value));
    }

    public static function subscribe(EphemeralStore $store, callable $callback): Subscription
    {
        return $store->subscribe(new EphemeralSubscriberCallback($callback));
    }

    public static function subscribeLocalUpdate(EphemeralStore $store, callable $callback): Subscription
    {
        return $store->subscribeLocalUpdate(new LocalEphemeralListenerCallback($callback));
    }
}
