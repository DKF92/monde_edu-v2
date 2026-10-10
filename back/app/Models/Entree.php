<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entree banque (nouveaute V2) : argent recu sur un compte bancaire de
 * l'etablissement. La banque tient la place du beneficiaire des depenses.
 * Les entrees caisse sont les paiements des eleves (reglements) : elles ne
 * sont pas saisies ici.
 * numero : "E2627-00001" (annee + ordre dans l'etablissement).
 */
#[Fillable([
    'etablissement_id', 'annee_scolaire_id', 'numero', 'date_entree', 'banque_id', 'categorie', 'libelle', 'montant', 'mode_paiement',
    'provenance', 'verse_par_id', 'piece_comptable', 'piece_justificative_path', 'saisi_par_id',
])]
class Entree extends Model
{
    use BelongsToEtablissement;

    protected $table = 'entrees';

    public const CATEGORIES = [
        'versement_caisse' => 'Versement de la caisse',
        'subventions' => 'Subventions de l\'État',
        'dons' => 'Dons et partenariats',
        'activites' => 'Recettes d\'activités',
        'virements_parents' => 'Virements des parents',
        'autres' => 'Autres entrées',
    ];

    public const MODES = Depense::MODES;

    public function banque(): BelongsTo
    {
        return $this->belongsTo(Banque::class);
    }

    /** Personne qui a fait le versement a la banque. */
    public function versePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verse_par_id');
    }

    /** Compte qui a enregistre l'entree. */
    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par_id');
    }

    public function anneeScolaire(): BelongsTo
    {
        return $this->belongsTo(AnneeScolaire::class);
    }

    protected function casts(): array
    {
        return ['date_entree' => 'date'];
    }
}
