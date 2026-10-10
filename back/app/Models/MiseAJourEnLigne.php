<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mise a jour d'une inscription depuis l'inscription en ligne (voir la
 * migration mises_a_jour_en_ligne) : elements appliques directement ou en
 * attente (niveau / statut affecte a reporter par l'educateur, apres
 * suppression des paiements par le caissier si besoin).
 */
#[Fillable([
    'etablissement_id', 'annee_scolaire_id', 'inscription_id', 'eleve_id', 'type', 'tentatives', 'statut_inscription',
    'changements', 'remarques', 'en_attente', 'niveau_demande_id', 'affecte_demande', 'user_id',
])]
class MiseAJourEnLigne extends Model
{
    use BelongsToEtablissement;

    protected $table = 'mises_a_jour_en_ligne';

    public const MISE_A_JOUR = 'mise_a_jour';

    /** Scan sans recu trouve sur le site de l'Etat (une ligne par inscription, mise a jour a chaque scan). */
    public const INTROUVABLE = 'introuvable';

    protected function casts(): array
    {
        return [
            'changements' => 'array',
            'remarques' => 'array',
            'en_attente' => 'boolean',
            'affecte_demande' => 'boolean',
            'statut_inscription' => 'integer',
        ];
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(Inscription::class);
    }

    public function eleve(): BelongsTo
    {
        return $this->belongsTo(Eleve::class);
    }

    public function niveauDemande(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'niveau_demande_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Le niveau de l'inscription correspond-il au niveau en ligne ? Le site de
     * l'Etat donne le niveau general (TLE) : une inscription en TLE A2 y est.
     */
    public static function memeNiveau(?int $niveauInscription, ?int $niveauEnLigne): bool
    {
        return (int) $niveauInscription === (int) $niveauEnLigne
            || ($niveauInscription && Niveau::generalId($niveauInscription) === (int) $niveauEnLigne);
    }

    /** Reste-t-il un element en attente (l'inscription n'a pas encore la valeur en ligne) ? */
    public function aTraiter(): bool
    {
        $i = $this->inscription;
        if (! $this->en_attente || ! $i) {
            return false;
        }

        return ($this->niveau_demande_id && ! self::memeNiveau($i->niveau_id, $this->niveau_demande_id))
            || ($this->affecte_demande !== null && (bool) $i->affecte !== (bool) $this->affecte_demande);
    }
}
