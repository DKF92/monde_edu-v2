<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Achat de credit SMS (V1 rechargement_sms). */
#[Fillable(['etablissement_id', 'date_rechargement', 'montant', 'nombre_sms', 'commentaire', 'enregistre_par_id'])]
class SmsRechargement extends Model
{
    use BelongsToEtablissement;

    protected $table = 'sms_rechargements';

    protected function casts(): array
    {
        return ['date_rechargement' => 'datetime', 'montant' => 'integer', 'nombre_sms' => 'integer'];
    }

    public function enregistrePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enregistre_par_id');
    }
}
