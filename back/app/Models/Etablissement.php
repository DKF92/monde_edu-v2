<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'nom', 'sigle', 'type_etablissement', 'telephone1', 'telephone2',
    'email', 'site_web', 'boite_postale', 'quartier', 'ville', 'region', 'pays',
    'indicatif_telephonique', 'sms_actif', 'sms_expediteur', 'sms_credit', 'logo_path', 'slogan', 'statut',
    'date_expiration_abonnement', 'parametres', 'cree_par_id',
])]
class Etablissement extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'parametres' => 'array',
            'sms_actif' => 'boolean',
            'sms_credit' => 'integer',
            'date_expiration_abonnement' => 'date',
        ];
    }

    /**
     * Parametres de l'etablissement (colonne JSON "parametres") et leur
     * valeur par defaut :
     * - effectif_max_classe   : limite generale d'eleves par classe (null = aucune) ;
     * - statut_visible_classe : a partir de quand un eleve apparait dans la liste
     *   de sa classe : classe_attribuee | premier_paiement | solde.
     */
    public const PARAMETRES_DEFAUT = [
        'effectif_max_classe' => null,
        'statut_visible_classe' => 'premier_paiement',
        // Formats du matricule national : 9 = chiffre, A = lettre majuscule.
        'formats_matricule' => ['99999999A'],
        // Consultation de l'inscription en ligne (site de l'Etat) a la saisie du matricule.
        'verification_en_ligne' => true,
        // Code de l'etablissement au MENA (DESPS), compare au recu en ligne.
        'code_mena' => null,
        // Caisse (V1) : nombre de versements par inscription (le dernier solde
        // tout) et montant minimum d'un versement apres le premier (le premier
        // respecte le minimum d'inscription de la grille).
        'versements_max' => 6,
        'versement_minimum' => 1000,
        // Numerotation des classes d'un niveau (V1 : numero, "6EME 1") : chiffres | lettres ("6EME A").
        'numerotation_classes' => 'chiffres',
        // En-tete des documents (a gauche) : ministere de tutelle, direction
        // regionale, adresse postale et telephone (vides : ceux de l'etablissement).
        'entete_ministere' => "MINISTÈRE DE L'ÉDUCATION NATIONALE ET DE L'ALPHABÉTISATION",
        'entete_direction' => null,
        'entete_adresse' => null,
        'entete_telephone' => null,
    ];

    /** Lignes de gauche de l'en-tete des documents. */
    public function enteteTutelle(): array
    {
        $adresse = $this->parametre('entete_adresse')
            ?: trim(($this->boite_postale ?? '').($this->boite_postale && $this->ville && ! str_contains($this->boite_postale, $this->ville) ? ' '.$this->ville : ''));
        $telephone = $this->parametre('entete_telephone') ?: collect([$this->telephone1, $this->telephone2])->filter()->implode(' / ');

        return [
            'ministere' => $this->parametre('entete_ministere'),
            'direction' => $this->parametre('entete_direction'),
            'adresse' => $adresse ?: null,
            'telephone' => $telephone ? 'Tél : '.$telephone : null,
        ];
    }

    public function parametre(string $cle): mixed
    {
        return $this->parametres[$cle] ?? self::PARAMETRES_DEFAUT[$cle] ?? null;
    }

    public function definirParametres(array $valeurs): void
    {
        $this->parametres = array_merge($this->parametres ?? [], $valeurs);
        $this->save();
    }

    public function utilisateurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('is_defaut')
            ->withTimestamps();
    }

    public function anneesScolaires(): HasMany
    {
        return $this->hasMany(AnneeScolaire::class);
    }

    public function anneeScolaireActive(): ?AnneeScolaire
    {
        return $this->anneesScolaires()->where('is_active', true)->first();
    }

    public function matieres(): HasMany
    {
        return $this->hasMany(Matiere::class);
    }

    public function eleves(): HasMany
    {
        return $this->hasMany(Eleve::class);
    }

    public function personnels(): HasMany
    {
        return $this->hasMany(Personnel::class);
    }

    public function typesFrais(): HasMany
    {
        return $this->hasMany(TypeFrais::class);
    }
}
