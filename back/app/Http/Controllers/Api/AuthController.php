<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EtablissementResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\MigrationV1\Conversions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Identifiant : e-mail ou numero de telephone (identifiant V1, E3).
        // "email" reste accepte pour les anciennes versions de l'application.
        $data = $request->validate([
            'identifiant' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:identifiant', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);
        $identifiant = trim((string) ($data['identifiant'] ?? $data['email']));

        $user = $this->trouverCompte($identifiant, $data['password']);

        if (! $user) {
            throw ValidationException::withMessages([
                'identifiant' => ["Identifiants incorrects."],
            ]);
        }

        if ($user->statut !== 'actif') {
            throw ValidationException::withMessages([
                'identifiant' => ["Ce compte est desactive."],
            ]);
        }

        $user->forceFill(['derniere_connexion_at' => now()])->save();

        $token = $user->createToken('pwa')->plainTextToken;

        $etablissements = collect([$user->etablissement])
            ->merge($user->etablissements)
            ->filter()
            ->unique('id')
            ->values();

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
            'etablissements' => EtablissementResource::collection($etablissements),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return new UserResource($user);
    }

    /**
     * L'utilisateur choisit son mot de passe (obligatoire a la premiere
     * connexion, apres un mot de passe provisoire). Ses autres sessions
     * sont fermees.
     */
    public function changerMotDePasse(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'actuel' => ['required', 'string'],
            'nouveau' => ['required', 'string', 'min:8', 'max:100', 'confirmed', 'different:actuel', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ], [
            'actuel.required' => 'Saisissez votre mot de passe actuel.',
            'nouveau.required' => 'Saisissez votre nouveau mot de passe.',
            'nouveau.min' => 'Au moins 8 caractères.',
            'nouveau.max' => '100 caractères au plus.',
            'nouveau.confirmed' => 'Les deux saisies ne sont pas identiques.',
            'nouveau.different' => 'Choisissez un mot de passe différent du mot de passe provisoire.',
            'nouveau.regex' => 'Mélangez des lettres et des chiffres.',
        ]);

        if (! Hash::check($data['actuel'], $user->password)) {
            throw ValidationException::withMessages(['actuel' => ['Mot de passe actuel incorrect.']]);
        }

        $user->forceFill(['password' => $data['nouveau'], 'doit_changer_mot_de_passe' => false])->save();
        $courant = $user->currentAccessToken();
        $user->tokens()->when($courant?->id, fn ($q, $id) => $q->where('id', '!=', $id))->delete();

        return new UserResource($user->fresh());
    }

    /**
     * Compte dont l'identifiant (e-mail, ou telephone a 10 chiffres saisi avec
     * ou sans +225, espaces, ancien numero a 8 chiffres) et le mot de passe
     * correspondent. Un meme numero peut servir a plusieurs comptes (V1 : un
     * compte par poste) : on retient celui dont le mot de passe est le bon.
     */
    private function trouverCompte(string $identifiant, string $motDePasse): ?User
    {
        if (str_contains($identifiant, '@')) {
            $candidats = User::where('email', $identifiant)->get();
        } else {
            [$telephone] = Conversions::telephone($identifiant);
            $candidats = $telephone && preg_match('/^\d{10}$/', $telephone)
                ? User::where('telephone', $telephone)->orderByRaw("statut = 'actif' desc")->orderBy('id')->get()
                : collect();
        }

        return $candidats->first(fn (User $u) => Hash::check($motDePasse, $u->password));
    }
}
