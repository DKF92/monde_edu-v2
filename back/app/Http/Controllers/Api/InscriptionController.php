<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Dette;
use App\Models\Eleve;
use App\Models\Etablissement;
use App\Models\FraisEleve;
use App\Models\GrilleTarifaire;
use App\Models\Inscription;
use App\Models\MiseAJourEnLigne;
use App\Models\Niveau;
use App\Models\Personnel;
use App\Models\Tuteur;
use App\Models\TypeFrais;
use App\Services\InscriptionEnLigneService;
use App\Support\Document;
use App\Support\FicheEleve;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inscriptions (droit "inscriptions.gerer", V1 : l'educateur), iso V1
 * EleveFacade::finaliserInscr :
 * 1. recherche par matricule : nouvel eleve / reinscription / deja inscrit ;
 *    en reinscription, decision de fin d'annee saisie si elle manque et dette
 *    creee si l'annee precedente n'est pas soldee ;
 * 2. fiche eleve + parents ;
 * 3. choix de la classe (effectif maximum respecte) : les montants de la
 *    grille (niveau + affecte/non affecte) sont copies dans l'inscription ;
 * 4. la caisse encaisse ensuite (statut V1 inscr_termine : 0 sans classe,
 *    1 classe choisie, 2 premier paiement, 3 solde).
 * Annuler = supprimer l'inscription (V1 suprInscrid) : la fiche de l'eleve
 * reste, il peut etre reinscrit par "Nouvelle inscription".
 *
 * Un poste lie aux niveaux (educateur) ne voit que les niveaux qui lui sont
 * attribues pour l'annee (aucun niveau attribue = aucun niveau).
 */
class InscriptionController extends Controller
{
    public const DECISIONS = ['ADMIS', 'REDOUBLE', 'EXCLU'];

    public const LANGUES = ['Allemand', 'Espagnol'];

    /** Mise a jour en ligne : inscriptions verifiees par appel (le site de l'Etat est lent). */
    private const LOT_EN_LIGNE = 4;

    // ------------------------------------------------------------ Liste

    public function index(Request $request, TenantContext $tenant)
    {
        $this->autoriserLecture($request);

        $anneeId = $this->anneeId($tenant);
        $niveaux = $this->niveauxAutorises($request, $tenant);

        $base = Inscription::where('inscriptions.annee_scolaire_id', $anneeId)
            ->when($niveaux !== null, fn (Builder $q) => $q->whereIn('inscriptions.niveau_id', $niveaux))
            ->when($request->integer('niveau_id'), fn (Builder $q, $id) => $q->where('inscriptions.niveau_id', $id))
            ->when($request->integer('classe_id'), fn (Builder $q, $id) => $q->where('inscriptions.classe_id', $id))
            ->when($request->filled('affecte'), fn (Builder $q) => $q->where('inscriptions.affecte', $request->boolean('affecte')))
            ->when($request->filled('redoublant'), fn (Builder $q) => $q->where('inscriptions.redoublant', $request->boolean('redoublant')))
            ->when($request->filled('en_ligne'), fn (Builder $q) => $q->where('inscriptions.inscrit_en_ligne', $request->boolean('en_ligne')))
            ->when(in_array($request->query('sexe'), ['M', 'F'], true), fn (Builder $q) => $q->whereIn('inscriptions.eleve_id', Eleve::where('sexe', $request->query('sexe'))->select('id')))
            ->when($request->filled('photo'), fn (Builder $q) => $request->boolean('photo')
                ? $q->whereIn('inscriptions.eleve_id', Eleve::whereNotNull('photo_path')->where('photo_path', '<>', '')->select('id'))
                : $q->whereNotIn('inscriptions.eleve_id', Eleve::whereNotNull('photo_path')->where('photo_path', '<>', '')->select('id')))
            ->when($request->string('recherche')->trim()->isNotEmpty(), function (Builder $q) use ($request) {
                $recherche = $request->string('recherche')->trim()->value();
                $q->whereHas('eleve', fn (Builder $e) => $e->where(fn (Builder $w) => $w
                    ->where('matricule', 'like', "%{$recherche}%")
                    ->orWhere('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenoms', 'like', "%{$recherche}%")
                    ->orWhereRaw("CONCAT(nom, ' ', prenoms) LIKE ?", ["%{$recherche}%"])));
            });

        $compteurs = (clone $base)->selectRaw('statut, COUNT(*) AS total')->groupBy('statut')->pluck('total', 'statut');

        $page = (clone $base)
            ->when($request->filled('statut'), fn (Builder $q) => $q->where('inscriptions.statut', $request->integer('statut')))
            ->with(['eleve', 'niveau:id,libelle,ordre', 'classe:id,libelle', 'enregistrePar:id,name,prenoms'])
            ->join('eleves', 'eleves.id', '=', 'inscriptions.eleve_id')
            // Non inscrits en ligne : ceux deja scannes sans recu passent en premier.
            ->when($request->filled('en_ligne') && ! $request->boolean('en_ligne'), fn (Builder $q) => $q->orderByRaw('inscriptions.en_ligne_introuvable_le IS NULL'))
            ->orderBy('eleves.nom')->orderBy('eleves.prenoms')
            ->select('inscriptions.*')
            ->paginate(min(max($request->integer('par_page', 20), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Inscription $i) => $this->presenterLigne($i))->values(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'par_page' => $page->perPage(),
            'compteurs' => collect(Inscription::STATUTS)->mapWithKeys(fn ($s) => [$s => (int) ($compteurs[$s] ?? 0)]),
            // Inscriptions de l'annee sans verification en ligne (bouton "Mettre a jour").
            'sans_en_ligne' => $this->aVerifierEnLigne($request, $tenant)->count(),
        ]);
    }

    /** Listes du formulaire : niveaux et classes (avec effectifs), formats du matricule... */
    public function options(Request $request, TenantContext $tenant)
    {
        $this->autoriserLecture($request);

        $anneeId = $this->anneeId($tenant);
        $autorises = $this->niveauxAutorises($request, $tenant);
        $etablissement = Etablissement::findOrFail($tenant->id());

        $classes = Classe::where('annee_scolaire_id', $anneeId)
            ->when($autorises !== null, fn (Builder $q) => $q->whereIn('niveau_id', $autorises))
            ->orderBy('libelle')
            ->get();

        $niveaux = Niveau::whereIn('id', $classes->pluck('niveau_id'))->ordonnes()->get();
        // Effectifs de toutes les classes en une requete (pas une par classe).
        $effectifs = Inscription::whereIn('inscriptions.classe_id', $classes->pluck('id'))
            ->join('eleves', 'eleves.id', '=', 'inscriptions.eleve_id')->groupBy('inscriptions.classe_id')
            ->selectRaw("inscriptions.classe_id, SUM(eleves.sexe = 'M') AS garcons, SUM(eleves.sexe = 'F') AS filles, COUNT(*) AS total")
            ->get()->keyBy('classe_id');

        return response()->json([
            'annee' => AnneeScolaire::find($anneeId)?->libelle,
            'niveaux' => $niveaux->map(fn (Niveau $n) => [
                'id' => $n->id,
                'code' => $n->code,
                'libelle' => $n->libelle,
                'ordre' => $n->ordre,
                'classes' => $classes->where('niveau_id', $n->id)->map(fn (Classe $c) => $this->presenterClasse($c, null, $effectifs->get($c->id) ?? (object) ['garcons' => 0, 'filles' => 0, 'total' => 0]))->values(),
                // Montants d'inscription renseignes (Parametres > Paiements) : sinon pas d'inscription.
                'tarifs' => [
                    'affecte' => $this->tarifDefini($anneeId, $n->id, true),
                    'non_affecte' => $this->tarifDefini($anneeId, $n->id, false),
                ],
            ])->values(),
            // Tous les niveaux (libelles) ; de_classe = proposes dans les listes (series, pas TLE seul).
            'tous_niveaux' => Niveau::ordonnes()->withCount('series')->get(['id', 'code', 'libelle', 'ordre'])
                ->map(fn (Niveau $n) => $n->only(['id', 'code', 'libelle', 'ordre']) + ['de_classe' => ! $n->series_count]),
            'niveaux_restreints' => $autorises !== null,
            'formats_matricule' => $etablissement->parametre('formats_matricule'),
            'verification_en_ligne' => (bool) $etablissement->parametre('verification_en_ligne'),
            'langues' => collect(self::LANGUES)->merge($classes->pluck('langue_vivante_2')->filter())->unique()->values(),
            'decisions' => self::DECISIONS,
        ]);
    }

    /**
     * Apercu des frais qui seront copies dans l'inscription (recapitulatif
     * avant validation) : memes regles que copierFrais().
     */
    public function apercuFrais(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        $data = $request->validate([
            'niveau_id' => ['required', 'integer', Rule::exists('niveaux', 'id')],
            'affecte' => ['required', 'boolean'],
        ]);
        $affecte = (bool) $data['affecte'];
        $grille = GrilleTarifaire::with('lignes')
            ->where('annee_scolaire_id', $this->anneeId($tenant))
            ->where('niveau_id', Niveau::generalId((int) $data['niveau_id']))
            ->where('affecte', $affecte)
            ->first();
        $montants = $grille?->lignes->pluck('montant', 'type_frais_id') ?? collect();

        $frais = [];
        $fournitures = [];
        foreach (TypeFrais::orderBy('ordre')->get() as $type) {
            $valeur = (int) ($montants[$type->id] ?? 0);
            if (! $type->applicable($affecte) || $valeur <= 0) {
                continue;
            }
            if ($type->nature === 'en_nature') {
                $fournitures[] = ['libelle' => $type->libelle, 'quantite' => $valeur];
            } else {
                $frais[] = ['libelle' => $type->libelle, 'nature' => $type->nature, 'montant' => $valeur];
            }
        }

        return response()->json([
            'grille_definie' => (bool) $grille,
            'frais' => $frais,
            'fournitures' => $fournitures,
            'total' => array_sum(array_column($frais, 'montant')),
            'minimum' => $grille ? (int) $grille->montant_minimum_inscription : null,
        ]);
    }

    // ------------------------------------------------------------ Etape 1 : matricule

    /**
     * Situation d'un matricule (V1 finaliserInscr) : nouveau, reinscription
     * (derniere inscription, decision, dette) ou deja inscrit cette annee.
     */
    public function rechercher(Request $request, TenantContext $tenant, string $matricule)
    {
        $this->autoriser($request);

        $matricule = $this->validerMatricule($matricule, $tenant);
        $anneeId = $this->anneeId($tenant);
        $eleve = Eleve::with('tuteurs')->where('matricule', $matricule)->first();

        if (! $eleve) {
            return response()->json(['cas' => 'nouveau', 'matricule' => $matricule]);
        }

        $courante = $eleve->inscriptions()->where('annee_scolaire_id', $anneeId)->with(['classe:id,libelle', 'niveau:id,libelle'])->first();
        if ($courante) {
            return response()->json([
                'cas' => 'deja_inscrit',
                'matricule' => $matricule,
                'eleve' => $this->presenterEleve($eleve),
                'inscription' => $this->presenterLigne($courante->setRelation('eleve', $eleve)),
            ]);
        }

        $precedente = $this->inscriptionPrecedente($eleve, $anneeId);

        return response()->json([
            'cas' => 'reinscription',
            'matricule' => $matricule,
            'eleve' => $this->presenterEleve($eleve),
            'precedente' => $precedente ? $this->presenterPrecedente($precedente) : null,
            'dettes' => $this->dettesOuvertes($eleve, (int) $anneeId, $precedente),
        ]);
    }

    /** Recu de l'inscription en ligne sur le site de l'Etat pour l'annee de travail. */
    public function enLigne(Request $request, TenantContext $tenant, InscriptionEnLigneService $service, string $matricule)
    {
        $this->autoriser($request);

        $matricule = $this->validerMatricule($matricule, $tenant);
        $annee = AnneeScolaire::find($this->anneeId($tenant));
        abort_unless($annee, 422, 'Aucune année scolaire active.');

        return response()->json($this->consulterEnLigne($service, $tenant, $matricule, $annee->libelle));
    }

    /**
     * Recu en ligne + correspondances chez nous (niveaux, autre etablissement).
     *
     * @return array{trouve: bool, erreur?: ?string, donnees?: array, niveau_precedent_id?: ?int, niveau_suivant_id?: ?int, autre_etablissement?: bool}
     */
    private function consulterEnLigne(InscriptionEnLigneService $service, TenantContext $tenant, string $matricule, string $annee): array
    {
        $resultat = $service->rechercher($matricule, $annee);
        if (! $resultat['trouve']) {
            return ['trouve' => false, 'erreur' => $resultat['erreur'] ?? null];
        }

        $donnees = $resultat['donnees'];
        // Niveau en ligne : niveau general (2NDE) ou, s'il est donne, avec serie (2NDE A).
        $niveaux = Niveau::pluck('id', 'code')->union(Niveau::pluck('id', 'libelle')->mapWithKeys(fn ($id, $l) => [strtoupper($l) => $id]));
        $trouverNiveau = fn (?string $v) => $niveaux[strtoupper(trim((string) $v))] ?? $niveaux[str_replace(' ', '', strtoupper((string) $v))] ?? null;
        $codeMena = Etablissement::findOrFail($tenant->id())->parametre('code_mena');

        return [
            'trouve' => true,
            'donnees' => $donnees,
            'niveau_precedent_id' => $trouverNiveau($donnees['niveau_precedent'] ?? null),
            'niveau_suivant_id' => $trouverNiveau($donnees['niveau_suivant'] ?? null),
            'autre_etablissement' => $codeMena && isset($donnees['code_etablissement'])
                && ltrim($codeMena, '0') !== ltrim($donnees['code_etablissement'], '0'),
        ];
    }

    /**
     * "Mettre a jour" (liste des inscriptions) : les inscriptions de l'annee
     * sans verification en ligne sont consultees sur le site de l'Etat, par
     * petits lots (l'ecran enchaine les appels en arriere-plan) ; si le recu
     * existe, la base est mise a jour (directement ou en attente selon le
     * statut, voir appliquerEnLigne()) et la mise a jour est notee.
     * Si le site ne repond pas, le lot s'arrete (site_indisponible).
     */
    public function miseAJourEnLigne(Request $request, TenantContext $tenant, InscriptionEnLigneService $service)
    {
        $this->autoriser($request);

        $data = $request->validate(['apres_id' => ['nullable', 'integer', 'min:0']]);
        $annee = AnneeScolaire::find($this->anneeId($tenant));
        abort_unless($annee, 422, 'Aucune année scolaire active.');

        $apresId = (int) ($data['apres_id'] ?? 0);
        $lot = $this->aVerifierEnLigne($request, $tenant)
            ->where('inscriptions.id', '>', $apresId)
            ->with('eleve.tuteurs')
            ->orderBy('inscriptions.id')
            ->limit(self::LOT_EN_LIGNE)
            ->get();

        $traitees = [];
        $siteIndisponible = null;
        foreach ($lot as $inscription) {
            $ligne = $this->traiterEnLigne($inscription, $annee->libelle, $tenant, $request->user()->id);
            if ($ligne['erreur']) {
                // Panne du site : on s'arrete, cette inscription sera reprise.
                $siteIndisponible = $ligne['erreur'];
                break;
            }
            $traitees[] = $ligne;
            $apresId = $inscription->id;
        }

        return response()->json([
            'traitees' => $traitees,
            'dernier_id' => $apresId,
            'restantes' => $this->aVerifierEnLigne($request, $tenant)->where('inscriptions.id', '>', $apresId)->count(),
            'site_indisponible' => $siteIndisponible,
        ]);
    }

    /**
     * Inscriptions de l'annee (niveaux du poste) jamais verifiees en ligne,
     * quel que soit leur statut (ce qui est applique : appliquerEnLigne()).
     */
    private function aVerifierEnLigne(Request $request, TenantContext $tenant): Builder
    {
        return self::inscriptionsAVerifier((int) $this->anneeId($tenant), $this->niveauxAutorises($request, $tenant));
    }

    /**
     * Inscriptions a scanner : pas encore trouvees en ligne (les
     * « introuvables » deja scannees sont rescannees a chaque fois).
     *
     * @param  ?array<int>  $niveaux  niveaux du poste (educateur), null = tous
     */
    public static function inscriptionsAVerifier(int $anneeId, ?array $niveaux): Builder
    {
        return Inscription::where('inscriptions.annee_scolaire_id', $anneeId)
            ->where('inscriptions.inscrit_en_ligne', false)
            ->when($niveaux !== null, fn (Builder $q) => $q->whereIn('inscriptions.niveau_id', $niveaux ?: [0]));
    }

    /**
     * Scanne une inscription sur le site de l'Etat : recu trouve -> repris
     * (appliquerEnLigne) ; aucun recu -> marquee « introuvable en ligne »
     * (date du scan + ligne du journal) ; site en panne -> erreur, rien ne change.
     *
     * @return array{id: int, eleve: string, trouve: bool, introuvable: bool, remarques: list<string>, erreur: ?string}
     */
    public function traiterEnLigne(Inscription $inscription, string $annee, TenantContext $tenant, ?int $userId): array
    {
        $inscription->loadMissing('eleve.tuteurs', 'niveau');
        $ligne = ['id' => $inscription->id, 'eleve' => trim($inscription->eleve->nom.' '.$inscription->eleve->prenoms),
            'trouve' => false, 'introuvable' => false, 'remarques' => [], 'erreur' => null];
        $resultat = $this->consulterEnLigne(app(InscriptionEnLigneService::class), $tenant, $inscription->eleve->matricule, $annee);

        if (! $resultat['trouve'] && ! empty($resultat['erreur'])) {
            $ligne['erreur'] = $resultat['erreur'];

            return $ligne;
        }
        if (! $resultat['trouve']) {
            $this->marquerIntrouvable($inscription, $userId);
            $ligne['introuvable'] = true;
            $ligne['remarques'] = ['Aucune inscription en ligne trouvée'];

            return $ligne;
        }

        // Trouvee (eventuellement apres des scans sans resultat).
        if ($inscription->en_ligne_introuvable_le) {
            $inscription->update(['en_ligne_introuvable_le' => null]);
        }
        MiseAJourEnLigne::where('inscription_id', $inscription->id)->where('type', MiseAJourEnLigne::INTROUVABLE)->delete();
        $ligne['trouve'] = true;
        $ligne['remarques'] = $this->appliquerEnLigne($inscription, $resultat, $tenant, $userId);

        return $ligne;
    }

    /** Scan sans recu : date sur l'inscription, une ligne « introuvable » par inscription dans le journal. */
    private function marquerIntrouvable(Inscription $inscription, ?int $userId): void
    {
        $inscription->update(['en_ligne_introuvable_le' => now()]);
        $journal = MiseAJourEnLigne::where('inscription_id', $inscription->id)->where('type', MiseAJourEnLigne::INTROUVABLE)->first();
        if ($journal) {
            $journal->update(['tentatives' => $journal->tentatives + 1, 'statut_inscription' => (int) $inscription->statut, 'user_id' => $userId]);
            $journal->touch();

            return;
        }
        MiseAJourEnLigne::create([
            'annee_scolaire_id' => $inscription->annee_scolaire_id,
            'inscription_id' => $inscription->id,
            'eleve_id' => $inscription->eleve_id,
            'type' => MiseAJourEnLigne::INTROUVABLE,
            'tentatives' => 1,
            'statut_inscription' => (int) $inscription->statut,
            'changements' => [],
            'remarques' => ['Aucune inscription en ligne trouvée'],
            'en_attente' => false,
            'user_id' => $userId,
        ]);
    }

    /**
     * Reporte le recu en ligne dans la base et note la mise a jour (liste
     * Inscriptions > Mises a jour en ligne).
     * - Toujours appliques : identite de l'eleve, contact du parent,
     *   provenance ou decision de l'annee precedente, photo s'il n'en a pas.
     * - Statut affecte et niveau, selon le statut de l'inscription :
     *   statut 0 (sans classe) : appliques directement ;
     *   statut 1 (classe choisie) : statut affecte applique (frais recalcules),
     *   niveau en attente (l'educateur modifie l'inscription) ;
     *   statut > 1 (paiement) : tous deux en attente (le caissier supprime
     *   d'abord les paiements, puis l'educateur modifie l'inscription).
     *
     * @return list<string> remarques pour l'ecran
     */
    private function appliquerEnLigne(Inscription $inscription, array $resultat, TenantContext $tenant, ?int $userId = null): array
    {
        $recu = $resultat['donnees'];
        $eleve = $inscription->eleve;
        $statut = (int) $inscription->statut;
        $remarques = [];
        $changements = [];
        $niveauDemande = null;
        $affecteDemande = null;
        $noter = function (string $champ, string $libelle, $avant, $apres, bool $applique = true) use (&$changements) {
            $changements[] = ['champ' => $champ, 'libelle' => $libelle, 'avant' => $avant, 'apres' => $apres, 'applique' => $applique];
        };
        $texteAffecte = fn (bool $affecte) => $affecte ? 'Affecté(e)' : 'Non affecté(e)';

        DB::transaction(function () use ($inscription, $recu, $resultat, $eleve, $statut, $noter, $texteAffecte, &$remarques, &$niveauDemande, &$affecteDemande) {
            // Identite de l'eleve
            $identite = array_filter([
                'nom' => isset($recu['nom']) ? mb_strtoupper(trim($recu['nom'])) : null,
                'prenoms' => isset($recu['prenoms']) ? mb_strtoupper(trim($recu['prenoms'])) : null,
                'date_naissance' => $recu['date_naissance'] ?? null,
                'lieu_naissance' => $recu['lieu_naissance'] ?? null,
                'sexe' => in_array($recu['sexe'] ?? null, ['M', 'F'], true) ? $recu['sexe'] : null,
            ], fn ($v) => $v !== null && $v !== '');
            $libelles = ['nom' => 'Nom', 'prenoms' => 'Prénoms', 'date_naissance' => 'Date de naissance', 'lieu_naissance' => 'Lieu de naissance', 'sexe' => 'Sexe'];
            foreach ($identite as $champ => $valeur) {
                $avant = $eleve->{$champ} instanceof \DateTimeInterface ? $eleve->{$champ}->format('Y-m-d') : $eleve->{$champ};
                if ((string) $avant !== (string) $valeur) {
                    $noter($champ, $libelles[$champ], $avant, $valeur);
                }
            }
            $eleve->update($identite);

            // Contact du parent : sur le contact principal sans numero, ou
            // nouveau tuteur si l'eleve n'a aucun parent.
            $contact = $recu['contact_parent'] ?? null;
            if ($contact && ! $eleve->tuteurs->contains('telephone1', $contact)) {
                $cible = $eleve->tuteurs->first(fn (Tuteur $t) => $t->pivot->is_contact_principal && ! $t->telephone1);
                if ($cible) {
                    $cible->update(['telephone1' => $contact]);
                    $noter('contact_parent', 'Contact du parent', null, $contact);
                } elseif ($eleve->tuteurs->isEmpty()) {
                    $tuteur = Tuteur::create(['nom' => '', 'prenoms' => '', 'telephone1' => $contact]);
                    $eleve->tuteurs()->attach($tuteur->id, ['lien_parente' => 'tuteur_legal', 'is_contact_principal' => true, 'is_payeur' => false]);
                    $noter('contact_parent', 'Contact du parent', null, $contact);
                }
            }

            // Provenance / decision de l'annee precedente
            $champs = ['inscrit_en_ligne' => true, 'inscription_en_ligne' => $recu];
            $precedente = $this->inscriptionPrecedente($eleve, $inscription->annee_scolaire_id);
            $decision = in_array($recu['decision'] ?? null, self::DECISIONS, true) ? $recu['decision'] : null;
            if ($precedente) {
                if (! $precedente->decision_finale && $decision) {
                    $precedente->update(array_filter([
                        'decision_finale' => $decision,
                        'moyenne_annuelle' => $recu['moyenne'] ?? null,
                        'niveau_a_suivre_id' => $resultat['niveau_suivant_id'] ?? null,
                    ], fn ($v) => $v !== null));
                    $noter('decision_precedente', 'Décision '.$precedente->anneeScolaire?->libelle, null, $decision);
                    $remarques[] = 'Décision '.$precedente->anneeScolaire?->libelle.' reprise';
                }
            } else {
                $origine = array_filter([
                    'decision_origine' => $inscription->decision_origine ? null : $decision,
                    'moyenne_origine' => $inscription->moyenne_origine ? null : ($recu['moyenne'] ?? null),
                    'classe_origine' => $inscription->classe_origine ? null : ($recu['niveau_precedent'] ?? null),
                ], fn ($v) => $v !== null);
                $libellesOrigine = ['decision_origine' => "Décision d'origine", 'moyenne_origine' => "Moyenne d'origine", 'classe_origine' => "Classe d'origine"];
                foreach ($origine as $champ => $valeur) {
                    $noter($champ, $libellesOrigine[$champ], null, $valeur);
                }
                $champs += $origine;
                if ($decision && ($decision === 'REDOUBLE') !== (bool) $inscription->redoublant) {
                    $champs['redoublant'] = $decision === 'REDOUBLE';
                    $noter('redoublant', 'Redoublant', $inscription->redoublant ? 'Oui' : 'Non', $champs['redoublant'] ? 'Oui' : 'Non');
                }
            }

            // Statut affecte : direct sans paiement, en attente sinon.
            $affecteChange = false;
            $affecteEnLigne = isset($recu['affecte']) ? (bool) $recu['affecte'] : null;
            if ($affecteEnLigne !== null && $affecteEnLigne !== (bool) $inscription->affecte) {
                if ($statut >= Inscription::PAIEMENT) {
                    $affecteDemande = $affecteEnLigne;
                    $noter('affecte', 'Statut', $texteAffecte((bool) $inscription->affecte), $texteAffecte($affecteEnLigne), false);
                    $remarques[] = 'Statut '.mb_strtolower($texteAffecte($affecteEnLigne)).' en attente (paiements enregistrés)';
                } else {
                    $champs['affecte'] = $affecteEnLigne;
                    $affecteChange = true;
                    $noter('affecte', 'Statut', $texteAffecte((bool) $inscription->affecte), $texteAffecte($affecteEnLigne));
                    $remarques[] = $affecteEnLigne ? 'Devient affecté(e)' : 'Devient non affecté(e)';
                }
            }

            // Niveau : direct sans classe (statut 0), en attente sinon.
            $niveauEnLigne = $resultat['niveau_suivant_id'] ?? null;
            if ($niveauEnLigne && ! MiseAJourEnLigne::memeNiveau($inscription->niveau_id, (int) $niveauEnLigne)) {
                $avant = $inscription->niveau?->libelle;
                $apres = Niveau::find($niveauEnLigne)?->libelle ?? ($recu['niveau_suivant'] ?? null);
                if ($statut === Inscription::SANS_CLASSE) {
                    $champs['niveau_id'] = (int) $niveauEnLigne;
                    $noter('niveau', 'Niveau', $avant, $apres);
                    $remarques[] = 'Niveau '.$apres.' repris';
                } else {
                    $niveauDemande = (int) $niveauEnLigne;
                    $noter('niveau', 'Niveau', $avant, $apres, false);
                    $remarques[] = 'Niveau en ligne : '.$apres.' (en attente)';
                }
            }

            $inscription->update($champs);
            if ($affecteChange && $inscription->classe_id) {
                $this->copierFrais($inscription);
            }
        });

        if ($resultat['autre_etablissement'] ?? false) {
            $remarques[] = 'Préinscrit(e) dans un autre établissement';
        }
        if (! $eleve->photo_path && ! empty($recu['photo_url'])) {
            $this->sauverPhotoEnLigne($eleve, $recu['photo_url'], $tenant);
            if ($eleve->fresh()?->photo_path) {
                $changements[] = ['champ' => 'photo', 'libelle' => 'Photo', 'avant' => null, 'apres' => 'ajoutée', 'applique' => true];
            }
        }

        if ($changements || $remarques) {
            MiseAJourEnLigne::create([
                'annee_scolaire_id' => $inscription->annee_scolaire_id,
                'inscription_id' => $inscription->id,
                'eleve_id' => $eleve->id,
                'statut_inscription' => $statut,
                'changements' => $changements,
                'remarques' => $remarques ?: null,
                'en_attente' => $niveauDemande !== null || $affecteDemande !== null,
                'niveau_demande_id' => $niveauDemande,
                'affecte_demande' => $affecteDemande,
                'user_id' => $userId,
            ]);
        }

        return $remarques;
    }

    // ------------------------------------------------------------ Creation / modification

    public function store(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        $anneeId = $this->anneeId($tenant);
        abort_unless($anneeId, 422, 'Aucune année scolaire active.');
        // Reinscription : la fiche existante est mise a jour.
        $eleve = Eleve::where('matricule', strtoupper(trim((string) $request->input('eleve.matricule'))))->first();
        $data = $this->valider($request, $tenant, $eleve);
        $this->verifierTarif($data['inscription'], $anneeId);

        if ($eleve &&$eleve->inscriptions()->where('annee_scolaire_id', $anneeId)->exists()) {
            throw ValidationException::withMessages(['eleve.matricule' => ['Cet élève est déjà inscrit pour cette année.']]);
        }

        $inscription = DB::transaction(function () use ($data, $eleve, $anneeId, $request, $tenant) {
            $eleve = $this->enregistrerEleve($eleve, $data);

            // Reinscription : decision de fin d'annee de l'annee precedente
            // (V1 modif_decision) et dette si elle n'est pas soldee.
            $precedente = $this->inscriptionPrecedente($eleve, $anneeId);
            if ($precedente && isset($data['precedente'])) {
                $precedente->update(array_filter($data['precedente'], fn ($v) => $v !== null));
            }
            if ($precedente) {
                $this->creerDette($eleve, $precedente, $request);
            }

            $inscription = Inscription::create($this->champsInscription($data['inscription']) + [
                'annee_scolaire_id' => $anneeId,
                'eleve_id' => $eleve->id,
                'date_inscription' => now(),
                'statut' => Inscription::SANS_CLASSE,
                'enregistre_par_id' => $request->user()->id,
            ]);

            if ($classeId = $data['inscription']['classe_id'] ?? null) {
                $this->attribuerClasse($inscription, Classe::findOrFail($classeId), $tenant);
            }

            return $inscription;
        });
        $this->enregistrerPhotoEnLigne($request, $inscription, $tenant);

        return response()->json(['data' => $this->detail($inscription, $request, $tenant)], 201);
    }

    public function show(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriserLecture($request);

        return response()->json(['data' => $this->detail($this->trouver($request, $tenant, $id), $request, $tenant)]);
    }

    /** Fiche d'inscription imprimable (V1 recu_inscr), avec l'en-tete officiel des documents. */
    public function fiche(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriserLecture($request);
        $inscription = $this->trouver($request, $tenant, $id);
        $detail = $this->detail($inscription, $request, $tenant);
        $photo = null;
        $chemin = $inscription->eleve->photo_path;
        if ($chemin && Storage::disk('public')->exists($chemin)) {
            $fichier = Storage::disk('public')->path($chemin);
            $photo = 'data:'.(mime_content_type($fichier) ?: 'image/jpeg').';base64,'.base64_encode(file_get_contents($fichier));
        }

        return Document::repondre('pdf.fiche-inscription', Document::entete(Etablissement::findOrFail($tenant->id())) + [
            'd' => $detail,
            'photo' => $photo,
            'annee' => $detail['annee'],
            'decisions' => self::DECISIONS,
            'genere_le' => now(),
            'edite_par' => trim($request->user()->prenoms.' '.$request->user()->name),
        ], 'fiche-inscription-'.$inscription->eleve->matricule, $request->query('format'));
    }

    public function update(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);

        $inscription = $this->trouver($request, $tenant, $id);
        $data = $this->valider($request, $tenant, $inscription->eleve);
        $champs = $this->champsInscription($data['inscription']);

        // Changement de niveau ou de statut affecte : le nouveau tarif doit exister.
        if ((int) $champs['niveau_id'] !== (int) $inscription->niveau_id || (bool) $champs['affecte'] !== (bool) $inscription->affecte) {
            $this->verifierTarif($data['inscription'], $inscription->annee_scolaire_id);
        }
        // Mise a jour depuis l'inscription en ligne : seulement avant tout paiement (statut < 2).
        if ($champs['inscrit_en_ligne'] && ! $inscription->inscrit_en_ligne && $inscription->statut >= Inscription::PAIEMENT) {
            throw ValidationException::withMessages(['inscription.inscrit_en_ligne' => [
                'Des paiements sont déjà enregistrés : la mise à jour depuis l\'inscription en ligne n\'est plus possible.',
            ]]);
        }

        $niveauChange = (int) $champs['niveau_id'] !== (int) $inscription->niveau_id;
        $nouvelleClasse = isset($data['inscription']['classe_id']) ? Classe::find($data['inscription']['classe_id']) : null;
        $tarifChange = $inscription->classe_id && (
            (bool) $champs['affecte'] !== (bool) $inscription->affecte
            || $niveauChange
            || ($nouvelleClasse && $nouvelleClasse->niveau_id !== $inscription->niveau_id)
        );
        if ($tarifChange && $this->aDesPaiements($inscription)) {
            throw ValidationException::withMessages(['inscription.affecte' => [
                'Des paiements sont déjà enregistrés : le statut affecté et le niveau ne peuvent plus changer.',
            ]]);
        }
        // Nouveau niveau sans classe de ce niveau : l'ancienne classe et ses frais
        // sont retires, l'inscription repasse au statut 0 (classe a choisir).
        $retirerClasse = $niveauChange && $inscription->classe_id
            && (! $nouvelleClasse || (int) $nouvelleClasse->niveau_id !== (int) $champs['niveau_id']);

        DB::transaction(function () use ($inscription, $data, $champs, $tarifChange, $retirerClasse, $tenant) {
            $this->enregistrerEleve($inscription->eleve, $data);

            $precedente = $this->inscriptionPrecedente($inscription->eleve, $inscription->annee_scolaire_id);
            if ($precedente && isset($data['precedente'])) {
                $precedente->update(array_filter($data['precedente'], fn ($v) => $v !== null));
            }

            $inscription->update($champs);

            $classeId = $data['inscription']['classe_id'] ?? null;
            if ($retirerClasse) {
                $inscription->update(['classe_id' => null]);
                $inscription->fraisEleves()->delete();
                $inscription->recalculerTotaux();
            } elseif ($classeId && $classeId !== $inscription->classe_id) {
                $this->attribuerClasse($inscription, Classe::findOrFail($classeId), $tenant);
                // Niveau ou statut affecte change en meme temps : frais du nouveau tarif.
                if ($tarifChange) {
                    $this->copierFrais($inscription);
                }
            } elseif ($tarifChange) {
                $this->copierFrais($inscription);
            }
        });
        $this->enregistrerPhotoEnLigne($request, $inscription, $tenant);

        return response()->json(['data' => $this->detail($inscription->fresh(), $request, $tenant)]);
    }

    /**
     * A la validation : photo du recu de l'inscription en ligne enregistree
     * dans le dossier de l'eleve (photo_en_ligne = true, envoye par l'ecran
     * quand l'utilisateur n'a pas choisi lui-meme une photo).
     */
    private function enregistrerPhotoEnLigne(Request $request, Inscription $inscription, TenantContext $tenant): void
    {
        $url = $inscription->inscription_en_ligne['photo_url'] ?? null;
        if (! $request->boolean('photo_en_ligne') || ! $url) {
            return;
        }
        $this->sauverPhotoEnLigne($inscription->eleve()->first(), $url, $tenant);
    }

    private function sauverPhotoEnLigne(Eleve $eleve, string $url, TenantContext $tenant): void
    {
        $photo = app(InscriptionEnLigneService::class)->telechargerPhoto($url);
        if (! $photo) {
            return;
        }
        $chemin = "eleves/{$tenant->id()}/{$eleve->matricule}-".now()->format('YmdHis').".{$photo['extension']}";
        Storage::disk('public')->put($chemin, $photo['contenu']);
        if ($eleve->photo_path) {
            Storage::disk('public')->delete($eleve->photo_path);
        }
        $eleve->update(['photo_path' => $chemin]);
    }

    /** Attribue ou change la classe (V1 choose_classe / modif_eleve_classe). */
    public function changerClasse(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);

        $inscription = $this->trouver($request, $tenant, $id);
        $data = $request->validate([
            'classe_id' => ['required', 'integer', Rule::exists('classes', 'id')
                ->where('etablissement_id', $tenant->id())
                ->where('annee_scolaire_id', $inscription->annee_scolaire_id)],
        ], ['classe_id.required' => 'Choisissez une classe.']);

        $classe = Classe::findOrFail($data['classe_id']);
        $this->verifierNiveauAutorise($request, $tenant, $classe->niveau_id);
        if ($classe->niveau_id !== $inscription->niveau_id && $inscription->classe_id && $this->aDesPaiements($inscription)) {
            throw ValidationException::withMessages(['classe_id' => [
                'Des paiements sont déjà enregistrés : l\'élève ne peut changer que pour une classe du même niveau.',
            ]]);
        }

        DB::transaction(fn () => $this->attribuerClasse($inscription, $classe, $tenant));

        return response()->json(['data' => $this->detail($inscription->fresh(), $request, $tenant)]);
    }

    /** Fournitures en nature remises (V1 : craie / rame rapportees). */
    public function fournitures(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);

        $inscription = $this->trouver($request, $tenant, $id);
        $data = $request->validate([
            'fournitures' => ['required', 'array'],
            'fournitures.*.id' => ['required', 'integer'],
            'fournitures.*.quantite_remise' => ['required', 'integer', 'min:0'],
        ]);

        $lignes = $inscription->fraisEleves()->whereNotNull('quantite_due')->get()->keyBy('id');
        foreach ($data['fournitures'] as $fourniture) {
            $ligne = $lignes[$fourniture['id']] ?? null;
            abort_unless($ligne, 422, 'Fourniture inconnue pour cette inscription.');
            $ligne->update(['quantite_remise' => min($fourniture['quantite_remise'], $ligne->quantite_due)]);
        }

        return response()->json(['data' => $this->detail($inscription->fresh(), $request, $tenant)]);
    }

    /**
     * Annuler = supprimer l'inscription et ses frais (V1 suprInscrid), tant
     * qu'aucun paiement n'est enregistre. Si la fiche de l'eleve n'a servi qu'a
     * cette inscription (aucune autre annee, paiement, dette, note, absence,
     * document), elle est supprimee aussi avec ses parents et sa photo : une
     * nouvelle inscription repart du site de l'Etat. Sinon la fiche reste.
     */
    public function destroy(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);

        $inscription = $this->trouver($request, $tenant, $id);
        if ($this->aDesPaiements($inscription)) {
            throw ValidationException::withMessages(['inscription' => ['Des paiements sont déjà enregistrés : cette inscription ne peut pas être annulée.']]);
        }
        $eleve = $inscription->eleve;
        $annee = AnneeScolaire::find($inscription->annee_scolaire_id)?->libelle;
        $photo = null;

        DB::transaction(function () use ($inscription, $eleve, &$photo) {
            $inscription->fraisEleves()->delete();
            $inscription->delete();

            if ($eleve && $this->ficheSansHistorique($eleve)) {
                $photo = $eleve->photo_path;
                $tuteurs = $eleve->tuteurs()->pluck('tuteurs.id');
                $eleve->tuteurs()->detach();
                // Parents qui ne sont rattaches a aucun autre eleve.
                Tuteur::whereIn('id', $tuteurs)->whereDoesntHave('eleves')->delete();
                $eleve->forceDelete();
            }
        });
        if ($photo) {
            Storage::disk('public')->delete($photo);
        }
        // Le recu en ligne sera relu sur le site de l'Etat a la prochaine inscription.
        if ($eleve && $annee && ($code = app(InscriptionEnLigneService::class)->codeAnnee($annee))) {
            Cache::forget("inscription-en-ligne:{$code}:{$eleve->matricule}");
        }

        return response()->noContent();
    }

    /** La fiche n'a aucune autre trace dans l'etablissement que l'inscription supprimee. */
    private function ficheSansHistorique(Eleve $eleve): bool
    {
        if ($eleve->inscriptions()->exists()) {
            return false;
        }
        foreach (['reglements', 'dettes', 'notes', 'moyennes', 'moyennes_generales', 'absences', 'documents_eleves'] as $table) {
            if (DB::table($table)->where('eleve_id', $eleve->id)->exists()) {
                return false;
            }
        }

        return true;
    }

    /** Refuse un niveau dont les montants d'inscription ne sont pas renseignes. */
    private function verifierTarif(array $inscription, ?int $anneeId): void
    {
        $affecte = (bool) ($inscription['affecte'] ?? false);
        if (! $this->tarifDefini($anneeId, (int) $inscription['niveau_id'], $affecte)) {
            $niveau = Niveau::find($inscription['niveau_id'])?->libelle;
            throw ValidationException::withMessages(['inscription.niveau_id' => [
                "Les montants d'inscription de {$niveau} (élève ".($affecte ? 'affecté' : 'non affecté').') ne sont pas renseignés : complétez Paramètres › Paiements avant d\'inscrire.',
            ]]);
        }
    }

    /** Montants d'inscription renseignes pour ce niveau et ce statut (grille avec au moins un montant). */
    private function tarifDefini(?int $anneeId, int $niveauId, bool $affecte): bool
    {
        return GrilleTarifaire::where('annee_scolaire_id', $anneeId)
            ->where('niveau_id', Niveau::generalId($niveauId))
            ->where('affecte', $affecte)
            ->whereHas('lignes', fn (Builder $q) => $q->where('montant', '>', 0))
            ->exists();
    }

    /** Photo de l'eleve (fichier ou prise de vue). */
    public function photo(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);

        $inscription = $this->trouver($request, $tenant, $id);
        $request->validate(['photo' => ['required', 'image', 'max:4096']], [
            'photo.image' => 'Le fichier doit être une image.',
            'photo.max' => 'La photo ne doit pas dépasser 4 Mo.',
        ]);

        $eleve = $inscription->eleve;
        if ($eleve->photo_path) {
            Storage::disk('public')->delete($eleve->photo_path);
        }
        $eleve->update(['photo_path' => $request->file('photo')->store("eleves/{$tenant->id()}", 'public')]);

        return response()->json(['data' => $this->detail($inscription->fresh(), $request, $tenant)]);
    }

    // ------------------------------------------------------------ Regles

    /**
     * Copie les montants de la grille dans l'inscription (snapshot V1) :
     * niveau + affecte/non affecte, types actifs et applicables ; les frais en
     * nature deviennent des quantites a apporter.
     */
    private function copierFrais(Inscription $inscription): void
    {
        $grille = GrilleTarifaire::with('lignes')
            ->where('annee_scolaire_id', $inscription->annee_scolaire_id)
            ->where('niveau_id', Niveau::generalId($inscription->niveau_id))
            ->where('affecte', $inscription->affecte)
            ->first();
        $montants = $grille?->lignes->pluck('montant', 'type_frais_id') ?? collect();

        $inscription->fraisEleves()->delete();
        foreach (TypeFrais::orderBy('ordre')->get() as $type) {
            $valeur = (int) ($montants[$type->id] ?? 0);
            if (! $type->applicable($inscription->affecte) || $valeur <= 0) {
                continue;
            }
            $inscription->fraisEleves()->create($type->nature === 'en_nature'
                ? ['type_frais_id' => $type->id, 'montant_du' => 0, 'quantite_due' => $valeur]
                : ['type_frais_id' => $type->id, 'montant_du' => $valeur]);
        }
        $inscription->recalculerTotaux();
    }

    private function attribuerClasse(Inscription $inscription, Classe $classe, TenantContext $tenant): void
    {
        $this->verifierPlace($classe, $inscription->id);

        $niveauChange = $inscription->niveau_id !== $classe->niveau_id || ! $inscription->classe_id;
        $inscription->classe_id = $classe->id;
        $inscription->niveau_id = $classe->niveau_id;
        if (! $inscription->langue_vivante_2 && $classe->langue_vivante_2) {
            $inscription->langue_vivante_2 = $classe->langue_vivante_2;
        }
        $inscription->save();

        if ($niveauChange && ! $this->aDesPaiements($inscription)) {
            $this->copierFrais($inscription);
        } else {
            $inscription->recalculerTotaux();
        }
    }

    /** Refuse une classe pleine (limite classe > niveau > etablissement). */
    private function verifierPlace(Classe $classe, ?int $saufInscriptionId = null): void
    {
        $limite = $classe->effectifLimite()['limite'];
        if (! $limite) {
            return;
        }
        $effectif = $classe->inscriptions()
            ->when($saufInscriptionId, fn (Builder $q) => $q->whereKeyNot($saufInscriptionId))
            ->count();
        if ($effectif >= $limite) {
            throw ValidationException::withMessages(['inscription.classe_id' => [
                "La classe {$classe->libelle} est complète ({$effectif} / {$limite} élèves).",
            ]]);
        }
    }

    /** V1 : a la reinscription, le reste de l'annee precedente devient une dette. */
    private function creerDette(Eleve $eleve, Inscription $precedente, Request $request): void
    {
        $reste = $precedente->resteAPayer();
        if ($reste <= 0) {
            return;
        }
        if (Dette::where('eleve_id', $eleve->id)->where('annee_scolaire_id', $precedente->annee_scolaire_id)->exists()) {
            return;
        }
        Dette::create([
            'eleve_id' => $eleve->id,
            'annee_scolaire_id' => $precedente->annee_scolaire_id,
            'montant' => $reste,
            'enregistre_par_id' => $request->user()->id,
        ]);
    }

    private function aDesPaiements(Inscription $inscription): bool
    {
        return (float) $inscription->montant_total_paye > 0
            || $inscription->fraisEleves()->whereHas('reglementLignes')->exists();
    }

    private function enregistrerEleve(?Eleve $eleve, array $data): Eleve
    {
        $champs = $data['eleve'];
        $champs['nom'] = mb_strtoupper(trim($champs['nom']));
        $champs['prenoms'] = mb_strtoupper(trim($champs['prenoms']));
        $champs['orphelin_pere'] = (bool) ($champs['orphelin_pere'] ?? false);
        $champs['orphelin_mere'] = (bool) ($champs['orphelin_mere'] ?? false);

        $eleve = $eleve ? tap($eleve)->update($champs) : Eleve::create($champs + ['statut' => 'actif']);
        $this->enregistrerParents($eleve, $data['parents'] ?? []);

        return $eleve;
    }

    /** Pere, mere, tuteur (V1 : un nom + un numero chacun). */
    private function enregistrerParents(Eleve $eleve, array $parents): void
    {
        $existants = $eleve->tuteurs()->get()->keyBy(fn (Tuteur $t) => $t->pivot->lien_parente);
        $gardes = [];

        foreach ($parents as $parent) {
            $nomComplet = trim(preg_replace('/\s+/', ' ', $parent['nom_complet'] ?? ''));
            if ($nomComplet === '' && empty($parent['telephone'])) {
                continue;
            }
            [$nom, $prenoms] = array_pad(explode(' ', mb_strtoupper($nomComplet), 2), 2, '');
            $champs = [
                'nom' => $nom,
                'prenoms' => $prenoms,
                'telephone1' => $parent['telephone'] ?? null,
                'profession' => $parent['profession'] ?? null,
            ];
            $pivot = [
                'lien_parente' => $parent['lien_parente'],
                'is_contact_principal' => (bool) ($parent['is_contact_principal'] ?? false),
                'is_payeur' => (bool) ($parent['is_payeur'] ?? false),
            ];

            $tuteur = $existants[$parent['lien_parente']] ?? null;
            if ($tuteur) {
                $tuteur->update($champs);
                $eleve->tuteurs()->updateExistingPivot($tuteur->id, $pivot);
            } else {
                $tuteur = Tuteur::create($champs);
                $eleve->tuteurs()->attach($tuteur->id, $pivot);
            }
            $gardes[] = $tuteur->id;
        }

        $retires = $existants->reject(fn (Tuteur $t) => in_array($t->id, $gardes, true))->pluck('id');
        if ($retires->isNotEmpty()) {
            $eleve->tuteurs()->detach($retires);
        }
    }

    private function champsInscription(array $donnees): array
    {
        return [
            'niveau_id' => $donnees['niveau_id'],
            'affecte' => (bool) ($donnees['affecte'] ?? false),
            'redoublant' => (bool) ($donnees['redoublant'] ?? false),
            'boursier' => (bool) ($donnees['boursier'] ?? false),
            'langue_vivante_2' => $donnees['langue_vivante_2'] ?? null,
            'etablissement_origine' => $donnees['etablissement_origine'] ?? null,
            'classe_origine' => $donnees['classe_origine'] ?? null,
            'decision_origine' => $donnees['decision_origine'] ?? null,
            'moyenne_origine' => $donnees['moyenne_origine'] ?? null,
            'inscrit_en_ligne' => (bool) ($donnees['inscrit_en_ligne'] ?? false),
            'inscription_en_ligne' => ($donnees['inscrit_en_ligne'] ?? false) ? ($donnees['inscription_en_ligne'] ?? null) : null,
        ];
    }

    private function valider(Request $request, TenantContext $tenant, ?Eleve $eleve = null): array
    {
        $request->merge(['eleve' => array_merge($request->input('eleve', []), [
            'matricule' => strtoupper(trim((string) $request->input('eleve.matricule'))),
        ])]);

        $data = $request->validate([
            'eleve.matricule' => ['required', 'string', 'max:20', Rule::unique('eleves', 'matricule')
                ->where('etablissement_id', $tenant->id())->whereNull('deleted_at')->ignore($eleve?->id)],
            'eleve.nom' => ['required', 'string', 'max:80'],
            'eleve.prenoms' => ['required', 'string', 'max:180'],
            'eleve.sexe' => ['required', Rule::in(['M', 'F'])],
            'eleve.date_naissance' => ['nullable', 'date', 'before:today'],
            'eleve.lieu_naissance' => ['nullable', 'string', 'max:100'],
            'eleve.nationalite' => ['nullable', 'string', 'max:60'],
            'eleve.telephone' => ['nullable', 'string', 'max:20'],
            'eleve.quartier' => ['nullable', 'string', 'max:150'],
            'eleve.particularites_medicales' => ['nullable', 'string', 'max:2000'],
            'eleve.orphelin_pere' => ['boolean'],
            'eleve.orphelin_mere' => ['boolean'],

            'parents' => ['array', 'max:3'],
            'parents.*.lien_parente' => ['required', Rule::in(['pere', 'mere', 'tuteur_legal'])],
            'parents.*.nom_complet' => ['nullable', 'string', 'max:200'],
            'parents.*.telephone' => ['nullable', 'string', 'max:20'],
            'parents.*.profession' => ['nullable', 'string', 'max:100'],
            'parents.*.is_contact_principal' => ['boolean'],
            'parents.*.is_payeur' => ['boolean'],

            'inscription.niveau_id' => ['required', 'integer', Rule::exists('niveaux', 'id')],
            'inscription.classe_id' => ['nullable', 'integer', Rule::exists('classes', 'id')
                ->where('etablissement_id', $tenant->id())->where('annee_scolaire_id', $this->anneeId($tenant))],
            'inscription.affecte' => ['boolean'],
            'inscription.redoublant' => ['boolean'],
            'inscription.boursier' => ['boolean'],
            'inscription.langue_vivante_2' => ['nullable', 'string', 'max:30'],
            'inscription.etablissement_origine' => ['nullable', 'string', 'max:200'],
            'inscription.classe_origine' => ['nullable', 'string', 'max:30'],
            'inscription.decision_origine' => ['nullable', 'string', 'max:20'],
            'inscription.moyenne_origine' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'inscription.inscrit_en_ligne' => ['boolean'],
            'inscription.inscription_en_ligne' => ['nullable', 'array'],

            'precedente' => ['nullable', 'array'],
            'precedente.decision_finale' => ['nullable', Rule::in(self::DECISIONS)],
            'precedente.niveau_a_suivre_id' => ['nullable', 'integer', Rule::exists('niveaux', 'id')],
            'precedente.moyenne_annuelle' => ['nullable', 'numeric', 'min:0', 'max:20'],
        ], [
            'eleve.matricule.required' => 'Le matricule est obligatoire.',
            'eleve.matricule.unique' => 'Ce matricule est déjà utilisé par un autre élève.',
            'eleve.nom.required' => 'Le nom est obligatoire.',
            'eleve.prenoms.required' => 'Les prénoms sont obligatoires.',
            'eleve.sexe.required' => 'Précisez le sexe.',
            'eleve.date_naissance.before' => 'La date de naissance doit être passée.',
            'inscription.niveau_id.required' => 'Choisissez le niveau.',
            '*.moyenne_origine.max' => 'La moyenne est sur 20.',
            '*.moyenne_annuelle.max' => 'La moyenne est sur 20.',
        ]);

        $this->validerMatricule($data['eleve']['matricule'], $tenant, 'eleve.matricule');
        $this->verifierNiveauAutorise($request, $tenant, $data['inscription']['niveau_id']);
        if (($classeId = $data['inscription']['classe_id'] ?? null) && Classe::find($classeId)?->niveau_id !== (int) $data['inscription']['niveau_id']) {
            throw ValidationException::withMessages(['inscription.classe_id' => ['Cette classe n\'est pas du niveau choisi.']]);
        }

        return $data;
    }

    /** Le matricule national doit respecter l'un des formats parametres. */
    private function validerMatricule(string $matricule, TenantContext $tenant, string $champ = 'matricule'): string
    {
        $matricule = strtoupper(trim($matricule));
        $formats = Etablissement::findOrFail($tenant->id())->parametre('formats_matricule') ?: [];

        foreach ($formats as $format) {
            if (preg_match(self::regexFormat($format), $matricule)) {
                return $matricule;
            }
        }

        throw ValidationException::withMessages([$champ => [
            'Matricule invalide. Format attendu : '.implode(' ou ', array_map([self::class, 'exempleFormat'], $formats)).'.',
        ]]);
    }

    /** "99999999A" -> /^[0-9]{8}[A-Z]$/ (9 = chiffre, A = lettre majuscule). */
    public static function regexFormat(string $format): string
    {
        $regex = '';
        foreach (str_split($format) as $caractere) {
            $regex .= match ($caractere) {
                '9' => '[0-9]',
                'A' => '[A-Z]',
                default => preg_quote($caractere, '/'),
            };
        }

        return '/^'.$regex.'$/';
    }

    /** "99999999A" -> "8 chiffres + 1 lettre". */
    public static function exempleFormat(string $format): string
    {
        preg_match_all('/9+|A+|[^9A]+/', $format, $groupes);

        return implode(' + ', array_map(fn ($g) => match ($g[0]) {
            '9' => strlen($g).' chiffre'.(strlen($g) > 1 ? 's' : ''),
            'A' => strlen($g).' lettre'.(strlen($g) > 1 ? 's' : ''),
            default => "« {$g} »",
        }, $groupes[0]));
    }

    // ------------------------------------------------------------ Niveaux de l'educateur

    /** Niveaux autorises pour le poste en cours : null = tous, [] = aucun (educateur sans niveau). */
    private function niveauxAutorises(Request $request, TenantContext $tenant): ?array
    {
        return \App\Support\PorteePedagogique::niveaux($request, (int) $this->anneeId($tenant));
    }

    private function verifierNiveauAutorise(Request $request, TenantContext $tenant, int $niveauId): void
    {
        $autorises = $this->niveauxAutorises($request, $tenant);
        if ($autorises !== null && ! in_array($niveauId, $autorises, true)) {
            throw ValidationException::withMessages(['inscription.niveau_id' => ['Ce niveau ne fait pas partie de vos niveaux.']]);
        }
    }

    private function trouver(Request $request, TenantContext $tenant, int $id): Inscription
    {
        $inscription = Inscription::with('eleve')->findOrFail($id);
        $autorises = $this->niveauxAutorises($request, $tenant);
        abort_if($autorises !== null && ! in_array($inscription->niveau_id, $autorises, true), 403, 'Cette inscription ne fait pas partie de vos niveaux.');

        return $inscription;
    }

    // ------------------------------------------------------------ Outils

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('inscriptions.gerer'), 403);
    }

    /** Liste, fiche et options des filtres : aussi en consultation seule (inscriptions.voir, comptable). */
    private function autoriserLecture(Request $request): void
    {
        abort_unless($request->user()->canAny(['inscriptions.gerer', 'inscriptions.voir']), 403);
    }

    private function anneeId(TenantContext $tenant): ?int
    {
        return $tenant->anneeScolaireId() ?? AnneeScolaire::where('is_active', true)->value('id');
    }

    /** Derniere inscription d'une annee anterieure a l'annee de travail. */
    private function inscriptionPrecedente(Eleve $eleve, ?int $anneeId): ?Inscription
    {
        $libelle = AnneeScolaire::find($anneeId)?->libelle;

        return $eleve->inscriptions()
            ->join('annees_scolaires', 'annees_scolaires.id', '=', 'inscriptions.annee_scolaire_id')
            ->when($libelle, fn ($q) => $q->where('annees_scolaires.libelle', '<', $libelle))
            ->orderByDesc('annees_scolaires.libelle')
            ->select('inscriptions.*')
            ->with(['anneeScolaire', 'classe:id,libelle', 'niveau:id,libelle,ordre', 'niveauASuivre:id,libelle'])
            ->first();
    }

    private function dettesOuvertes(Eleve $eleve, int $anneeId, ?Inscription $precedente = null): array
    {
        $dettes = Dette::with('anneeScolaire')->where('eleve_id', $eleve->id)->where('is_annulee', false)->prisesEnCompte($anneeId)->get()
            ->map(fn (Dette $d) => ['id' => $d->id, 'annee' => $d->anneeScolaire?->libelle, 'montant' => (int) $d->montant, 'reste' => (int) $d->resteAPayer(), 'a_creer' => false]);

        // Reste de l'annee precedente qui deviendra une dette a l'enregistrement.
        if ($precedente && $precedente->resteAPayer() > 0
            && in_array((int) $precedente->annee_scolaire_id, Dette::anneesPrisesEnCompte($anneeId) ?? [(int) $precedente->annee_scolaire_id], true)
            && ! $dettes->contains('annee', $precedente->anneeScolaire?->libelle)) {
            $dettes->push(['id' => null, 'annee' => $precedente->anneeScolaire?->libelle, 'montant' => (int) $precedente->resteAPayer(), 'reste' => (int) $precedente->resteAPayer(), 'a_creer' => true]);
        }

        return $dettes->filter(fn ($d) => $d['reste'] > 0)->values()->all();
    }

    // ------------------------------------------------------------ Presentation

    private function presenterClasse(Classe $classe, ?int $saufInscriptionId = null, ?object $inscrits = null): array
    {
        $inscrits ??= $classe->inscriptions()
            ->when($saufInscriptionId, fn (Builder $q) => $q->whereKeyNot($saufInscriptionId))
            ->join('eleves', 'eleves.id', '=', 'inscriptions.eleve_id')
            ->selectRaw("SUM(eleves.sexe = 'M') AS garcons, SUM(eleves.sexe = 'F') AS filles, COUNT(*) AS total")
            ->first();
        $limite = $classe->effectifLimite();

        return [
            'id' => $classe->id,
            'libelle' => $classe->libelle,
            'niveau_id' => $classe->niveau_id,
            'langue_vivante_2' => $classe->langue_vivante_2,
            'effectif' => (int) $inscrits->total,
            'garcons' => (int) $inscrits->garcons,
            'filles' => (int) $inscrits->filles,
            'limite' => $limite['limite'],
            'complete' => $limite['limite'] !== null && (int) $inscrits->total >= $limite['limite'],
        ];
    }

    private function presenterEleve(Eleve $eleve): array
    {
        return FicheEleve::presenter($eleve);
    }

    private function presenterPrecedente(Inscription $i): array
    {
        return [
            'id' => $i->id,
            'annee' => $i->anneeScolaire?->libelle,
            'classe' => $i->classe?->libelle,
            'niveau_id' => $i->niveau_id,
            'niveau' => $i->niveau?->libelle,
            'affecte' => (bool) $i->affecte,
            'langue_vivante_2' => $i->langue_vivante_2,
            'decision_finale' => $i->decision_finale,
            'moyenne_annuelle' => $i->moyenne_annuelle,
            'niveau_a_suivre_id' => $i->niveau_a_suivre_id,
            'niveau_a_suivre' => $i->niveauASuivre?->libelle,
            'reste' => (int) max(0, $i->resteAPayer()),
        ];
    }

    private function presenterLigne(Inscription $i): array
    {
        return [
            'id' => $i->id,
            'eleve' => [
                'id' => $i->eleve->id,
                'matricule' => $i->eleve->matricule,
                'nom' => $i->eleve->nom,
                'prenoms' => $i->eleve->prenoms,
                'sexe' => $i->eleve->sexe,
                'photo_url' => $i->eleve->photo_path ? url('storage/'.$i->eleve->photo_path) : null,
            ],
            'niveau' => $i->niveau ? ['id' => $i->niveau->id, 'libelle' => $i->niveau->libelle] : null,
            'classe' => $i->classe ? ['id' => $i->classe->id, 'libelle' => $i->classe->libelle] : null,
            'statut' => $i->statut,
            'affecte' => (bool) $i->affecte,
            'redoublant' => (bool) $i->redoublant,
            'inscrit_en_ligne' => (bool) $i->inscrit_en_ligne,
            // Deja scannee sans recu trouve sur le site de l'Etat.
            'en_ligne_introuvable' => ! $i->inscrit_en_ligne && $i->en_ligne_introuvable_le !== null,
            'date_inscription' => $i->date_inscription?->toDateTimeString(),
            // Liste des inscrits : aucun montant ni paiement (consultable par d'autres postes).
            'enregistre_par' => $i->enregistrePar ? trim($i->enregistrePar->prenoms.' '.$i->enregistrePar->name) : null,
            'boursier' => (bool) $i->boursier,
        ];
    }

    private function detail(Inscription $inscription, Request $request, TenantContext $tenant): array
    {
        $inscription->load(['eleve.tuteurs', 'niveau', 'classe', 'anneeScolaire', 'niveauASuivre:id,libelle', 'enregistrePar:id,name,prenoms', 'fraisEleves.typeFrais']);
        $eleve = $inscription->eleve;
        $precedente = $this->inscriptionPrecedente($eleve, $inscription->annee_scolaire_id);
        $minimum = GrilleTarifaire::where('annee_scolaire_id', $inscription->annee_scolaire_id)
            ->where('niveau_id', Niveau::generalId($inscription->niveau_id))->where('affecte', $inscription->affecte)
            ->value('montant_minimum_inscription');
        $frais = $inscription->fraisEleves->sortBy(fn (FraisEleve $f) => [$f->typeFrais->ordre, $f->type_frais_id]);

        return array_merge($this->presenterLigne($inscription), [
            // Fiche : montants de l'inscription (absents de la liste des inscrits).
            'total_du' => (int) $inscription->montant_total_du,
            'total_reduit' => (int) $inscription->montant_total_reduit,
            'total_paye' => (int) $inscription->montant_total_paye,
            'reste' => (int) max(0, $inscription->resteAPayer()),
            'annee' => $inscription->anneeScolaire?->libelle,
            'eleve' => $this->presenterEleve($eleve),
            'langue_vivante_2' => $inscription->langue_vivante_2,
            'boursier' => (bool) $inscription->boursier,
            'etablissement_origine' => $inscription->etablissement_origine,
            'classe_origine' => $inscription->classe_origine,
            'decision_origine' => $inscription->decision_origine,
            'moyenne_origine' => $inscription->moyenne_origine,
            'decision_finale' => $inscription->decision_finale,
            'niveau_a_suivre' => $inscription->niveauASuivre?->libelle,
            'inscription_en_ligne' => $inscription->inscription_en_ligne,
            'enregistre_par' => \App\Support\Personnes::agent($inscription->enregistrePar),
            'precedente' => $precedente ? $this->presenterPrecedente($precedente) : null,
            'frais' => $frais->whereNull('quantite_due')->map(fn (FraisEleve $f) => [
                'id' => $f->id,
                'libelle' => $f->typeFrais->libelle,
                'nature' => $f->typeFrais->nature,
                'montant_du' => (int) $f->montant_du,
                'montant_reduit' => (int) $f->montant_reduit,
                'montant_paye' => (int) $f->montant_paye,
                'reste' => (int) max(0, $f->resteAPayer()),
            ])->values(),
            'fournitures' => $frais->whereNotNull('quantite_due')->map(fn (FraisEleve $f) => [
                'id' => $f->id,
                'libelle' => $f->typeFrais->libelle,
                'quantite_due' => (int) $f->quantite_due,
                'quantite_remise' => (int) $f->quantite_remise,
            ])->values(),
            'dettes' => $this->dettesOuvertes($eleve, (int) $inscription->annee_scolaire_id),
            'montant_minimum' => $minimum !== null ? (int) $minimum : null,
            'grille_definie' => $minimum !== null,
            'modifiable_tarif' => ! $this->aDesPaiements($inscription),
            'classe_detail' => $inscription->classe ? $this->presenterClasse($inscription->classe) : null,
            // En-tete de la fiche d'inscription imprimee.
            'etablissement' => ($e = Etablissement::find($tenant->id())) ? [
                'nom' => $e->nom,
                'slogan' => $e->slogan,
                'logo_url' => $e->logo_path ? url('storage/'.$e->logo_path) : null,
                'contacts' => collect([$e->boite_postale, $e->ville, $e->telephone1, $e->telephone2, $e->email])->filter()->implode(' · '),
            ] : null,
        ]);
    }
}
