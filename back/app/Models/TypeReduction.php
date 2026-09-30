<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Type de reduction parametrable. Reprend les 2 cas de la V1
 * (pages/Reduction/new_reduc.php + controllers/reductionController.php) :
 *
 * - "Reduction" (is_cas=1) : ne porte que sur les frais principaux
 *   (inscription pour un eleve affecte, scolarite sinon), 1 000 F minimum ;
 * - "Cas" (is_cas=2) : peut aller jusqu'a la totalite de ce qui reste du,
 *   annexes comprises, mais doit au moins couvrir le reste des frais
 *   principaux (V1 : minimum = reste inscription + reste scolarite + 5).
 */
#[Fillable([
    'etablissement_id', 'code', 'libelle', 'base', 'montant_minimum',
    'minimum_frais_principaux', 'plafond_pourcentage', 'is_active',
])]
class TypeReduction extends Model
{
    use BelongsToEtablissement;

    protected $table = 'types_reductions';

    public const STANDARDS = [
        [
            'code' => 'REDUCTION',
            'libelle' => 'Réduction',
            'base' => 'frais_principaux',
            'montant_minimum' => 1000,
            'minimum_frais_principaux' => false,
            'plafond_pourcentage' => null,
            'is_active' => true,
        ],
        [
            'code' => 'CAS',
            'libelle' => 'Cas social',
            'base' => 'total',
            'montant_minimum' => 0,
            'minimum_frais_principaux' => true,
            'plafond_pourcentage' => null,
            'is_active' => true,
        ],
    ];

    protected function casts(): array
    {
        return [
            'montant_minimum' => 'integer',
            'minimum_frais_principaux' => 'boolean',
            'plafond_pourcentage' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Bornes du montant de reduction autorise pour une inscription, a partir
     * de ce qui reste a payer. A utiliser par l'ecran des reductions.
     *
     * @return array{min: int, max: int}
     */
    public function bornes(Inscription $inscription): array
    {
        $restes = $inscription->fraisEleves()->with('typeFrais')->get()
            ->groupBy(fn (FraisEleve $f) => in_array($f->typeFrais?->nature, ['inscription', 'scolarite'], true) ? 'principaux' : 'annexes')
            ->map(fn ($lignes) => (int) $lignes->sum(fn (FraisEleve $f) => max(0, $f->montant_du - $f->montant_reduit - $f->montant_paye)));

        $principaux = $restes['principaux'] ?? 0;
        $base = $this->base === 'total' ? $principaux + ($restes['annexes'] ?? 0) : $principaux;

        $max = $this->plafond_pourcentage !== null ? intdiv($base * $this->plafond_pourcentage, 100) : $base;
        $min = max($this->montant_minimum, $this->minimum_frais_principaux ? $principaux + 1 : 0);

        return ['min' => min($min, $max), 'max' => $max];
    }
}
