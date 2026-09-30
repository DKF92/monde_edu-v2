<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\GrilleTarifaire;
use App\Models\Niveau;
use App\Models\TypeFrais;
use App\Models\TypeReduction;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Parametres des paiements (droit "tarifs.gerer"), iso V1 :
 * - montants d'inscription par niveau pour l'annee de travail, en 2 colonnes
 *   eleve affecte / non affecte (V1 pages/CoutFormation, table cout_formation) ;
 *   comme en V1, un eleve affecte ne paie pas de scolarite ;
 * - types de frais (les colonnes cout_* de la V1, extensibles) ;
 * - types de reductions (V1 "reduction" et "cas").
 */
class ParametrePaiementController extends Controller
{
    // ------------------------------------------------------------ Tarifs

    public function tarifs(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        $anneeId = $this->anneeId($tenant);
        $grilles = GrilleTarifaire::where('annee_scolaire_id', $anneeId)->with('lignes')->get();

        return response()->json([
            'annee' => AnneeScolaire::find($anneeId)?->libelle,
            'types_frais' => $this->typesFrais()->map(fn (TypeFrais $t) => $this->presenterTypeFrais($t))->values(),
            'niveaux' => $this->niveauxOuverts($tenant, $anneeId)->map(fn (Niveau $n) => [
                'id' => $n->id,
                'libelle' => $n->libelle,
                'affecte' => $this->presenterGrille($grilles->first(fn ($g) => $g->niveau_id === $n->id && $g->affecte)),
                'non_affecte' => $this->presenterGrille($grilles->first(fn ($g) => $g->niveau_id === $n->id && ! $g->affecte)),
            ])->values(),
        ]);
    }

    /** Enregistre les 2 grilles (affecte / non affecte) d'un niveau. */
    public function enregistrerTarif(Request $request, TenantContext $tenant, int $niveauId)
    {
        $this->autoriser($request);
        abort_unless(Niveau::whereKey($niveauId)->exists(), 404);

        $regles = [];
        foreach (['affecte', 'non_affecte'] as $colonne) {
            $regles["{$colonne}.montants"] = ['array'];
            $regles["{$colonne}.montants.*"] = ['nullable', 'integer', 'min:0', 'max:100000000'];
        }
        $data = $request->validate($regles, [
            '*.*.min' => 'Le montant ne peut pas être négatif.',
            '*.montants.*.min' => 'Le montant ne peut pas être négatif.',
        ]);

        $anneeId = $this->anneeId($tenant);
        abort_unless($anneeId, 422, 'Aucune année scolaire active.');
        $types = $this->typesFrais()->keyBy('id');

        DB::transaction(function () use ($data, $anneeId, $niveauId, $types) {
            foreach (['affecte' => true, 'non_affecte' => false] as $colonne => $affecte) {
                $grille = GrilleTarifaire::firstOrCreate(
                    ['annee_scolaire_id' => $anneeId, 'niveau_id' => $niveauId, 'affecte' => $affecte]
                );

                foreach ($types as $typeId => $type) {
                    // Type desactive : son montant est conserve tel quel (il
                    // reviendra si le type est reactive).
                    if (! $type->is_active) {
                        continue;
                    }
                    // Frais qui ne concerne pas ce type d'eleve (ex : scolarite
                    // d'un affecte, regle V1) : toujours 0.
                    $montant = $type->applicable($affecte) ? (int) ($data[$colonne]['montants'][$typeId] ?? 0) : 0;
                    $grille->lignes()->updateOrCreate(['type_frais_id' => $typeId], ['montant' => $montant]);
                }
            }
        });
        $this->recalculerMinimums();

        return $this->tarifs($request, $tenant);
    }

    // ------------------------------------------------------------ Types de frais

