<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\DocumentEleve;
use App\Models\Dette;
use App\Models\Eleve;
use App\Models\FraisEleve;
use App\Models\Inscription;
use App\Models\MoyenneGenerale;
use App\Models\Reglement;
use App\Support\FicheEleve;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Eleves de l'etablissement (droit "eleves.voir") : tous les eleves connus,
 * inscrits ou non pour l'annee de travail, et leur dossier complet.
 */
class EleveController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('eleves.voir'), 403);

        $anneeId = $this->anneeId($tenant);
        $inscriteCetteAnnee = fn (Builder $q) => $q->where('annee_scolaire_id', $anneeId);

        $base = Eleve::query()
            ->when($request->string('recherche')->trim()->isNotEmpty(), function (Builder $query) use ($request) {
                $recherche = $request->string('recherche')->trim()->value();
                $query->where(fn (Builder $q) => $q
                    ->where('matricule', 'like', "{$recherche}%")
                    ->orWhere('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenoms', 'like', "%{$recherche}%")
                    ->orWhereRaw("CONCAT(nom, ' ', prenoms) LIKE ?", ["%{$recherche}%"]));
            })
            ->when($request->filled('sexe'), fn (Builder $q) => $q->where('sexe', $request->string('sexe')->value()));

        $compteurs = [
            'total' => (clone $base)->count(),
            'inscrits' => (clone $base)->whereHas('inscriptions', $inscriteCetteAnnee)->count(),
        ];
        $compteurs['non_inscrits'] = $compteurs['total'] - $compteurs['inscrits'];

        $page = (clone $base)
            ->when($request->input('inscription') === 'inscrits', fn (Builder $q) => $q->whereHas('inscriptions', $inscriteCetteAnnee))
            ->when($request->input('inscription') === 'non_inscrits', fn (Builder $q) => $q->whereDoesntHave('inscriptions', $inscriteCetteAnnee))
            ->with(['inscriptions' => fn ($q) => $q
                ->join('annees_scolaires', 'annees_scolaires.id', '=', 'inscriptions.annee_scolaire_id')
                ->orderByDesc('annees_scolaires.libelle')
                ->select('inscriptions.*', 'annees_scolaires.libelle as annee_libelle')
                ->with(['classe:id,libelle', 'niveau:id,libelle'])])
            ->orderBy('nom')->orderBy('prenoms')
            ->paginate(min(max($request->integer('par_page', 50), 1), 200));

        return response()->json([
            'data' => collect($page->items())->map(function (Eleve $e) use ($anneeId) {
                $courante = $e->inscriptions->first(fn (Inscription $i) => $i->annee_scolaire_id === $anneeId);
                $derniere = $e->inscriptions->first();

                return [
                    'id' => $e->id,
                    'matricule' => $e->matricule,
                    'nom' => $e->nom,
                    'prenoms' => $e->prenoms,
                    'sexe' => $e->sexe,
                    'date_naissance' => $e->date_naissance?->toDateString(),
                    'photo_url' => FicheEleve::photoUrl($e),
                    'inscription' => $courante ? [
                        'id' => $courante->id,
                        'classe' => $courante->classe?->libelle,
                        'niveau' => $courante->niveau?->libelle,
                        'statut' => $courante->statut,
                    ] : null,
                    'derniere_annee' => $derniere ? [
                        'annee' => $derniere->annee_libelle,
                        'classe' => $derniere->classe?->libelle ?? $derniere->niveau?->libelle,
                    ] : null,
                    'nombre_annees' => $e->inscriptions->count(),
                ];
            })->values(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'par_page' => $page->perPage(),
            'derniere_page' => $page->lastPage(),
            'compteurs' => $compteurs,
            'annee' => AnneeScolaire::find($anneeId)?->libelle,
        ]);
    }

    /** Dossier de l'eleve : fiche, parcours annee par annee, paiements, dettes, documents. */
    public function show(Request $request, TenantContext $tenant, int $id)
    {
        abort_unless($request->user()->can('eleves.voir'), 403);

        $eleve = Eleve::with('tuteurs')->findOrFail($id);
        $voitFinances = $request->user()->can('reglements.voir') || $request->user()->can('inscriptions.gerer');
        $anneeId = $this->anneeId($tenant);

        $inscriptions = Inscription::where('eleve_id', $eleve->id)
            ->join('annees_scolaires', 'annees_scolaires.id', '=', 'inscriptions.annee_scolaire_id')
            ->orderByDesc('annees_scolaires.libelle')
            ->select('inscriptions.*', 'annees_scolaires.libelle as annee_libelle')
            ->with(['classe:id,libelle', 'niveau:id,libelle', 'niveauASuivre:id,libelle', 'enregistrePar:id,name', 'fraisEleves.typeFrais'])
            ->get();

        $moyennes = MoyenneGenerale::where('eleve_id', $eleve->id)
            ->with(['periode:id,libelle,numero', 'classe:id,annee_scolaire_id'])
            ->get()
            ->groupBy(fn (MoyenneGenerale $m) => $m->classe?->annee_scolaire_id);

        $parcours = $inscriptions->map(fn (Inscription $i) => [
            'id' => $i->id,
            'annee' => $i->annee_libelle,
            'en_cours' => $i->annee_scolaire_id === $anneeId,
            'niveau' => $i->niveau?->libelle,
            'classe' => $i->classe?->libelle,
            'statut' => $i->statut,
            'affecte' => (bool) $i->affecte,
            'redoublant' => (bool) $i->redoublant,
            'boursier' => (bool) $i->boursier,
            'langue_vivante_2' => $i->langue_vivante_2,
            'inscrit_en_ligne' => (bool) $i->inscrit_en_ligne,
            'date_inscription' => $i->date_inscription?->toDateString(),
            'enregistre_par' => $i->enregistrePar?->name,
            'decision_finale' => $i->decision_finale,
            'moyenne_annuelle' => $i->moyenne_annuelle,
            'niveau_a_suivre' => $i->niveauASuivre?->libelle,
            'provenance' => collect([$i->etablissement_origine, $i->classe_origine])->filter()->implode(' · ') ?: null,
            'moyennes' => ($moyennes[$i->annee_scolaire_id] ?? collect())
                ->sortBy(fn (MoyenneGenerale $m) => $m->periode?->numero)
                ->map(fn (MoyenneGenerale $m) => [
                    'periode' => $m->periode?->libelle,
                    'moyenne' => $m->moyenne_generale !== null ? (float) $m->moyenne_generale : null,
                    'rang' => $m->rang_classe,
                    'mention' => $m->mention,
                ])->values(),
            'finances' => $voitFinances ? [
                'total_du' => (int) $i->montant_total_du,
                'total_reduit' => (int) $i->montant_total_reduit,
                'total_paye' => (int) $i->montant_total_paye,
                'reste' => (int) max(0, $i->resteAPayer()),
                'frais' => $i->fraisEleves->whereNull('quantite_due')
                    ->sortBy(fn (FraisEleve $f) => $f->typeFrais->ordre)
                    ->map(fn (FraisEleve $f) => [
                        'libelle' => $f->typeFrais->libelle,
                        'montant_du' => (int) $f->montant_du,
                        'montant_reduit' => (int) $f->montant_reduit,
                        'montant_paye' => (int) $f->montant_paye,
                    ])->values(),
            ] : null,
        ])->values();

        return response()->json(['data' => [
            'eleve' => FicheEleve::presenter($eleve),
            'parcours' => $parcours,
            'finances_visibles' => $voitFinances,
            'reglements' => $voitFinances ? Reglement::with('anneeScolaire:id,libelle')
                ->where('eleve_id', $eleve->id)->orderByDesc('date_paiement')->get()
                ->map(fn (Reglement $r) => [
                    'id' => $r->id,
                    'date' => $r->date_paiement?->toDateTimeString(),
                    'annee' => $r->anneeScolaire?->libelle,
                    'numero_recu' => $r->numero_recu,
                    'montant' => (int) $r->montant_total,
                    'mode' => $r->mode_paiement,
                ])->values() : [],
            'dettes' => $voitFinances ? Dette::with('anneeScolaire:id,libelle')->where('eleve_id', $eleve->id)->get()
                ->map(fn (Dette $d) => [
                    'annee' => $d->anneeScolaire?->libelle,
                    'montant' => (int) $d->montant,
                    'reste' => (int) max(0, $d->resteAPayer()),
                    'annulee' => (bool) $d->is_annulee,
                ])->values() : [],
            'documents' => DocumentEleve::where('eleve_id', $eleve->id)->latest()->get()
                ->map(fn (DocumentEleve $d) => [
                    'id' => $d->id,
                    'type' => $d->type_document,
                    'url' => Storage::disk('public')->url($d->fichier_path),
                    'date' => $d->created_at?->toDateString(),
                ])->values(),
        ]]);
    }

    private function anneeId(TenantContext $tenant): ?int
    {
        return $tenant->anneeScolaireId() ?? AnneeScolaire::where('is_active', true)->value('id');
    }
}
