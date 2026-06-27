<?php

namespace App\Providers;

use App\Events\RoadworkSaved;
use App\Listeners\LinkRoadworkAreas;
use App\Melvin\Client;
use App\Melvin\OAuth2Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(OAuth2Client::class, fn (): OAuth2Client => new OAuth2Client(
            tokenUrl: (string) config('services.melvin.token_url'),
            clientId: (string) config('services.melvin.client_id'),
            username: (string) config('services.melvin.user'),
            password: (string) config('services.melvin.password'),
        ));

        $this->app->singleton(Client::class, fn ($app): Client => new Client(
            oauth: $app->make(OAuth2Client::class),
            baseUrl: (string) config('services.melvin.base_url'),
        ));

        // The TypeScript transformer is a dev/build-time tool (require-dev), so
        // only register its provider when the package is actually installed.
        // This keeps a production `composer install --no-dev` boot safe.
        if (class_exists(TypeScriptTransformerApplicationServiceProvider::class)) {
            $this->app->register(TypeScriptTransformerServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(RoadworkSaved::class, LinkRoadworkAreas::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
