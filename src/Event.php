<?php

declare(strict_types=1);

namespace Loro;

final class SubscriberCallback extends Subscriber
{
    private mixed $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function onDiff(DiffEvent $diff): void
    {
        ($this->callback)($diff);
    }
}

final class LocalUpdateCallbackClosure extends LocalUpdateCallback
{
    private mixed $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function onLocalUpdate(string $update): void
    {
        ($this->callback)($update);
    }
}

final class JsonPathSubscriberCallback extends JsonPathSubscriber
{
    private mixed $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function onJsonpathChanged(): void
    {
        ($this->callback)();
    }
}

final class Events
{
    public static function subscriber(callable $callback): Subscriber
    {
        return new SubscriberCallback($callback);
    }

    public static function localUpdateCallback(callable $callback): LocalUpdateCallback
    {
        return new LocalUpdateCallbackClosure($callback);
    }

    public static function jsonPathSubscriber(callable $callback): JsonPathSubscriber
    {
        return new JsonPathSubscriberCallback($callback);
    }

    public static function subscribeRoot(LoroDoc $doc, callable $callback): Subscription
    {
        return $doc->subscribeRoot(new SubscriberCallback($callback));
    }

    public static function subscribeDoc(LoroDoc $doc, ContainerId $containerId, callable $callback): Subscription
    {
        return $doc->subscribe($containerId, new SubscriberCallback($callback));
    }

    public static function subscribeContainer(
        LoroText|LoroMap|LoroList|LoroMovableList|LoroTree|LoroCounter $container,
        callable $callback
    ): ?Subscription {
        return $container->subscribe(new SubscriberCallback($callback));
    }

    public static function subscribeLocalUpdate(LoroDoc $doc, callable $callback): Subscription
    {
        return $doc->subscribeLocalUpdate(new LocalUpdateCallbackClosure($callback));
    }

    public static function subscribeJsonpath(LoroDoc $doc, string $path, callable $callback): Subscription
    {
        return $doc->subscribeJsonpath($path, new JsonPathSubscriberCallback($callback));
    }
}
