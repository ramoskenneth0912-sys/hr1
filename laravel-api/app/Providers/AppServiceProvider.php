<?php

namespace App\Providers;

use App\Auth\LegacyAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::viaRequest('legacy', function (Request $request) {
            return app(LegacyAuthenticator::class)->resolve($request);
        });
    }
}
