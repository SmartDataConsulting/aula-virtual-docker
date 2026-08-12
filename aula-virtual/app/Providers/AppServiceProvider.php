<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

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
        Paginator::defaultView('vendor.pagination.smart-data');
        Paginator::defaultSimpleView('vendor.pagination.smart-data-simple');
    }
}
