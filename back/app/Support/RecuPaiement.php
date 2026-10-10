<?php

namespace App\Support;

use App\Models\Etablissement;
use App\Models\Reglement;
use App\Models\ReglementLigne;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

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
            'billet' => self::billet($reglement, $etablissement, $situation),
            'r' => $reglement,
            'lignes' => $lignes,
            'situation' => $situation,
            'modes' => ['especes' => 'Espèces', 'mobile_money' => 'Mobile money', 'cheque' => 'Chèque', 'virement' => 'Virement'],
            'versement' => $reglement->numero_versement
                ? ($reglement->numero_versement === 1 ? '1er versement' : $reglement->numero_versement.'e versement')
                : 'Règlement de dette',
        ];
    }

    /** Paiement a moins de N jours de la date d'echeance : echeance calculee (paiement + jours). */
    public const SEUIL_ECHEANCE_PROCHE = 15;

    /**
     * Billet d'autorisation d'entree en classe (V1), imprime a la suite du recu
     * d'un paiement de frais. Eleve non solde : valable jusqu'a la date saisie a
     * la caisse, sinon jusqu'a la date d'echeance (Parametres paiements) ; si le
     * paiement est a moins de 15 jours de l'echeance (ou apres, ou sans date) :
     * paiement + nombre de jours. Solde : jusqu'en fin d'annee. Pas de billet
     * pour un reglement de dette seule.
     */
    private static function billet(Reglement $reglement, Etablissement $etablissement, ?array $situation): ?array
    {
        $inscription = $reglement->inscription;
        if (! $inscription || ! $reglement->numero_versement) {
            return null;
        }
        $solde = $situation !== null && $situation['reste'] <= 0;
        // Date du recu si elle suit le paiement (la V1 en a d'anterieures au paiement), sinon le delai parametre.
        $dateRecu = $reglement->date_expiration && $reglement->date_expiration->gte($reglement->date_paiement->copy()->startOfDay()) ? $reglement->date_expiration : null;
        $paiement = $reglement->date_paiement->copy()->startOfDay();
        $echeance = $etablissement->parametre('date_validite_billet') ? Carbon::parse($etablissement->parametre('date_validite_billet'))->startOfDay() : null;
        if ($echeance && $paiement->diffInDays($echeance, false) < self::SEUIL_ECHEANCE_PROCHE) {
            $echeance = null;
        }
        $jusquau = $solde ? null : ($dateRecu ?? $echeance
            ?? $paiement->copy()->addDays(max(1, (int) $etablissement->parametre('delai_validite_recu'))));

        $photo = null;
        foreach ([$reglement->eleve?->photo_path, $etablissement->parametre('photo_eleve_defaut_path')] as $chemin) {
            if ($chemin && Storage::disk('public')->exists($chemin)) {
                $fichier = Storage::disk('public')->path($chemin);
                $photo = 'data:'.(mime_content_type($fichier) ?: 'image/jpeg').';base64,'.base64_encode(file_get_contents($fichier));
                break;
            }
        }

        return [
            'classe' => $inscription->classe?->libelle ?? $inscription->niveau?->libelle,
            'affecte' => (bool) $inscription->affecte,
            'solde' => $solde,
            'jusquau' => $jusquau,
            'photo' => $photo,
            'ville' => $etablissement->ville,
        ];
    }

    public static function montant(int $valeur): string
    {
        // Espace insecable fine : lisible et ne se coupe pas en fin de ligne.
        return number_format($valeur, 0, ',', "\u{202F}").' F CFA';
    }
}
