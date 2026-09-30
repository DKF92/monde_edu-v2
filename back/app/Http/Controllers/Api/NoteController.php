<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classe;
use App\Models\Moyenne;
use App\Models\Note;
use App\Models\Periode;
use App\Support\PorteePedagogique;
use App\Support\Resultats;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saisie des notes (V1 Note/new_note, Moyenne/enr_moy) :
 * - une evaluation = numero dans la periode, notee sur 10, 20 ou 40 ;
 * - notes.saisir : ses classes et matieres (professeur) ; moyennes.gerer : toutes ;
 * - les moyennes arretees par le directeur (Parametres > Arret des notes,
 *   droit notes.arreter) bloquent les notes (V1 arrete) ; une periode
 *   cloturee n'accepte plus de notes. Voir App\Support\ArretNotes.
 */
class NoteController extends Controller
{
    public const BAREMES = [10, 20, 40];

    /** Periodes, classes et matieres accessibles. */
    public function options(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $visibles = PorteePedagogique::classes($request, $anneeId);
        $affectations = PorteePedagogique::affectations($request, $anneeId);

        $classes = Classe::where('classes.annee_scolaire_id', $anneeId)
            ->when($visibles !== null, fn ($q) => $q->whereIn('classes.id', $visibles))
            ->with('niveau:id,libelle,ordre,cycle')->get()
            ->sortBy(fn (Classe $c) => [$c->niveau?->ordre, $c->libelle])->values();

        return response()->json([
            'periodes' => $this->periodes($anneeId),
            'baremes' => self::BAREMES,
            'peut_arreter' => $request->user()->can('notes.arreter'),
            'classes' => $classes->map(function (Classe $c) use ($request, $anneeId, $affectations) {
                $matieres = Resultats::matieres($c->niveau_id)->reject(fn ($m) => $m['conduite'])
                    ->when($affectations !== null, fn (Collection $ms) => $ms->filter(fn ($m) => collect($affectations)
                        ->contains(fn ($a) => $a['classe_id'] === $c->id && $a['matiere_id'] === $m['id'])));

                return [
                    'id' => $c->id,
                    'libelle' => $c->libelle,
                    'niveau' => $c->niveau?->libelle,
                    'matieres' => $matieres->map(fn ($m) => [
                        'id' => $m['id'], 'libelle' => $m['libelle'], 'coefficient' => $m['coefficient'],
                        'peut_saisir' => PorteePedagogique::peutSaisir($request, $anneeId, $c->id, $m['id']),
                    ])->values(),
                ];
            })->values(),
        ]);
    }

    /** Notes d'une classe dans une matiere pour une periode. */
    public function index(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        [$classe, $matiere, $periode, $anneeId] = $this->contexte($request, $tenant);

        return response()->json($this->presenter($request, $classe, $matiere, $periode, $anneeId));
    }

