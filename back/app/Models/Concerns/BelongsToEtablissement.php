<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * A appliquer sur tout modele portant une colonne etablissement_id.
 * Filtre automatiquement chaque requete sur l'etablissement courant
 * (App\Support\TenantContext) et le renseigne automatiquement a la creation,
 * afin qu'une requete/controleur ne puisse jamais lire ou ecrire les donnees
 * d'un autre etablissement par erreur.
 */
trait BelongsToEtablissement
{
    public static function bootBelongsToEtablissement(): void
    {
        static::addGlobalScope('etablissement', function (Builder $builder) {
            $tenant = app(TenantContext::class);
            if ($tenant->has()) {
                $builder->where($builder->getModel()->getTable().'.etablissement_id', $tenant->id());
            }
        });

        static::creating(function ($model) {
            $tenant = app(TenantContext::class);
            if (! $model->etablissement_id && $tenant->has()) {
                $model->etablissement_id = $tenant->id();
            }
        });
    }
}
