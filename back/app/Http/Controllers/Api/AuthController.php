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
}
