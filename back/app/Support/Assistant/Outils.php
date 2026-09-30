<?php

namespace App\Support\Assistant;

use App\Http\Controllers\Api\RapportController;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Dette;
use App\Models\Eleve;
use App\Models\Etablissement;
use App\Models\Inscription;
use App\Models\Periode;
use App\Models\Reglement;
use App\Models\TypeFrais;
use App\Support\ArretNotes;
use App\Support\PorteeFinances;
use App\Support\PorteePedagogique;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Outils de l'assistant : consultation seule (aucune ecriture), dans la
 * portee de l'utilisateur connecte (droits du poste, enfants d'un parent,
 * classes d'un professeur ou d'un educateur).
 */
class Outils
{
    private const STATUTS = [0 => 'inscrit, en attente de classe', 1 => 'en attente du 1er versement', 2 => 'en attente de solder', 3 => 'soldé'];

    private int $anneeId;

    public function __construct(private Request $request, private TenantContext $tenant)
    {
        $this->anneeId = PorteePedagogique::anneeId($tenant);
    }

    /** Definitions envoyees au modele (API Messages, "tools"). */
    public static function definitions(): array
    {
        $vide = ['type' => 'object', 'properties' => new \stdClass()];

        return [
            ['name' => 'mes_droits', 'description' => 'Poste, droits (permissions) de l\'utilisateur connecté, établissement, année et période de travail. À utiliser quand un bouton ou un menu manque, ou pour adapter la réponse.', 'input_schema' => $vide],
            ['name' => 'rechercher_eleves', 'description' => 'Recherche des élèves inscrits cette année par matricule, nom ou prénoms (10 résultats au plus).', 'input_schema' => [
                'type' => 'object', 'properties' => ['recherche' => ['type' => 'string', 'description' => 'Matricule, nom ou prénoms']], 'required' => ['recherche']]],
            ['name' => 'dossier_eleve', 'description' => 'Situation d\'un élève par son matricule : inscription de l\'année (niveau, classe, statut), et si l\'utilisateur a le droit de voir les paiements : montants dus, réduits, payés, reste, dettes et derniers paiements.', 'input_schema' => [
                'type' => 'object', 'properties' => ['matricule' => ['type' => 'string']], 'required' => ['matricule']]],
            ['name' => 'infos_classe', 'description' => 'Informations d\'une classe de l\'année par son libellé (ex. 6EME A) : effectif, filles, garçons, affectés, professeur principal, éducateur, salle.', 'input_schema' => [
                'type' => 'object', 'properties' => ['classe' => ['type' => 'string']], 'required' => ['classe']]],
            ['name' => 'etat_notes', 'description' => 'État de la saisie des notes d\'une période : pour chaque classe, matières notées, nombre de notes, matières arrêtées, et si la période est clôturée.', 'input_schema' => [
                'type' => 'object', 'properties' => ['periode' => ['type' => 'string', 'description' => 'Libellé de la période (ex. 1er trimestre) ; vide : période active']]]],
            ['name' => 'parametres_ecole', 'description' => 'Réglages de l\'établissement : année et périodes (dates, clôture), versements, ordre de paiement des frais, numérotation des classes.', 'input_schema' => $vide],
            ['name' => 'statistiques', 'description' => 'Chiffres clés de l\'année : élèves, filles, affectés, classes, notes, absences et, avec le droit de voir les paiements, encaissements du jour et du mois et reste à recouvrer.', 'input_schema' => $vide],
            ['name' => 'consulter_guide', 'description' => 'Fiche d\'aide détaillée d\'une tâche de l\'application (menu, étapes, conseils, droit nécessaire).', 'input_schema' => [
                'type' => 'object', 'properties' => ['fiche' => ['type' => 'string', 'enum' => array_keys(Guide::FICHES)]], 'required' => ['fiche']]],
            ['name' => 'montrer_demo', 'description' => 'Affiche à l\'utilisateur la vidéo de démonstration d\'une tâche. À utiliser quand l\'utilisateur ne trouve pas comment faire ou le demande.', 'input_schema' => [
                'type' => 'object', 'properties' => ['demo' => ['type' => 'string', 'enum' => array_keys(Guide::DEMOS)]], 'required' => ['demo']]],
        ];
    }

    /** Libelle affiche pendant l'execution d'un outil. */
    public static function libelle(string $nom): string
    {
        return match ($nom) {
            'mes_droits' => 'Vérification de vos droits…',
            'rechercher_eleves', 'dossier_eleve' => 'Consultation du dossier de l\'élève…',
            'infos_classe' => 'Consultation de la classe…',
            'etat_notes' => 'Consultation des notes…',
            'parametres_ecole' => 'Consultation des paramètres…',
            'statistiques' => 'Consultation des chiffres de l\'année…',
            'consulter_guide' => 'Lecture du guide…',
            'montrer_demo' => 'Préparation de la démonstration…',
            default => 'Consultation…',
        };
    }

