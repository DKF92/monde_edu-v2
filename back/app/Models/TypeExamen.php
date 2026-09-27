<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['etablissement_id', 'libelle', 'ponderation'])]
class TypeExamen extends Model
{
    use BelongsToEtablissement;

    protected $table = 'types_examens';
}
