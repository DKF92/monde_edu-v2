<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * montant_reduit et montant_paye sont des totaux mis en cache : ne jamais les
 * modifier a la main (voir recalculerMontantReduit()/recalculerMontantPaye()).
 */
#[Fillable(['etablissement_id', 'eleve_id', 'annee_scolaire_id', 'montant', 'is_annulee', 'enregistre_par_id'])]
class Dette extends Model
{
    use BelongsToEtablissement;

    protected function casts(): array
    {
        return ['is_annulee' => 'boolean'];
    }

    public function eleve(): BelongsTo
    {
        return $this->belongsTo(Eleve::class);
    }

    public function anneeScolaire(): BelongsTo
    {
        return $this->belongsTo(AnneeScolaire::class);
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
        return (float) $this->montant - (float) $this->montant_reduit - (float) $this->montant_paye;
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
