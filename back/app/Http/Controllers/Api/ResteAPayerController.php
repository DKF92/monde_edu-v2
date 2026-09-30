<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Etablissement;
use App\Support\BilanCaisse;
use App\Support\Document;
use App\Support\PorteeFinances;
use App\Support\ResteAPayer;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reste a payer des eleves inscrits (droit reglements.voir ; un parent ne
 * voit que ses enfants) : liste, compteurs par etape, documents imprimables
 * (liste par classe, avec ou sans statistiques) en PDF, Word ou Excel.
 *
 * Liste des dettes (V1 liste_dette, droits reglements.voir ou dettes.gerer) :
 * memes filtres et documents, eleves inscrits de l'annee qui ont une dette.
 */
class ResteAPayerController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        [$annee, $filtres] = $this->filtres($request, $tenant);

        $sansEtape = array_diff_key($filtres, ['statut' => true]);
        $parEtape = ResteAPayer::requete($annee->id, $sansEtape)
            ->select('i.statut as etape')->selectRaw('COUNT(*) AS nombre')->groupBy('i.statut')->pluck('nombre', 'etape');
        $totaux = ResteAPayer::requete($annee->id, $filtres)->get()->map(fn ($l) => ResteAPayer::presenter($l));

        $page = ResteAPayer::requete($annee->id, $filtres)
            ->orderBy('e.nom')->orderBy('e.prenoms')->orderBy('e.matricule')
            ->paginate(min(max($request->integer('par_page', 50), 1), 200));

        return response()->json([
            'data' => collect($page->items())->map(fn ($l) => ResteAPayer::presenter($l))->values(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'par_page' => $page->perPage(),
            'annee' => $annee->libelle,
            'compteurs' => collect(array_keys(ResteAPayer::ETAPES))->mapWithKeys(fn ($e) => [$e => (int) ($parEtape[$e] ?? 0)]),
            'totaux' => ResteAPayer::totaux($totaux),
            'criteres_libelle' => BilanCaisse::libelleCriteres(BilanCaisse::criteres($filtres['criteres'])),
            'criteres_disponibles' => BilanCaisse::criteresDisponibles($annee->id),
        ]);
    }

    /** Liste imprimable (classe apres classe), avec statistiques si stats=1. */
    public function document(Request $request, TenantContext $tenant)
    {
        [$annee, $filtres] = $this->filtres($request, $tenant);
        $lignes = ResteAPayer::requete($annee->id, $filtres)->get()->map(fn ($l) => ResteAPayer::presenter($l));
        $stats = $request->boolean('stats');
        $criteres = BilanCaisse::libelleCriteres(BilanCaisse::criteres($filtres['criteres']));

        return Document::repondre('pdf.reste-a-payer', Document::entete(Etablissement::findOrFail($tenant->id())) + [
            'annee' => $annee->libelle,
            'titre' => 'Reste à payer '.$annee->libelle,
            'sous_titre' => collect([
                isset($filtres['statut']) ? ResteAPayer::ETAPES[$filtres['statut']] : null,
                $criteres ?: null,
                trim((string) $filtres['recherche']) !== '' ? 'recherche « '.trim($filtres['recherche']).' »' : null,
            ])->filter()->implode(' · '),
            'classes' => ResteAPayer::parClasse($lignes),
            'totaux' => ResteAPayer::totaux($lignes),
            'nombre' => $lignes->count(),
            'statistiques' => $stats ? ResteAPayer::statistiques($lignes) : [],
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], $stats ? 'reste-a-payer-statistiques' : 'reste-a-payer', $request->query('format'), 'landscape');
    }

    public function dettes(Request $request, TenantContext $tenant)
    {
        [$annee, $filtres] = $this->filtres($request, $tenant, true);

        $sansEtat = array_diff_key($filtres, ['etat_dette' => true]);
        $toutes = ResteAPayer::requete($annee->id, $sansEtat)->get()->map(fn ($l) => ResteAPayer::presenter($l));
        $lignes = isset($filtres['etat_dette']) ? $toutes->where('etat_dette', $filtres['etat_dette']) : $toutes;

        $page = ResteAPayer::requete($annee->id, $filtres)
            ->orderBy('e.nom')->orderBy('e.prenoms')->orderBy('e.matricule')
            ->paginate(min(max($request->integer('par_page', 50), 1), 200));

        return response()->json([
            'data' => collect($page->items())->map(fn ($l) => ResteAPayer::presenter($l))->values(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'par_page' => $page->perPage(),
            'annee' => $annee->libelle,
            'compteurs' => collect(array_keys(ResteAPayer::ETATS_DETTE))->mapWithKeys(fn ($e) => [$e => $toutes->where('etat_dette', $e)->count()]),
            'totaux' => ResteAPayer::totaux($lignes),
            'criteres_libelle' => BilanCaisse::libelleCriteres(BilanCaisse::criteres($filtres['criteres'])),
            'criteres_disponibles' => BilanCaisse::criteresDisponibles($annee->id),
        ]);
    }

    /** Liste des dettes imprimable (classe apres classe), avec statistiques si stats=1. */
    public function documentDettes(Request $request, TenantContext $tenant)
    {
        [$annee, $filtres] = $this->filtres($request, $tenant, true);
        $lignes = ResteAPayer::requete($annee->id, $filtres)->get()->map(fn ($l) => ResteAPayer::presenter($l));
        $stats = $request->boolean('stats');
        $criteres = BilanCaisse::libelleCriteres(BilanCaisse::criteres($filtres['criteres']));

        return Document::repondre('pdf.dettes', Document::entete(Etablissement::findOrFail($tenant->id())) + [
            'annee' => $annee->libelle,
            'titre' => 'Liste des dettes '.$annee->libelle,
            'sous_titre' => collect([
                isset($filtres['etat_dette']) ? ResteAPayer::ETATS_DETTE[$filtres['etat_dette']] : null,
                $criteres ?: null,
                trim((string) $filtres['recherche']) !== '' ? 'recherche « '.trim($filtres['recherche']).' »' : null,
            ])->filter()->implode(' · '),
            'classes' => ResteAPayer::parClasse($lignes),
            'totaux' => ResteAPayer::totaux($lignes),
            'nombre' => $lignes->count(),
            'statistiques' => $stats ? ResteAPayer::statistiques($lignes, ResteAPayer::ETATS_DETTE, 'etat_dette') : [],
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], $stats ? 'dettes-statistiques' : 'dettes', $request->query('format'), 'landscape');
    }

    /** @return array{0: AnneeScolaire, 1: array} */
    private function filtres(Request $request, TenantContext $tenant, bool $dettes = false): array
    {
        $user = $request->user();
        abort_unless($user->can('reglements.voir') || ($dettes && $user->can('dettes.gerer')), 403);

        $data = $request->validate([
            'recherche' => ['nullable', 'string', 'max:100'],
            'statut' => ['nullable', 'integer', Rule::in(array_keys($dettes ? ResteAPayer::ETATS_DETTE : ResteAPayer::ETAPES))],
            'sexe' => ['nullable', Rule::in(['M', 'F'])],
            'redoublant' => ['nullable', 'boolean'],
            'cycle' => ['nullable', Rule::in(array_keys(BilanCaisse::CYCLES))],
            'niveau_id' => ['nullable', 'integer'],
            'classe_id' => ['nullable', 'integer'],
        ]);
        $annee = AnneeScolaire::find($tenant->anneeScolaireId() ?? AnneeScolaire::where('is_active', true)->value('id'));
        abort_unless($annee, 422, 'Aucune année scolaire active.');

        $filtres = [
            'recherche' => $data['recherche'] ?? null,
            'criteres' => $request->only(['sexe', 'redoublant', 'cycle', 'niveau_id', 'classe_id']),
            'enfants' => PorteeFinances::elevesDuParent($request),
        ];
        if ($dettes) {
            $filtres['dettes'] = true;
            if ($request->filled('statut')) {
                $filtres['etat_dette'] = (int) $data['statut'];
            }
        } elseif ($request->filled('statut')) {
            $filtres['statut'] = (int) $data['statut'];
        }

        return [$annee, $filtres];
    }
}