    public function executer(string $nom, array $entree): array
    {
        try {
            return match ($nom) {
                'mes_droits' => $this->mesDroits(),
                'rechercher_eleves' => $this->rechercherEleves((string) ($entree['recherche'] ?? '')),
                'dossier_eleve' => $this->dossierEleve((string) ($entree['matricule'] ?? '')),
                'infos_classe' => $this->infosClasse((string) ($entree['classe'] ?? '')),
                'etat_notes' => $this->etatNotes($entree['periode'] ?? null),
                'parametres_ecole' => $this->parametresEcole(),
                'statistiques' => $this->statistiques(),
                'consulter_guide' => Guide::fiche((string) ($entree['fiche'] ?? '')) ?? ['erreur' => 'Fiche inconnue.'],
                'montrer_demo' => isset(Guide::DEMOS[$entree['demo'] ?? '']) ? ['affichee' => true, 'titre' => Guide::DEMOS[$entree['demo']][0]] : ['erreur' => 'Démonstration inconnue.'],
                default => ['erreur' => 'Outil inconnu.'],
            };
        } catch (\Throwable $e) {
            report($e);

            return ['erreur' => 'Consultation impossible.'];
        }
    }

    // ------------------------------------------------------------ Outils

    private function mesDroits(): array
    {
        $user = $this->request->user();
        $poste = PorteePedagogique::poste($this->request);
        $libelles = config('roles.libelles', []);
        $periode = $this->tenant->periodeId() ? Periode::find($this->tenant->periodeId()) : null;

        return [
            'utilisateur' => trim($user->name.' '.$user->prenoms),
            'poste' => $poste?->name,
            'etablissement' => Etablissement::find($this->tenant->id())?->nom,
            'annee' => AnneeScolaire::find($this->anneeId)?->libelle,
            'periode' => $periode?->libelle,
            'droits' => $user->getAllPermissions()->pluck('name')->map(fn ($p) => ($libelles[$p][0] ?? $p).' ('.$p.')')->values()->all(),
        ];
    }

    private function rechercherEleves(string $recherche): array
    {
        if (! $this->peut('eleves.voir') && ! $this->peut('inscriptions.gerer')) {
            return ['refus' => 'Votre poste n\'a pas le droit de consulter les élèves.'];
        }
        $recherche = trim($recherche);
        if (mb_strlen($recherche) < 2) {
            return ['erreur' => 'Recherche trop courte.'];
        }
        $lignes = $this->inscriptions()
            ->where(fn ($q) => $q->where('e.matricule', 'like', $recherche.'%')->orWhere('e.nom', 'like', '%'.$recherche.'%')
                ->orWhere('e.prenoms', 'like', '%'.$recherche.'%')->orWhereRaw("CONCAT(e.nom, ' ', e.prenoms) LIKE ?", ['%'.$recherche.'%']))
            ->limit(10)->get(['e.matricule', 'e.nom', 'e.prenoms', 'c.libelle as classe', 'n.libelle as niveau', 'i.statut']);

        return ['nombre' => $lignes->count(), 'eleves' => $lignes->map(fn ($l) => [
            'matricule' => $l->matricule, 'nom' => $l->nom.' '.$l->prenoms, 'classe' => $l->classe ?? 'sans classe ('.$l->niveau.')', 'statut' => self::STATUTS[$l->statut] ?? null,
        ])->all()];
    }