    public function creerTypeFrais(Request $request)
    {
        $this->autoriser($request);

        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:100', Rule::unique('types_frais', 'libelle')->where('etablissement_id', app(TenantContext::class)->id())],
            'nature' => ['sometimes', Rule::in(['annexe', 'en_nature'])],
        ], ['libelle.unique' => 'Ce type de frais existe déjà.', 'libelle.required' => 'Le libellé est obligatoire.']);

        // Nouveau frais annexe (ou en nature) : il passe avant l'inscription et
        // la scolarite dans l'ordre d'imputation des paiements.
        $ordre = (int) TypeFrais::whereIn('nature', ['annexe', 'en_nature'])->max('ordre') + 1;
        TypeFrais::whereNotIn('nature', ['annexe', 'en_nature'])->where('ordre', '>=', $ordre)->increment('ordre');

        $type = TypeFrais::create([
            'code' => $this->codeUnique($data['libelle']),
            'libelle' => $data['libelle'],
            'nature' => $data['nature'] ?? 'annexe',
            'ordre' => $ordre,
            'is_obligatoire' => true,
        ]);

        return response()->json(['data' => $this->presenterTypeFrais($type)], 201);
    }

    public function modifierTypeFrais(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);

        $type = TypeFrais::findOrFail($id);
        $data = $request->validate([
            'libelle' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('types_frais', 'libelle')->where('etablissement_id', $tenant->id())->ignore($id)],
            'is_obligatoire' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'applicable_affecte' => ['sometimes', 'boolean'],
            'applicable_non_affecte' => ['sometimes', 'boolean'],
        ], ['libelle.unique' => 'Ce type de frais existe déjà.', 'libelle.required' => 'Le libellé est obligatoire.']);

        $type->update($data);
        // Le minimum depend des types actifs et applicables : on le recalcule.
        $this->recalculerMinimums();

        return response()->json(['data' => $this->presenterTypeFrais($type)]);
    }

    /**
     * Ordre de paiement : un versement est impute sur les frais dans cet ordre
     * (le premier frais est solde avant de passer au suivant ; CaisseService).
     * "ids" = types de frais en argent, du premier au dernier paye ; les frais
     * en nature (fournitures) restent a la fin, ils ne recoivent pas d'argent.
     */
    public function ordonnerTypesFrais(Request $request)
    {
        $this->autoriser($request);

        $argent = TypeFrais::whereNotIn('nature', ['en_nature', 'dette'])->pluck('id');
        $data = $request->validate([
            'ids' => ['required', 'array', 'size:'.$argent->count()],
            'ids.*' => ['integer', 'distinct', Rule::in($argent->all())],
        ], ['ids.size' => 'L\'ordre doit contenir tous les types de frais.']);

        DB::transaction(function () use ($data) {
            foreach ($data['ids'] as $i => $id) {
                TypeFrais::whereKey($id)->update(['ordre' => $i + 1]);
            }
            $suite = count($data['ids']);
            foreach (TypeFrais::whereIn('nature', ['en_nature', 'dette'])->orderBy('ordre')->orderBy('id')->get() as $type) {
                $type->update(['ordre' => ++$suite]);
            }
        });

        return response()->json(['data' => $this->typesFrais()->map(fn (TypeFrais $t) => $this->presenterTypeFrais($t))->values()]);
    }

    // ------------------------------------------------------------ Types de reductions

    public function typesReductions(Request $request)
    {
        $this->autoriser($request);

        return response()->json([
            'data' => TypeReduction::orderBy('id')->get()->map(fn (TypeReduction $t) => $this->presenterTypeReduction($t))->values(),
        ]);
    }

    public function creerTypeReduction(Request $request)
    {
        $this->autoriser($request);

        $data = $this->validerTypeReduction($request);
        $type = TypeReduction::create($data + ['code' => $this->codeUnique($data['libelle'], TypeReduction::class), 'is_active' => true]);

        return response()->json(['data' => $this->presenterTypeReduction($type)], 201);
    }

    public function modifierTypeReduction(Request $request, int $id)
    {
        $this->autoriser($request);

        $type = TypeReduction::findOrFail($id);
        $data = $this->validerTypeReduction($request, $type);
        $type->update($data);

        return response()->json(['data' => $this->presenterTypeReduction($type)]);
    }

    // ------------------------------------------------------------ Outils

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('tarifs.gerer'), 403);
    }

    /**
     * Montant minimum a verser a l'inscription, calcule (non saisi) :
     * affecte = total des frais annexes ; non affecte = frais annexes +
     * frais d'inscription. Seuls les types actifs et applicables comptent.
     */
    private function recalculerMinimums(): void
    {
        $types = $this->typesFrais();

        GrilleTarifaire::with('lignes')->get()->each(function (GrilleTarifaire $grille) use ($types) {
            $montants = $grille->lignes->mapWithKeys(fn ($l) => [$l->type_frais_id => (int) $l->montant])->all();
            $grille->update(['montant_minimum_inscription' => TypeFrais::montantMinimum($types, $montants, $grille->affecte)]);
        });
    }

    private function anneeId(TenantContext $tenant): ?int
    {
        return $tenant->anneeScolaireId() ?? AnneeScolaire::where('is_active', true)->value('id');
    }

    private function typesFrais(): Collection
    {
        return TypeFrais::orderBy('ordre')->orderBy('id')->get();
    }

    /** Niveaux qui ont au moins une classe cette annee, sinon tous. */
    private function niveauxOuverts(TenantContext $tenant, ?int $anneeId): Collection
    {
        $ouverts = Niveau::whereHas('classes', fn (Builder $q) => $q
            ->where('classes.etablissement_id', $tenant->id())
            ->where('classes.annee_scolaire_id', $anneeId))
            ->orderBy('ordre')
            ->get();

        return $ouverts->isNotEmpty() ? $ouverts : Niveau::orderBy('ordre')->get();
    }

    private function presenterTypeFrais(TypeFrais $type): array
    {
        return [
            'id' => $type->id,
            'code' => $type->code,
            'libelle' => $type->libelle,
            'nature' => $type->nature,
            'ordre' => $type->ordre,
            'is_obligatoire' => $type->is_obligatoire,
            'is_active' => $type->is_active,
            'applicable_affecte' => $type->applicable_affecte,
            'applicable_non_affecte' => $type->applicable_non_affecte,
        ];
    }

    private function presenterGrille(?GrilleTarifaire $grille): ?array
    {
        if (! $grille) {
            return null;
        }

        return [
            'montant_minimum' => (int) $grille->montant_minimum_inscription,
            'montants' => $grille->lignes->mapWithKeys(fn ($l) => [$l->type_frais_id => (int) $l->montant]),
        ];
    }

    private function presenterTypeReduction(TypeReduction $type): array
    {
        return [
            'id' => $type->id,
            'code' => $type->code,
            'libelle' => $type->libelle,
            'base' => $type->base,
            'montant_minimum' => $type->montant_minimum,
            'minimum_frais_principaux' => $type->minimum_frais_principaux,
            'plafond_pourcentage' => $type->plafond_pourcentage,
            'is_active' => $type->is_active,
        ];
    }

    private function validerTypeReduction(Request $request, ?TypeReduction $type = null): array
    {
        return $request->validate([
            'libelle' => ['required', 'string', 'max:100', Rule::unique('types_reductions', 'libelle')
                ->where('etablissement_id', app(TenantContext::class)->id())->ignore($type?->id)],
            'base' => ['required', Rule::in(['frais_principaux', 'total'])],
            'montant_minimum' => ['required', 'integer', 'min:0', 'max:100000000'],
            'minimum_frais_principaux' => ['boolean'],
            'plafond_pourcentage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_active' => ['boolean'],
        ], [
            'libelle.unique' => 'Ce type de réduction existe déjà.',
            'libelle.required' => 'Le libellé est obligatoire.',
            'plafond_pourcentage.min' => 'Le plafond doit être compris entre 1 et 100 %.',
            'plafond_pourcentage.max' => 'Le plafond doit être compris entre 1 et 100 %.',
        ]);
    }

    private function codeUnique(string $libelle, string $modele = TypeFrais::class): string
    {
        $base = substr(strtoupper(Str::slug(Str::ascii($libelle), '_')), 0, 24) ?: 'TYPE';
        $code = $base;
        $i = 2;
        while ($modele::where('code', $code)->exists()) {
            $code = $base.'_'.$i++;
        }

        return $code;
    }
}
