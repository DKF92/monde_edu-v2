<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['eleve_id', 'classe_id', 'periode_id', 'moyenne_generale', 'rang_classe', 'rang_niveau', 'mention', 'decision'])]
class MoyenneGenerale extends Model
{
    protected $table = 'moyennes_generales';

    public function eleve(): BelongsTo
    {
        return $this->belongsTo(Eleve::class);
    }

    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }
}
