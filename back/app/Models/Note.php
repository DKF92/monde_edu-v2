<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'eleve_id', 'classe_id', 'matiere_id', 'periode_id', 'type_examen_id',
    'personnel_id', 'valeur', 'coefficient', 'date_evaluation',
])]
class Note extends Model
{
    protected function casts(): array
    {
        return ['date_evaluation' => 'date'];
    }

    public function eleve(): BelongsTo
    {
        return $this->belongsTo(Eleve::class);
    }

    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    public function typeExamen(): BelongsTo
    {
        return $this->belongsTo(TypeExamen::class);
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
