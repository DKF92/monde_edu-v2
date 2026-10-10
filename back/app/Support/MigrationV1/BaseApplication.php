<?php

namespace App\Support\MigrationV1;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Bases V2 du serveur MySQL et base utilisee par l'application (.env
 * DB_DATABASE) : liste, et basculement vers une autre base (l'ancien .env
 * est garde dans storage/app/private/migration-v1/).
 */
class BaseApplication
{
    public static function actuelle(): string
    {
        return (string) config('database.connections.mysql.database');
    }

    /**
     * Bases qui ont le schema V2 (tables etablissements, users, migrations).
     *
     * @return list<string>
     */
    public static function basesV2(): array
    {
        return DB::table('information_schema.tables')
            ->whereIn('table_name', ['etablissements', 'users', 'migrations'])
            ->groupBy('table_schema')->havingRaw('count(distinct table_name) = 3')
            ->orderBy('table_schema')->selectRaw('table_schema as nom')->pluck('nom')->all();
    }

    /** L'application utilise desormais $base. */
    public static function basculer(string $base): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $base)) {
            throw new \InvalidArgumentException("Nom de base invalide : $base");
        }
        $dossier = storage_path('app/private/migration-v1');
        if (! is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }
        $env = base_path('.env');
        $contenu = file_get_contents($env);
        copy($env, $dossier.'/env-avant-bascule-'.now()->format('Ymd-His'));
        $contenu = preg_match('/^DB_DATABASE=.*$/m', $contenu)
            ? preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE='.$base, $contenu)
            : rtrim($contenu)."\nDB_DATABASE=$base\n";
        file_put_contents($env, $contenu);
        Artisan::call('config:clear');
    }
}
