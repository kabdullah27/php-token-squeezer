<?php

declare(strict_types=1);

namespace TokenSqueezer\Events;

/**
 * Dual-mode event dispatcher.
 *
 * Works in BOTH plain PHP and Laravel:
 *
 *   Plain PHP  → calls all registered static listeners.
 *   Laravel    → calls Laravel's event() helper PLUS static listeners.
 *
 * Usage (plain PHP):
 *   EventDispatcher::listen(AnalysisCompleted::class, function (AnalysisCompleted $e) {
 *       error_log("done: {$e->provider}, tokens: {$e->inputTokens}");
 *   });
 *
 * Usage (Laravel — recommended via EventServiceProvider):
 *   protected $listen = [
 *       AnalysisCompleted::class => [YourListener::class],
 *   ];
 *
 * The static listener registry is separate from Laravel's; both are always called.
 */
final class EventDispatcher
{
    /** @var array<class-string, list<callable>> */
    private static array $listeners = [];

    /**
     * Register a listener for a specific event class.
     *
     * @param  class-string  $eventClass  e.g. AnalysisCompleted::class
     * @param  callable      $listener    Receives the event object as first argument
     */
    public static function listen(string $eventClass, callable $listener): void
    {
        self::$listeners[$eventClass][] = $listener;
    }

    /**
     * Dispatch an event to:
     *   1. Laravel's event() helper (if available)
     *   2. All static listeners registered via listen()
     */
    public static function dispatch(object $event): void
    {
        // 1. Laravel integration — delegate to its event system
        if (function_exists('event')) {
            event($event);
        }

        // 2. Static listeners (plain PHP + fallback when Laravel is unavailable)
        $class = $event::class;
        foreach (self::$listeners[$class] ?? [] as $listener) {
            $listener($event);
        }
    }

    /**
     * Remove listeners for one event class, or clear all listeners.
     * Mainly useful in tests.
     */
    public static function forget(?string $eventClass = null): void
    {
        if ($eventClass === null) {
            self::$listeners = [];
        } else {
            unset(self::$listeners[$eventClass]);
        }
    }
}
