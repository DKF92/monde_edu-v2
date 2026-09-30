<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'etablissement_id', 'matricule', 'nom', 'prenoms', 'date_naissance',
    'lieu_naissance', 'sexe', 'telephone', 'nationalite', 'photo_path',
    'particularites_medicales', 'orphelin_pere', 'orphelin_mere', 'quartier', 'statut',
])]
class Eleve extends Model
{
    use BelongsToEtablissement, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date_naissance' => 'date',
            'orphelin_pere' => 'boolean',
            'orphelin_mere' => 'boolean',
        ];
    }

    public function nomComplet(): string
    {
        return trim("{$this->nom} {$this->prenoms}");
    }

    public function tuteurs(): BelongsToMany
    {
        return $this->belongsToMany(Tuteur::class, 'eleve_tuteur')
            ->withPivot('lien_parente', 'is_contact_principal', 'is_payeur')
            ->withTimestamps();
    }

    public function inscriptions(): HasMany
    {
        return $this->hasMany(Inscription::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DocumentEleve::class);
    }

    public function dettes(): HasMany
    {
        return $this->hasMany(Dette::class);
    }

    public function reglements(): HasMany
    {
        return $this->hasMany(Reglement::class);
    }
}
