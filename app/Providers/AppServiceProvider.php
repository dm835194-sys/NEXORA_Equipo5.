<?php

namespace App\Providers;

use App\Models\Notice;
use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

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
        Carbon::setLocale('es');
        \Illuminate\Support\Facades\View::composer('layouts.app', function (View $view): void {
            $view->with('noticeCount', auth()->check() ? Notice::where('user_id', auth()->id())->where('status', 'delivered')->count() : 0);
        });
    }
}
