<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SMS envoye (V1 table "sms"). */
#[Fillable([
    'etablissement_id', 'eleve_id', 'numero', 'message', 'campagne', 'nombre_sms',
    'statut', 'erreur', 'envoye_par_id',
])]
class SmsMessage extends Model
{
    use BelongsToEtablissement;

    protected $table = 'sms_messages';

    public function eleve(): BelongsTo
    {
        return $this->belongsTo(Eleve::class);
    }

    public function envoyePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'envoye_par_id');
    }
}
