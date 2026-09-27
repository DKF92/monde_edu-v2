<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['echeancier_id', 'numero_versement', 'date_limite', 'montant'])]
class EcheancierLigne extends Model
{
    protected function casts(): array
    {
        return ['date_limite' => 'date'];
    }

    public function echeancier(): BelongsTo
    {
        return $this->belongsTo(Echeancier::class);
    }
}
