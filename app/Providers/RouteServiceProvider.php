<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // FIX 429 Check Label (2026-10-08): pecah ember throttle numerik
        // throttle:30,1 lama memakai key sha1(user_id) untuk SEMUA route,
        // sehingga polling split-ocr-status (40/menit) menghabiskan kuota
        // split-preview. Named limiter di bawah memberi key per-route.
        RateLimiter::for('split-preview', function (Request $request) {
            return Limit::perMinute(30)->by(optional($request->user())->id ?: $request->ip() . '|split-preview');
        });

        RateLimiter::for('split-status', function (Request $request) {
            return Limit::perMinute(120)->by(optional($request->user())->id ?: $request->ip() . '|split-status');
        });

        RateLimiter::for('sessions-show', function (Request $request) {
            // 60/menit: poller browser checkPollOcrBrowserLog tiap 2s = 30/menit + margin refresh manual.
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip() . '|sessions-show');
        });
    }
}
