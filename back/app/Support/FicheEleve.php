<?php

namespace App\Support;

use App\Models\Eleve;
use App\Models\Tuteur;

/** Presentation de la fiche d'un eleve (inscriptions, dossier eleve). */
class FicheEleve
{
    public static function photoUrl(Eleve $eleve): ?string
    {
        return $eleve->photo_path ? url('storage/'.$eleve->photo_path) : null;
    }

    public static function presenter(Eleve $eleve): array
    {
        return [
            'id' => $eleve->id,
            'matricule' => $eleve->matricule,
            'nom' => $eleve->nom,
            'prenoms' => $eleve->prenoms,
            'sexe' => $eleve->sexe,
            'date_naissance' => $eleve->date_naissance?->toDateString(),
            'lieu_naissance' => $eleve->lieu_naissance,
            'nationalite' => $eleve->nationalite,
            'telephone' => $eleve->telephone,
            'quartier' => $eleve->quartier,
            'particularites_medicales' => $eleve->particularites_medicales,
            'orphelin_pere' => (bool) $eleve->orphelin_pere,
            'orphelin_mere' => (bool) $eleve->orphelin_mere,
            'statut' => $eleve->statut,
            'photo_url' => self::photoUrl($eleve),
            'parents' => $eleve->tuteurs->map(fn (Tuteur $t) => [
                'lien_parente' => $t->pivot->lien_parente,
                'nom_complet' => trim("{$t->nom} {$t->prenoms}"),
                'telephone' => $t->telephone1,
                'profession' => $t->profession,
                'is_contact_principal' => (bool) $t->pivot->is_contact_principal,
                'is_payeur' => (bool) $t->pivot->is_payeur,
            ])->values(),
        ];
    }
}