    private function dossierEleve(string $matricule): array
    {
        if (! $this->peut('eleves.voir') && ! $this->peut('inscriptions.gerer') && ! $this->peut('reglements.voir')) {
            return ['refus' => 'Votre poste n\'a pas le droit de consulter les élèves.'];
        }
        $matricule = strtoupper(trim($matricule));
        $ligne = $this->inscriptions()->where('e.matricule', $matricule)
            ->first(['i.id', 'i.eleve_id', 'e.matricule', 'e.nom', 'e.prenoms', 'e.sexe', 'e.date_naissance', 'c.libelle as classe', 'n.libelle as niveau',
                'i.affecte', 'i.redoublant', 'i.statut', 'i.date_inscription', 'i.montant_total_du', 'i.montant_total_reduit', 'i.montant_total_paye']);
        if (! $ligne) {
            $existe = Eleve::where('matricule', $matricule)->exists();

            return ['trouve' => false, 'message' => $existe
                ? 'Cet élève existe mais n\'est pas inscrit cette année (ou ne fait pas partie de vos élèves).'
                : 'Aucun élève avec ce matricule dans l\'établissement.'];
        }
        $dossier = [
            'trouve' => true,
            'eleve' => ['matricule' => $ligne->matricule, 'nom' => $ligne->nom.' '.$ligne->prenoms, 'sexe' => $ligne->sexe === 'F' ? 'fille' : 'garçon',
                'date_naissance' => $ligne->date_naissance],
            'inscription' => ['niveau' => $ligne->niveau, 'classe' => $ligne->classe ?? 'pas encore placé en classe', 'affecte' => (bool) $ligne->affecte,
                'redoublant' => (bool) $ligne->redoublant, 'statut' => self::STATUTS[$ligne->statut] ?? null, 'date_inscription' => $ligne->date_inscription],
        ];
        if ($this->peut('reglements.voir') || $this->peut('reglements.encaisser')) {
            $du = (int) $ligne->montant_total_du;
            $reduit = (int) $ligne->montant_total_reduit;
            $paye = (int) $ligne->montant_total_paye;
            $dettes = Dette::where('eleve_id', $ligne->eleve_id)->where('is_annulee', false)->get();
            $dossier['finances'] = [
                'montant_du' => $du, 'reduction' => $reduit, 'paye' => $paye, 'reste' => max(0, $du - $reduit - $paye),
                'dettes_reste' => (int) $dettes->sum(fn (Dette $d) => max(0, $d->montant - $d->montant_reduit - $d->montant_paye)),
                'derniers_paiements' => Reglement::where('eleve_id', $ligne->eleve_id)->orderByDesc('date_paiement')->limit(5)
                    ->get(['numero_recu', 'date_paiement', 'montant_total', 'mode_paiement'])
                    ->map(fn ($r) => ['recu' => $r->numero_recu, 'date' => $r->date_paiement?->format('d/m/Y'), 'montant' => (int) $r->montant_total, 'mode' => $r->mode_paiement])->all(),
                'devise' => 'F CFA',
            ];
        } else {
            $dossier['finances'] = 'non communiquées (droit « Voir les paiements » absent)';
        }

        return $dossier;
    }

    private function infosClasse(string $libelle): array
    {
        if (! $this->peut('classes.voir') && ! $this->peut('classes.gerer') && ! $this->peut('notes.saisir') && ! $this->peut('notes.voir')) {
            return ['refus' => 'Votre poste n\'a pas le droit de consulter les classes.'];
        }
        $normal = strtoupper(preg_replace('/\s+/', ' ', trim($libelle)));
        $classe = Classe::where('annee_scolaire_id', $this->anneeId)->whereRaw('UPPER(libelle) = ?', [$normal])->first();
        if (! $classe || ! $this->classeAutorisee($classe->id)) {
            return ['trouve' => false, 'classes_existantes' => Classe::where('annee_scolaire_id', $this->anneeId)
                ->when(PorteePedagogique::classes($this->request, $this->anneeId) !== null, fn ($q) => $q->whereIn('id', PorteePedagogique::classes($this->request, $this->anneeId)))->orderBy('libelle')->pluck('libelle')->all()];
        }
        $nom = fn (?int $personnelId) => $personnelId ? DB::table('personnels as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.id', $personnelId)->selectRaw("TRIM(CONCAT(u.name, ' ', COALESCE(u.prenoms, ''))) AS nom")->value('nom') : null;
        $eff = DB::table('inscriptions as i')->join('eleves as e', 'e.id', '=', 'i.eleve_id')->where('i.classe_id', $classe->id)->whereNull('e.deleted_at')
            ->selectRaw("COUNT(*) AS total, SUM(e.sexe = 'F') AS filles, SUM(e.sexe = 'M') AS garcons, SUM(i.affecte = 1) AS affectes, SUM(i.redoublant = 1) AS redoublants")->first();

        return ['trouve' => true, 'classe' => $classe->libelle, 'effectif' => (int) $eff->total, 'filles' => (int) $eff->filles, 'garcons' => (int) $eff->garcons,
            'affectes' => (int) $eff->affectes, 'redoublants' => (int) $eff->redoublants, 'capacite' => $classe->capacite, 'salle' => $classe->salle,
            'professeur_principal' => $nom($classe->professeur_principal_id), 'educateur' => $nom($classe->educateur_id), 'lv2' => $classe->langue_vivante_2];
    }

