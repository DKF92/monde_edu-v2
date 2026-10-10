<?php

namespace App\Support\Synchro;

use Illuminate\Support\Facades\DB;

/**
 * Tables synchronisees et facon d'identifier leurs lignes d'un serveur a
 * l'autre (config/serveur.php, section synchro) :
 * - « pedagogie » : par uuid (les id different entre les serveurs) ;
 * - « jetons »    : sessions, par le hash du jeton ;
 * - « pivot »     : tables sans id, par leur cle primaire composee ;
 * - « id »        : toutes les autres, ecrites seulement a l'ecole : par id.
 */
class Tables
{
    private static ?array $tables = null;

    private static array $cles = [];

    private static array $uniques = [];

    /** @return list<string> */
    public static function synchronisees(): array
    {
        return self::$tables ??= collect(DB::select(
            "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
        ))->pluck('t')->diff(config('serveur.synchro.exclues', []))->values()->all();
    }

    public static function estSynchronisee(string $table): bool
    {
        return in_array($table, self::synchronisees(), true);
    }

    /** @return 'pedagogie'|'jetons'|'pivot'|'id' */
    public static function genre(string $table): string
    {
        return match (true) {
            in_array($table, config('serveur.synchro.pedagogie', []), true) => 'pedagogie',
            $table === config('serveur.synchro.jetons') => 'jetons',
            self::clePrimaire($table) !== ['id'] => 'pivot',
            default => 'id',
        };
    }

    /** @return list<string> colonnes de la cle primaire */
    public static function clePrimaire(string $table): array
    {
        return self::$cles[$table] ??= collect(DB::select(
            "SELECT column_name AS c FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = 'PRIMARY' ORDER BY ordinal_position",
            [$table]
        ))->pluck('c')->all();
    }

    /**
     * Cles naturelles (index uniques hors id et uuid) : deux serveurs qui ont
     * cree la « meme » ligne (ex : conduite d'un eleve pour une periode).
     *
     * @return list<list<string>>
     */
    public static function clesNaturelles(string $table): array
    {
        return self::$uniques[$table] ??= collect(DB::select(
            "SELECT index_name AS i, column_name AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0 AND index_name <> 'PRIMARY' ORDER BY index_name, seq_in_index",
            [$table]
        ))->groupBy('i')->map(fn ($l) => $l->pluck('c')->all())->reject(fn ($c) => $c === ['uuid'])->values()->all();
    }

    /** Valeurs d'identification d'une ligne (envoyees avec chaque changement). */
    public static function cle(string $table, array $ligne): array
    {
        return match (self::genre($table)) {
            'pedagogie' => ['uuid' => $ligne['uuid']],
            'jetons' => ['token' => $ligne['token']],
            default => collect(self::clePrimaire($table))->mapWithKeys(fn ($c) => [$c => $ligne[$c]])->all(),
        };
    }

    /** Cle lue dans le journal (ligne_id ou ligne_cle « a|b|c ») pour une suppression. */
    public static function cleDuJournal(string $table, ?int $ligneId, ?string $ligneCle): array
    {
        return match (self::genre($table)) {
            'pedagogie' => ['uuid' => $ligneCle],
            'jetons' => ['token' => $ligneCle],
            'pivot' => array_combine(self::clePrimaire($table), explode('|', (string) $ligneCle)),
            default => ['id' => $ligneId],
        };
    }

    /** Recharge les listes (apres une migration). */
    public static function oublier(): void
    {
        self::$tables = null;
        self::$cles = [];
        self::$uniques = [];
    }
}
