<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sortie de caisse (V1 table "depense") : date, libelle, categorie, montant,
 * mode, beneficiaire (V1 receveur), piece comptable (reference) et
 * justificatif scanne, saisie par payeur_id (V1 payeur_dep = compte connecte).
 * numero : "D2627-00001" (annee + ordre dans l'etablissement).
 */
#[Fillable([
    'etablissement_id', 'annee_scolaire_id', 'numero', 'libelle', 'montant', 'mode_paiement', 'categorie',
    'beneficiaire', 'piece_comptable', 'payeur_id', 'piece_justificative_path', 'date_depense',
])]
class Depense extends Model
{
    use BelongsToEtablissement;

    /** Categories (V1 : "SALAIRE" et les autres depenses). */
    public const CATEGORIES = [
        'salaires' => 'Salaires',
        'fournitures' => 'Fournitures et matériel',
        'entretien' => 'Entretien et réparations',
        'factures' => 'Eau, électricité, internet',
        'transport' => 'Transport et déplacements',
        'activites' => 'Activités et événements',
        'autres' => 'Autres dépenses',
    ];

    public const MODES = ['especes' => 'Espèces', 'mobile_money' => 'Mobile money', 'cheque' => 'Chèque', 'virement' => 'Virement'];

    protected function casts(): array
    {
        return ['date_depense' => 'date'];
    }

    /** Compte qui a enregistre la sortie (V1 payeur_dep). */
    public function payeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payeur_id');
    }

    public function anneeScolaire(): BelongsTo
    {
        return $this->belongsTo(AnneeScolaire::class);
    }
}
