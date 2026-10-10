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

    /**
     * Dettes prises en compte pour une annee (par defaut l'annee de travail) :
     * celles des N annees precedentes, N = parametre dettes_annees de
     * l'etablissement (1 = seulement le reste de l'annee n-1). Les dettes plus
     * anciennes restent en base mais ne comptent plus.
     */
    public function scopePrisesEnCompte(Builder $query, ?int $anneeId = null): Builder
    {
        $annees = self::anneesPrisesEnCompte($anneeId);

        return $annees === null ? $query : $query->whereIn($query->qualifyColumn('annee_scolaire_id'), $annees);
    }

    /** @return list<int>|null annees dont la dette compte (null : annee inconnue, pas de filtre) */
    public static function anneesPrisesEnCompte(?int $anneeId = null): ?array
    {
        static $memo = [];
        $anneeId ??= app(TenantContext::class)->anneeScolaireId() ?? AnneeScolaire::where('is_active', true)->value('id');
        if (! $anneeId) {
            return null;
        }
        if (array_key_exists($anneeId, $memo)) {
            return $memo[$anneeId];
        }
        $annee = AnneeScolaire::withoutGlobalScopes()->find($anneeId);
        if (! $annee) {
            return $memo[$anneeId] = null;
        }
        $nombre = max(1, (int) Etablissement::withoutGlobalScopes()->find($annee->etablissement_id)?->parametre('dettes_annees'));
        $debut = (int) substr($annee->libelle, 0, 4);

        return $memo[$anneeId] = AnneeScolaire::withoutGlobalScopes()->where('etablissement_id', $annee->etablissement_id)->get(['id', 'libelle'])
            ->filter(fn (AnneeScolaire $a) => ($an = (int) substr($a->libelle, 0, 4)) < $debut && $an >= $debut - $nombre)
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
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
