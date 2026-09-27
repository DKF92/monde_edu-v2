<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une reduction cible soit une ligne de frais, soit une dette (exclusivite
 * validee au niveau applicatif, ex: FormRequest / model boot()). Chaque
 * ecriture ici recalcule automatiquement le montant_reduit (en cache) du
 * frais_eleve/de la dette concerne, et le total de l'inscription.
 */
#[Fillable(['etablissement_id', 'frais_eleve_id', 'dette_id', 'montant', 'motif', 'accorde_par_id', 'is_active'])]
class Reduction extends Model
{
    use BelongsToEtablissement;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::created(fn (self $reduction) => $reduction->recalculerCibles());
        static::updated(fn (self $reduction) => $reduction->recalculerCibles());
        static::deleted(fn (self $reduction) => $reduction->recalculerCibles());
    }

    public function fraisEleve(): BelongsTo
    {
        return $this->belongsTo(FraisEleve::class);
    }

    public function dette(): BelongsTo
    {
        return $this->belongsTo(Dette::class);
    }

    public function accordePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accorde_par_id');
    }

    private function recalculerCibles(): void
    {
        if ($this->frais_eleve_id && $fraisEleve = FraisEleve::find($this->frais_eleve_id)) {
            $fraisEleve->recalculerMontantReduit();
            $fraisEleve->inscription->recalculerTotaux();
        }

        if ($this->dette_id && $dette = Dette::find($this->dette_id)) {
            $dette->recalculerMontantReduit();
        }
    }
}
