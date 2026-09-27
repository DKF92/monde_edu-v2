<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne = le montant d'un reglement affecte a UN frais precis ou a une
 * dette (un meme reglement peut donc regler plusieurs frais a la fois, voir
 * Reglement). Chaque ecriture ici recalcule automatiquement le montant_paye
 * (en cache) du frais_eleve/de la dette concerne, et le total de l'inscription.
 */
#[Fillable(['reglement_id', 'frais_eleve_id', 'dette_id', 'montant'])]
class ReglementLigne extends Model
{
    protected static function booted(): void
    {
        static::created(fn (self $ligne) => $ligne->recalculerCibles());
        static::updated(fn (self $ligne) => $ligne->recalculerCibles());
        static::deleted(fn (self $ligne) => $ligne->recalculerCibles());
    }

    public function reglement(): BelongsTo
    {
        return $this->belongsTo(Reglement::class);
    }

    public function fraisEleve(): BelongsTo
    {
        return $this->belongsTo(FraisEleve::class);
    }

    public function dette(): BelongsTo
    {
        return $this->belongsTo(Dette::class);
    }

    private function recalculerCibles(): void
    {
        if ($this->frais_eleve_id && $fraisEleve = FraisEleve::find($this->frais_eleve_id)) {
            $fraisEleve->recalculerMontantPaye();
            $fraisEleve->inscription->recalculerTotaux();
        }

        if ($this->dette_id && $dette = Dette::find($this->dette_id)) {
            $dette->recalculerMontantPaye();
        }
    }
}
