<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['etablissement_id', 'code', 'libelle', 'nature', 'ordre', 'is_obligatoire'])]
class TypeFrais extends Model
{
    use BelongsToEtablissement;

    protected $table = 'types_frais';

    protected function casts(): array
    {
        return ['is_obligatoire' => 'boolean'];
    }
}
