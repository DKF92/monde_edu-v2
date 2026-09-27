<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['etablissement_id', 'libelle', 'montant', 'categorie', 'beneficiaire', 'payeur_id', 'piece_justificative_path', 'date_depense'])]
class Depense extends Model
{
    use BelongsToEtablissement;

    protected function casts(): array
    {
        return ['date_depense' => 'date'];
    }

    public function payeur(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'payeur_id');
    }
}
