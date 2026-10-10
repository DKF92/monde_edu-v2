<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Referentiel global, commun a tous les etablissements (voir migration).
 *
 * Modele V1 (decision E1) : le NIVEAU porte la serie au 2nd cycle (TLE A2)
 * et pointe vers son NIVEAU GENERAL (TLE) ; au 1er cycle le niveau est son
 * propre niveau general. Classes, inscriptions, coefficients, limites et
 * listes deroulantes : niveau. Grilles de tarifs, niveaux des educateurs,
 * inscription en ligne : niveau general.
 */
#[Fillable(['niveau_general_id', 'code', 'libelle', 'cycle', 'ordre'])]
class Niveau extends Model
{
    protected $table = 'niveaux';

    /** @var array<int, int>|null niveau => niveau general */
    private static ?array $generaux = null;

    public function classes(): HasMany
    {
        return $this->hasMany(Classe::class);
    }

    public function general(): BelongsTo
    {
        return $this->belongsTo(self::class, 'niveau_general_id');
    }

    public function series(): HasMany
    {
        return $this->hasMany(self::class, 'niveau_general_id');
    }

    public function matieres(): BelongsToMany
    {
        return $this->belongsToMany(Matiere::class, 'matiere_niveau')
            ->withPivot('coefficient', 'is_obligatoire')
            ->withTimestamps();
    }

    /** Niveaux de classe (listes deroulantes) : les series et les niveaux sans serie. */
    public function scopeDeClasse(Builder $query): Builder
    {
        return $query->whereNotIn('id', self::query()->whereNotNull('niveau_general_id')->select('niveau_general_id'));
    }

    /** Niveaux generaux (grilles de tarifs, educateurs). */
    public function scopeGeneraux(Builder $query): Builder
    {
        return $query->whereNull('niveau_general_id');
    }

    /** Tri des listes : progression, puis serie. */
    public function scopeOrdonnes(Builder $query): Builder
    {
        return $query->orderBy('ordre')->orderBy('libelle');
    }

    /** Niveau general d'un niveau (lui-meme au 1er cycle). */
    public static function generalId(?int $niveauId): ?int
    {
        if ($niveauId === null) {
            return null;
        }
        self::$generaux ??= self::query()->pluck('niveau_general_id', 'id')->map(fn ($g, $id) => (int) ($g ?: $id))->all();

        return self::$generaux[$niveauId] ?? $niveauId;
    }

    /**
     * Niveaux couverts par des niveaux generaux (ex. educateur de TLE : TLE,
     * TLE A1, TLE A2, TLE D).
     *
     * @param  array<int>  $generaux
     * @return array<int>
     */
    public static function avecSeries(array $generaux): array
    {
        if ($generaux === []) {
            return [];
        }

        return self::query()->whereIn('id', $generaux)->orWhereIn('niveau_general_id', $generaux)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
