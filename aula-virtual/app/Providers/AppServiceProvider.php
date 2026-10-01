<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
        $this->guardWordpressAuthBypass();

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Paginator::defaultView('vendor.pagination.smart-data');
        Paginator::defaultSimpleView('vendor.pagination.smart-data-simple');
    }

    /**
     * Impide iniciar la aplicacion con el bypass solicitado fuera de entornos seguros.
     */
    private function guardWordpressAuthBypass(): void
    {
        $requested = (bool) config('auth.gateway.wordpress_bypass.requested', false);
        $allowedEnvironments = (array) config(
            'auth.gateway.wordpress_bypass.allowed_environments',
            ['local', 'testing']
        );
        $environment = (string) config('app.env', 'production');
        $environmentIsAllowed = in_array($environment, $allowedEnvironments, true);
        $effective = (bool) config('auth.gateway.wordpress_bypass.effective', false);

        if ($requested && !$environmentIsAllowed) {
            throw new RuntimeException(
                'Configuracion insegura: WP_AUTH_BYPASS solo esta permitido en local/testing.'
            );
        }

        if ($effective !== ($requested && $environmentIsAllowed)) {
            throw new RuntimeException(
                'Configuracion insegura: el estado efectivo de WP_AUTH_BYPASS es inconsistente.'
            );
        }
    }
}
