<?php

namespace App\Support;

use App\Models\Classe;
use App\Models\Moyenne;
use App\Models\Periode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Arret des notes (V1 enr_moy : arrete = OUI) et cloture des periodes, reserves
 * au directeur (droit notes.arreter) :
 * - arreter fige la moyenne de chaque eleve dans chaque matiere : les notes ne
 *   changent plus ; rouvrir supprime ces moyennes figees ;
 * - cloturer une periode arrete d'abord toutes les notes de toutes les classes,
 *   puis refuse toute saisie (notes, absences, conduite) jusqu'a reouverture.
 */
class ArretNotes
{
    /** Matieres notees de la classe (hors conduite), filtrees si $matiereId. */
    public static function matieres(Classe $classe, ?int $matiereId = null): Collection
    {
        return Resultats::matieres($classe->niveau_id)->reject(fn ($m) => $m['conduite'])
            ->when($matiereId, fn (Collection $m) => $m->where('id', $matiereId))->values();
    }

    /** Fige les moyennes calculees ; renvoie le nombre de moyennes arretees. */
    public static function arreter(Classe $classe, Periode $periode, Collection $matieres, int $userId): int
    {
        $eleves = Resultats::eleves($classe);
        $resultats = collect(Resultats::periode($classe, $periode)['eleves'])->keyBy('eleve_id');
        $nombre = 0;
        DB::transaction(function () use ($matieres, $eleves, $resultats, $classe, $periode, $userId, &$nombre) {
            foreach ($matieres as $m) {
                foreach ($eleves as $e) {
                    $moyenne = $resultats[$e['eleve_id']]['moyennes'][$m['id']] ?? null;
                    if (! $moyenne || $moyenne['arretee']) {
                        continue;
                    }
                    Moyenne::updateOrCreate(
                        ['eleve_id' => $e['eleve_id'], 'matiere_id' => $m['id'], 'periode_id' => $periode->id],
                        ['classe_id' => $classe->id, 'moyenne' => $moyenne['moyenne'], 'coefficient' => $m['coefficient'],
                            'rang' => $moyenne['rang'] ?? null, 'appreciation' => $moyenne['appreciation'] ?? null,
                            'is_arretee' => true, 'saisi_par_id' => $userId],
                    );
                    $nombre++;
                }
            }
        });

        return $nombre;
    }

    /** Supprime les moyennes figees : les notes redeviennent modifiables. */
    public static function rouvrir(Classe $classe, Periode $periode, Collection $matieres): int
    {
        return Moyenne::where('periode_id', $periode->id)->whereIn('eleve_id', Resultats::eleves($classe)->pluck('eleve_id'))
            ->whereIn('matiere_id', $matieres->pluck('id'))->where('is_arretee', true)->delete();
    }

    /** Arrete toutes les classes de l'annee puis cloture la periode. */
    public static function cloturer(Periode $periode, int $userId): int
    {
        $nombre = 0;
        foreach (Classe::where('annee_scolaire_id', $periode->annee_scolaire_id)->get() as $classe) {
            $nombre += self::arreter($classe, $periode, self::matieres($classe), $userId);
        }
        $periode->update(['is_cloturee' => true]);

        return $nombre;
    }

    /**
     * Etat des classes pour une periode : matieres notees et arretees.
     *
     * @return list<array>
     */
    public static function etat(int $anneeId, Periode $periode): array
    {
        $classes = Classe::where('classes.annee_scolaire_id', $anneeId)->with('niveau:id,libelle,ordre')->get()
            ->sortBy(fn (Classe $c) => [$c->niveau?->ordre, $c->libelle])->values();
        $notees = DB::table('notes')->where('periode_id', $periode->id)
            ->groupBy('classe_id')->selectRaw('classe_id, COUNT(DISTINCT matiere_id) AS matieres, COUNT(*) AS notes')->get()->keyBy('classe_id');
        $arretees = DB::table('moyennes')->join('inscriptions', fn ($j) => $j->on('inscriptions.eleve_id', '=', 'moyennes.eleve_id')
            ->where('inscriptions.annee_scolaire_id', $anneeId))
            ->join('matieres', 'matieres.id', '=', 'moyennes.matiere_id')
            ->where('moyennes.periode_id', $periode->id)->where('moyennes.is_arretee', true)->where('matieres.code', '!=', Resultats::CONDUITE)
            ->groupBy('inscriptions.classe_id')->selectRaw('inscriptions.classe_id, COUNT(DISTINCT moyennes.matiere_id) AS matieres')
            ->pluck('matieres', 'classe_id');
        $effectifs = DB::table('inscriptions')->where('annee_scolaire_id', $anneeId)->whereNotNull('classe_id')
            ->groupBy('classe_id')->selectRaw('classe_id, COUNT(*) AS n')->pluck('n', 'classe_id');

        return $classes->map(fn (Classe $c) => [
            'id' => $c->id,
            'libelle' => $c->libelle,
            'niveau' => $c->niveau?->libelle,
            'effectif' => (int) ($effectifs[$c->id] ?? 0),
            'matieres' => self::matieres($c)->count(),
            'matieres_notees' => (int) ($notees[$c->id]->matieres ?? 0),
            'notes' => (int) ($notees[$c->id]->notes ?? 0),
            'matieres_arretees' => (int) ($arretees[$c->id] ?? 0),
        ])->all();
    }
}
