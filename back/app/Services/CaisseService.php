<?php

namespace App\Services;

use App\Models\AnneeScolaire;
use App\Models\Dette;
use App\Models\Eleve;
use App\Models\Etablissement;
use App\Models\FraisEleve;
use App\Models\GrilleTarifaire;
use App\Models\Inscription;
use App\Models\Reglement;
use App\Models\Tuteur;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Encaissement, iso V1 (controllers/reglementController.php, action create) :
 * - le versement est reparti sur les frais restant a payer, dans l'ordre des
 *   types de frais (types_frais.ordre, V1 type_frais_ordre) ;
 * - un montant a part regle les dettes (plus ancienne annee d'abord) ;
 * - 1er versement >= minimum d'inscription de la grille, suivants >= minimum
 *   de l'etablissement, jamais plus que le reste ; au dernier versement
 *   autorise (6 en V1) le reste doit etre solde ;
 * - statut de l'inscription : 2 (paiement entame) ou 3 (solde) ;
 * - SMS au parent apres l'enregistrement (V1 reglementFacade::create).
 */
class CaisseService
{
    public function __construct(private readonly SmsService $sms) {}

    /** Frais en argent restant a payer, dans l'ordre de priorite V1. */
    public function fraisAPayer(Inscription $inscription): Collection
    {
        return $inscription->fraisEleves()->with('typeFrais')->whereNull('quantite_due')->get()
            ->sortBy(fn (FraisEleve $f) => [$f->typeFrais->ordre, $f->id])
            ->filter(fn (FraisEleve $f) => $f->resteAPayer() > 0)
            ->values();
    }

    /** Dettes des annees precedentes encore ouvertes (plus ancienne d'abord). */
    public function dettesOuvertes(Eleve $eleve): Collection
    {
        return Dette::with('anneeScolaire')->where('eleve_id', $eleve->id)->where('is_annulee', false)->get()
            ->filter(fn (Dette $d) => $d->resteAPayer() > 0)
            ->sortBy(fn (Dette $d) => $d->anneeScolaire?->libelle)
            ->values();
    }

    public function numeroVersementSuivant(Inscription $inscription): int
    {
        return (int) Reglement::where('inscription_id', $inscription->id)->max('numero_versement') + 1;
    }

    /**
     * Bornes du prochain versement : minimum (grille au 1er versement, sinon
     * parametre), maximum = reste, et obligation de solder au dernier.
     *
     * @return array{numero: int, maximum: int, minimum: int, doit_solder: bool, versements_max: int}
     */
    public function bornes(Inscription $inscription, Etablissement $etablissement, ?int $numero = null): array
    {
        // Modification d'un paiement : bornes de SON numero de versement.
        $numero ??= $this->numeroVersementSuivant($inscription);
        $reste = (int) max(0, $inscription->resteAPayer());
        $max = (int) $etablissement->parametre('versements_max');
        $minimum = $numero === 1
            ? (int) (GrilleTarifaire::where('annee_scolaire_id', $inscription->annee_scolaire_id)
                ->where('niveau_id', $inscription->niveau_id)->where('affecte', $inscription->affecte)
                ->value('montant_minimum_inscription') ?? $etablissement->parametre('versement_minimum'))
            : (int) $etablissement->parametre('versement_minimum');
        $doitSolder = $max > 0 && $numero >= $max;

        return [
            'numero' => $numero,
            'maximum' => $reste,
            'minimum' => $doitSolder ? $reste : min($minimum, $reste),
            // Minimum de la regle, avant plafonnement par le reste (modification d'un paiement).
            'minimum_regle' => $minimum,
            'doit_solder' => $doitSolder,
            'versements_max' => $max,
        ];
    }

    /**
     * @param  array{montant: int, montant_dette: int, mode_paiement: string, date_paiement: ?string, date_expiration: ?string, fournitures: array}  $saisie
     */
    public function encaisser(Inscription $inscription, Etablissement $etablissement, array $saisie, int $caissierId): Reglement
    {
        $montant = (int) $saisie['montant'];
        $montantDette = (int) ($saisie['montant_dette'] ?? 0);
        $eleve = $inscription->eleve;

        if (! $inscription->classe_id) {
            throw ValidationException::withMessages(['montant' => ['Placez d\'abord l\'élève dans une classe : ses frais seront alors fixés.']]);
        }
        if ($montant <= 0 && $montantDette <= 0) {
            throw ValidationException::withMessages(['montant' => ['Saisissez le montant versé.']]);
        }

        return DB::transaction(function () use ($inscription, $etablissement, $saisie, $caissierId, $montant, $montantDette, $eleve) {
            // Verrou : deux caisses ne peuvent pas encaisser la meme inscription en meme temps.
            $inscription = Inscription::whereKey($inscription->id)->lockForUpdate()->firstOrFail();
            $bornes = $this->bornes($inscription, $etablissement);
            $dettes = $this->verifierMontants($inscription, $bornes, $montant, $montantDette);

            $reglement = Reglement::create([
                'eleve_id' => $eleve->id,
                'annee_scolaire_id' => $inscription->annee_scolaire_id,
                'inscription_id' => $inscription->id,
                'caissier_id' => $caissierId,
                'numero_recu' => $this->numeroRecu($inscription->annee_scolaire_id),
                'numero_versement' => $montant > 0 ? $bornes['numero'] : null,
                'montant_total' => $montant + $montantDette,
                'mode_paiement' => $saisie['mode_paiement'] ?? 'especes',
                'date_paiement' => $saisie['date_paiement'] ?? now(),
                'date_expiration' => $saisie['date_expiration'] ?? null,
            ]);
            $this->repartir($reglement, $inscription, $dettes, $montant, $montantDette);

            // Fournitures en nature rapportees au passage en caisse (V1 craie / rame).
            $lignesNature = $inscription->fraisEleves()->whereNotNull('quantite_due')->get()->keyBy('id');
            foreach ($saisie['fournitures'] ?? [] as $f) {
                if ($ligne = $lignesNature[$f['id']] ?? null) {
                    $ligne->update(['quantite_remise' => min((int) $f['quantite_remise'], $ligne->quantite_due)]);
                }
            }

            $inscription->refresh()->recalculerTotaux();

            return $reglement;
        });
    }

    /**
     * Regles du versement (V1) : reste, minimum (1er versement = grille,
     * suivants = parametre), dernier versement autorise = solde ; dette <=
     * reste des dettes. Rend les dettes ouvertes (plus ancienne d'abord).
     */
    private function verifierMontants(Inscription $inscription, array $bornes, int $montant, int $montantDette): Collection
    {
        if ($montant <= 0 && $montantDette <= 0) {
            throw ValidationException::withMessages(['montant' => ['Saisissez le montant versé.']]);
        }
        if ($montant > 0) {
            $format = fn (int $v) => number_format($v, 0, ',', ' ').' F';
            if ($bornes['maximum'] <= 0) {
                throw ValidationException::withMessages(['montant' => ['Les frais de cette inscription sont déjà soldés.']]);
            }
            if ($montant > $bornes['maximum']) {
                throw ValidationException::withMessages(['montant' => ['Le montant dépasse le reste à payer ('.$format($bornes['maximum']).').']]);
            }
            if ($bornes['doit_solder'] && $montant !== $bornes['maximum']) {
                throw ValidationException::withMessages(['montant' => ["Versement n° {$bornes['numero']} (dernier autorisé) : il doit solder le reste de ".$format($bornes['maximum']).'.']]);
            }
            if ($montant < $bornes['minimum']) {
                throw ValidationException::withMessages(['montant' => [($bornes['numero'] === 1 ? 'Premier versement' : 'Versement').' minimum : '.$format($bornes['minimum']).'.']]);
            }
        }

        $dettes = $this->dettesOuvertes($inscription->eleve);
        $resteDettes = (int) $dettes->sum(fn (Dette $d) => $d->resteAPayer());
        if ($montantDette > $resteDettes) {
            throw ValidationException::withMessages(['montant_dette' => [$resteDettes > 0
                ? 'Le montant dépasse le reste des dettes ('.number_format($resteDettes, 0, ',', ' ').' F).'
                : 'Cet élève n\'a pas de dette.']]);
        }

        return $dettes;
    }

    /** Dettes d'abord (V1), puis frais par ordre de priorite. */
    private function repartir(Reglement $reglement, Inscription $inscription, Collection $dettes, int $montant, int $montantDette): void
    {
        $aRepartir = $montantDette;
        foreach ($dettes as $dette) {
            $part = (int) min($aRepartir, $dette->resteAPayer());
            if ($part > 0) {
                $reglement->lignes()->create(['dette_id' => $dette->id, 'montant' => $part]);
                $aRepartir -= $part;
            }
        }
        $aRepartir = $montant;
        foreach ($this->fraisAPayer($inscription) as $frais) {
            $part = (int) min($aRepartir, $frais->resteAPayer());
            if ($part > 0) {
                $reglement->lignes()->create(['frais_eleve_id' => $frais->id, 'montant' => $part]);
                $aRepartir -= $part;
            }
        }
    }

    // ------------------------------------------------------------ Modification / suppression

    /**
     * Corrige un paiement (motif obligatoire) : ses lignes sont retirees, les
     * montants revalides avec les regles de SON versement puis repartis a
     * nouveau ; l'etat avant/apres est historise. Le numero de recu ne change pas.
     *
     * @param  array{montant: int, montant_dette: ?int, mode_paiement: string, date_paiement: ?string, date_expiration: ?string}  $saisie
     */
    public function modifier(Reglement $reglement, Etablissement $etablissement, array $saisie, string $motif, int $userId): Reglement
    {
        return DB::transaction(function () use ($reglement, $etablissement, $saisie, $motif, $userId) {
            $reglement = Reglement::whereKey($reglement->id)->lockForUpdate()->firstOrFail();
            $inscription = Inscription::whereKey($reglement->inscription_id)->lockForUpdate()->firstOrFail();
            $avant = $this->instantane($reglement);

            $this->retirerLignes($reglement);
            $inscription->refresh();

            $montant = (int) $saisie['montant'];
            $montantDette = (int) ($saisie['montant_dette'] ?? 0);
            // Numero : celui du paiement ; un paiement de dette seule qui regle des frais prend le suivant.
            $numero = $montant > 0 ? ($reglement->numero_versement ?? $this->numeroVersementSuivant($inscription)) : null;
            $dettes = $this->verifierMontants($inscription, $this->bornes($inscription, $etablissement, $numero ?? 1), $montant, $montantDette);

            $reglement->update([
                'numero_versement' => $numero,
                'montant_total' => $montant + $montantDette,
                'mode_paiement' => $saisie['mode_paiement'],
                'date_paiement' => $saisie['date_paiement'] ?? $reglement->date_paiement,
                'date_expiration' => $saisie['date_expiration'] ?? null,
            ]);
            $this->repartir($reglement, $inscription, $dettes, $montant, $montantDette);
            $inscription->refresh()->recalculerTotaux();

            $this->historiser($reglement, 'modification', $motif, $avant, $this->instantane($reglement->fresh()), $userId);

            return $reglement->fresh();
        });
    }

    /** Supprime un paiement (motif obligatoire) : montants payes recalcules, trace dans l'historique. */
    public function supprimer(Reglement $reglement, string $motif, int $userId): void
    {
        DB::transaction(function () use ($reglement, $motif, $userId) {
            $reglement = Reglement::whereKey($reglement->id)->lockForUpdate()->firstOrFail();
            $avant = $this->instantane($reglement);
            $this->retirerLignes($reglement);
            $this->historiser($reglement, 'suppression', $motif, $avant, null, $userId);
            $reglement->delete();
            if ($reglement->inscription_id && $inscription = Inscription::find($reglement->inscription_id)) {
                $inscription->recalculerTotaux();
            }
        });
    }

    /** Une par une : chaque suppression recalcule le paye du frais / de la dette concerne. */
    private function retirerLignes(Reglement $reglement): void
    {
        foreach ($reglement->lignes()->get() as $ligne) {
            $ligne->delete();
        }
    }

    private function instantane(Reglement $reglement): array
    {
        $reglement->loadMissing('lignes.fraisEleve.typeFrais');

        return [
            'numero_recu' => $reglement->numero_recu,
            'numero_versement' => $reglement->numero_versement,
            'montant_total' => (int) $reglement->montant_total,
            'mode_paiement' => $reglement->mode_paiement,
            'date_paiement' => $reglement->date_paiement?->toDateTimeString(),
            'date_expiration' => $reglement->date_expiration?->toDateString(),
            'caissier_id' => $reglement->caissier_id,
            'lignes' => $reglement->lignes->map(fn ($l) => [
                'frais_eleve_id' => $l->frais_eleve_id,
                'dette_id' => $l->dette_id,
                'libelle' => $l->fraisEleve?->typeFrais?->libelle ?? 'Dette',
                'montant' => (int) $l->montant,
            ])->values()->all(),
        ];
    }

    private function historiser(Reglement $reglement, string $action, string $motif, array $avant, ?array $apres, int $userId): void
    {
        DB::table('reglements_historique')->insert([
            'etablissement_id' => $reglement->etablissement_id,
            'reglement_id' => $reglement->id,
            'numero_recu' => $reglement->numero_recu,
            'eleve_id' => $reglement->eleve_id,
            'action' => $action,
            'motif' => mb_substr(trim($motif), 0, 500),
            'avant' => json_encode($avant, JSON_UNESCAPED_UNICODE),
            'apres' => $apres ? json_encode($apres, JSON_UNESCAPED_UNICODE) : null,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** "2627-00001" : code de l'annee + numero d'ordre dans l'etablissement. */
    private function numeroRecu(int $anneeId): string
    {
        $libelle = AnneeScolaire::find($anneeId)?->libelle ?? '';
        $prefixe = preg_match('/^\d{2}(\d{2})-\d{2}(\d{2})$/', $libelle, $m) ? $m[1].$m[2] : (string) $anneeId;
        $dernier = Reglement::where('numero_recu', 'like', $prefixe.'-%')->lockForUpdate()->max('numero_recu');
        $suivant = $dernier ? ((int) substr($dernier, strlen($prefixe) + 1)) + 1 : 1;

        return $prefixe.'-'.str_pad((string) $suivant, 5, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------ SMS

    /**
     * SMS au parent (V1) : "Matricule / Nom / Classe / Versement / Reste /
     * Date". Ne bloque jamais l'encaissement : le resultat est rendu a l'ecran.
     *
     * @return array{statut: string, message: string, numero?: string}
     */
    public function envoyerSms(Reglement $reglement, Etablissement $etablissement, int $userId): array
    {
        $reglement->loadMissing(['eleve.tuteurs', 'inscription.classe']);
        $eleve = $reglement->eleve;
        $numero = $this->numeroParent($eleve);
        if (! $numero) {
            return ['statut' => 'sans_numero', 'message' => 'Aucun numéro de parent : SMS non envoyé.'];
        }

        $inscription = $reglement->inscription;
        $texte = "Matricule: {$eleve->matricule} \nNom: ".mb_substr($eleve->nom.' '.$eleve->prenoms, 0, 20)
            ." \nClasse: ".($inscription?->classe?->libelle ?? '-')
            ." \nVersement: ".number_format((int) $reglement->montant_total, 0, ',', ' ').' F.CFA'
            ." \nReste: ".number_format((int) max(0, $inscription?->resteAPayer() ?? 0), 0, ',', ' ').' F.CFA'
            ." \nDate: ".$reglement->date_paiement->format('d/m/Y H:i')
            ."\nBonne annee scolaire !!!";
        $campagne = $reglement->numero_versement
            ? ($reglement->numero_versement === 1 ? '1er' : $reglement->numero_versement.'e').' Versement'
            : 'Reglement dette';

        if (config('services.sms.simulation')) {
            $this->sms->envoyer($etablissement, [['numero' => $numero, 'eleve_id' => $eleve->id]], $texte, $campagne, $userId);

            return ['statut' => 'simule', 'message' => "SMS simulé (mode test) vers {$numero}.", 'numero' => $numero];
        }
        if (! $etablissement->sms_actif) {
            return ['statut' => 'desactive', 'message' => 'Envoi de SMS désactivé pour l\'établissement.'];
        }
        if ($etablissement->sms_credit <= 0) {
            return ['statut' => 'credit', 'message' => 'Crédit SMS épuisé : SMS non envoyé.'];
        }
        try {
            $this->sms->envoyer($etablissement, [['numero' => $numero, 'eleve_id' => $eleve->id]], $texte, $campagne, $userId);

            return ['statut' => 'envoye', 'message' => "SMS envoyé au {$numero}.", 'numero' => $numero];
        } catch (Throwable $e) {
            return ['statut' => 'echec', 'message' => $e->getMessage()];
        }
    }

    /** Contact principal, sinon pere, mere, tuteur (V1). */
    private function numeroParent(Eleve $eleve): ?string
    {
        $tuteurs = $eleve->tuteurs->filter(fn (Tuteur $t) => filled($t->telephone1));
        $principal = $tuteurs->first(fn (Tuteur $t) => $t->pivot->is_contact_principal);
        if ($principal) {
            return $principal->telephone1;
        }
        foreach (['pere', 'mere', 'tuteur_legal'] as $lien) {
            if ($t = $tuteurs->first(fn (Tuteur $t) => $t->pivot->lien_parente === $lien)) {
                return $t->telephone1;
            }
        }

        return $tuteurs->first()?->telephone1 ?? $eleve->telephone;
    }
}
