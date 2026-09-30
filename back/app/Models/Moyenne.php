<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['eleve_id', 'classe_id', 'matiere_id', 'periode_id', 'moyenne', 'coefficient', 'rang', 'appreciation', 'is_arretee', 'saisi_par_id'])]
class Moyenne extends Model
{
    protected function casts(): array
    {
        return ['is_arretee' => 'boolean'];
    }

    public function eleve(): BelongsTo
    {
        return $this->belongsTo(Eleve::class);
    }

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }
}
