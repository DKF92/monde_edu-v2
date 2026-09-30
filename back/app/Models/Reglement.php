<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versement en caisse (V1 reglement) : montant total reparti en lignes sur
 * les frais de l'inscription (ordre des types de frais) et/ou les dettes.
 * numero_versement = 1er, 2e... versement de l'inscription (V1 num_reglement) ;
 * date_expiration = date de validite du recu (V1).
 */
#[Fillable([
    'etablissement_id', 'eleve_id', 'annee_scolaire_id', 'inscription_id', 'caissier_id',
    'numero_recu', 'numero_versement', 'montant_total', 'mode_paiement', 'date_paiement', 'date_expiration',
])]
class Reglement extends Model
{
    use BelongsToEtablissement;

    protected function casts(): array
    {
        return ['date_paiement' => 'datetime', 'date_expiration' => 'date'];
    }

    public function eleve(): BelongsTo
    {
        return $this->belongsTo(Eleve::class);
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(Inscription::class);
    }

    public function anneeScolaire(): BelongsTo
    {
        return $this->belongsTo(AnneeScolaire::class);
    }

    /** Compte qui a encaisse (V1 mat_admin). */
    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caissier_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(ReglementLigne::class);
    }
}
