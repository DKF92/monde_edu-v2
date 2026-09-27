<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * montant_total_du/reduit/paye sont des totaux mis en cache (somme des
 * frais_eleves rattaches) : ne jamais les modifier a la main, ils sont
 * recalcules automatiquement via recalculerTotaux() a chaque paiement ou
 * reduction touchant l'une des lignes de frais de cette inscription.
 */
#[Fillable([
    'etablissement_id', 'annee_scolaire_id', 'eleve_id', 'classe_id',
    'date_inscription', 'statut', 'redoublant', 'affecte', 'boursier',
    'etablissement_origine', 'classe_origine', 'enregistre_par_id',
])]
class Inscription extends Model
{
    use BelongsToEtablissement;

    protected function casts(): array
    {
        return [
            'date_inscription' => 'datetime',
            'redoublant' => 'boolean',
            'affecte' => 'boolean',
            'boursier' => 'boolean',
        ];
    }

    public function eleve(): BelongsTo
    {
        return $this->belongsTo(Eleve::class);
    }

    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    public function anneeScolaire(): BelongsTo
    {
        return $this->belongsTo(AnneeScolaire::class);
    }

    public function fraisEleves(): HasMany
    {
        return $this->hasMany(FraisEleve::class);
    }

    public function resteAPayer(): float
    {
        return (float) $this->montant_total_du - (float) $this->montant_total_reduit - (float) $this->montant_total_paye;
    }

    public function recalculerTotaux(): void
    {
        $this->montant_total_du = (float) $this->fraisEleves()->sum('montant_du');
        $this->montant_total_reduit = (float) $this->fraisEleves()->sum('montant_reduit');
        $this->montant_total_paye = (float) $this->fraisEleves()->sum('montant_paye');
        $this->save();
    }
}
