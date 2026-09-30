<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UtilisateurResource;
use App\Models\AnneeScolaire;
use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\Personnel;
use App\Models\Poste;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administration des utilisateurs de l'etablissement courant (droit
 * "utilisateurs.gerer"). Reprend la logique de la V1
 * (controllers/administrationController.php) :
 * - matricule genere a la creation (annee + debut du poste + code aleatoire) ;
 * - mot de passe provisoire a changer a la premiere connexion ;
 * - pas de suppression : un compte se desactive (l'historique des reglements,
 *   notes, absences... qu'il a saisis reste intact) ;
 * - niveaux de l'educateur (V1 "choix") et matieres du professeur
 *   (V1 administration.matiere_prof).
 *
 * Postes proteges (caisse, comptabilite, Fondateur...) : seul un poste ayant
 * "utilisateurs.gerer_sensibles" (le Super admin) peut les attribuer ou
 * modifier un compte qui en a un. Ex : un Directeur des etudes ne peut pas se
 * creer un compte Caissier pour consulter les finances.
 */
class UtilisateurController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        $data = $request->validate([
            'recherche' => ['nullable', 'string', 'max:100'],
            'poste_id' => ['nullable', 'integer'],
            'statut' => ['nullable', Rule::in(['actif', 'inactif'])],
        ]);

        $utilisateurs = $this->utilisateursDeLEtablissement($tenant)
            ->with($this->relations($tenant))
            ->when($data['recherche'] ?? null, function (Builder $q, string $recherche) {
                $q->where(function (Builder $q) use ($recherche) {
                    $q->where('name', 'like', "%{$recherche}%")
                        ->orWhere('prenoms', 'like', "%{$recherche}%")
                        ->orWhere('email', 'like', "%{$recherche}%")
                        ->orWhere('matricule', 'like', "%{$recherche}%")
                        ->orWhere('telephone', 'like', "%{$recherche}%");
                });
            })
            ->when($data['poste_id'] ?? null, fn (Builder $q, int $posteId) => $q->whereHas(
                'roles',
                fn (Builder $q) => $q->where('roles.id', $posteId)
            ))
            ->when($data['statut'] ?? null, fn (Builder $q, string $statut) => $q->where('statut', $statut))
            ->orderBy('name')
            ->orderBy('prenoms')
            ->get();

        return UtilisateurResource::collection($utilisateurs);
    }

    /** Listes des formulaires : postes, niveaux (educateurs), matieres (professeurs). */
    public function options(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        $peutSensibles = $this->peutGererSensibles($request);

        return response()->json([
            'peut_gerer_sensibles' => $peutSensibles,
            'postes' => $this->postesDeLEtablissement()->map(fn (Poste $poste) => [
                'id' => $poste->id,
                'nom' => $poste->name,
                'is_active' => $poste->is_active,
                'est_sensible' => $poste->est_sensible,
                'lie_niveaux' => $poste->lie_niveaux,
                'lie_matieres' => $poste->lie_matieres,
                'attribuable' => $poste->is_active && ($peutSensibles || ! $poste->est_sensible),
            ])->values(),
            'niveaux' => $this->niveauxProposes($tenant)->map(fn (Niveau $n) => [
                'id' => $n->id,
                'libelle' => $n->libelle,
                'cycle' => $n->cycle,
            ])->values(),
            'matieres' => Matiere::orderBy('libelle')->get(['id', 'code', 'libelle'])->map(fn (Matiere $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'libelle' => $m->libelle,
            ])->values(),
        ]);
    }

    public function store(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        $data = $this->valider($request, $tenant);
        $postes = $this->postesAutorises($request, $data['postes'], null);
        $motDePasse = $this->motDePasseProvisoire();

        $user = DB::transaction(function () use ($data, $postes, $motDePasse, $tenant) {
            $user = User::create([
                'etablissement_id' => $tenant->id(),
                'matricule' => $this->nouveauMatricule($tenant, $postes->first()),
                'name' => $data['nom'],
                'prenoms' => $data['prenoms'] ?? null,
                'email' => $data['email'],
                'telephone' => $data['telephone'] ?? null,
                'sexe' => $data['sexe'] ?? null,
                'password' => $motDePasse,
                'statut' => 'actif',
                'doit_changer_mot_de_passe' => true,
            ]);

            $user->etablissements()->syncWithoutDetaching([$tenant->id() => ['is_defaut' => true]]);
            $personnel = $this->enregistrerPersonnel($user, $data, $tenant);
            $user->syncRoles($postes);
            $this->enregistrerAffectations($personnel, $postes, $data, $tenant);

            return $user;
        });

        return (new UtilisateurResource($user->load($this->relations($tenant))))
            ->additional(['mot_de_passe_provisoire' => $motDePasse])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);

        $user = $this->trouver($tenant, $id);
        $this->verifierProtection($request, $user);

        $data = $this->valider($request, $tenant, $user);
        $estMoi = $user->id === $request->user()->id;
        // On ne modifie pas ses propres postes (risque de se retirer l'acces
        // a cet ecran) : c'est a un autre administrateur de le faire.
        $postes = $estMoi ? null : $this->postesAutorises($request, $data['postes'], $user);

        DB::transaction(function () use ($user, $data, $postes, $tenant) {
            $user->update([
                'name' => $data['nom'],
                'prenoms' => $data['prenoms'] ?? null,
                'email' => $data['email'],
                'telephone' => $data['telephone'] ?? null,
                'sexe' => $data['sexe'] ?? null,
            ]);

            $personnel = $this->enregistrerPersonnel($user, $data, $tenant);

            if ($postes !== null) {
                // syncRoles ne touche qu'aux postes de l'etablissement courant
                // (team id positionne par le middleware "etablissement").
                $user->syncRoles($postes);
            }

            $this->enregistrerAffectations($personnel, $postes ?? $user->roles, $data, $tenant);
        });

        return new UtilisateurResource($user->fresh()->load($this->relations($tenant)));
    }

    public function changerStatut(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);

        $user = $this->trouver($tenant, $id);
        $this->verifierProtection($request, $user);

        $data = $request->validate(['statut' => ['required', Rule::in(['actif', 'inactif'])]]);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['statut' => ['Vous ne pouvez pas desactiver votre propre compte.']]);
        }

        $user->update(['statut' => $data['statut']]);

        if ($data['statut'] === 'inactif') {
            // Deconnecte immediatement le compte sur tous ses appareils.
            $user->tokens()->delete();
        }

        return new UtilisateurResource($user->load($this->relations($tenant)));
    }

    public function reinitialiserMotDePasse(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);

        $user = $this->trouver($tenant, $id);
        $this->verifierProtection($request, $user);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['mot_de_passe' => ['Utilisez votre profil pour changer votre propre mot de passe.']]);
        }

        $motDePasse = $this->motDePasseProvisoire();
        $user->update(['password' => $motDePasse, 'doit_changer_mot_de_passe' => true]);
        $user->tokens()->delete();

        return (new UtilisateurResource($user->load($this->relations($tenant))))
            ->additional(['mot_de_passe_provisoire' => $motDePasse]);
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('utilisateurs.gerer'), 403);
    }

    private function peutGererSensibles(Request $request): bool
    {
        return $request->user()->can('utilisateurs.gerer_sensibles');
    }

    private function relations(TenantContext $tenant): array
    {
        $annee = $this->anneeId($tenant);

        return [
            'roles',
            'personnel.matieres',
            'personnel.niveaux' => fn ($q) => $q->wherePivot('annee_scolaire_id', $annee)->orderBy('ordre'),
        ];
    }

    /** Comptes rattaches a l'etablissement (principal ou acces supplementaire). */
    private function utilisateursDeLEtablissement(TenantContext $tenant): Builder
    {
        $etablissementId = $tenant->id();

        return User::query()->where(function (Builder $q) use ($etablissementId) {
            $q->where('etablissement_id', $etablissementId)
                ->orWhereHas('etablissements', fn (Builder $q) => $q->where('etablissements.id', $etablissementId));
        });
    }

    private function trouver(TenantContext $tenant, int $id): User
    {
        return $this->utilisateursDeLEtablissement($tenant)->with($this->relations($tenant))->findOrFail($id);
    }

    private function postesDeLEtablissement(): Collection
    {
        // Les roles sont scopes par etablissement (team id du middleware).
        return Poste::where('etablissement_id', app(TenantContext::class)->id())->orderBy('name')->get();
    }

    /** Un compte qui a un poste protege ne se modifie qu'avec le droit adequat. */
    private function verifierProtection(Request $request, User $cible): void
    {
        // Sa propre fiche reste modifiable (identite) : ses postes, son statut
        // et son mot de passe sont bloques par ailleurs.
        if ($cible->id === $request->user()->id) {
            return;
        }

        $proteges = $cible->roles->where('est_sensible', true);

        if ($proteges->isNotEmpty() && ! $this->peutGererSensibles($request)) {
            abort(403, 'Ce compte a un poste protege ('.$proteges->pluck('name')->implode(', ')
                .') : seul le Super admin peut le modifier.');
        }
    }

    /**
     * Postes demandes, verifies : de cet etablissement, actifs (sauf s'ils
     * sont deja attribues au compte), et postes proteges reserves au Super admin.
     */
    private function postesAutorises(Request $request, array $ids, ?User $cible): Collection
    {
        $postes = $this->postesDeLEtablissement()->whereIn('id', $ids)->values();

        if ($postes->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['postes' => ['Un des postes choisis n\'existe pas dans cet etablissement.']]);
        }

        $actuels = $cible?->roles->pluck('id')->all() ?? [];

        $inactifs = $postes->filter(fn (Poste $p) => ! $p->is_active && ! in_array($p->id, $actuels, true));
        if ($inactifs->isNotEmpty()) {
            throw ValidationException::withMessages(['postes' => ['Le poste '.$inactifs->first()->name.' est desactive : il ne peut plus etre attribue.']]);
        }

        $sensibles = $postes->filter(fn (Poste $p) => $p->est_sensible);
        if ($sensibles->isNotEmpty() && ! $this->peutGererSensibles($request)) {
            throw ValidationException::withMessages(['postes' => ['Le poste '.$sensibles->first()->name.' est protege : seul le Super admin peut l\'attribuer.']]);
        }

        return $postes;
    }

    private function valider(Request $request, TenantContext $tenant, ?User $user = null): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:80'],
            'prenoms' => ['nullable', 'string', 'max:180'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user?->id)],
            'telephone' => ['nullable', 'string', 'max:20'],
            'sexe' => ['nullable', Rule::in(['M', 'F'])],
            'postes' => [$user?->id === $request->user()->id ? 'nullable' : 'required', 'array', 'min:1'],
            'postes.*' => ['integer'],
            'niveaux' => ['nullable', 'array'],
            'niveaux.*' => ['integer', Rule::exists('niveaux', 'id')],
            'matieres' => ['nullable', 'array'],
            'matieres.*' => ['integer', Rule::exists('matieres', 'id')->where('etablissement_id', $tenant->id())],
            'diplome' => ['nullable', 'string', 'max:100'],
            'statut_contrat' => ['nullable', Rule::in(['permanent', 'vacataire', 'stagiaire'])],
            'date_embauche' => ['nullable', 'date'],
            'nationalite' => ['nullable', 'string', 'max:60'],
            'quartier' => ['nullable', 'string', 'max:150'],
        ], [
            'email.unique' => 'Cette adresse e-mail est deja utilisee par un autre compte.',
            'postes.required' => 'Choisissez au moins un poste.',
            'postes.min' => 'Choisissez au moins un poste.',
            'niveaux.*.exists' => 'Un des niveaux choisis n\'existe pas.',
            'matieres.*.exists' => 'Une des matieres choisies n\'existe pas dans cet etablissement.',
        ]);
    }

    private function enregistrerPersonnel(User $user, array $data, TenantContext $tenant): Personnel
    {
        $personnel = Personnel::withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'etablissement_id' => $user->personnel?->etablissement_id ?? $tenant->id(),
                'diplome' => $data['diplome'] ?? null,
                'statut_contrat' => $data['statut_contrat'] ?? null,
                'date_embauche' => $data['date_embauche'] ?? null,
                'nationalite' => $data['nationalite'] ?? null,
                'quartier' => $data['quartier'] ?? null,
            ]
        );
        $user->unsetRelation('personnel');

        return $personnel;
    }

    /**
     * Niveaux (annee scolaire en cours) et matieres (dans cet etablissement)
     * du compte. Si plus aucun de ses postes n'y est rattache, on les retire.
     */
    private function enregistrerAffectations(Personnel $personnel, Collection $postes, array $data, TenantContext $tenant): void
    {
        $etablissementId = $tenant->id();

        if ($anneeId = $this->anneeId($tenant)) {
            $niveaux = $postes->contains('lie_niveaux', true) ? array_unique($data['niveaux'] ?? []) : [];

            DB::table('educateur_niveaux')
                ->where('personnel_id', $personnel->id)
                ->where('annee_scolaire_id', $anneeId)
                ->whereNotIn('niveau_id', $niveaux)
                ->delete();

            foreach ($niveaux as $niveauId) {
                DB::table('educateur_niveaux')->insertOrIgnore([
                    'etablissement_id' => $etablissementId,
                    'annee_scolaire_id' => $anneeId,
                    'personnel_id' => $personnel->id,
                    'niveau_id' => $niveauId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $matieres = $postes->contains('lie_matieres', true) ? array_unique($data['matieres'] ?? []) : [];
        $matieresEtablissement = Matiere::pluck('id');

        // Seules les matieres de CET etablissement sont remplacees (un
        // professeur peut enseigner dans plusieurs etablissements).
        DB::table('personnel_matieres')
            ->where('personnel_id', $personnel->id)
            ->whereIn('matiere_id', $matieresEtablissement)
            ->whereNotIn('matiere_id', $matieres)
            ->delete();

        foreach ($matieres as $matiereId) {
            DB::table('personnel_matieres')->insertOrIgnore([
                'personnel_id' => $personnel->id,
                'matiere_id' => $matiereId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Annee du contexte de travail, sinon l'annee active de l'etablissement. */
    private function anneeId(TenantContext $tenant): ?int
    {
        return $tenant->anneeScolaireId() ?? AnneeScolaire::where('is_active', true)->value('id');
    }

    /** Niveaux ouverts cette annee dans l'etablissement, sinon tous les niveaux. */
    private function niveauxProposes(TenantContext $tenant): Collection
    {
        $anneeId = $this->anneeId($tenant);

        $ouverts = Niveau::whereHas('classes', fn (Builder $q) => $q
            ->where('classes.etablissement_id', $tenant->id())
            ->where('classes.annee_scolaire_id', $anneeId))
            ->orderBy('ordre')
            ->get();

        return $ouverts->isNotEmpty() ? $ouverts : Niveau::orderBy('ordre')->get();
    }

    /**
     * Comme en V1 : 2 chiffres de l'annee scolaire + 3 premieres lettres du
     * poste + 5 caracteres aleatoires (ex: 25PRO7K2QX), unique dans
     * l'etablissement.
     */
    private function nouveauMatricule(TenantContext $tenant, Poste $poste): string
    {
        $annee = $tenant->anneeScolaireId() ? AnneeScolaire::find($tenant->anneeScolaireId()) : null;
        $prefixeAnnee = $annee ? substr($annee->libelle, 2, 2) : now()->format('y');
        $prefixePoste = strtoupper(substr(Str::ascii(str_replace(' ', '', $poste->name)), 0, 3));

        do {
            $matricule = $prefixeAnnee.$prefixePoste.strtoupper(Str::random(5));
        } while (User::withTrashed()->where('etablissement_id', $tenant->id())->where('matricule', $matricule)->exists());

        return $matricule;
    }

    /** 8 caracteres faciles a dicter (sans 0/O, 1/l/I). */
    private function motDePasseProvisoire(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        return collect(range(1, 8))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
    }
}
