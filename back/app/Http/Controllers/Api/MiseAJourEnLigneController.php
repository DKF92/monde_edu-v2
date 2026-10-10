<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Inscription;
use App\Models\MiseAJourEnLigne;
use App\Models\Reglement;
use App\Services\CaisseService;
use App\Support\ArrierePlan;
use App\Support\PorteePedagogique;
use App\Support\ScanEnLigne;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Inscriptions > Mises a jour en ligne : ce que le bouton « Mettre a jour »
 * (inscription en ligne) a change, element par element, applique directement
 * ou en attente.
 * - Educateur (inscriptions.gerer) : « Modifier » une inscription en attente
 *   dont le statut est 1 (classe choisie, aucun paiement) ;
 * - Caissier (reglements.modifier) : « Supprimer » les paiements d'une
 *   inscription en attente dont le statut est superieur a 1 ; elle repasse au
 *   statut 1 et l'educateur peut la modifier ;
 * - autres postes (consultation des inscriptions ou des paiements) : lecture.
 */
class MiseAJourEnLigneController extends Controller
{
    public const DROITS_LECTURE = ['inscriptions.gerer', 'inscriptions.voir', 'reglements.voir', 'reglements.modifier'];

    public function index(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->canAny(self::DROITS_LECTURE), 403);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $niveaux = PorteePedagogique::niveaux($request, $anneeId);

