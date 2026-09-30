<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * montant_total_du/reduit/paye sont des totaux mis en cache (somme des
 * frais_eleves rattaches) : ne jamais les modifier a la main, ils sont
 * recalcules automatiquement via recalculerTotaux() a chaque paiement ou
 * reduction touchant l'une des lignes de frais de cette inscription.
 *
 * statut = V1 inscription.inscr_termine, tenu a jour par recalculerTotaux() :
 * 0 = inscription creee sans classe, 1 = classe choisie (frais copies),
 * 2 = au moins un paiement, 3 = solde. Une inscription annulee est supprimee
 * (V1 suprInscrid).
 */
#[Fillable([
    'etablissement_id', 'annee_scolaire_id', 'eleve_id', 'classe_id',
    'niveau_id', 'date_inscription', 'statut', 'redoublant', 'affecte', 'boursier',
    'langue_vivante_2', 'etablissement_origine', 'classe_origine', 'decision_origine',
    'moyenne_origine', 'decision_finale', 'moyenne_annuelle', 'niveau_a_suivre_id',
    'inscrit_en_ligne', 'inscription_en_ligne', 'enregistre_par_id',
])]
class Inscription extends Model
{
    use BelongsToEtablissement;

    public const SANS_CLASSE = 0;

    public const CLASSE_CHOISIE = 1;

    public const PAIEMENT = 2;

    public const SOLDE = 3;

    public const STATUTS = [self::SANS_CLASSE, self::CLASSE_CHOISIE, self::PAIEMENT, self::SOLDE];

    protected function casts(): array
    {
        return [
            'statut' => 'integer',
            'date_inscription' => 'datetime',
            'redoublant' => 'boolean',
            'affecte' => 'boolean',
            'boursier' => 'boolean',
            'inscrit_en_ligne' => 'boolean',
            'inscription_en_ligne' => 'array',
            'moyenne_origine' => 'float',
            'moyenne_annuelle' => 'float',
        ];
    }

    /**
     * Inscriptions affichees dans la liste d'une classe, selon le parametre
     * "statut_visible_classe" de l'etablissement (V1 inscription.inscr_termine :
     * 1 = classe attribuee par l'educateur, 2 = premier paiement, 3 = solde).
     */
    public function scopeVisiblesEnClasse(Builder $query): Builder
    {
        $statut = Etablissement::find(app(TenantContext::class)->id())?->parametre('statut_visible_classe');

        $query->whereNotNull($query->qualifyColumn('classe_id'));

        return $query->where($query->qualifyColumn('statut'), '>=', match ($statut) {
            'classe_attribuee' => self::CLASSE_CHOISIE,
            'solde' => self::SOLDE,
            default => self::PAIEMENT,
        });
    }

    public function eleve(): BelongsTo
    {
        return $this->belongsTo(Eleve::class);
    }

    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    public function niveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class);
    }

    public function niveauASuivre(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'niveau_a_suivre_id');
    }

    public function enregistrePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enregistre_par_id');
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
        $this->statut = $this->statutCalcule();
        $this->save();
    }

    /** Etape V1 (inscr_termine) deduite de la classe et des paiements. */
    public function statutCalcule(): int
    {
        if (! $this->classe_id) {
            return self::SANS_CLASSE;
        }
        if ((float) $this->montant_total_paye <= 0) {
            return self::CLASSE_CHOISIE;
        }

        return $this->resteAPayer() <= 0 ? self::SOLDE : self::PAIEMENT;
    }
}
