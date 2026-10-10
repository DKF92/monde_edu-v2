<?php

namespace App\Support\Synchro;

use Illuminate\Support\Facades\DB;

/**
 * Declencheurs MySQL qui notent chaque changement dans sync_journal, quelle
 * que soit la facon d'ecrire (modeles, ecritures directes, edition manuelle de
 * la base). Ils se taisent quand la variable de session @sync_application est
 * posee : les changements recus de l'autre serveur ne repartent pas (pas de
 * boucle). Recrees apres chaque migration (AppServiceProvider).
 */
class Declencheurs
{
    private const PREFIXE = 'sj_';

    /** (Re)cree les declencheurs de toutes les tables synchronisees ; renvoie leur nombre. */
    public static function creer(): int
    {
        Tables::oublier();
        self::supprimer();
        $nombre = 0;
        foreach (Tables::synchronisees() as $table) {
            $genre = Tables::genre($table);
            foreach (['INSERT' => 'NEW', 'UPDATE' => 'NEW', 'DELETE' => 'OLD'] as $evenement => $ligne) {
                // Sessions : last_used_at change a chaque requete, seules la
                // creation et la suppression (deconnexion) comptent.
                if ($genre === 'jetons' && $evenement === 'UPDATE') {
                    continue;
                }
                [$id, $cle] = self::identification($table, $genre, $ligne);
                $operation = $evenement === 'DELETE' ? 'suppression' : 'ecriture';
                DB::unprepared(sprintf(
                    "CREATE TRIGGER `%s` AFTER %s ON `%s` FOR EACH ROW IF @sync_application IS NULL THEN "
                    ."INSERT INTO sync_journal (table_nom, ligne_id, ligne_cle, operation, origine) VALUES ('%s', %s, %s, '%s', "
                    ."(SELECT COALESCE(MAX(valeur), 'local') FROM sync_etat WHERE cle = 'role')); END IF",
                    self::nom($table, $evenement), $evenement, $table, $table, $id, $cle, $operation
                ));
                $nombre++;
            }
        }

        return $nombre;
    }

    public static function supprimer(): void
    {
        foreach (DB::select('SELECT trigger_name AS t FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name LIKE ?', [self::PREFIXE.'%']) as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$t->t}`");
        }
    }

    /** @return array{0: string, 1: string} expressions SQL de ligne_id et ligne_cle */
    private static function identification(string $table, string $genre, string $ligne): array
    {
        return match ($genre) {
            'pedagogie' => ["$ligne.`id`", "$ligne.`uuid`"],
            'jetons' => ['NULL', "$ligne.`token`"],
            'pivot' => ['NULL', 'CONCAT_WS(\'|\', '.collect(Tables::clePrimaire($table))->map(fn ($c) => "$ligne.`$c`")->join(', ').')'],
            default => ["$ligne.`id`", 'NULL'],
        };
    }

    private static function nom(string $table, string $evenement): string
    {
        $suffixe = ['INSERT' => '_ai', 'UPDATE' => '_au', 'DELETE' => '_ad'][$evenement];

        return self::PREFIXE.substr($table, 0, 64 - strlen(self::PREFIXE) - 3).$suffixe;
    }
}
