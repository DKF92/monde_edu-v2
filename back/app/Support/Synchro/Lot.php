<?php

namespace App\Support\Synchro;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lot de changements a transmettre, construit depuis des entrees de
 * sync_journal : une ligne modifiee plusieurs fois n'est envoyee qu'une fois,
 * avec son etat actuel (ou comme suppression si elle n'existe plus).
 *
 * Format d'un changement : ['table', 'operation' => ecriture|suppression,
 * 'cle' => valeurs d'identification, 'ligne' => colonnes (ecriture)].
 */
class Lot
{
    /** @param Collection<int, object> $entrees lignes de sync_journal */
    public static function depuisJournal(Collection $entrees): array
    {
        $changements = [];
        foreach ($entrees->groupBy(fn ($e) => $e->table_nom.'#'.($e->ligne_cle ?? $e->ligne_id)) as $groupe) {
            $e = $groupe->last();
            if (! Tables::estSynchronisee($e->table_nom)) {
                continue;
            }
            $cle = Tables::cleDuJournal($e->table_nom, $e->ligne_id, $e->ligne_cle);
            $ligne = $e->operation === 'ecriture' ? self::lire($e->table_nom, $cle) : null;
            $changements[] = $ligne
                ? ['table' => $e->table_nom, 'operation' => 'ecriture', 'cle' => Tables::cle($e->table_nom, $ligne), 'ligne' => $ligne]
                : ['table' => $e->table_nom, 'operation' => 'suppression', 'cle' => $cle];
        }

        return $changements;
    }

    private static function lire(string $table, array $cle): ?array
    {
        $ligne = DB::table($table)->where($cle)->first();

        return $ligne ? (array) $ligne : null;
    }
}
