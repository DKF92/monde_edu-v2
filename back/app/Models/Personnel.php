<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'etablissement_id', 'diplome', 'statut_contrat', 'date_embauche',
    'matiere_principale_id', 'nationalite', 'quartier',
])]
class Personnel extends Model
{
    use BelongsToEtablissement;

    protected $table = 'personnels';

    protected function casts(): array
    {
        return ['date_embauche' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matierePrincipale(): BelongsTo
    {
        return $this->belongsTo(Matiere::class, 'matiere_principale_id');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(AffectationEnseignant::class);
    }

    public function classesEducateur(): HasMany
    {
        return $this->hasMany(Classe::class, 'educateur_id');
    }

    public function classesProfesseurPrincipal(): HasMany
    {
        return $this->hasMany(Classe::class, 'professeur_principal_id');
    }
}
