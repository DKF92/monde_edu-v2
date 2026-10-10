<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Nom d'un agent tel qu'on l'affiche dans une trace (« inscrit par »,
 * « encaisse par ») : prenoms et nom, puis son ou ses postes dans
 * l'etablissement, pour ne pas confondre avec l'utilisateur qui consulte
 * (ex. « Rene DODO · Educateur »).
 */
class Personnes
{
    /** @var array<int, string> */
    private static array $postes = [];

    public static function agent(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        $nom = trim(($user->prenoms ?? '').' '.$user->name);
        $postes = self::$postes[$user->id] ??= DB::table('model_has_roles as mr')->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.model_id', $user->id)->where('mr.model_type', $user->getMorphClass())
            ->where('r.etablissement_id', app(TenantContext::class)->id())
            ->orderBy('r.name')->pluck('r.name')->implode(', ');

        return $postes ? $nom.' · '.$postes : $nom;
    }
}