    private function etatNotes(?string $libelle): array
    {
        if (! $this->peut('notes.voir') && ! $this->peut('notes.saisir') && ! $this->peut('moyennes.gerer') && ! $this->peut('notes.arreter')) {
            return ['refus' => 'Votre poste n\'a pas le droit de consulter les notes.'];
        }
        $periodes = Periode::where('annee_scolaire_id', $this->anneeId)->orderBy('numero')->get();
        $periode = $libelle ? $periodes->first(fn (Periode $p) => str_contains(mb_strtolower($p->libelle), mb_strtolower(trim($libelle)))) : null;
        $periode ??= $periodes->firstWhere('is_active', true) ?? $periodes->first();
        if (! $periode) {
            return ['erreur' => 'Aucune période pour l\'année en cours.'];
        }
        $autorisees = PorteePedagogique::classes($this->request, $this->anneeId);

        return ['periode' => $periode->libelle, 'cloturee' => (bool) $periode->is_cloturee,
            'classes' => collect(ArretNotes::etat($this->anneeId, $periode))->filter(fn ($c) => $autorisees === null || in_array($c['id'], $autorisees, true))
                ->map(fn ($c) => ['classe' => $c['libelle'], 'effectif' => $c['effectif'], 'matieres' => $c['matieres'], 'matieres_notees' => $c['matieres_notees'],
                    'notes' => $c['notes'], 'matieres_arretees' => $c['matieres_arretees']])->values()->all()];
    }

    private function parametresEcole(): array
    {
        $e = Etablissement::findOrFail($this->tenant->id());
        $annee = AnneeScolaire::find($this->anneeId);

        return [
            'annee' => ['libelle' => $annee?->libelle, 'cloturee' => (bool) $annee?->is_cloturee],
            'periodes' => Periode::where('annee_scolaire_id', $this->anneeId)->orderBy('numero')->get()
                ->map(fn (Periode $p) => ['libelle' => $p->libelle, 'debut' => $p->date_debut?->format('d/m/Y'), 'fin' => $p->date_fin?->format('d/m/Y'),
                    'active' => (bool) $p->is_active, 'cloturee' => (bool) $p->is_cloturee])->all(),
            'versements_max' => $e->parametre('versements_max'),
            'versement_minimum' => $e->parametre('versement_minimum'),
            'numerotation_classes' => $e->parametre('numerotation_classes'),
            'effectif_max_classe' => $e->parametre('effectif_max_classe'),
            'ordre_de_paiement' => TypeFrais::orderBy('ordre')->pluck('libelle')->all(),
        ];
    }

    private function statistiques(): array
    {
        $stats = app(RapportController::class)->indicateurs($this->anneeId);
        if ($this->peut('reglements.voir') && PorteeFinances::elevesDuParent($this->request) === null) {
            $base = Reglement::where('annee_scolaire_id', $this->anneeId);
            $stats['encaisse_aujourdhui'] = (int) (clone $base)->whereDate('date_paiement', today())->sum('montant_total');
            $stats['encaisse_ce_mois'] = (int) (clone $base)->whereYear('date_paiement', now()->year)->whereMonth('date_paiement', now()->month)->sum('montant_total');
            $stats['reste_a_recouvrer'] = (int) Inscription::where('annee_scolaire_id', $this->anneeId)->whereNotNull('classe_id')
                ->sum(DB::raw('GREATEST(montant_total_du - montant_total_reduit - montant_total_paye, 0)'));
            $stats['devise'] = 'F CFA';
        }

        return $stats;
    }

    // ------------------------------------------------------------ Portee

    /** Inscriptions de l'annee visibles par l'utilisateur. */
    private function inscriptions()
    {
        $enfants = PorteeFinances::elevesDuParent($this->request);
        $classes = PorteePedagogique::classes($this->request, $this->anneeId);

        return DB::table('inscriptions as i')->join('eleves as e', 'e.id', '=', 'i.eleve_id')
            ->join('niveaux as n', 'n.id', '=', 'i.niveau_id')->leftJoin('classes as c', 'c.id', '=', 'i.classe_id')
            ->where('i.etablissement_id', $this->tenant->id())->where('i.annee_scolaire_id', $this->anneeId)->whereNull('e.deleted_at')
            ->when($enfants !== null, fn ($q) => $q->whereIn('i.eleve_id', $enfants ?: [0]))
            ->when($classes !== null && $enfants === null, fn ($q) => $q->whereIn('i.classe_id', $classes ?: [0]))
            ->orderBy('e.nom')->orderBy('e.prenoms');
    }

    private function classeAutorisee(int $classeId): bool
    {
        $classes = PorteePedagogique::classes($this->request, $this->anneeId);

        return $classes === null || in_array($classeId, $classes, true);
    }

    private function peut(string $droit): bool
    {
        return $this->request->user()->can($droit);
    }
}
