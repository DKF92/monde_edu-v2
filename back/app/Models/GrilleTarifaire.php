<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['etablissement_id', 'annee_scolaire_id', 'niveau_id', 'affecte', 'montant_minimum_inscription'])]
class GrilleTarifaire extends Model
{
    use BelongsToEtablissement;

    protected $table = 'grilles_tarifaires';

    protected function casts(): array
    {
        return [
            'affecte' => 'boolean',
            'montant_minimum_inscription' => 'integer',
        ];
    }

    public function anneeScolaire(): BelongsTo
    {
        return $this->belongsTo(AnneeScolaire::class);
    }

    public function niveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(GrilleTarifaireLigne::class);
    }
}
