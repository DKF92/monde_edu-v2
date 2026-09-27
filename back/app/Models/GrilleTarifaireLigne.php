<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['grille_tarifaire_id', 'type_frais_id', 'montant'])]
class GrilleTarifaireLigne extends Model
{
    public function grilleTarifaire(): BelongsTo
    {
        return $this->belongsTo(GrilleTarifaire::class);
    }

    public function typeFrais(): BelongsTo
    {
        return $this->belongsTo(TypeFrais::class);
    }
}
