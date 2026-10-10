<?php

namespace App\Providers;

use App\Models\JetonAcces;
use App\Support\Synchro\Declencheurs;
use App\Support\TenantContext;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sessions valables sur les deux serveurs (voir JetonAcces).
        Sanctum::usePersonalAccessTokenModel(JetonAcces::class);

        // Apres chaque migration, les declencheurs du journal de synchronisation
        // sont recrees : une table ou une colonne nouvelle est suivie d'office.
        Event::listen(MigrationsEnded::class, function () {
            if (Schema::hasTable('sync_journal')) {
                Declencheurs::creer();
            }
        });
    }
}
