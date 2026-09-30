<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Poste;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Administration des postes de l'etablissement (droit "roles.gerer").
 * V1 : tables "role" (is_active) et "role_fonction" (droits du poste).
 *
 * Garde-fous :
 * - pas de suppression : un poste se desactive (il ne peut plus etre choisi a
 *   la connexion ni attribue, les comptes qui l'ont le gardent) ;
 * - on ne peut accorder a un poste que des droits qu'on a soi-meme ;
 * - un poste protege, et le droit "utilisateurs.gerer_sensibles", ne se
 *   modifient qu'avec ce droit (Super admin) ;
 * - le poste Super admin garde tous ses droits et ne se desactive pas.
 */
class PosteController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        $nombres = DB::table('model_has_roles')
            ->where('etablissement_id', $tenant->id())
            ->select('role_id', DB::raw('count(*) as total'))
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        return response()->json([
            'data' => $this->postes($tenant)->map(fn (Poste $p) => $this->presenter($request, $p, (int) ($nombres[$p->id] ?? 0)))->values(),
        ]);
    }

    /** Droits disponibles, par groupe, avec leur libelle. */
    public function catalogue(Request $request)
    {
        $this->autoriser($request);

        $miens = $this->mesPermissions($request);
        $superAdmin = $this->peutGererSensibles($request);
        $groupes = collect(config('roles.libelles'));
        $connues = $groupes->flatMap(fn ($droits) => array_keys($droits))->all();

        $autres = Permission::whereNotIn('name', $connues)->pluck('name')
            ->mapWithKeys(fn ($nom) => [$nom => [$nom, null]]);
        if ($autres->isNotEmpty()) {
            $groupes['Autres'] = $autres->all();
        }

        return response()->json([
            'peut_gerer_sensibles' => $superAdmin,
            'groupes' => $groupes->map(fn ($droits, $groupe) => [
                'groupe' => $groupe,
                'droits' => collect($droits)->map(fn ($libelle, $nom) => [
                    'nom' => $nom,
                    'libelle' => $libelle[0],
                    'description' => $libelle[1],
                    'reserve' => in_array($nom, config('roles.permissions_reservees', []), true),
                    // Accordable seulement si on a soi-meme ce droit.
                    'accordable' => $superAdmin || in_array($nom, $miens, true),
                ])->values(),
            ])->values(),
        ]);
    }

    public function show(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);

        $poste = $this->trouver($tenant, $id);

        $utilisateurs = User::whereIn('id', DB::table('model_has_roles')
            ->where('etablissement_id', $tenant->id())
            ->where('role_id', $poste->id)
            ->pluck('model_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'prenoms', 'email', 'statut', 'matricule']);

        return response()->json([
            'data' => $this->presenter($request, $poste, $utilisateurs->count()) + [
                'utilisateurs' => $utilisateurs->map(fn (User $u) => [
                    'id' => $u->id,
                    'nom' => $u->name,
                    'prenoms' => $u->prenoms,
                    'email' => $u->email,
                    'matricule' => $u->matricule,
                    'statut' => $u->statut,
                ])->values(),
            ],
        ]);
    }

    public function store(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        $data = $this->valider($request, $tenant);
        $this->verifierSensibilite($request, null, $data);
        $permissions = $this->permissionsAccordees($request, null, $data['permissions'] ?? []);

        $poste = DB::transaction(function () use ($data, $tenant, $permissions) {
            $poste = Poste::create([
                'name' => $data['nom'],
                'guard_name' => 'web',
                'etablissement_id' => $tenant->id(),
                'description' => $data['description'] ?? null,
                'is_active' => true,
                'est_sensible' => $data['est_sensible'] ?? false,
                'lie_niveaux' => $data['lie_niveaux'] ?? false,
                'lie_matieres' => $data['lie_matieres'] ?? false,
            ]);
            $poste->syncPermissions($permissions);

            return $poste;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['data' => $this->presenter($request, $poste->fresh('permissions'), 0)], 201);
    }

    public function update(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);

        $poste = $this->trouver($tenant, $id);
        $data = $this->valider($request, $tenant, $poste);
        $this->verifierSensibilite($request, $poste, $data);

        // Le Super admin garde tous ses droits (sinon plus personne ne peut
        // administrer les postes proteges).
        $permissions = $poste->estSuperAdmin()
            ? null
            : $this->permissionsAccordees($request, $poste, $data['permissions'] ?? []);

        DB::transaction(function () use ($poste, $data, $permissions) {
            $poste->update([
                'name' => $data['nom'],
                'description' => $data['description'] ?? null,
                'est_sensible' => $poste->estSuperAdmin() ? true : ($data['est_sensible'] ?? false),
                'lie_niveaux' => $data['lie_niveaux'] ?? false,
                'lie_matieres' => $data['lie_matieres'] ?? false,
            ]);

            if ($permissions !== null) {
                $poste->syncPermissions($permissions);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->show($request, $tenant, $poste->id);
    }

    public function changerStatut(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);

        $poste = $this->trouver($tenant, $id);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $this->verifierSensibilite($request, $poste, []);

        if (! $data['is_active']) {
            if ($poste->estSuperAdmin()) {
                throw ValidationException::withMessages(['is_active' => ['Le poste Super admin ne peut pas etre desactive.']]);
            }
            if ($this->posteCourant($request)?->id === $poste->id) {
                throw ValidationException::withMessages(['is_active' => ['Vous travaillez avec ce poste : il ne peut pas etre desactive maintenant.']]);
            }
        }

        $poste->update(['is_active' => $data['is_active']]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->show($request, $tenant, $poste->id);
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('roles.gerer'), 403);
    }

    private function peutGererSensibles(Request $request): bool
    {
        return $request->user()->can('utilisateurs.gerer_sensibles');
    }

    /** Droits du poste avec lequel l'utilisateur travaille. */
    private function mesPermissions(Request $request): array
    {
        return $request->user()->getAllPermissions()->pluck('name')->all();
    }

    private function posteCourant(Request $request): ?Poste
    {
        return $request->hasHeader('X-Poste-Id') ? $request->user()->roles->first() : null;
    }

    private function postes(TenantContext $tenant): Collection
    {
        return Poste::where('etablissement_id', $tenant->id())
            ->with('permissions:id,name')
            ->orderByRaw('code is null')
            ->orderBy('name')
            ->get();
    }

    private function trouver(TenantContext $tenant, int $id): Poste
    {
        return Poste::where('etablissement_id', $tenant->id())->with('permissions:id,name')->findOrFail($id);
    }

    private function presenter(Request $request, Poste $poste, int $nombre): array
    {
        $reservees = config('roles.permissions_reservees', []);
        $permissions = $poste->permissions->pluck('name');

        return [
            'id' => $poste->id,
            'nom' => $poste->name,
            'code' => $poste->code,
            'description' => $poste->description,
            'standard' => $poste->code !== null,
            'super_admin' => $poste->estSuperAdmin(),
            'is_active' => $poste->is_active,
            'est_sensible' => $poste->est_sensible,
            'lie_niveaux' => $poste->lie_niveaux,
            'lie_matieres' => $poste->lie_matieres,
            'permissions' => $permissions->values(),
            'nombre_utilisateurs' => $nombre,
            'modifiable' => $this->peutGererSensibles($request)
                || (! $poste->est_sensible && $permissions->intersect($reservees)->isEmpty()),
        ];
    }

    private function valider(Request $request, TenantContext $tenant, ?Poste $poste = null): array
    {
        return $request->validate([
            'nom' => [
                'required', 'string', 'max:60',
                Rule::unique('roles', 'name')
                    ->where('etablissement_id', $tenant->id())
                    ->where('guard_name', 'web')
                    ->ignore($poste?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'est_sensible' => ['boolean'],
            'lie_niveaux' => ['boolean'],
            'lie_matieres' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ], [
            'nom.unique' => 'Un poste porte deja ce nom dans l\'etablissement.',
            'nom.required' => 'Le nom du poste est obligatoire.',
        ]);
    }

    /** Un poste protege (avant ou apres modification) exige le droit Super admin. */
    private function verifierSensibilite(Request $request, ?Poste $poste, array $data): void
    {
        if ($this->peutGererSensibles($request)) {
            return;
        }

        $reservees = config('roles.permissions_reservees', []);

        if ($poste && ($poste->est_sensible || $poste->permissions->pluck('name')->intersect($reservees)->isNotEmpty())) {
            abort(403, 'Ce poste est protege : seul le Super admin peut le modifier.');
        }

        if (! empty($data['est_sensible'])) {
            throw ValidationException::withMessages(['est_sensible' => ['Seul le Super admin peut declarer un poste protege.']]);
        }
    }

    /**
     * Droits a enregistrer. Un droit ajoute doit etre detenu par l'utilisateur
     * (sinon un Directeur pourrait s'accorder l'encaissement) ; les droits
     * deja presents qu'il n'a pas sont conserves tels quels.
     */
    private function permissionsAccordees(Request $request, ?Poste $poste, array $demandees): array
    {
        $demandees = array_values(array_unique($demandees));

        if ($this->peutGererSensibles($request)) {
            return $demandees;
        }

        $miens = $this->mesPermissions($request);
        $actuelles = $poste?->permissions->pluck('name')->all() ?? [];

        $interdites = array_diff($demandees, $miens, $actuelles);
        if ($interdites) {
            throw ValidationException::withMessages([
                'permissions' => ['Vous ne pouvez pas accorder un droit que vous n\'avez pas vous-meme ('.implode(', ', $interdites).').'],
            ]);
        }

        // Droits actuels hors de ma portee : je ne peux pas les retirer non plus.
        $horsPortee = array_diff($actuelles, $miens);

        return array_values(array_unique(array_merge($demandees, $horsPortee)));
    }
}
