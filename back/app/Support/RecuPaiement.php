<?php

namespace App\Support;

use App\Models\Etablissement;
use App\Models\Reglement;
use App\Models\ReglementLigne;

/**
 * Recu de paiement (A4, deux exemplaires : parent / etablissement),
 * regenere a chaque demande a partir de la base. La situation imprimee est
 * celle du moment du versement (paiements jusqu'a ce recu inclus), le recu
 * est donc toujours identique.
 */
class RecuPaiement
{
    /** Donnees de la vue pdf.recu-paiement (voir Document::repondre). */
    public static function donnees(Reglement $reglement, Etablissement $etablissement): array
    {
        $reglement->loadMissing(['eleve', 'inscription.classe', 'inscription.niveau', 'anneeScolaire', 'caissier']);
        $inscription = $reglement->inscription;

        $lignes = $reglement->lignes()->with(['fraisEleve.typeFrais', 'dette.anneeScolaire'])->get()
            ->map(fn (ReglementLigne $l) => [
                'libelle' => $l->fraisEleve?->typeFrais?->libelle ?? trim('Dette '.($l->dette?->anneeScolaire?->libelle ?? 'année précédente')),
                'montant' => (int) $l->montant,
            ]);

        $situation = null;
        if ($inscription) {
            $payeCumule = (int) ReglementLigne::join('reglements', 'reglements.id', '=', 'reglement_lignes.reglement_id')
                ->join('frais_eleves', 'frais_eleves.id', '=', 'reglement_lignes.frais_eleve_id')
                ->where('frais_eleves.inscription_id', $inscription->id)
                ->where('reglements.id', '<=', $reglement->id)
                ->sum('reglement_lignes.montant');
            $du = (int) $inscription->montant_total_du;
            $reduit = (int) $inscription->montant_total_reduit;
            $situation = ['du' => $du, 'reduit' => $reduit, 'paye' => $payeCumule, 'reste' => max(0, $du - $reduit - $payeCumule)];
        }

        return Document::entete($etablissement) + [
            'r' => $reglement,
            'lignes' => $lignes,
            'situation' => $situation,
            'modes' => ['especes' => 'Espèces', 'mobile_money' => 'Mobile money', 'cheque' => 'Chèque', 'virement' => 'Virement'],
            'versement' => $reglement->numero_versement
                ? ($reglement->numero_versement === 1 ? '1er versement' : $reglement->numero_versement.'e versement')
                : 'Règlement de dette',
        ];
    }

    public static function montant(int $valeur): string
    {
        // Espace insecable fine : lisible et ne se coupe pas en fin de ligne.
        return number_format($valeur, 0, ',', "\u{202F}").' F CFA';
    }
}
