<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\EcheancierLigne;
use App\Models\Eleve;
use App\Models\Inscription;
use App\Models\Periode;
use App\Models\Personnel;
use App\Models\Reglement;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    private const NB_CLASSES_AFFICHEES = 5;

    private const NB_ECHEANCES_AFFICHEES = 4;

    public function index(Request $request, TenantContext $tenant)
    {
        /** @var User $user */
        $user = $request->user();

        // Priorite a l'annee scolaire choisie par l'utilisateur (en-tete
        // X-Annee-Scolaire-Id) ; a defaut, l'annee marquee active sert de repli.
        $annee = $tenant->anneeScolaireId()
            ? AnneeScolaire::find($tenant->anneeScolaireId())
            : AnneeScolaire::where('is_active', true)->first();

        // Les montants ne sont communiques qu'aux profils qui ont le droit de
        // les consulter (un professeur n'a pas a voir la caisse).
        $voitFinances = $user->can('reglements.voir') || $user->can('rapports.voir');

        [$classes, $portee, $totalClasses] = $this->classes($user, $annee);

        return response()->json([
            'annee_scolaire_id' => $annee?->id,
            'annee_scolaire_active' => $annee?->libelle,
            'periode' => $this->periode($tenant, $annee),
            'effectif_eleves' => Eleve::where('statut', 'actif')->count(),
            'nombre_classes' => $annee
                ? Classe::where('annee_scolaire_id', $annee->id)->count()
                : 0,
            'classes' => $classes,
            'classes_portee' => $portee,
            'classes_total' => $totalClasses,
            'echeances' => $annee && $voitFinances ? $this->echeances($annee) : [],
            'finances' => $voitFinances ? $this->finances($annee) : null,
        ]);
    }

    /**
     * Un enseignant voit SES classes (affectations ou professeur principal) ;
     * un profil administratif voit toutes les classes de l'annee ; les autres
     * (caisse, comptabilite...) n'en voient aucune.
     *
     * @return array{0: Collection, 1: 'mes'|'toutes'|null, 2: int}
     */
    private function classes(User $user, ?AnneeScolaire $annee): array
    {
        if (! $annee) {
            return [collect(), null, 0];
        }

        $query = Classe::query()
            ->where('annee_scolaire_id', $annee->id)
            ->with('niveau:id,libelle,ordre')
            ->withCount(['inscriptions as effectif' => fn ($q) => $q->visiblesEnClasse()]);

        $personnel = Personnel::where('user_id', $user->id)->first();
        $classeIdsEnseignant = $personnel
            ? $personnel->affectations()->where('annee_scolaire_id', $annee->id)->pluck('classe_id')
                ->merge($personnel->classesProfesseurPrincipal()->where('annee_scolaire_id', $annee->id)->pluck('id'))
                ->unique()
            : collect();

        if ($classeIdsEnseignant->isNotEmpty()) {
            $portee = 'mes';
            $query->whereIn('id', $classeIdsEnseignant);
        } elseif ($user->can('classes.gerer') || $user->can('eleves.voir')) {
            $portee = 'toutes';
        } else {
            return [collect(), null, 0];
        }

        $total = (clone $query)->count();

        $classes = $query->get()
            // Ordre pedagogique (6EME avant TLE) puis alphabetique (6EME A avant 6EME B).
            ->sortBy(fn (Classe $c) => sprintf('%03d-%s', $c->niveau?->ordre ?? 0, $c->libelle))
            ->take(self::NB_CLASSES_AFFICHEES)
            ->map(fn (Classe $c) => [
                'id' => $c->id,
                'libelle' => $c->libelle,
                'niveau' => $c->niveau?->libelle,
                'salle' => $c->salle,
                'effectif' => (int) $c->effectif,
                'capacite' => $c->capacite,
            ])
            ->values();

        return [$classes, $portee, $total];
    }

    private function periode(TenantContext $tenant, ?AnneeScolaire $annee): ?array
    {
        if (! $annee) {
            return null;
        }

        $periode = $tenant->periodeId()
            ? Periode::find($tenant->periodeId())
            : Periode::where('annee_scolaire_id', $annee->id)->where('is_active', true)->first();

        if (! $periode) {
            return null;
        }

        return [
            'id' => $periode->id,
            'libelle' => $periode->libelle,
            'date_debut' => $periode->date_debut?->toDateString(),
            'date_fin' => $periode->date_fin?->toDateString(),
        ];
    }

    /**
     * Prochaines dates limites de versement des frais scolaires. La table des
     * echeanciers n'a pas d'etablissement_id : le filtre sur l'annee (deja
     * verifiee comme appartenant a l'etablissement courant) suffit.
     */
    private function echeances(AnneeScolaire $annee): Collection
    {
        return EcheancierLigne::query()
            ->whereHas('echeancier', fn ($q) => $q->where('annee_scolaire_id', $annee->id))
            ->with('echeancier.niveau:id,libelle')
            ->whereDate('date_limite', '>=', today())
            ->orderBy('date_limite')
            ->limit(self::NB_ECHEANCES_AFFICHEES)
            ->get()
            ->map(fn (EcheancierLigne $ligne) => [
                'date' => $ligne->date_limite->toDateString(),
                'libelle' => $ligne->numero_versement === 1
                    ? '1er versement'
                    : $ligne->numero_versement.'e versement',
                'detail' => $ligne->echeancier->niveau?->libelle ?? $ligne->echeancier->libelle ?? 'Tous niveaux',
                'montant' => (float) $ligne->montant,
            ]);
    }

    private function finances(?AnneeScolaire $annee): array
    {
        $resteARecouvrer = $annee
            ? (float) Inscription::where('annee_scolaire_id', $annee->id)
                ->selectRaw('COALESCE(SUM(montant_total_du - montant_total_reduit - montant_total_paye), 0) as reste')
                ->value('reste')
            : 0.0;

        return [
            'encaissements_du_jour' => (float) Reglement::whereDate('date_paiement', today())->sum('montant_total'),
            'encaissements_du_mois' => (float) Reglement::whereMonth('date_paiement', now()->month)
                ->whereYear('date_paiement', now()->year)
                ->sum('montant_total'),
            'reste_a_recouvrer' => max(0.0, $resteARecouvrer),
        ];
    }
}
