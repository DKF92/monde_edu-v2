<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Type de frais (colonnes cout_* de la V1).
 * - is_active : un type desactive n'apparait plus dans les montants d'inscription ;
 * - applicable_affecte / applicable_non_affecte : le frais concerne-t-il ce
 *   type d'eleve (ex : pas de scolarite pour un eleve affecte) ;
 * - nature en_nature (V1 craie / rame) : fourniture apportee par l'eleve, la
 *   grille porte une quantite et non un montant (hors total et minimum).
 */
#[Fillable([
    'etablissement_id', 'code', 'libelle', 'nature', 'ordre', 'is_obligatoire',
    'is_active', 'applicable_affecte', 'applicable_non_affecte',
])]
class TypeFrais extends Model
{
    use BelongsToEtablissement;

    protected $table = 'types_frais';

    protected function casts(): array
    {
        return [
            'is_obligatoire' => 'boolean',
            'is_active' => 'boolean',
            'applicable_affecte' => 'boolean',
            'applicable_non_affecte' => 'boolean',
        ];
    }

    public function applicable(bool $affecte): bool
    {
        return $this->is_active && ($affecte ? $this->applicable_affecte : $this->applicable_non_affecte);
    }

    /**
     * Montant minimum a verser a l'inscription, calcule a partir des montants
     * saisis : affecte = total des frais annexes ; non affecte = total des
     * frais annexes + frais d'inscription.
     *
     * @param  iterable<self>  $types
     * @param  array<int, int>  $montants  montant par id de type
     */
    public static function montantMinimum(iterable $types, array $montants, bool $affecte): int
    {
        $total = 0;
        foreach ($types as $type) {
            $compte = $type->nature === 'annexe' || (! $affecte && $type->nature === 'inscription');
            if ($compte && $type->applicable($affecte)) {
                $total += (int) ($montants[$type->id] ?? 0);
            }
        }

        return $total;
    }
}
