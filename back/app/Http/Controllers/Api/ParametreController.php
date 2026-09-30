<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Etablissement;
use App\Models\LimiteEffectifNiveau;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Parametres de l'etablissement : classes (effectifs maximums) et
 * inscriptions (a partir de quand un eleve apparait dans sa classe).
 */
class ParametreController extends Controller
{
    public const STATUTS_VISIBLES = ['classe_attribuee', 'premier_paiement', 'solde'];

    /**
     * Effectifs maximums : etablissement, niveaux et classes de l'annee de
     * travail. La limite la plus precise l'emporte (voir Classe::effectifLimite).
     */
    public function classes(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('classes.gerer'), 403);

        $etablissement = Etablissement::findOrFail($tenant->id());
        $anneeId = $this->anneeId($tenant);
        $limitesNiveaux = LimiteEffectifNiveau::pluck('effectif_max', 'niveau_id');

        $classes = Classe::where('annee_scolaire_id', $anneeId)
            ->with('niveau:id,libelle,ordre')
            ->withCount(['inscriptions as effectif' => fn ($q) => $q->visiblesEnClasse()])
            ->get()
            ->sortBy(fn (Classe $c) => sprintf('%03d-%s', $c->niveau->ordre, $c->libelle));

        $niveaux = $classes->groupBy('niveau_id')->map(function ($classesDuNiveau) use ($limitesNiveaux) {
            $niveau = $classesDuNiveau->first()->niveau;

            return [
                'id' => $niveau->id,
                'libelle' => $niveau->libelle,
                'effectif_max' => $limitesNiveaux[$niveau->id] ?? null,
                'classes' => $classesDuNiveau->map(fn (Classe $c) => [
                    'id' => $c->id,
                    'libelle' => $c->libelle,
                    'capacite' => $c->capacite,
                    'effectif' => (int) $c->effectif,
                ])->values(),
            ];
        })->values();

        return response()->json([
            'annee' => AnneeScolaire::find($anneeId)?->libelle,
            'effectif_max_classe' => $etablissement->parametre('effectif_max_classe'),
            'numerotation_classes' => $etablissement->parametre('numerotation_classes'),
            'niveaux' => $niveaux,
        ]);
    }

    public function enregistrerClasses(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('classes.gerer'), 403);

        $data = $request->validate([
            'effectif_max_classe' => ['nullable', 'integer', 'min:1', 'max:500'],
            'numerotation_classes' => ['sometimes', Rule::in(['chiffres', 'lettres'])],
            'niveaux' => ['array'],
            'niveaux.*.id' => ['required', 'integer', Rule::exists('niveaux', 'id')],
            'niveaux.*.effectif_max' => ['nullable', 'integer', 'min:1', 'max:500'],
            'classes' => ['array'],
            'classes.*.id' => ['required', 'integer', Rule::exists('classes', 'id')->where('etablissement_id', $tenant->id())],
            'classes.*.capacite' => ['nullable', 'integer', 'min:1', 'max:500'],
        ], [
            '*.min' => 'La limite doit être d\'au moins 1 élève.',
            '*.*.effectif_max.min' => 'La limite doit être d\'au moins 1 élève.',
            '*.*.capacite.min' => 'La limite doit être d\'au moins 1 élève.',
            '*.max' => 'La limite ne peut pas dépasser 500 élèves.',
            '*.*.effectif_max.max' => 'La limite ne peut pas dépasser 500 élèves.',
            '*.*.capacite.max' => 'La limite ne peut pas dépasser 500 élèves.',
        ]);

        DB::transaction(function () use ($data, $tenant) {
            Etablissement::findOrFail($tenant->id())->definirParametres([
                'effectif_max_classe' => $data['effectif_max_classe'] ?? null,
            ] + (isset($data['numerotation_classes']) ? ['numerotation_classes' => $data['numerotation_classes']] : []));

            foreach ($data['niveaux'] ?? [] as $niveau) {
                if ($niveau['effectif_max'] ?? null) {
                    LimiteEffectifNiveau::updateOrCreate(
                        ['etablissement_id' => $tenant->id(), 'niveau_id' => $niveau['id']],
                        ['effectif_max' => $niveau['effectif_max']]
                    );
                } else {
                    LimiteEffectifNiveau::where('niveau_id', $niveau['id'])->delete();
                }
            }

            foreach ($data['classes'] ?? [] as $classe) {
                Classe::whereKey($classe['id'])->update(['capacite' => $classe['capacite'] ?? null]);
            }
        });

        return $this->classes($request, $tenant);
    }

    public function inscriptions(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('etablissement.gerer'), 403);

        $etablissement = Etablissement::findOrFail($tenant->id());

        return response()->json([
            'statut_visible_classe' => $etablissement->parametre('statut_visible_classe'),
            'formats_matricule' => collect($etablissement->parametre('formats_matricule'))->map(fn ($f) => [
                'format' => $f,
                'description' => InscriptionController::exempleFormat($f),
            ])->values(),
            'verification_en_ligne' => (bool) $etablissement->parametre('verification_en_ligne'),
            'code_mena' => $etablissement->parametre('code_mena'),
        ]);
    }

    public function enregistrerInscriptions(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('etablissement.gerer'), 403);

        $request->merge(['formats_matricule' => array_values(array_unique(array_map(
            fn ($f) => strtoupper(trim((string) $f)),
            (array) $request->input('formats_matricule', [])
        )))]);

        $data = $request->validate([
            'statut_visible_classe' => ['required', Rule::in(self::STATUTS_VISIBLES)],
            // 9 = chiffre, A = lettre majuscule, autre caractere = tel quel (ex : 99999999A, AA999999).
            'formats_matricule' => ['required', 'array', 'min:1', 'max:10'],
            'formats_matricule.*' => ['required', 'string', 'min:3', 'max:20', 'regex:/^[9A\-\/]+$/', 'regex:/9/'],
            'verification_en_ligne' => ['boolean'],
            'code_mena' => ['nullable', 'string', 'max:20'],
        ], [
            'formats_matricule.required' => 'Gardez au moins un format de matricule.',
            'formats_matricule.min' => 'Gardez au moins un format de matricule.',
            'formats_matricule.*.regex' => 'Format invalide : utilisez 9 pour un chiffre et A pour une lettre (ex : 99999999A).',
            'formats_matricule.*.min' => 'Un format compte au moins 3 caractères.',
        ]);

        Etablissement::findOrFail($tenant->id())->definirParametres($data);

        return $this->inscriptions($request, $tenant);
    }

    private function anneeId(TenantContext $tenant): ?int
    {
        return $tenant->anneeScolaireId() ?? AnneeScolaire::where('is_active', true)->value('id');
    }
}
