<?php

namespace App\Support\Synchro;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applique un lot de changements recu de l'autre serveur.
 *
 * - $aLEcole = true : changements venus du serveur en ligne, appliques a
 *   l'ecole. Seuls la pedagogie, les sessions et quelques colonnes
 *   (config serveur.synchro.colonnes_en_ligne) sont acceptes ; une note,
 *   conduite ou absence saisie en ligne sur une periode cloturee ou des notes
 *   arretees a l'ecole est refusee (sync_rejets) et retiree du serveur en ligne.
 * - $aLEcole = false : copie complete recue de l'ecole par le serveur en ligne.
 *
 * Partout : la modification la plus recente (updated_at) l'emporte, et deux
 * lignes pedagogiques creees des deux cotes avec la meme cle naturelle sont
 * fusionnees. Les declencheurs du journal se taisent pendant l'application.
 */
class Applicateur
{
    private array $bilan = ['appliques' => 0, 'ignores' => 0, 'plus_recents_ici' => 0, 'rejetes' => 0, 'erreurs' => []];

    public function __construct(private readonly bool $aLEcole) {}

    public function appliquer(array $changements): array
    {
        DB::statement('SET @sync_application = 1');
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($changements as $c) {
                try {
                    $this->un($c);
                } catch (Throwable $e) {
                    $this->bilan['erreurs'][] = ['table' => $c['table'] ?? '?', 'cle' => $c['cle'] ?? null, 'erreur' => mb_substr($e->getMessage(), 0, 300)];
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            DB::statement('SET @sync_application = NULL');
        }

        return $this->bilan;
    }

    private function un(array $c): void
    {
        $table = $c['table'];
        if (! Tables::estSynchronisee($table)) {
            $this->bilan['ignores']++;

            return;
        }
        $genre = Tables::genre($table);
        $colonnes = config("serveur.synchro.colonnes_en_ligne.$table");
        if ($this->aLEcole && $genre === 'id' && ! $colonnes) {
            // Le serveur en ligne n'a pas a modifier cette table : ignore.
            $this->bilan['ignores']++;

            return;
        }

        if ($c['operation'] === 'suppression') {
            if ($this->aLEcole && $colonnes) {
                $this->bilan['ignores']++; // une ligne administrative ne se supprime qu'a l'ecole

                return;
            }
            DB::table($table)->where($c['cle'])->delete();
            $this->bilan['appliques']++;

            return;
        }

        $ligne = $c['ligne'];
        match (true) {
            $this->aLEcole && (bool) $colonnes => $this->colonnes($table, $ligne, $colonnes),
            $genre === 'pedagogie' => $this->pedagogie($table, $ligne),
            $genre === 'jetons' => $this->remplacer($table, ['token' => $ligne['token']], $ligne, sansId: true),
            default => $this->remplacer($table, $c['cle'], $ligne, sansId: false),
        };
    }

    /** Ligne pedagogique : par uuid, sinon par cle naturelle ; la plus recente gagne. */
    private function pedagogie(string $table, array $ligne): void
    {
        unset($ligne['id']); // les id different d'un serveur a l'autre
        $existante = DB::table($table)->where('uuid', $ligne['uuid'])->first();
        foreach (Tables::clesNaturelles($table) as $cols) {
            if ($existante) {
                break;
            }
            $valeurs = collect($cols)->mapWithKeys(fn ($col) => [$col => $ligne[$col] ?? null]);
            if ($valeurs->contains(null)) {
                continue;
            }
            $existante = DB::table($table)->where($valeurs->all())->first();
        }

        if ($this->aLEcole && ($motif = $this->barriere($table, $ligne))) {
            $this->rejeter($table, $ligne, $motif, $existante);

            return;
        }
        if ($existante && $this->plusRecent($existante, $ligne)) {
            $this->bilan['plus_recents_ici']++;

            return;
        }
        if ($existante) {
            DB::table($table)->where('id', $existante->id)->update($ligne); // adopte aussi l'uuid recu
        } else {
            DB::table($table)->insert($ligne);
        }
        $this->bilan['appliques']++;
    }

    /** Ecole : seules les colonnes autorisees d'une ligne administrative existante. */
    private function colonnes(string $table, array $ligne, array $colonnes): void
    {
        $existante = DB::table($table)->where('id', $ligne['id'])->first();
        if (! $existante) {
            $this->bilan['ignores']++;

            return;
        }
        if ($this->plusRecent($existante, $ligne)) {
            $this->bilan['plus_recents_ici']++;

            return;
        }
        $maj = array_intersect_key($ligne, array_flip($colonnes));
        if (array_key_exists('updated_at', $ligne)) {
            $maj['updated_at'] = $ligne['updated_at'];
        }
        DB::table($table)->where('id', $ligne['id'])->update($maj);
        $this->bilan['appliques']++;
    }

    private function remplacer(string $table, array $cle, array $ligne, bool $sansId): void
    {
        if ($sansId) {
            unset($ligne['id']);
        }
        DB::table($table)->updateOrInsert($cle, $ligne);
        $this->bilan['appliques']++;
    }

    /** La ligne deja presente ici est-elle plus recente que celle recue ? */
    private function plusRecent(object $existante, array $ligne): bool
    {
        $ici = $existante->updated_at ?? null;
        $recue = $ligne['updated_at'] ?? null;

        return $ici && $recue && strcmp((string) $ici, (string) $recue) > 0;
    }

    /** Motif de refus a l'ecole d'une saisie pedagogique faite en ligne, ou null. */
    private function barriere(string $table, array $ligne): ?string
    {
        if (! in_array($table, ['notes', 'moyennes', 'absences', 'moyennes_generales'], true) || empty($ligne['periode_id'])) {
            return null;
        }
        if (DB::table('periodes')->where('id', $ligne['periode_id'])->value('is_cloturee')) {
            return 'Période clôturée à l\'école.';
        }
        if ($table === 'notes' && DB::table('moyennes')->where(['classe_id' => $ligne['classe_id'], 'matiere_id' => $ligne['matiere_id'], 'periode_id' => $ligne['periode_id'], 'is_arretee' => true])->exists()) {
            return 'Notes de cette matière arrêtées à l\'école.';
        }
        if ($table === 'moyennes' && empty($ligne['is_arretee'])
            && DB::table('moyennes')->where(['eleve_id' => $ligne['eleve_id'], 'matiere_id' => $ligne['matiere_id'], 'periode_id' => $ligne['periode_id'], 'is_arretee' => true])->exists()) {
            return 'Moyenne arrêtée à l\'école.';
        }

        return null;
    }

    /**
     * Refus : note dans sync_rejets (auteur a prevenir), puis le serveur en
     * ligne est corrige au prochain envoi (suppression de sa version, renvoi de
     * celle de l'ecole si elle existe).
     */
    private function rejeter(string $table, array $ligne, string $motif, ?object $existante): void
    {
        $rejetId = DB::table('sync_rejets')->insertGetId([
            'table_nom' => $table, 'ligne_uuid' => $ligne['uuid'], 'donnees' => json_encode($ligne, JSON_UNESCAPED_UNICODE),
            'motif' => $motif, 'auteur_id' => $ligne['saisi_par_id'] ?? $ligne['modifie_par_id'] ?? null, 'cree_le' => now(),
        ]);
        // Meme uuid : ligne de l'ecole modifiee en ligne -> on y renvoie la
        // version de l'ecole. Sinon : la version en ligne est supprimee (et
        // celle de l'ecole, de meme cle naturelle, renvoyee).
        // Le refus est envoye au serveur en ligne : l'auteur le voit ou qu'il soit connecte.
        $journal = [['table_nom' => 'sync_rejets', 'ligne_id' => $rejetId, 'ligne_cle' => null, 'operation' => 'ecriture', 'origine' => 'local']];
        if (! $existante || $existante->uuid !== $ligne['uuid']) {
            $journal[] = ['table_nom' => $table, 'ligne_id' => null, 'ligne_cle' => $ligne['uuid'], 'operation' => 'suppression', 'origine' => 'local'];
        }
        if ($existante) {
            // La version de l'ecole doit l'emporter en ligne, ou elle passerait
            // pour plus ancienne que la saisie refusee.
            DB::table($table)->where('id', $existante->id)->update(['updated_at' => now()]);
            $journal[] = ['table_nom' => $table, 'ligne_id' => $existante->id, 'ligne_cle' => $existante->uuid, 'operation' => 'ecriture', 'origine' => 'local'];
        }
        DB::table('sync_journal')->insert($journal);
        $this->bilan['rejetes']++;
    }
}