        $base = MiseAJourEnLigne::query()
            ->where('mises_a_jour_en_ligne.annee_scolaire_id', $anneeId)
            ->leftJoin('inscriptions as i', 'i.id', '=', 'mises_a_jour_en_ligne.inscription_id')
            // Introuvable : seulement tant que l'inscription n'a pas ete trouvee en ligne.
            ->where(fn (Builder $q) => $q->where('mises_a_jour_en_ligne.type', MiseAJourEnLigne::MISE_A_JOUR)
                ->orWhere(fn (Builder $w) => $w->whereNotNull('i.id')->where('i.inscrit_en_ligne', false)))
            ->leftJoin('eleves as e', 'e.id', '=', 'mises_a_jour_en_ligne.eleve_id')
            // Educateur : ses niveaux (niveau actuel ou niveau demande en ligne).
            ->when($niveaux !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereIn('i.niveau_id', $niveaux)->orWhereIn('mises_a_jour_en_ligne.niveau_demande_id', $niveaux)))
            ->when($request->string('recherche')->trim()->isNotEmpty(), function (Builder $q) use ($request) {
                $r = $request->string('recherche')->trim()->value();
                $q->where(fn (Builder $w) => $w->where('e.matricule', 'like', "%{$r}%")->orWhere('e.nom', 'like', "%{$r}%")
                    ->orWhere('e.prenoms', 'like', "%{$r}%")->orWhereRaw("CONCAT(e.nom, ' ', e.prenoms) LIKE ?", ["%{$r}%"]));
            });

        $aTraiter = fn (Builder $q) => $q->where('mises_a_jour_en_ligne.en_attente', true)->whereNotNull('i.id')
            ->where(fn (Builder $w) => $w
                ->where(fn (Builder $n) => $n->whereNotNull('mises_a_jour_en_ligne.niveau_demande_id')->whereColumn('i.niveau_id', '!=', 'mises_a_jour_en_ligne.niveau_demande_id')
                    // niveau en ligne general (TLE) : atteint par toute serie (TLE A2)
                    ->whereRaw('(SELECT COALESCE(nv.niveau_general_id, nv.id) FROM niveaux nv WHERE nv.id = i.niveau_id) <> mises_a_jour_en_ligne.niveau_demande_id'))
                ->orWhere(fn (Builder $a) => $a->whereNotNull('mises_a_jour_en_ligne.affecte_demande')->whereColumn('i.affecte', '!=', 'mises_a_jour_en_ligne.affecte_demande')));

        $introuvables = fn (Builder $q) => $q->where('mises_a_jour_en_ligne.type', MiseAJourEnLigne::INTROUVABLE);
        $compteurs = [
            'toutes' => (clone $base)->count(),
            'a_traiter' => $aTraiter(clone $base)->count(),
            'introuvables' => $introuvables(clone $base)->count(),
        ];

        $page = (clone $base)
            ->when($request->query('etat') === 'a_traiter', $aTraiter)
            ->when($request->query('etat') === 'introuvables', $introuvables)
            // Introuvables en ligne d'abord (a regarder en priorite), puis les plus recentes.
            ->orderByRaw("mises_a_jour_en_ligne.type = 'introuvable' DESC")
            ->orderByDesc('mises_a_jour_en_ligne.updated_at')
            ->with(['inscription.niveau:id,libelle', 'inscription.classe:id,libelle', 'eleve:id,matricule,nom,prenoms', 'auteur:id,name,prenoms'])
            ->select('mises_a_jour_en_ligne.*')
            ->paginate(min(max($request->integer('par_page', 10), 1), 100));

        $user = $request->user();

        return response()->json([
            'data' => collect($page->items())->map(fn (MiseAJourEnLigne $m) => $this->presenter($m, $user))->values(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'par_page' => $page->perPage(),
            'compteurs' => $compteurs,
            'annee' => AnneeScolaire::find($anneeId)?->libelle,
        ]);
    }

    /**
     * Caissier : supprime les paiements de l'inscription (avec un motif,
     * historises comme toute suppression) pour qu'elle repasse au statut 1 et
     * que l'educateur reporte le niveau / le statut affecte en ligne.
     */
    public function supprimerPaiements(Request $request, CaisseService $caisse, int $id)
    {
        abort_unless($request->user()->can('reglements.modifier'), 403);
        $miseAJour = MiseAJourEnLigne::with('inscription')->findOrFail($id);
        $inscription = $miseAJour->inscription;
        abort_unless($inscription && $miseAJour->aTraiter(), 422, 'Cette mise à jour n\'est plus en attente.');
        abort_unless($inscription->statut >= Inscription::PAIEMENT, 422, 'Cette inscription n\'a pas de paiement.');
        $data = $request->validate(['motif' => ['required', 'string', 'min:3', 'max:500']], [
            'motif.required' => 'Le motif de la suppression est obligatoire.',
            'motif.min' => 'Précisez le motif (3 caractères au moins).',
        ]);

        $reglements = Reglement::where('inscription_id', $inscription->id)->get();
        foreach ($reglements as $reglement) {
            $caisse->supprimer($reglement, 'Mise à jour en ligne : '.$data['motif'], $request->user()->id);
        }

        return response()->json([
            'message' => count($reglements).' paiement(s) supprimé(s) : l\'inscription peut maintenant être modifiée.',
            'data' => $this->presenter($miseAJour->fresh(['inscription.niveau:id,libelle', 'inscription.classe:id,libelle', 'eleve:id,matricule,nom,prenoms', 'auteur:id,name,prenoms']), $request->user()),
        ]);
    }

    /**
     * « Mettre a jour » : lance le scan en arriere-plan sur le serveur (il
     * continue si l'utilisateur change de page) ; rend l'etat.
     */
    public function lancer(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('inscriptions.gerer'), 403);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $etab = (int) $tenant->id();
        if (! ScanEnLigne::lire($etab, $anneeId)['en_cours']) {
            $niveaux = PorteePedagogique::niveaux($request, $anneeId);
            ScanEnLigne::effacerArret($etab, $anneeId);
            // Etat « en cours » tout de suite (l'ecran l'affiche avant que la commande demarre).
            ScanEnLigne::ecrire($etab, $anneeId, ['en_cours' => true, 'total' => 0, 'traitees' => 0, 'trouvees' => 0, 'introuvables' => 0,
                'erreur' => null, 'debut' => now()->toDateTimeString(), 'fin' => null, 'dernieres' => [],
                'par' => trim($request->user()->prenoms.' '.$request->user()->name)]);
            ArrierePlan::artisan(array_filter([
                'inscriptions:mise-a-jour-en-ligne', (string) $etab, (string) $anneeId, '--user='.$request->user()->id,
                $niveaux !== null ? '--niveaux='.implode(',', $niveaux ?: [0]) : null,
            ]), ScanEnLigne::journal($etab, $anneeId));
        }

        return $this->etat($request, $tenant);
    }

    /** Etat du scan (toutes les pages de l'application le suivent pendant qu'il tourne). */
    public function etat(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->canAny(self::DROITS_LECTURE), 403);

        return response()->json(['data' => ScanEnLigne::lire((int) $tenant->id(), PorteePedagogique::anneeId($tenant))]);
    }

    public function arreter(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('inscriptions.gerer'), 403);
        ScanEnLigne::demanderArret((int) $tenant->id(), PorteePedagogique::anneeId($tenant));

        return $this->etat($request, $tenant);
    }

    private function presenter(MiseAJourEnLigne $m, $user): array
    {
        $i = $m->inscription;
        $aTraiter = $m->aTraiter();
        $statut = $i ? (int) $i->statut : null;

        return [
            'id' => $m->id,
            'type' => $m->type,
            // Introuvable : nombre de scans sans recu, date du dernier.
            'tentatives' => (int) $m->tentatives,
            'date' => ($m->type === MiseAJourEnLigne::INTROUVABLE ? $m->updated_at : $m->created_at)?->toDateTimeString(),
            'auteur' => $m->auteur ? trim($m->auteur->prenoms.' '.$m->auteur->name) : null,
            'eleve' => $m->eleve ? ['matricule' => $m->eleve->matricule, 'nom' => $m->eleve->nom, 'prenoms' => $m->eleve->prenoms] : null,
            'inscription_id' => $i?->id,
            'statut_lors' => $m->statut_inscription,
            'statut' => $statut,
            'niveau' => $i?->niveau?->libelle,
            'classe' => $i?->classe?->libelle,
            'changements' => collect($m->changements ?? [])->map(fn ($c) => $c + [
                // Element en attente deja reporte sur l'inscription ?
                'traite' => ! $c['applique'] && $i && match ($c['champ']) {
                    'niveau' => MiseAJourEnLigne::memeNiveau($i->niveau_id, $m->niveau_demande_id),
                    'affecte' => (bool) $i->affecte === (bool) $m->affecte_demande,
                    default => false,
                },
            ])->values(),
            'remarques' => $m->remarques ?? [],
            'niveau_demande_id' => $m->niveau_demande_id,
            'affecte_demande' => $m->affecte_demande,
            'en_attente' => (bool) $m->en_attente,
            'a_traiter' => $aTraiter,
            // Educateur : statut 1 (pas de paiement) ; caissier : statut > 1.
            'peut_modifier' => $aTraiter && $statut !== null && $statut < Inscription::PAIEMENT && $user->can('inscriptions.gerer'),
            'peut_supprimer' => $aTraiter && $statut !== null && $statut >= Inscription::PAIEMENT && $user->can('reglements.modifier'),
        ];
    }
}
