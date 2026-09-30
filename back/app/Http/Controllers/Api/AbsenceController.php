<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\Classe;
use App\Models\Etablissement;
use App\Models\Inscription;
use App\Models\Matiere;
use App\Models\Moyenne;
use App\Models\Periode;
use App\Support\Document;
use App\Support\PorteePedagogique;
use App\Support\Resultats;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Absences des eleves (V1 EDUCATEUR enregistrer_abs / consult_abs /
 * just_abs, PROFESSEUR appel) et notes de conduite (V1 enregistrer_cond),
 * droit absences.gerer ; l'educateur ne voit que les classes de ses niveaux.
 */
class AbsenceController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $requete = $this->requete($request, $anneeId);

        $totaux = (clone $requete)->reorder()->selectRaw('COUNT(*) AS nombre, COALESCE(SUM(nombre_heures), 0) AS heures,
            COALESCE(SUM(CASE WHEN is_justifiee THEN nombre_heures ELSE 0 END), 0) AS justifiees, COUNT(DISTINCT eleve_id) AS eleves')->first();
        $page = $requete->with(['eleve:id,matricule,nom,prenoms,sexe', 'classe:id,libelle', 'periode:id,libelle', 'saisiPar:id,name,prenoms'])
            ->orderByDesc('date_debut')->orderByDesc('id')
            ->paginate(min(max($request->integer('par_page', 50), 1), 200));

        return response()->json([
            'data' => collect($page->items())->map(fn (Absence $a) => $this->presenter($a)),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'par_page' => $page->perPage(),
            'totaux' => [
                'nombre' => (int) $totaux->nombre,
                'heures' => (float) $totaux->heures,
                'justifiees' => (float) $totaux->justifiees,
                'non_justifiees' => (float) $totaux->heures - (float) $totaux->justifiees,
                'eleves' => (int) $totaux->eleves,
            ],
        ] + $this->options($request, $anneeId));
    }

    public function document(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $absences = $this->requete($request, $anneeId)->with(['eleve:id,matricule,nom,prenoms,sexe', 'classe:id,libelle', 'periode:id,libelle'])
            ->orderBy('date_debut')->get()->map(fn (Absence $a) => $this->presenter($a));
        $periode = $request->integer('periode_id') ? Periode::find($request->integer('periode_id')) : null;
        $classe = $request->integer('classe_id') ? Classe::find($request->integer('classe_id')) : null;

        return Document::repondre('pdf.absences', Document::entete(Etablissement::findOrFail($tenant->id())) + [
            'annee' => DB::table('annees_scolaires')->where('id', $anneeId)->value('libelle'),
            'absences' => $absences,
            // Recapitulatif par eleve (V1 : heures d'absence du trimestre).
            'par_eleve' => $absences->groupBy('eleve.id')->map(fn ($g) => [
                'eleve' => $g->first()['eleve'], 'classe' => $g->first()['classe'],
                'heures' => $g->sum('nombre_heures'), 'justifiees' => $g->where('is_justifiee', true)->sum('nombre_heures'),
            ])->sortBy(fn ($r) => [$r['classe'], $r['eleve']['nom'], $r['eleve']['prenoms']])->values(),
            'sous_titre' => collect([$periode?->libelle, $classe?->libelle,
                $request->filled('justifiee') ? ($request->boolean('justifiee') ? 'justifiées' : 'non justifiées') : null])->filter()->implode(' · '),
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], 'absences', $request->query('format'), 'landscape');
    }

    /** Eleves d'une classe et leurs heures d'absence de la periode (appel). */
    public function classe(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $classe = $this->classeVisible($request, $anneeId, $id);
        $heures = Absence::where('classe_id', $classe->id)->when($request->integer('periode_id'), fn ($q, $p) => $q->where('periode_id', $p))
            ->selectRaw('eleve_id, SUM(nombre_heures) AS heures, SUM(CASE WHEN is_justifiee THEN 0 ELSE nombre_heures END) AS non_justifiees')
            ->groupBy('eleve_id')->get()->keyBy('eleve_id');

        return response()->json([
            'classe' => ['id' => $classe->id, 'libelle' => $classe->libelle],
            'eleves' => Resultats::eleves($classe)->map(fn ($e) => [
                'eleve_id' => $e['eleve_id'], 'matricule' => $e['matricule'], 'nom' => $e['nom'], 'prenoms' => $e['prenoms'], 'sexe' => $e['sexe'],
                'heures' => (float) ($heures[$e['eleve_id']]->heures ?? 0),
                'non_justifiees' => (float) ($heures[$e['eleve_id']]->non_justifiees ?? 0),
            ])->values(),
        ]);
    }

    /**
     * Enregistre des absences (appel) : {periode_id, date_debut, date_fin?,
     * nombre_heures, is_justifiee, motif?, eleve_ids: []}.
     */
    public function store(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $data = $this->valider($request, $anneeId, true);

        $inscriptions = Inscription::where('annee_scolaire_id', $anneeId)->whereIn('eleve_id', $data['eleve_ids'])->whereNotNull('classe_id')->get()->keyBy('eleve_id');
        $visibles = PorteePedagogique::classes($request, $anneeId);
        foreach ($data['eleve_ids'] as $eleve) {
            $i = $inscriptions->get($eleve);
            if (! $i || ($visibles !== null && ! in_array((int) $i->classe_id, $visibles, true))) {
                throw ValidationException::withMessages(['eleve_ids' => ['Un des élèves n\'est pas dans vos classes.']]);
            }
        }

        $ids = DB::transaction(fn () => collect($data['eleve_ids'])->map(fn ($eleve) => Absence::create([
            'annee_scolaire_id' => $anneeId,
            'periode_id' => $data['periode_id'],
            'eleve_id' => $eleve,
            'classe_id' => $inscriptions[$eleve]->classe_id,
            'date_debut' => $data['date_debut'],
            'date_fin' => $data['date_fin'] ?? null,
            'nombre_heures' => $data['nombre_heures'],
            'is_justifiee' => $data['is_justifiee'] ?? false,
            'motif' => $data['motif'] ?? null,
            'saisi_par_id' => $request->user()->id,
        ])->id)->all());

        return response()->json(['message' => count($ids).' absence'.(count($ids) > 1 ? 's enregistrées' : ' enregistrée').'.', 'ids' => $ids], 201);
    }

    public function update(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $absence = $this->trouver($request, $anneeId, $id);
        $data = $this->valider($request, $anneeId, false);
        $absence->update([
            'periode_id' => $data['periode_id'],
            'date_debut' => $data['date_debut'],
            'date_fin' => $data['date_fin'] ?? null,
            'nombre_heures' => $data['nombre_heures'],
            'is_justifiee' => $data['is_justifiee'] ?? false,
            'motif' => $data['motif'] ?? null,
        ]);

        return response()->json($this->presenter($absence->fresh(['eleve', 'classe', 'periode', 'saisiPar'])));
    }

    /** Justifier (ou non) une absence (V1 just_abs). */
    public function justifier(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);
        $absence = $this->trouver($request, PorteePedagogique::anneeId($tenant), $id);
        $data = $request->validate(['is_justifiee' => ['required', 'boolean'], 'motif' => ['nullable', 'string', 'max:200']]);
        $absence->update(['is_justifiee' => $data['is_justifiee'], 'motif' => $data['motif'] ?? $absence->motif]);

        return response()->json($this->presenter($absence->fresh(['eleve', 'classe', 'periode', 'saisiPar'])));
    }

    public function destroy(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);
        $this->trouver($request, PorteePedagogique::anneeId($tenant), $id)->delete();

        return response()->json(['message' => 'Absence supprimée.']);
    }

    // ------------------------------------------------------------ Conduite

    /** Notes de conduite /20 d'une classe pour une periode (V1 enregistrer_cond). */
    public function conduite(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        [$classe, $periode, $matiere] = $this->contexteConduite($request, $tenant);
        $notes = Moyenne::where('matiere_id', $matiere->id)->where('periode_id', $periode->id)->get()->keyBy('eleve_id');
        $heures = Absence::where('classe_id', $classe->id)->where('periode_id', $periode->id)
            ->selectRaw('eleve_id, SUM(CASE WHEN is_justifiee THEN 0 ELSE nombre_heures END) AS non_justifiees')->groupBy('eleve_id')->pluck('non_justifiees', 'eleve_id');

        return response()->json([
            'classe' => ['id' => $classe->id, 'libelle' => $classe->libelle],
            'periode' => ['id' => $periode->id, 'libelle' => $periode->libelle, 'cloturee' => (bool) $periode->is_cloturee],
            'enseignee' => Resultats::matieres($classe->niveau_id)->contains('conduite', true),
            'eleves' => Resultats::eleves($classe)->map(fn ($e) => [
                'eleve_id' => $e['eleve_id'], 'matricule' => $e['matricule'], 'nom' => $e['nom'], 'prenoms' => $e['prenoms'], 'sexe' => $e['sexe'],
                'note' => isset($notes[$e['eleve_id']]) ? (float) $notes[$e['eleve_id']]->moyenne : null,
                'heures_non_justifiees' => (float) ($heures[$e['eleve_id']] ?? 0),
            ])->values(),
        ]);
    }

    /** {classe_id, periode_id, notes: [{eleve_id, valeur|null}]} */
    public function enregistrerConduite(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        [$classe, $periode, $matiere] = $this->contexteConduite($request, $tenant);
        abort_if($periode->is_cloturee, 422, 'La période '.$periode->libelle.' est clôturée.');
        $data = $request->validate([
            'notes' => ['present', 'array'],
            'notes.*.eleve_id' => ['required', 'integer'],
            'notes.*.valeur' => ['nullable', 'numeric', 'min:0', 'max:20'],
        ], ['notes.*.valeur.max' => 'La note de conduite est sur 20.']);
        $eleves = Resultats::eleves($classe)->pluck('eleve_id')->all();

        DB::transaction(function () use ($data, $eleves, $matiere, $periode, $classe, $request) {
            foreach ($data['notes'] as $n) {
                abort_unless(in_array((int) $n['eleve_id'], $eleves, true), 422, 'Un des élèves n\'est pas dans la classe.');
                $cle = ['eleve_id' => $n['eleve_id'], 'matiere_id' => $matiere->id, 'periode_id' => $periode->id];
                if (($n['valeur'] ?? null) === null) {
                    Moyenne::where($cle)->delete();

                    continue;
                }
                Moyenne::updateOrCreate($cle, [
                    'classe_id' => $classe->id, 'moyenne' => round((float) $n['valeur'], 2), 'coefficient' => 1,
                    'appreciation' => Resultats::appreciation((float) $n['valeur']), 'is_arretee' => false, 'saisi_par_id' => $request->user()->id,
                ]);
            }
        });

        return $this->conduite($request, $tenant);
    }

    // ------------------------------------------------------------ Outils

    private function requete(Request $request, int $anneeId): Builder
    {
        $visibles = PorteePedagogique::classes($request, $anneeId);
        $recherche = trim((string) $request->query('recherche', ''));

        return Absence::where('absences.annee_scolaire_id', $anneeId)->whereNotNull('eleve_id')
            ->when($visibles !== null, fn (Builder $q) => $q->whereIn('classe_id', $visibles ?: [0]))
            ->when($request->integer('periode_id'), fn (Builder $q, $p) => $q->where('periode_id', $p))
            ->when($request->integer('classe_id'), fn (Builder $q, $c) => $q->where('classe_id', $c))
            ->when($request->filled('justifiee'), fn (Builder $q) => $q->where('is_justifiee', $request->boolean('justifiee')))
            ->when($request->filled('du'), fn (Builder $q) => $q->whereDate('date_debut', '>=', $request->query('du')))
            ->when($request->filled('au'), fn (Builder $q) => $q->whereDate('date_debut', '<=', $request->query('au')))
            ->when($recherche !== '', fn (Builder $q) => $q->whereHas('eleve', fn (Builder $e) => $e
                ->where('matricule', 'like', "{$recherche}%")->orWhere('nom', 'like', "%{$recherche}%")
                ->orWhere('prenoms', 'like', "%{$recherche}%")->orWhereRaw("CONCAT(nom, ' ', prenoms) LIKE ?", ["%{$recherche}%"])));
    }

    private function options(Request $request, int $anneeId): array
    {
        $visibles = PorteePedagogique::classes($request, $anneeId);

        return [
            'periodes' => Periode::where('annee_scolaire_id', $anneeId)->orderBy('numero')->get()
                ->map(fn (Periode $p) => ['id' => $p->id, 'libelle' => $p->libelle, 'active' => (bool) $p->is_active, 'cloturee' => (bool) $p->is_cloturee]),
            'classes' => Classe::where('classes.annee_scolaire_id', $anneeId)->when($visibles !== null, fn ($q) => $q->whereIn('classes.id', $visibles ?: [0]))
                ->join('niveaux', 'niveaux.id', '=', 'classes.niveau_id')->orderBy('niveaux.ordre')->orderBy('classes.libelle')
                ->get(['classes.id', 'classes.libelle']),
        ];
    }

    private function presenter(Absence $a): array
    {
        return [
            'id' => $a->id,
            'eleve' => $a->eleve ? ['id' => $a->eleve->id, 'matricule' => $a->eleve->matricule, 'nom' => $a->eleve->nom, 'prenoms' => $a->eleve->prenoms, 'sexe' => $a->eleve->sexe] : null,
            'classe' => $a->classe?->libelle,
            'classe_id' => $a->classe_id,
            'periode' => $a->periode?->libelle,
            'periode_id' => $a->periode_id,
            'date_debut' => $a->date_debut?->toDateString(),
            'date_fin' => $a->date_fin?->toDateString(),
            'nombre_heures' => (float) $a->nombre_heures,
            'is_justifiee' => (bool) $a->is_justifiee,
            'motif' => $a->motif,
            'saisi_par' => $a->saisiPar ? trim($a->saisiPar->name.' '.$a->saisiPar->prenoms) : null,
        ];
    }

    private function valider(Request $request, int $anneeId, bool $creation): array
    {
        return $request->validate([
            'periode_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('periodes', 'id')->where('annee_scolaire_id', $anneeId)],
            'date_debut' => ['required', 'date', 'before_or_equal:today'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut', 'before_or_equal:today'],
            'nombre_heures' => ['required', 'numeric', 'min:0.5', 'max:200'],
            'is_justifiee' => ['sometimes', 'boolean'],
            'motif' => ['nullable', 'string', 'max:200'],
            'eleve_ids' => $creation ? ['required', 'array', 'min:1'] : ['prohibited'],
            'eleve_ids.*' => ['integer'],
        ], [
            'eleve_ids.required' => 'Cochez au moins un élève absent.',
            'eleve_ids.min' => 'Cochez au moins un élève absent.',
            'date_debut.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'date_fin.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'date_fin.after_or_equal' => 'La date de fin suit la date de début.',
            'nombre_heures.min' => 'Au moins une demi-heure.',
        ]);
    }

    private function trouver(Request $request, int $anneeId, int $id): Absence
    {
        $absence = Absence::where('annee_scolaire_id', $anneeId)->findOrFail($id);
        $visibles = PorteePedagogique::classes($request, $anneeId);
        abort_if($visibles !== null && ! in_array((int) $absence->classe_id, $visibles, true), 403);

        return $absence;
    }

    private function classeVisible(Request $request, int $anneeId, int $id): Classe
    {
        $classe = Classe::where('annee_scolaire_id', $anneeId)->findOrFail($id);
        PorteePedagogique::verifierClasse($request, $anneeId, $classe->id);

        return $classe;
    }

    /** @return array{0: Classe, 1: Periode, 2: Matiere} */
    private function contexteConduite(Request $request, TenantContext $tenant): array
    {
        $anneeId = PorteePedagogique::anneeId($tenant);
        $request->validate(['classe_id' => ['required', 'integer'], 'periode_id' => ['required', 'integer']]);
        $classe = $this->classeVisible($request, $anneeId, $request->integer('classe_id'));
        $periode = Periode::where('annee_scolaire_id', $anneeId)->findOrFail($request->integer('periode_id'));
        $matiere = Matiere::where('code', Resultats::CONDUITE)->firstOrFail();

        return [$classe, $periode, $matiere];
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('absences.gerer'), 403);
    }
}
