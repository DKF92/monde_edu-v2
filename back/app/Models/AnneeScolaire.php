<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['etablissement_id', 'libelle', 'date_debut', 'date_fin', 'is_active', 'is_cloturee'])]
class AnneeScolaire extends Model
{
    use BelongsToEtablissement;

    protected $table = 'annees_scolaires';

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'is_active' => 'boolean',
            'is_cloturee' => 'boolean',
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function periodes(): HasMany
    {
        return $this->hasMany(Periode::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(Classe::class);
    }

    public function inscriptions(): HasMany
    {
        return $this->hasMany(Inscription::class);
    }
}
