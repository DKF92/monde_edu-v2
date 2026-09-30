<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ce qu'un eleve doit payer pour un type de frais donne, sur son inscription.
 * montant_reduit et montant_paye sont des totaux mis en cache : ne jamais les
 * modifier a la main, ils sont recalcules par Reduction et ReglementLigne a
 * chaque ecriture (voir recalculerMontantReduit()/recalculerMontantPaye()).
 *
 * Frais en nature (V1 craie / rame) : montant_du = 0 et quantite_due = nombre
 * d'unites a apporter, quantite_remise = ce que l'eleve a deja remis.
 */
#[Fillable(['inscription_id', 'type_frais_id', 'montant_du', 'quantite_due', 'quantite_remise'])]
class FraisEleve extends Model
{
    public function inscription(): BelongsTo
    {
        return $this->belongsTo(Inscription::class);
    }

    public function typeFrais(): BelongsTo
    {
        return $this->belongsTo(TypeFrais::class);
    }

    public function reductions(): HasMany
    {
        return $this->hasMany(Reduction::class);
    }

    public function reglementLignes(): HasMany
    {
        return $this->hasMany(ReglementLigne::class);
    }

    public function resteAPayer(): float
    {
        return (float) $this->montant_du - (float) $this->montant_reduit - (float) $this->montant_paye;
    }

    public function recalculerMontantReduit(): void
    {
        $this->montant_reduit = (float) $this->reductions()->where('is_active', true)->sum('montant');
        $this->save();
    }

    public function recalculerMontantPaye(): void
    {
        $this->montant_paye = (float) $this->reglementLignes()->sum('montant');
        $this->save();
    }
}
