<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EtablissementResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var User|null $user */
        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ["Identifiants incorrects."],
            ]);
        }

        if ($user->statut !== 'actif') {
            throw ValidationException::withMessages([
                'email' => ["Ce compte est desactive."],
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
}
