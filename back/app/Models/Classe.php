<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'etablissement_id', 'annee_scolaire_id', 'niveau_id', 'libelle', 'salle',
    'capacite', 'langue_vivante_2', 'professeur_principal_id', 'educateur_id',
])]
class Classe extends Model
{
    use BelongsToEtablissement;

    public function anneeScolaire(): BelongsTo
    {
        return $this->belongsTo(AnneeScolaire::class);
    }

    public function niveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class);
    }

    public function professeurPrincipal(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'professeur_principal_id');
    }

    public function educateur(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'educateur_id');
    }

    public function inscriptions(): HasMany
    {
        return $this->hasMany(Inscription::class);
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(AffectationEnseignant::class);
    }

    public function emploiDuTemps(): HasMany
    {
        return $this->hasMany(EmploiDuTemps::class);
    }

    public function effectif(): int
    {
        return $this->inscriptions()->where('statut', 'validee')->count();
    }
}
