<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'nom', 'sigle', 'type_etablissement', 'telephone1', 'telephone2',
    'email', 'site_web', 'boite_postale', 'quartier', 'ville', 'region', 'pays',
    'indicatif_telephonique', 'logo_path', 'slogan', 'statut',
    'date_expiration_abonnement', 'parametres', 'cree_par_id',
])]
class Etablissement extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'parametres' => 'array',
            'date_expiration_abonnement' => 'date',
        ];
    }

    public function utilisateurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('is_defaut')
            ->withTimestamps();
    }

    public function anneesScolaires(): HasMany
    {
        return $this->hasMany(AnneeScolaire::class);
    }

    public function anneeScolaireActive(): ?AnneeScolaire
    {
        return $this->anneesScolaires()->where('is_active', true)->first();
    }

    public function matieres(): HasMany
    {
        return $this->hasMany(Matiere::class);
    }

    public function eleves(): HasMany
    {
        return $this->hasMany(Eleve::class);
    }

    public function personnels(): HasMany
    {
        return $this->hasMany(Personnel::class);
    }

    public function typesFrais(): HasMany
    {
        return $this->hasMany(TypeFrais::class);
    }
}
