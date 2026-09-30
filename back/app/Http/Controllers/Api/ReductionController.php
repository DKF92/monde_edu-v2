<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Dette;
use App\Models\Eleve;
use App\Models\Etablissement;
use App\Models\FraisEleve;
use App\Models\Inscription;
use App\Models\TypeReduction;
use App\Support\BilanCaisse;
use App\Support\Document;
use App\Support\FicheEleve;
use App\Support\PorteePedagogique;
use App\Support\Reductions;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu Reductions (droit reductions.gerer) : liste des reductions de l'annee
 * (filtres, impression, impression generale), recherche d'un eleve par
 * matricule puis formulaire de reduction d'inscription ou de dette, suppression.
 */
class ReductionController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        [$annee, $f] = $this->filtres($request, $tenant);
        $toutes = Reductions::requete($annee->id, $f)->get()->map(fn ($l) => Reductions::presenter($l));
        $triees = $toutes->sortByDesc(fn ($l) => [$l['date'], $l['lot']])->values();

        return response()->json([
            'data' => $triees,
            'annee' => $annee->libelle,
            'totaux' => [
                'nombre' => $toutes->count(),
                'eleves' => $toutes->unique('eleve_id')->count(),
                'montant' => (int) $toutes->sum('montant'),
                'inscription' => (int) $toutes->where('cible', 'inscription')->sum('montant'),
                'dette' => (int) $toutes->where('cible', 'dette')->sum('montant'),
                'cas' => (int) $toutes->where('code', 'CAS')->sum('montant'),
            ],
            'types' => TypeReduction::orderBy('id')->get(['id', 'libelle', 'code', 'is_active']),
            'criteres_libelle' => BilanCaisse::libelleCriteres($f['criteres']),
            'criteres_disponibles' => BilanCaisse::criteresDisponibles($annee->id),
        ]);
    }

    /** Liste imprimable (general=1 : point par type, genre, niveau, classe, donneur d'ordre). */
    public function document(Request $request, TenantContext $tenant)
    {
        [$annee, $f] = $this->filtres($request, $tenant);
        $lignes = Reductions::requete($annee->id, $f)->get()->map(fn ($l) => Reductions::presenter($l))
            ->sortBy(fn ($l) => [$l['niveau_ordre'], $l['classe'], $l['nom'], $l['prenoms']])->values();
        $general = $request->boolean('general');
        $sousTitre = collect([
            ($f['cible'] ?? null) ? Reductions::CIBLES[$f['cible']] : null,
            ($f['type_reduction_id'] ?? null) ? TypeReduction::find($f['type_reduction_id'])?->libelle : null,
            ($f['du'] ?? null) ? 'du '.date('d/m/Y', strtotime($f['du'])).' au '.date('d/m/Y', strtotime($f['au'] ?? $f['du'])) : null,
            $f['affecte'] === null ? null : ($f['affecte'] ? 'affectés' : 'non affectés'),
            BilanCaisse::libelleCriteres($f['criteres']) ?: null,
            trim((string) $f['recherche']) !== '' ? 'recherche « '.trim($f['recherche']).' »' : null,
        ])->filter()->implode(' · ');
        $entete = Document::entete(Etablissement::findOrFail($tenant->id()));
        $editePar = trim($request->user()->name.' '.$request->user()->prenoms);

        if ($general) {
            $p = [
                'titre' => 'Point des réductions '.$annee->libelle.($sousTitre ? ' · '.$sousTitre : ''),
                'annee' => $annee->libelle,
                'genere_le' => now()->toDateTimeString(),
                'unite' => 'montant',
                'colonnes' => ['affecte' => 'Élèves affectés', 'non_affecte' => 'Élèves non affectés', 'total' => 'Total'],
                'sections' => Reductions::sections($lignes, true),
            ];

            return Document::repondre('pdf.point', ['p' => $p, 'titreDocument' => 'Point général des réductions', 'edite_par' => $editePar] + $entete,
                'point-reductions', $request->query('format'));
        }

        return Document::repondre('pdf.reductions', $entete + [
            'annee' => $annee->libelle,
            'titre' => 'Liste des réductions '.$annee->libelle,
            'sous_titre' => $sousTitre,
            'lignes' => $lignes,
            'total' => (int) $lignes->sum('montant'),
            'genere_le' => now(),
            'edite_par' => $editePar,
        ], 'liste-reductions', $request->query('format'), 'landscape');
    }

    /**
     * Fenetre "matricule" : verifie l'eleve (V1 action verify) puis renvoie ce
     * qu'il faut au formulaire : frais, types et bornes (inscription) ou dettes.
     */
    public function eleve(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        $data = $request->validate(['matricule' => ['required', 'string', 'max:30'], 'cible' => ['required', Rule::in(array_keys(Reductions::CIBLES))]]);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $eleve = Eleve::where('matricule', strtoupper(trim($data['matricule'])))->first();
        abort_unless($eleve, 404, 'Aucun élève avec ce matricule.');
        $inscription = Inscription::with(['classe:id,libelle', 'niveau:id,libelle', 'fraisEleves.typeFrais'])
            ->where('annee_scolaire_id', $anneeId)->where('eleve_id', $eleve->id)->first();
        abort_unless($inscription, 422, $eleve->nom.' '.$eleve->prenoms.' n\'est pas inscrit(e) cette année.');

        $base = [
            'eleve' => FicheEleve::presenter($eleve),
            'inscription' => [
                'id' => $inscription->id,
                'classe' => $inscription->classe?->libelle,
                'niveau' => $inscription->niveau?->libelle,
                'affecte' => (bool) $inscription->affecte,
                'date' => $inscription->date_inscription?->toDateString(),
                'total_du' => (int) $inscription->montant_total_du,
                'total_reduit' => (int) $inscription->montant_total_reduit,
                'total_paye' => (int) $inscription->montant_total_paye,
                'reste' => (int) max(0, $inscription->resteAPayer()),
            ],
        ];

        if ($data['cible'] === 'dette') {
            $dettes = Dette::with('anneeScolaire')->where('eleve_id', $eleve->id)->where('is_annulee', false)->get()
                ->sortBy(fn (Dette $d) => $d->anneeScolaire?->libelle)->values();
            abort_if($dettes->isEmpty(), 422, 'Cet élève n\'a pas de dette.');
            abort_if($dettes->every(fn (Dette $d) => $d->resteAPayer() <= 0), 422, 'Cet élève a épuré sa dette.');

            return response()->json($base + ['dettes' => $dettes->map(fn (Dette $d) => [
                'id' => $d->id,
                'annee' => $d->anneeScolaire?->libelle,
                'montant' => (int) $d->montant,
                'reduit' => (int) $d->montant_reduit,
                'paye' => (int) $d->montant_paye,
                'reste' => (int) max(0, $d->resteAPayer()),
            ])->values()]);
        }

        abort_unless($inscription->classe_id, 422, 'Choisissez d\'abord la classe de l\'élève : ses frais ne sont pas encore fixés.');
        $types = TypeReduction::where('is_active', true)->orderBy('id')->get();
        abort_if($types->isEmpty(), 422, 'Aucun type de réduction actif (Paramètres › Paiements › Réductions).');

        return response()->json($base + [
            'frais' => $inscription->fraisEleves->whereNull('quantite_due')->sortBy(fn (FraisEleve $f) => [$f->typeFrais->ordre, $f->id])
                ->map(fn (FraisEleve $f) => [
                    'id' => $f->id,
                    'libelle' => $f->typeFrais->libelle,
                    'principal' => in_array($f->typeFrais->nature, ['inscription', 'scolarite'], true),
                    'montant_du' => (int) $f->montant_du,
                    'montant_reduit' => (int) $f->montant_reduit,
                    'montant_paye' => (int) $f->montant_paye,
                    'reste' => (int) max(0, $f->resteAPayer()),
                ])->values(),
            'types' => $types->map(fn (TypeReduction $t) => [
                'id' => $t->id,
                'libelle' => $t->libelle,
                'code' => $t->code,
                'base' => $t->base,
                'bornes' => $t->bornes($inscription),
                // Apercu : frais reduits pour le montant maximum.
                'ordre' => collect(Reductions::repartition($inscription, $t, PHP_INT_MAX))->map(fn ($l) => $l['frais']->id)->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->autoriser($request);
        $data = $request->validate([
            'inscription_id' => ['required', 'integer'],
            'type_reduction_id' => ['required', 'integer'],
            'montant' => ['required', 'integer', 'min:1'],
            'motif' => ['required', 'string', 'max:200'],
        ], ['motif.required' => 'Indiquez qui accorde la réduction (ex. : Fondateur).', 'montant.min' => 'Saisissez le montant de la réduction.']);
        $inscription = Inscription::findOrFail($data['inscription_id']);
        $type = TypeReduction::where('is_active', true)->findOrFail($data['type_reduction_id']);
        $lot = Reductions::accorderInscription($inscription, $type, $data['montant'], trim($data['motif']), $request->user()->id);

        return response()->json(['lot' => $lot, 'message' => 'Réduction enregistrée : '.$type->libelle.' de '.number_format($data['montant'], 0, ',', ' ').' F.'], 201);
    }

    public function storeDette(Request $request)
    {
        $this->autoriser($request);
        $data = $request->validate([
            'dette_id' => ['required', 'integer'],
            'montant' => ['nullable', 'integer', 'min:0'],
            'annuler' => ['boolean'],
            'motif' => ['required', 'string', 'max:200'],
        ], ['motif.required' => 'Indiquez qui accorde la réduction (ex. : Fondateur).']);
        $dette = Dette::findOrFail($data['dette_id']);
        $annuler = (bool) ($data['annuler'] ?? false);
        $lot = Reductions::accorderDette($dette, (int) ($data['montant'] ?? 0), $annuler, trim($data['motif']), $request->user()->id);

        return response()->json(['lot' => $lot, 'message' => $annuler ? 'Dette annulée.' : 'Réduction de dette enregistrée.'], 201);
    }

    public function destroy(Request $request, string $lot)
    {
        $this->autoriser($request);
        Reductions::supprimer($lot);

        return response()->json(['message' => 'Réduction supprimée : les montants dus sont rétablis.']);
    }

    // ------------------------------------------------------------ Outils

    /** @return array{0: AnneeScolaire, 1: array} */
    private function filtres(Request $request, TenantContext $tenant): array
    {
        $this->autoriser($request);
        $data = $request->validate([
            'cible' => ['nullable', Rule::in(array_keys(Reductions::CIBLES))],
            'type_reduction_id' => ['nullable', 'integer'],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'affecte' => ['nullable', 'boolean'],
            'recherche' => ['nullable', 'string', 'max:100'],
            'sexe' => ['nullable', Rule::in(['M', 'F'])],
            'redoublant' => ['nullable', 'boolean'],
            'cycle' => ['nullable', Rule::in(array_keys(BilanCaisse::CYCLES))],
            'niveau_id' => ['nullable', 'integer'],
            'classe_id' => ['nullable', 'integer'],
        ]);
        $annee = AnneeScolaire::find(PorteePedagogique::anneeId($tenant));
        abort_unless($annee, 422, 'Aucune année scolaire active.');

        return [$annee, [
            'cible' => $data['cible'] ?? null,
            'type_reduction_id' => $data['type_reduction_id'] ?? null,
            'du' => $data['du'] ?? null,
            'au' => $data['au'] ?? ($data['du'] ?? null),
            'affecte' => $request->filled('affecte') ? $request->boolean('affecte') : null,
            'recherche' => $data['recherche'] ?? '',
            'criteres' => BilanCaisse::criteres($data),
        ]];
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('reductions.gerer'), 403);
    }
}
