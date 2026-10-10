<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banque;
use App\Models\Depense;
use App\Models\Entree;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Parametres > Banques : comptes bancaires de l'etablissement pour les
 * depenses banque. Gestion : parametres de l'etablissement ou modification
 * des depenses (econome). Lecture : aussi pour qui enregistre les depenses.
 */
class BanqueController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        abort_unless($u->can('etablissement.gerer') || $u->can('depenses.modifier') || $u->can('depenses.gerer') || $u->can('entrees.gerer'), 403);
        $utilisees = Depense::whereNotNull('banque_id')->selectRaw('banque_id, COUNT(*) AS n')->groupBy('banque_id')->pluck('n', 'banque_id');
        // Entrees banque : comptees avec les depenses (banque utilisee = suppression impossible).
        foreach (Entree::selectRaw('banque_id, COUNT(*) AS n')->groupBy('banque_id')->pluck('n', 'banque_id') as $id => $n) {
            $utilisees[$id] = ($utilisees[$id] ?? 0) + $n;
        }

        return response()->json([
            'data' => Banque::orderByDesc('is_active')->orderBy('nom')->get()->map(fn (Banque $b) => $this->presenter($b) + ['depenses' => (int) ($utilisees[$b->id] ?? 0)]),
            'peut_gerer' => $this->peutGerer($request),
        ]);
    }

    public function store(Request $request, TenantContext $tenant)
    {
        abort_unless($this->peutGerer($request), 403);
        $banque = Banque::create($this->valider($request, $tenant) + ['is_active' => true]);

        return response()->json(['data' => $this->presenter($banque) + ['depenses' => 0], 'message' => 'Banque '.$banque->nom.' ajoutée.'], 201);
    }

    public function update(Request $request, TenantContext $tenant, int $id)
    {
        abort_unless($this->peutGerer($request), 403);
        $banque = Banque::findOrFail($id);
        $banque->update($this->valider($request, $tenant, $banque) + ($request->has('is_active') ? ['is_active' => $request->boolean('is_active')] : []));

        return response()->json(['data' => $this->presenter($banque), 'message' => 'Banque '.$banque->nom.' modifiée.']);
    }

    public function destroy(Request $request, int $id)
    {
        abort_unless($this->peutGerer($request), 403);
        $banque = Banque::findOrFail($id);
        abort_if(Depense::where('banque_id', $banque->id)->exists() || Entree::where('banque_id', $banque->id)->exists(), 422, 'Cette banque est utilisée par des dépenses ou des entrées : désactivez-la plutôt.');
        $banque->delete();

        return response()->json(['message' => 'Banque '.$banque->nom.' supprimée.']);
    }

    private function peutGerer(Request $request): bool
    {
        return $request->user()->can('etablissement.gerer') || $request->user()->can('depenses.modifier');
    }

    private function valider(Request $request, TenantContext $tenant, ?Banque $banque = null): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:100', Rule::unique('banques', 'nom')->where('etablissement_id', $tenant->id())->ignore($banque?->id)],
            'sigle' => ['nullable', 'string', 'max:20'],
            'numero_compte' => ['nullable', 'string', 'max:50'],
            'agence' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'nom.required' => 'Indiquez le nom de la banque.',
            'nom.unique' => 'Cette banque existe déjà.',
        ]);
    }

    private function presenter(Banque $b): array
    {
        return [
            'id' => $b->id,
            'nom' => $b->nom,
            'sigle' => $b->sigle,
            'numero_compte' => $b->numero_compte,
            'agence' => $b->agence,
            'is_active' => $b->is_active,
            'libelle' => trim(($b->sigle ? $b->sigle.' · ' : '').$b->nom.($b->numero_compte ? ' · '.$b->numero_compte : '')),
        ];
    }
}
