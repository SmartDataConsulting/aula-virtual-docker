<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

/**
 * Proveedor de servicios base de la aplicacion.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Registra servicios de la aplicacion.
     */
    public function register(): void
    {
        //
    }

    /**
     * Inicializa servicios de la aplicacion.
     */
    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Paginator::defaultView('vendor.pagination.smart-data');
        Paginator::defaultSimpleView('vendor.pagination.smart-data-simple');
    }
}
