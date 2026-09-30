<?php

namespace App\Support;

use App\Models\AffectationEnseignant;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Personnel;
use App\Models\Poste;
use Illuminate\Http\Request;

/**
 * Ce que l'utilisateur voit et saisit en pedagogie, selon son poste actif
 * (V1 : le professeur ne voit que ses classes "enseigner", l'educateur ses
 * niveaux "choix", le parent ses enfants) :
 * - moyennes.gerer (directeur des etudes...) : toutes les classes et matieres ;
 * - poste qui enseigne des matieres (professeur) : ses affectations
 *   (classe + matiere) de l'annee ;
 * - poste rattache a des niveaux (educateur) : les classes de ses niveaux ;
 * - parent : ses enfants seulement.
 */
class PorteePedagogique
{
    public static function anneeId(TenantContext $tenant): int
    {
        $id = $tenant->anneeScolaireId() ?? AnneeScolaire::where('is_active', true)->value('id');
        abort_unless($id, 422, 'Aucune année scolaire active.');

        return (int) $id;
    }

    public static function poste(Request $request): ?Poste
    {
        return $request->hasHeader('X-Poste-Id') ? $request->user()->roles->first() : null;
    }

    public static function personnel(Request $request): ?Personnel
    {
        return Personnel::where('user_id', $request->user()->id)->first();
    }

    /** Toutes les classes et matieres (pas de restriction). */
    public static function complete(Request $request): bool
    {
        $poste = self::poste($request);

        return $request->user()->can('moyennes.gerer') || ! ($poste?->lie_matieres || $poste?->lie_niveaux || $poste?->code === 'parent');
    }

    /**
     * Affectations (classe_id, matiere_id) d'un poste qui enseigne, null si
     * le poste n'est pas limite a ses affectations.
     *
     * @return ?list<array{classe_id: int, matiere_id: int}>
     */
    public static function affectations(Request $request, int $anneeId): ?array
    {
        if ($request->user()->can('moyennes.gerer') || ! self::poste($request)?->lie_matieres) {
            return null;
        }
        $personnel = self::personnel($request);

        return $personnel
            ? AffectationEnseignant::where('annee_scolaire_id', $anneeId)->where('personnel_id', $personnel->id)
                ->get(['classe_id', 'matiere_id'])->map(fn ($a) => ['classe_id' => (int) $a->classe_id, 'matiere_id' => (int) $a->matiere_id])->all()
            : [];
    }

    /**
     * Niveaux d'un poste rattache a des niveaux (educateur) : ceux choisis
     * pour l'annee. Aucun niveau choisi = aucun niveau (jamais tous).
     * null = poste non limite a des niveaux.
     *
     * @return ?list<int>
     */
    public static function niveaux(Request $request, int $anneeId): ?array
    {
        if (! self::poste($request)?->lie_niveaux) {
            return null;
        }
        $personnel = self::personnel($request);

        return $personnel ? $personnel->niveauxDeLAnnee($anneeId)->pluck('niveaux.id')->map(fn ($id) => (int) $id)->all() : [];
    }

    /** Classes visibles (ids), null = toutes les classes de l'annee. */
    public static function classes(Request $request, int $anneeId): ?array
    {
        if ($request->user()->can('moyennes.gerer')) {
            return null;
        }
        $poste = self::poste($request);
        if ($poste?->lie_matieres) {
            $personnel = self::personnel($request);
            if (! $personnel) {
                return [];
            }
            // Ses classes d'enseignement et celles dont il est professeur principal.
            return collect(self::affectations($request, $anneeId))->pluck('classe_id')
                ->merge(Classe::where('annee_scolaire_id', $anneeId)->where('professeur_principal_id', $personnel->id)->pluck('id'))
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
        }
        if ($poste?->lie_niveaux) {
            return Classe::where('annee_scolaire_id', $anneeId)->whereIn('niveau_id', self::niveaux($request, $anneeId))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        if ($poste?->code === 'parent') {
            $enfants = PorteeFinances::elevesDuParent($request) ?: [0];

            return \App\Models\Inscription::where('annee_scolaire_id', $anneeId)->whereIn('eleve_id', $enfants)
                ->whereNotNull('classe_id')->pluck('classe_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        return null;
    }

    /** Eleves visibles : null = tous (parent : ses enfants). */
    public static function eleves(Request $request): ?array
    {
        return PorteeFinances::elevesDuParent($request);
    }

    public static function verifierClasse(Request $request, int $anneeId, int $classeId): void
    {
        $classes = self::classes($request, $anneeId);
        abort_if($classes !== null && ! in_array($classeId, $classes, true), 403, 'Cette classe ne fait pas partie de vos classes.');
    }

    /** Saisie des notes d'une matiere dans une classe (V1 : professeur de la classe). */
    public static function peutSaisir(Request $request, int $anneeId, int $classeId, int $matiereId): bool
    {
        $user = $request->user();
        if ($user->can('moyennes.gerer')) {
            return true;
        }
        if (! $user->can('notes.saisir')) {
            return false;
        }
        $affectations = self::affectations($request, $anneeId);

        return $affectations === null
            ? self::classes($request, $anneeId) === null || in_array($classeId, self::classes($request, $anneeId), true)
            : collect($affectations)->contains(fn ($a) => $a['classe_id'] === $classeId && $a['matiere_id'] === $matiereId);
    }
}
