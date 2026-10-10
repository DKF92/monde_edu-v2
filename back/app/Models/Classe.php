<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'etablissement_id', 'annee_scolaire_id', 'niveau_id', 'libelle', 'salle',
    'capacite', 'langue_vivante_2', 'professeur_principal_id', 'educateur_id',
])]
class Classe extends Model
{
    use BelongsToEtablissement;

    public function anneeScolaire(): BelongsTo
    {
        return $this->belongsTo(AnneeScolaire::class);
    }

    public function niveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class);
    }

    public function professeurPrincipal(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'professeur_principal_id');
    }

    public function educateur(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'educateur_id');
    }

    public function inscriptions(): HasMany
    {
        return $this->hasMany(Inscription::class);
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(AffectationEnseignant::class);
    }

    public function emploiDuTemps(): HasMany
    {
        return $this->hasMany(EmploiDuTemps::class);
    }

    /** Eleves visibles dans la liste de la classe (voir Inscription::scopeVisiblesEnClasse). */
    public function effectif(): int
    {
        return $this->inscriptions()->visiblesEnClasse()->count();
    }

    /**
     * Effectif maximum de la classe : la limite la plus precise l'emporte
     * (classe, puis niveau, puis etablissement), null = pas de limite.
     *
     * @return array{limite: ?int, source: ?string}
     */
    /** @var array<int, array{niveaux: array<int, int>, general: mixed}> */
    private static array $limites = [];

    /** A appeler apres une modification des limites (Parametres > Classes). */
    public static function oublierLimites(): void
    {
        self::$limites = [];
    }

    public function effectifLimite(): array
    {
        if ($this->capacite) {
            return ['limite' => (int) $this->capacite, 'source' => 'classe'];
        }

        // Limites lues une fois par requete (listes de classes : une requete par classe sinon).
        $cle = (int) $this->etablissement_id;
        self::$limites[$cle] ??= [
            'niveaux' => LimiteEffectifNiveau::where('etablissement_id', $cle)->pluck('effectif_max', 'niveau_id')->all(),
            'general' => Etablissement::find($cle)?->parametre('effectif_max_classe'),
        ];
        $niveau = self::$limites[$cle]['niveaux'][$this->niveau_id] ?? null;
        if ($niveau) {
            return ['limite' => (int) $niveau, 'source' => 'niveau'];
        }

        $general = self::$limites[$cle]['general'];

        return $general ? ['limite' => (int) $general, 'source' => 'etablissement'] : ['limite' => null, 'source' => null];
    }
}