    /**
     * Enregistre une evaluation : {classe_id, matiere_id, periode_id,
     * numero (vide = nouvelle), bareme, date, notes: [{eleve_id, valeur|null}]}.
     */
    public function enregistrer(Request $request, TenantContext $tenant)
    {
        [$classe, $matiere, $periode, $anneeId] = $this->contexte($request, $tenant);
        $this->verifierSaisie($request, $classe, $matiere, $periode, $anneeId);

        $data = $request->validate([
            'numero' => ['nullable', 'integer', 'min:1', 'max:30'],
            'bareme' => ['required', 'integer', Rule::in(self::BAREMES)],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['present', 'array'],
            'notes.*.eleve_id' => ['required', 'integer'],
            'notes.*.valeur' => ['nullable', 'numeric', 'min:0'],
        ], [
            'bareme.in' => 'Une évaluation est notée sur 10, 20 ou 40.',
            'date.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ]);

        $eleves = Resultats::eleves($classe)->filter(fn ($e) => Resultats::concerne($e, $matiere))->keyBy('eleve_id');
        $erreurs = [];
        foreach ($data['notes'] as $i => $n) {
            if (! $eleves->has($n['eleve_id'])) {
                $erreurs["notes.$i.eleve_id"] = ['Cet élève n\'est pas noté dans cette matière.'];
            } elseif (($n['valeur'] ?? null) !== null && $n['valeur'] > $data['bareme']) {
                $erreurs["notes.$i.valeur"] = ['Note supérieure au barème ('.$data['bareme'].') : '.$eleves[$n['eleve_id']]['nom'].' '.$eleves[$n['eleve_id']]['prenoms'].'.'];
            }
        }
        if ($erreurs) {
            throw ValidationException::withMessages($erreurs);
        }
        if (! collect($data['notes'])->contains(fn ($n) => ($n['valeur'] ?? null) !== null)) {
            throw ValidationException::withMessages(['notes' => ['Saisissez au moins une note.']]);
        }

        $base = Note::where('classe_id', $classe->id)->where('matiere_id', $matiere['id'])->where('periode_id', $periode->id);
        $numero = $data['numero'] ?? ((int) (clone $base)->max('numero') + 1);
        $personnel = PorteePedagogique::personnel($request);

        DB::transaction(function () use ($data, $classe, $matiere, $periode, $numero, $personnel, $request) {
            foreach ($data['notes'] as $n) {
                $cle = ['eleve_id' => $n['eleve_id'], 'matiere_id' => $matiere['id'], 'periode_id' => $periode->id, 'numero' => $numero];
                if (($n['valeur'] ?? null) === null) {
                    Note::where($cle)->delete();

                    continue;
                }
                Note::updateOrCreate($cle, [
                    'classe_id' => $classe->id,
                    'bareme' => $data['bareme'],
                    'valeur' => round((float) $n['valeur'], 2),
                    'coefficient' => 1,
                    'date_evaluation' => $data['date'] ?? now()->toDateString(),
                    'personnel_id' => $personnel?->id,
                    'saisi_par_id' => $request->user()->id,
                ]);
            }
            // Bareme et date communs a toute l'evaluation.
            Note::where('classe_id', $classe->id)->where('matiere_id', $matiere['id'])->where('periode_id', $periode->id)->where('numero', $numero)
                ->update(['bareme' => $data['bareme'], 'date_evaluation' => $data['date'] ?? now()->toDateString()]);
        });

        return response()->json($this->presenter($request, $classe, $matiere, $periode, $anneeId) + ['numero' => $numero]);
    }

    public function supprimerEvaluation(Request $request, TenantContext $tenant)
    {
        [$classe, $matiere, $periode, $anneeId] = $this->contexte($request, $tenant);
        $this->verifierSaisie($request, $classe, $matiere, $periode, $anneeId);
        $numero = $request->integer('numero');
        $supprimees = Note::where('classe_id', $classe->id)->where('matiere_id', $matiere['id'])->where('periode_id', $periode->id)->where('numero', $numero)->delete();
        abort_if($supprimees === 0, 404, 'Évaluation introuvable.');

        return response()->json($this->presenter($request, $classe, $matiere, $periode, $anneeId));
    }

    // ------------------------------------------------------------ Outils

    private function presenter(Request $request, Classe $classe, array $matiere, Periode $periode, int $anneeId): array
    {
        $eleves = Resultats::eleves($classe)->filter(fn ($e) => Resultats::concerne($e, $matiere))->values();
        $enfants = PorteePedagogique::eleves($request);
        $notes = Note::where('classe_id', $classe->id)->where('matiere_id', $matiere['id'])->where('periode_id', $periode->id)->get();
        $arretees = Moyenne::where('matiere_id', $matiere['id'])->where('periode_id', $periode->id)->where('is_arretee', true)
            ->whereIn('eleve_id', $eleves->pluck('eleve_id'))->get()->keyBy('eleve_id');
        $evaluations = $notes->groupBy('numero')->map(fn (Collection $g, $numero) => [
            'numero' => (int) $numero,
            'bareme' => (int) $g->first()->bareme,
            'date' => $g->first()->date_evaluation?->toDateString(),
            'nombre' => $g->count(),
            'moyenne' => round($g->avg('valeur'), 2),
        ])->sortKeys()->values();

        $lignes = $eleves->map(function (array $e) use ($notes, $arretees) {
            $siennes = $notes->where('eleve_id', $e['eleve_id']);
            $arretee = $arretees->get($e['eleve_id']);

            return [
                'eleve_id' => $e['eleve_id'],
                'matricule' => $e['matricule'],
                'nom' => $e['nom'],
                'prenoms' => $e['prenoms'],
                'sexe' => $e['sexe'],
                'notes' => $siennes->mapWithKeys(fn ($n) => [$n->numero => (float) $n->valeur]),
                'moyenne' => $arretee ? (float) $arretee->moyenne : Resultats::moyenneNotes($siennes),
                'arretee' => (bool) $arretee,
            ];
        });
        $lignes = Resultats::classer($lignes, 'moyenne', 'rang')
            ->when($enfants !== null, fn (Collection $l) => $l->whereIn('eleve_id', $enfants))->values();
        $arretee = $arretees->isNotEmpty();

        return [
            'classe' => ['id' => $classe->id, 'libelle' => $classe->libelle],
            'matiere' => ['id' => $matiere['id'], 'libelle' => $matiere['libelle'], 'coefficient' => $matiere['coefficient'], 'langue' => $matiere['langue']],
            'periode' => ['id' => $periode->id, 'libelle' => $periode->libelle, 'cloturee' => (bool) $periode->is_cloturee],
            'evaluations' => $evaluations,
            'eleves' => $lignes->map(fn ($l) => $l + ['appreciation' => Resultats::appreciation($l['moyenne'])])->all(),
            'arretee' => $arretee,
            'moyenne_classe' => $lignes->whereNotNull('moyenne')->count() ? round($lignes->whereNotNull('moyenne')->avg('moyenne'), 2) : null,
            'peut_saisir' => ! $arretee && ! $periode->is_cloturee && PorteePedagogique::peutSaisir($request, $anneeId, $classe->id, $matiere['id']),
            'peut_arreter' => $request->user()->can('notes.arreter') && ! $periode->is_cloturee,
        ];
    }

    /** @return array{0: Classe, 1: array, 2: Periode, 3: int} */
    private function contexte(Request $request, TenantContext $tenant): array
    {
        $anneeId = PorteePedagogique::anneeId($tenant);
        $request->validate([
            'classe_id' => ['required', 'integer'],
            'matiere_id' => ['required', 'integer'],
            'periode_id' => ['required', 'integer'],
        ]);
        $classe = Classe::where('annee_scolaire_id', $anneeId)->findOrFail($request->integer('classe_id'));
        PorteePedagogique::verifierClasse($request, $anneeId, $classe->id);
        $matiere = Resultats::matieres($classe->niveau_id)->firstWhere('id', $request->integer('matiere_id'));
        abort_unless($matiere && ! $matiere['conduite'], 422, 'Cette matière n\'est pas enseignée dans cette classe (Paramètres > Matières).');
        $periode = Periode::where('annee_scolaire_id', $anneeId)->findOrFail($request->integer('periode_id'));

        return [$classe, $matiere, $periode, $anneeId];
    }

    private function verifierSaisie(Request $request, Classe $classe, array $matiere, Periode $periode, int $anneeId): void
    {
        abort_unless(PorteePedagogique::peutSaisir($request, $anneeId, $classe->id, $matiere['id']), 403, 'Vous ne pouvez pas saisir les notes de cette matière dans cette classe.');
        abort_if($periode->is_cloturee, 422, 'La période '.$periode->libelle.' est clôturée.');
        abort_if(Moyenne::where('matiere_id', $matiere['id'])->where('periode_id', $periode->id)->where('is_arretee', true)
            ->whereIn('eleve_id', Resultats::eleves($classe)->pluck('eleve_id'))->exists(),
            422, 'Les moyennes de '.$matiere['libelle'].' sont arrêtées pour cette période : faites-les rouvrir pour modifier les notes.');
    }

    private function periodes(int $anneeId): array
    {
        return Periode::where('annee_scolaire_id', $anneeId)->orderBy('numero')->get()
            ->map(fn (Periode $p) => ['id' => $p->id, 'libelle' => $p->libelle, 'numero' => $p->numero, 'active' => (bool) $p->is_active, 'cloturee' => (bool) $p->is_cloturee])
            ->all();
    }

    private function autoriser(Request $request): void
    {
        $u = $request->user();
        abort_unless($u->can('notes.saisir') || $u->can('notes.voir') || $u->can('notes.arreter'), 403);
    }
}
