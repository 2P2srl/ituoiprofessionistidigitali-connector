<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\ConnectorServiceProvider;
use ITuoiProfessionistiDigitali\Connector\Events\PlatformEventReceived;
use ITuoiProfessionistiDigitali\Connector\Http\Middleware\VerifyPlatformSignature;
use ITuoiProfessionistiDigitali\Connector\Listeners\ReplyToPing;
use ITuoiProfessionistiDigitali\Connector\PlatformClient;

it('registers the webhook route behind the signature check', function (): void {
    $route = Route::getRoutes()->getByName('platform.webhook');

    expect($route?->uri())->toBe('platform/webhook')
        ->and($route?->methods())->toContain('POST')
        ->and($route?->middleware())->toContain(VerifyPlatformSignature::class);
});

it('does not register the webhook route without a path', function (): void {
    $this->app->instance(ConnectorConfig::class, new ConnectorConfig(webhookPath: null));
    Route::setRoutes(new RouteCollection);

    require __DIR__.'/../routes/webhook.php';

    expect(Route::has('platform.webhook'))->toBeFalse();
});

it('answers pings unless told not to', function (bool $replyToPings): void {
    Event::forget(PlatformEventReceived::class);
    $this->app->instance(ConnectorConfig::class, new ConnectorConfig(replyToPings: $replyToPings));

    new ConnectorServiceProvider($this->app)->boot($this->app->make('events'));

    expect(Event::hasListeners(PlatformEventReceived::class))->toBe($replyToPings);
})->with([true, false]);

it('shares one client and one configuration', function (): void {
    expect(resolve(PlatformClient::class))->toBe(resolve(PlatformClient::class))
        ->and(resolve(ConnectorConfig::class)->clientId)->toBe('client-id')
        ->and(Event::hasListeners(PlatformEventReceived::class))->toBeTrue()
        ->and(class_exists(ReplyToPing::class))->toBeTrue();
});

it('sends the outbox every five minutes, on one server only', function (): void {
    $events = collect(resolve(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'platform:send-outbox'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/5 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->onOneServer)->toBeTrue();
});
