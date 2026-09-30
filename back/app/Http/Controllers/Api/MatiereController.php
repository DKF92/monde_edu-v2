<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AffectationEnseignant;
use App\Models\Classe;
use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\Personnel;
use App\Support\PorteePedagogique;
use App\Support\Resultats;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Matieres et coefficients par niveau (V1 matiere / aff_coef, droit
 * matieres.gerer), et professeurs de chaque classe (V1 enseigner /
 * ajout_prof_classe, droits matieres.gerer ou classes.gerer).
 */
class MatiereController extends Controller
{
    public const GROUPES = ['LITTERAIRE' => 'Lettres', 'SCIENTIFIQUE' => 'Sciences', 'AUTRES' => 'Autres'];

    public function index(Request $request)
    {
        abort_unless($request->user()->can('matieres.gerer'), 403);

        return response()->json($this->donnees());
    }

    public function store(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('matieres.gerer'), 403);
        $data = $this->valider($request, $tenant);
        Matiere::create($data + ['coefficient_defaut' => 1]);

        return response()->json($this->donnees(), 201);
    }

    public function update(Request $request, TenantContext $tenant, int $id)
    {
        abort_unless($request->user()->can('matieres.gerer'), 403);
        $matiere = Matiere::findOrFail($id);
        abort_if($matiere->code === Resultats::CONDUITE, 422, 'La conduite est une matière réservée.');
        $matiere->update($this->valider($request, $tenant, $matiere));

        return response()->json($this->donnees());
    }

    public function destroy(Request $request, int $id)
    {
        abort_unless($request->user()->can('matieres.gerer'), 403);
        $matiere = Matiere::findOrFail($id);
        abort_if($matiere->code === Resultats::CONDUITE, 422, 'La conduite est une matière réservée.');
        abort_if(DB::table('notes')->where('matiere_id', $matiere->id)->exists() || DB::table('moyennes')->where('matiere_id', $matiere->id)->exists(),
            422, 'Des notes sont enregistrées en '.$matiere->libelle.' : retirez-la plutôt des niveaux (coefficient vide).');
        $matiere->delete();

        return response()->json($this->donnees());
    }

    /**
     * Coefficients : {coefficients: [{matiere_id, niveau_id, coefficient|null, obligatoire}]}
     * (null = matiere non enseignee dans le niveau).
     */
    public function coefficients(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('matieres.gerer'), 403);
        $data = $request->validate([
            'coefficients' => ['present', 'array'],
            'coefficients.*.matiere_id' => ['required', 'integer', Rule::exists('matieres', 'id')->where('etablissement_id', $tenant->id())],
            'coefficients.*.niveau_id' => ['required', 'integer', Rule::exists('niveaux', 'id')],
            'coefficients.*.coefficient' => ['nullable', 'integer', 'min:1', 'max:20'],
            'coefficients.*.obligatoire' => ['sometimes', 'boolean'],
        ], [
            'coefficients.*.coefficient.min' => 'Un coefficient vaut au moins 1 (laissez vide si la matière n\'est pas enseignée).',
            'coefficients.*.coefficient.max' => 'Un coefficient vaut au plus 20.',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['coefficients'] as $c) {
                $cle = ['matiere_id' => $c['matiere_id'], 'niveau_id' => $c['niveau_id']];
                if ($c['coefficient'] ?? null) {
                    DB::table('matiere_niveau')->updateOrInsert($cle, [
                        'coefficient' => $c['coefficient'],
                        'is_obligatoire' => $c['obligatoire'] ?? true,
                        'updated_at' => now(), 'created_at' => now(),
                    ]);
                } else {
                    DB::table('matiere_niveau')->where($cle)->delete();
                }
            }
        });

        return response()->json($this->donnees());
    }

    // ------------------------------------------------------------ Professeurs d'une classe

    /** Matieres du niveau de la classe et leur professeur (V1 liste_prof_classe). */
    public function enseignants(Request $request, TenantContext $tenant, int $id)
    {
        $user = $request->user();
        abort_unless($user->can('classes.voir') || $user->can('classes.gerer') || $user->can('matieres.gerer'), 403);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $classe = Classe::where('annee_scolaire_id', $anneeId)->findOrFail($id);

        return response()->json($this->presenterEnseignants($classe, $anneeId));
    }

    /** {enseignants: [{matiere_id, personnel_id|null}]} */
    public function enregistrerEnseignants(Request $request, TenantContext $tenant, int $id)
    {
        abort_unless($request->user()->can('classes.gerer') || $request->user()->can('matieres.gerer'), 403);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $classe = Classe::where('annee_scolaire_id', $anneeId)->findOrFail($id);
        $data = $request->validate([
            'enseignants' => ['present', 'array'],
            'enseignants.*.matiere_id' => ['required', 'integer', Rule::exists('matieres', 'id')->where('etablissement_id', $tenant->id())],
            'enseignants.*.personnel_id' => ['nullable', 'integer', Rule::exists('personnels', 'id')->where('etablissement_id', $tenant->id())],
        ]);

        DB::transaction(function () use ($data, $classe, $anneeId) {
            foreach ($data['enseignants'] as $e) {
                AffectationEnseignant::where('annee_scolaire_id', $anneeId)->where('classe_id', $classe->id)->where('matiere_id', $e['matiere_id'])->delete();
                if ($e['personnel_id'] ?? null) {
                    AffectationEnseignant::create([
                        'annee_scolaire_id' => $anneeId, 'classe_id' => $classe->id,
                        'matiere_id' => $e['matiere_id'], 'personnel_id' => $e['personnel_id'],
                    ]);
                }
            }
        });

        return response()->json($this->presenterEnseignants($classe, $anneeId));
    }

    // ------------------------------------------------------------ Outils

    private function donnees(): array
    {
        $matieres = Matiere::orderBy('libelle')->get();
        $coefficients = DB::table('matiere_niveau')->whereIn('matiere_id', $matieres->pluck('id'))->get();

        return [
            'groupes' => collect(self::GROUPES)->map(fn ($libelle, $valeur) => ['valeur' => $valeur, 'libelle' => $libelle])->values(),
            'niveaux' => Niveau::orderBy('ordre')->get(['id', 'code', 'libelle', 'cycle']),
            'matieres' => $matieres->map(fn (Matiere $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'libelle' => $m->libelle,
                'groupe' => $m->groupe_bulletin ?: 'AUTRES',
                'reservee' => $m->code === Resultats::CONDUITE,
                'utilisee' => DB::table('notes')->where('matiere_id', $m->id)->exists() || DB::table('moyennes')->where('matiere_id', $m->id)->exists(),
                'coefficients' => $coefficients->where('matiere_id', $m->id)->mapWithKeys(fn ($c) => [
                    $c->niveau_id => ['coefficient' => (int) $c->coefficient, 'obligatoire' => (bool) $c->is_obligatoire],
                ]),
            ])->values(),
        ];
    }

    private function valider(Request $request, TenantContext $tenant, ?Matiere $matiere = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:15', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('matieres', 'code')->where('etablissement_id', $tenant->id())->ignore($matiere?->id)],
            'libelle' => ['required', 'string', 'max:100',
                Rule::unique('matieres', 'libelle')->where('etablissement_id', $tenant->id())->ignore($matiere?->id)],
            'groupe' => ['required', Rule::in(array_keys(self::GROUPES))],
        ], [
            'code.unique' => 'Ce code est déjà utilisé.',
            'libelle.unique' => 'Cette matière existe déjà.',
            'code.regex' => 'Lettres, chiffres, - et _ uniquement.',
        ]);
        abort_if(mb_strtoupper($data['code']) === Resultats::CONDUITE, 422, 'Le code COND est réservé à la conduite.');

        return ['code' => mb_strtoupper($data['code']), 'libelle' => trim($data['libelle']), 'groupe_bulletin' => $data['groupe']];
    }

    private function presenterEnseignants(Classe $classe, int $anneeId): array
    {
        $affectations = AffectationEnseignant::where('annee_scolaire_id', $anneeId)->where('classe_id', $classe->id)->get()->keyBy('matiere_id');
        $personnels = Personnel::with(['user:id,name,prenoms', 'matieres:id'])->get();

        return [
            'matieres' => Resultats::matieres($classe->niveau_id)->reject(fn ($m) => $m['conduite'])->map(fn ($m) => [
                'id' => $m['id'],
                'libelle' => $m['libelle'],
                'coefficient' => $m['coefficient'],
                'personnel_id' => $affectations->get($m['id'])?->personnel_id,
            ])->values(),
            // Professeurs proposes : ceux qui enseignent la matiere d'abord (V1 matiere_prof).
            'personnels' => $personnels->filter(fn (Personnel $p) => $p->user)->map(fn (Personnel $p) => [
                'id' => $p->id,
                'nom' => trim($p->user->name.' '.$p->user->prenoms),
                'matieres' => $p->matieres->pluck('id'),
            ])->sortBy('nom')->values(),
        ];
    }
}
