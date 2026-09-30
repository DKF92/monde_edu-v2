<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'etablissement_id', 'diplome', 'statut_contrat', 'date_embauche',
    'matiere_principale_id', 'nationalite', 'quartier',
])]
class Personnel extends Model
{
    use BelongsToEtablissement;

    protected $table = 'personnels';

    protected function casts(): array
    {
        return ['date_embauche' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matierePrincipale(): BelongsTo
    {
        return $this->belongsTo(Matiere::class, 'matiere_principale_id');
    }

    /** Matieres enseignees (V1 administration.matiere_prof). */
    public function matieres(): BelongsToMany
    {
        return $this->belongsToMany(Matiere::class, 'personnel_matieres')->withTimestamps();
    }

    /** Niveaux suivis par un educateur, toutes annees (V1 table "choix"). */
    public function niveaux(): BelongsToMany
    {
        return $this->belongsToMany(Niveau::class, 'educateur_niveaux')
            ->withPivot('annee_scolaire_id', 'etablissement_id')
            ->withTimestamps();
    }

    /** Niveaux suivis pour une annee donnee. */
    public function niveauxDeLAnnee(int $anneeScolaireId): BelongsToMany
    {
        return $this->niveaux()->wherePivot('annee_scolaire_id', $anneeScolaireId);
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(AffectationEnseignant::class);
    }

    public function classesEducateur(): HasMany
    {
        return $this->hasMany(Classe::class, 'educateur_id');
    }

    public function classesProfesseurPrincipal(): HasMany
    {
        return $this->hasMany(Classe::class, 'professeur_principal_id');
    }
}
