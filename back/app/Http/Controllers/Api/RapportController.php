<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Etablissement;
use App\Models\Periode;
use App\Support\BilanCaisse;
use App\Support\Document;
use App\Support\PorteePedagogique;
use App\Support\Reductions;
use App\Support\Resultats;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rapports (V1 DirecteurEtude/rapports.php, droit rapports.voir) : tableaux
 * au meme format ({colonnes, groupes: [{titre, lignes, total}], total}) pour
 * l'ecran et les documents (PDF, Word, Excel).
 */
class RapportController extends Controller
{
    /**
     * Rapports proposes : code => [titre, description, periode requise, rubrique].
     * Un rapport peut etre retire du catalogue (parametre rapports_masques).
     */
    public const RAPPORTS = [
        'effectifs' => ['Tableau des effectifs', 'Effectifs par niveau et par classe : garçons, filles, affectés, non affectés, redoublants.', false, 'Effectifs'],
        'ages' => ['Effectifs par âge', 'Nombre d\'élèves de chaque âge (au 1er janvier), par niveau, filles et garçons.', false, 'Effectifs'],
        'nouveaux' => ['Nouveaux et anciens élèves', 'Par niveau : nouveaux, anciens, redoublants, boursiers et inscrits en ligne, filles et garçons.', false, 'Effectifs'],
        'resultats' => ['Résultats scolaires', 'Par classe : moyenne de classe, plus forte et plus faible moyennes, moyennes ≥ 10 et taux de réussite par sexe.', true, 'Pédagogie'],
        'majors' => ['Majors de classe', 'Le premier de chaque classe et de chaque niveau.', true, 'Pédagogie'],
        'merite' => ['Liste par ordre de mérite', 'Tous les élèves classe par classe, du premier au dernier (affectés, non affectés ou tous).', true, 'Pédagogie'],
        'matieres' => ['Moyennes par matière', 'Classe par classe : moyenne de chaque matière, plus forte, plus faible et taux de moyennes ≥ 10.', true, 'Pédagogie'],
        'distinctions' => ['Tableau d\'honneur', 'Élèves au tableau d\'honneur, avec encouragements ou félicitations.', true, 'Pédagogie'],
        'difficulte' => ['Élèves en difficulté', 'Élèves sous 10 de moyenne, avec leurs sanctions de travail et de conduite.', true, 'Pédagogie'],
        'decisions' => ['Admissions et redoublements', 'Décisions de fin d\'année (admis, redoublants, exclus) par niveau, avec l\'approche genre.', false, 'Pédagogie'],
        'absences' => ['Absences', 'Heures d\'absence par classe : justifiées, non justifiées, élèves concernés.', false, 'Vie scolaire'],
        'absents' => ['Élèves les plus absents', 'Élèves qui ont des heures d\'absence non justifiées, du plus absent au moins absent.', false, 'Vie scolaire'],
        'finances_classes' => ['Situation financière par classe', 'Dû, réduit, payé, reste à payer et taux de recouvrement de chaque classe.', false, 'Finances'],
        'recettes_frais' => ['Recettes par type de frais', 'Pour chaque frais : montant dû, réductions, encaissé, reste et taux de recouvrement.', false, 'Finances'],
        'recettes_mois' => ['Recettes et dépenses par mois', 'Encaissements (frais et dettes), dépenses et solde de chaque mois de l\'année.', false, 'Finances'],
        'reductions' => ['Réductions et cas sociaux', 'Réductions accordées par niveau : nombre et montants des réductions, cas sociaux et réductions de dette.', false, 'Finances'],
    ];

    /** Rubriques du catalogue, dans l'ordre d'affichage. */
    public const RUBRIQUES = ['Effectifs', 'Pédagogie', 'Vie scolaire', 'Finances'];

    public function index(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        $anneeId = PorteePedagogique::anneeId($tenant);

        $masques = $this->masques($tenant);

        return response()->json([
            'annee' => AnneeScolaire::find($anneeId)?->libelle,
            'rubriques' => self::RUBRIQUES,
            'rapports' => collect(self::RAPPORTS)->map(fn ($r, $code) => ['code' => $code, 'titre' => $r[0], 'description' => $r[1], 'periode' => $r[2],
                'rubrique' => $r[3], 'masque' => in_array($code, $masques, true)])->values(),
            'peut_masquer' => $request->user()->can('etablissement.gerer'),
            'periodes' => Periode::where('annee_scolaire_id', $anneeId)->orderBy('numero')->get()
                ->map(fn (Periode $p) => ['id' => $p->id, 'libelle' => $p->libelle, 'active' => (bool) $p->is_active]),
            'indicateurs' => $this->indicateurs($anneeId),
        ]);
    }

    /** Retire / remet des rapports dans le catalogue (droit etablissement.gerer). */
    public function masquer(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('rapports.voir') && $request->user()->can('etablissement.gerer'), 403);
        $data = $request->validate(['codes' => ['present', 'array'], 'codes.*' => ['string', Rule::in(array_keys(self::RAPPORTS))]]);
        Etablissement::findOrFail($tenant->id())->definirParametres(['rapports_masques' => array_values(array_unique($data['codes']))]);

        return response()->json(['masques' => $this->masques($tenant)]);
    }

    public function show(Request $request, TenantContext $tenant, string $code)
    {
        $this->autoriser($request);

        return response()->json($this->rapport($request, $tenant, $code));
    }

    public function document(Request $request, TenantContext $tenant, string $code)
    {
        $this->autoriser($request);
        $rapport = $this->rapport($request, $tenant, $code);

        return Document::repondre('pdf.rapport', Document::entete(Etablissement::findOrFail($tenant->id())) + [
            'rapport' => $rapport,
            'annee' => $rapport['annee'],
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], 'rapport-'.$code, $request->query('format'), count($rapport['colonnes']) > 8 ? 'landscape' : 'portrait');
    }

    // ------------------------------------------------------------ Rapports

    private function rapport(Request $request, TenantContext $tenant, string $code): array
    {
        abort_unless(isset(self::RAPPORTS[$code]), 404);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $periode = null;
        if (self::RAPPORTS[$code][2]) {
            $valeur = $request->query('periode');
            abort_unless($valeur, 422, 'Choisissez une période.');
            $periode = $valeur === 'annuel' ? 'annuel' : Periode::where('annee_scolaire_id', $anneeId)->findOrFail((int) $valeur);
        } elseif (in_array($code, ['absences', 'absents'], true) && $request->integer('periode')) {
            $periode = Periode::where('annee_scolaire_id', $anneeId)->find($request->integer('periode'));
        }

        return [
            'code' => $code,
            'titre' => self::RAPPORTS[$code][0],
            'annee' => AnneeScolaire::find($anneeId)?->libelle,
            'periode' => $periode === 'annuel' ? 'Annuelle' : ($periode instanceof Periode ? $periode->libelle : null),
        ] + $this->contenu($code, $anneeId, $periode, ['affecte' => $request->query('affecte'), 'alpha' => $request->query('ordre') === 'alpha']);
    }

    /**
     * Tableau d'un rapport ({colonnes, groupes, total, sous_titre?}), aussi
     * utilise par les rapports de rentree, de periode et annuel (RapportCompileController).
     */
    public function contenu(string $code, int $anneeId, Periode|string|null $periode, array $options = []): array
    {
        return match ($code) {
            'effectifs' => $this->effectifs($anneeId),
            'resultats' => $this->resultats($anneeId, $periode),
            'majors' => $this->majors($anneeId, $periode),
            'merite' => $this->merite($anneeId, $periode, $options['affecte'] ?? null, (bool) ($options['alpha'] ?? false)),
            'decisions' => $this->decisions($anneeId),
            'absences' => $this->absences($anneeId, $periode instanceof Periode ? $periode : null),
            'ages' => $this->ages($anneeId),
            'nouveaux' => $this->nouveaux($anneeId),
            'matieres' => $this->matieres($anneeId, $periode),
            'distinctions' => $this->distinctions($anneeId, $periode),
            'difficulte' => $this->difficulte($anneeId, $periode),
            'absents' => $this->absents($anneeId, $periode instanceof Periode ? $periode : null),
            'finances_classes' => $this->financesClasses($anneeId),
            'recettes_frais' => $this->recettesFrais($anneeId),
            'recettes_mois' => $this->recettesMois($anneeId),
            'reductions' => $this->reductions($anneeId),
        };
    }

    private function effectifs(int $anneeId): array
    {
        $lignes = DB::table('inscriptions as i')->join('eleves as e', 'e.id', '=', 'i.eleve_id')
            ->join('classes as c', 'c.id', '=', 'i.classe_id')->join('niveaux as n', 'n.id', '=', 'c.niveau_id')
            ->where('i.annee_scolaire_id', $anneeId)->where('i.etablissement_id', app(TenantContext::class)->id())->whereNull('e.deleted_at')
            ->groupBy('c.id', 'c.libelle', 'n.libelle', 'n.ordre')
            ->orderBy('n.ordre')->orderBy('c.libelle')
            ->selectRaw("c.libelle AS classe, n.libelle AS niveau,
                SUM(e.sexe = 'M') AS garcons, SUM(e.sexe = 'F') AS filles, COUNT(*) AS total,
                SUM(i.affecte = 1 AND e.sexe = 'M') AS aff_g, SUM(i.affecte = 1 AND e.sexe = 'F') AS aff_f,
                SUM(i.affecte = 0 AND e.sexe = 'M') AS naff_g, SUM(i.affecte = 0 AND e.sexe = 'F') AS naff_f,
                SUM(i.redoublant = 1) AS redoublants")
            ->get()->map(fn ($l) => collect((array) $l)->map(fn ($v, $k) => in_array($k, ['classe', 'niveau'], true) ? $v : (int) $v)->all());

        $colonnes = [
            ['cle' => 'classe', 'libelle' => 'Classe'],
            ['cle' => 'garcons', 'libelle' => 'Garçons', 'type' => 'nombre'],
            ['cle' => 'filles', 'libelle' => 'Filles', 'type' => 'nombre'],
            ['cle' => 'total', 'libelle' => 'Total', 'type' => 'nombre', 'fort' => true],
            ['cle' => 'aff_g', 'libelle' => 'Affectés G', 'type' => 'nombre'],
            ['cle' => 'aff_f', 'libelle' => 'Affectés F', 'type' => 'nombre'],
            ['cle' => 'naff_g', 'libelle' => 'Non aff. G', 'type' => 'nombre'],
            ['cle' => 'naff_f', 'libelle' => 'Non aff. F', 'type' => 'nombre'],
            ['cle' => 'redoublants', 'libelle' => 'Redoublants', 'type' => 'nombre'],
        ];

        return $this->grouper($lignes, 'niveau', $colonnes, fn (Collection $g) => $this->sommes($g, $colonnes, 'classe'));
    }

    private function resultats(int $anneeId, Periode|string $periode): array
    {
        $colonnes = [
            ['cle' => 'classe', 'libelle' => 'Classe'],
            ['cle' => 'effectif', 'libelle' => 'Effectif', 'type' => 'nombre'],
            ['cle' => 'classes', 'libelle' => 'Classés', 'type' => 'nombre'],
            ['cle' => 'moyenne_classe', 'libelle' => 'Moy. classe', 'type' => 'moyenne', 'fort' => true],
            ['cle' => 'maximum', 'libelle' => 'Plus forte', 'type' => 'moyenne'],
            ['cle' => 'minimum', 'libelle' => 'Plus faible', 'type' => 'moyenne'],
            ['cle' => 'admis_f', 'libelle' => '≥ 10 F', 'type' => 'nombre'],
            ['cle' => 'admis_g', 'libelle' => '≥ 10 G', 'type' => 'nombre'],
            ['cle' => 'moyenne_10', 'libelle' => '≥ 10 Total', 'type' => 'nombre'],
            ['cle' => 'taux_reussite', 'libelle' => 'Taux de réussite', 'type' => 'pourcent', 'fort' => true],
        ];
        $lignes = $this->classes($anneeId)->map(function (Classe $c) use ($periode) {
            $s = $this->calcul($c, $periode)['statistiques'];

            return ['classe' => $c->libelle, 'niveau' => $c->niveau?->libelle, 'admis_f' => $s['filles']['admis'], 'admis_g' => $s['garcons']['admis']] + $s;
        });
        $total = function (Collection $g) use ($colonnes) {
            $t = $this->sommes($g, $colonnes, 'classe');
            $classes = $g->sum('classes');
            // Moyenne ponderee par le nombre d'eleves classes.
            $t['moyenne_classe'] = $classes ? round($g->sum(fn ($l) => ($l['moyenne_classe'] ?? 0) * $l['classes']) / $classes, 2) : null;
            $t['maximum'] = $g->max('maximum');
            $t['minimum'] = $g->whereNotNull('minimum')->min('minimum');
            $t['taux_reussite'] = $classes ? round($g->sum('moyenne_10') * 100 / $classes, 2) : null;

            return $t;
        };

        return $this->grouper($lignes, 'niveau', $colonnes, $total);
    }

    private function majors(int $anneeId, Periode|string $periode): array
    {
        $colonnes = [
            ['cle' => 'classe', 'libelle' => 'Classe'],
            ['cle' => 'matricule', 'libelle' => 'Matricule'],
            ['cle' => 'eleve', 'libelle' => 'Nom et prénoms'],
            ['cle' => 'sexe', 'libelle' => 'Sexe', 'type' => 'centre'],
            ['cle' => 'statut', 'libelle' => 'Statut', 'type' => 'centre'],
            ['cle' => 'moyenne', 'libelle' => 'Moyenne', 'type' => 'moyenne', 'fort' => true],
            ['cle' => 'rang', 'libelle' => 'Rang niveau', 'type' => 'centre'],
        ];
        $tous = $this->classes($anneeId)->flatMap(fn (Classe $c) => collect($this->calcul($c, $periode)['eleves'])
            ->map(fn ($l) => $l + ['classe' => $c->libelle, 'niveau' => $c->niveau?->libelle]));
        $lignes = $tous->groupBy('niveau')->flatMap(function (Collection $niveau) {
            $niveau = Resultats::classer($niveau->values(), 'moyenne', 'rang_niveau');

            return $niveau->where('rang', 1)->map(fn ($l) => [
                'niveau' => $l['niveau'], 'classe' => $l['classe'], 'matricule' => $l['matricule'],
                'eleve' => $l['nom'].' '.$l['prenoms'], 'sexe' => $l['sexe'], 'statut' => $l['affecte'] ? 'Affecté' : 'Non affecté',
                'moyenne' => $l['moyenne'], 'rang' => $l['rang_niveau'] === 1 ? '1er du niveau' : $l['rang_niveau'].'e',
                'major_niveau' => $l['rang_niveau'] === 1,
            ]);
        })->values();

        return $this->grouper($lignes, 'niveau', $colonnes, null);
    }

    private function merite(int $anneeId, Periode|string $periode, ?string $affecte, bool $alphabetique): array
    {
        $colonnes = [
            ['cle' => 'rang', 'libelle' => 'Rang', 'type' => 'centre'],
            ['cle' => 'matricule', 'libelle' => 'Matricule'],
            ['cle' => 'eleve', 'libelle' => 'Nom et prénoms'],
            ['cle' => 'sexe', 'libelle' => 'Sexe', 'type' => 'centre'],
            ['cle' => 'statut', 'libelle' => 'Statut', 'type' => 'centre'],
            ['cle' => 'redoublant', 'libelle' => 'Red.', 'type' => 'centre'],
            ['cle' => 'moyenne', 'libelle' => 'Moyenne', 'type' => 'moyenne', 'fort' => true],
            ['cle' => 'distinction', 'libelle' => 'Distinction / sanction'],
        ];
        $lignes = $this->classes($anneeId)->flatMap(function (Classe $c) use ($periode, $affecte, $alphabetique) {
            return collect($this->calcul($c, $periode)['eleves'])
                ->when($affecte !== null && $affecte !== '', fn (Collection $l) => $l->where('affecte', (bool) (int) $affecte))
                ->sortBy(fn ($l) => $alphabetique ? [$l['nom'], $l['prenoms']] : [$l['rang'] ?? 9999, $l['nom']])
                ->map(fn ($l) => [
                    'classe' => $c->libelle,
                    'rang' => $l['rang'] ? $l['rang'].($l['rang'] === 1 ? 'er' : 'e').($l['rang_ex_aequo'] ? ' ex' : '') : '—',
                    'matricule' => $l['matricule'], 'eleve' => $l['nom'].' '.$l['prenoms'], 'sexe' => $l['sexe'],
                    'statut' => $l['affecte'] ? 'Aff.' : 'Non aff.', 'redoublant' => $l['redoublant'] ? 'Oui' : '',
                    'moyenne' => $l['moyenne'],
                    'distinction' => $l['distinction'] ?? implode(', ', $l['sanctions']),
                ]);
        })->values();

        $rapport = $this->grouper($lignes, 'classe', $colonnes, null);
        $rapport['sous_titre'] = collect([
            $affecte === null || $affecte === '' ? 'Tous les élèves' : ((int) $affecte ? 'Élèves affectés' : 'Élèves non affectés'),
            $alphabetique ? 'ordre alphabétique' : 'ordre de mérite',
        ])->implode(' · ');

        return $rapport;
    }

    private function decisions(int $anneeId): array
    {
        $colonnes = [['cle' => 'niveau', 'libelle' => 'Niveau']];
        foreach (['ADMIS' => 'Admis', 'REDOUBLE' => 'Redoublants', 'EXCLU' => 'Exclus', 'AUCUNE' => 'Sans décision'] as $d => $libelle) {
            foreach (['f' => 'F', 'g' => 'G', 't' => 'Total'] as $s => $ls) {
                $colonnes[] = ['cle' => strtolower($d).'_'.$s, 'libelle' => $libelle.' '.$ls, 'type' => 'nombre', 'fort' => $s === 't'];
            }
        }
        $lignes = DB::table('inscriptions as i')->join('eleves as e', 'e.id', '=', 'i.eleve_id')->join('niveaux as n', 'n.id', '=', 'i.niveau_id')
            ->where('i.annee_scolaire_id', $anneeId)->where('i.etablissement_id', app(TenantContext::class)->id())
            ->whereNotNull('i.classe_id')->whereNull('e.deleted_at')
            ->groupBy('n.id', 'n.libelle', 'n.ordre')->orderBy('n.ordre')
            ->selectRaw("n.libelle AS niveau,
                SUM(i.decision_finale = 'ADMIS' AND e.sexe = 'F') AS admis_f, SUM(i.decision_finale = 'ADMIS' AND e.sexe = 'M') AS admis_g, SUM(i.decision_finale = 'ADMIS') AS admis_t,
                SUM(i.decision_finale = 'REDOUBLE' AND e.sexe = 'F') AS redouble_f, SUM(i.decision_finale = 'REDOUBLE' AND e.sexe = 'M') AS redouble_g, SUM(i.decision_finale = 'REDOUBLE') AS redouble_t,
                SUM(i.decision_finale = 'EXCLU' AND e.sexe = 'F') AS exclu_f, SUM(i.decision_finale = 'EXCLU' AND e.sexe = 'M') AS exclu_g, SUM(i.decision_finale = 'EXCLU') AS exclu_t,
                SUM(i.decision_finale IS NULL AND e.sexe = 'F') AS aucune_f, SUM(i.decision_finale IS NULL AND e.sexe = 'M') AS aucune_g, SUM(i.decision_finale IS NULL) AS aucune_t")
            ->get()->map(fn ($l) => collect((array) $l)->map(fn ($v, $k) => $k === 'niveau' ? $v : (int) $v)->all());

        return [
            'colonnes' => $colonnes,
            'groupes' => [['titre' => null, 'lignes' => $lignes->all(), 'total' => null]],
            'total' => $this->sommes($lignes, $colonnes, 'niveau'),
        ];
    }

    private function absences(int $anneeId, ?Periode $periode): array
    {
        $colonnes = [
            ['cle' => 'classe', 'libelle' => 'Classe'],
            ['cle' => 'effectif', 'libelle' => 'Effectif', 'type' => 'nombre'],
            ['cle' => 'eleves', 'libelle' => 'Élèves absents', 'type' => 'nombre'],
            ['cle' => 'absences', 'libelle' => 'Absences', 'type' => 'nombre'],
            ['cle' => 'heures', 'libelle' => 'Heures', 'type' => 'nombre', 'fort' => true],
            ['cle' => 'justifiees', 'libelle' => 'Justifiées', 'type' => 'nombre'],
            ['cle' => 'non_justifiees', 'libelle' => 'Non justifiées', 'type' => 'nombre'],
        ];
        $absences = DB::table('absences')->where('etablissement_id', app(TenantContext::class)->id())->where('annee_scolaire_id', $anneeId)
            ->when($periode, fn ($q) => $q->where('periode_id', $periode->id))
            ->groupBy('classe_id')
            ->selectRaw('classe_id, COUNT(DISTINCT eleve_id) AS eleves, COUNT(*) AS absences, SUM(nombre_heures) AS heures,
                SUM(CASE WHEN is_justifiee THEN nombre_heures ELSE 0 END) AS justifiees')
            ->get()->keyBy('classe_id');
        $effectifs = DB::table('inscriptions')->where('annee_scolaire_id', $anneeId)->whereNotNull('classe_id')
            ->groupBy('classe_id')->selectRaw('classe_id, COUNT(*) AS n')->pluck('n', 'classe_id');
        $lignes = $this->classes($anneeId)->map(function (Classe $c) use ($absences, $effectifs) {
            $a = $absences->get($c->id);

            return [
                'classe' => $c->libelle, 'niveau' => $c->niveau?->libelle,
                'effectif' => (int) ($effectifs[$c->id] ?? 0),
                'eleves' => (int) ($a->eleves ?? 0), 'absences' => (int) ($a->absences ?? 0),
                'heures' => (float) ($a->heures ?? 0), 'justifiees' => (float) ($a->justifiees ?? 0),
                'non_justifiees' => (float) ($a->heures ?? 0) - (float) ($a->justifiees ?? 0),
            ];
        });

        return $this->grouper($lignes, 'niveau', $colonnes, fn (Collection $g) => $this->sommes($g, $colonnes, 'classe'));
    }

    // ------------------------------------------------------------ Rapports ajoutes

    private function ages(int $anneeId): array
    {
        $annee = AnneeScolaire::find($anneeId);
        $reference = (((int) substr((string) $annee?->libelle, 0, 4)) ?: (int) date('Y')) + 1 .'-01-01';
        $tranches = ['moins' => '- de 11 ans'];
        foreach (range(11, 20) as $a) {
            $tranches['a'.$a] = $a.' ans';
        }
        $tranches['plus'] = '21 ans et +';
        $lignes = DB::table('inscriptions as i')->join('eleves as e', 'e.id', '=', 'i.eleve_id')->join('niveaux as n', 'n.id', '=', 'i.niveau_id')
            ->where('i.annee_scolaire_id', $anneeId)->where('i.etablissement_id', app(TenantContext::class)->id())->whereNull('e.deleted_at')
            ->selectRaw('n.libelle AS niveau, n.ordre, e.sexe, TIMESTAMPDIFF(YEAR, e.date_naissance, ?) AS age', [$reference])
            ->get();
        $tranche = fn ($age) => $age === null ? null : ($age < 11 ? 'moins' : ($age > 20 ? 'plus' : 'a'.$age));
        $colonnes = [['cle' => 'niveau', 'libelle' => 'Niveau'], ['cle' => 'sexe', 'libelle' => 'Sexe', 'type' => 'centre']];
        foreach ($tranches as $cle => $libelle) {
            $colonnes[] = ['cle' => $cle, 'libelle' => $libelle, 'type' => 'nombre'];
        }
        $colonnes[] = ['cle' => 'inconnu', 'libelle' => 'Inconnu', 'type' => 'nombre'];
        $colonnes[] = ['cle' => 'total', 'libelle' => 'Total', 'type' => 'nombre', 'fort' => true];
        $compter = function (Collection $l, string $libelle, string $sexe) use ($tranches, $tranche) {
            $r = ['niveau' => $libelle, 'sexe' => $sexe];
            foreach (array_keys($tranches) as $c) {
                $r[$c] = $l->filter(fn ($x) => $tranche($x->age === null ? null : (int) $x->age) === $c)->count();
            }
            $r['inconnu'] = $l->whereNull('age')->count();
            $r['total'] = $l->count();

            return $r;
        };
        $groupes = $lignes->sortBy('ordre')->groupBy('niveau')->map(fn (Collection $l, $niveau) => [
            'titre' => (string) $niveau,
            'lignes' => [$compter($l->where('sexe', 'F'), $niveau, 'F'), $compter($l->where('sexe', 'M'), $niveau, 'G')],
            'total' => $compter($l, 'Total', 'F + G'),
        ])->values()->all();

        return ['colonnes' => $colonnes, 'groupes' => $groupes, 'total' => $lignes->isNotEmpty() ? $compter($lignes, 'Total', 'F + G') : null,
            'sous_titre' => 'Âge au 1er janvier '.substr($reference, 0, 4)];
    }

    private function nouveaux(int $anneeId): array
    {
        $colonnes = [['cle' => 'niveau', 'libelle' => 'Niveau']];
        foreach (['nouveaux' => 'Nouveaux', 'anciens' => 'Anciens', 'redoublants' => 'Redoublants', 'boursiers' => 'Boursiers', 'en_ligne' => 'En ligne'] as $c => $l) {
            $colonnes[] = ['cle' => $c.'_f', 'libelle' => $l.' F', 'type' => 'nombre'];
            $colonnes[] = ['cle' => $c.'_g', 'libelle' => $l.' G', 'type' => 'nombre'];
        }
        $colonnes[] = ['cle' => 'total', 'libelle' => 'Total', 'type' => 'nombre', 'fort' => true];
        $ancien = '(EXISTS (SELECT 1 FROM inscriptions p JOIN annees_scolaires ap ON ap.id = p.annee_scolaire_id
            WHERE p.eleve_id = i.eleve_id AND p.id <> i.id AND ap.libelle < a.libelle))';
        $lignes = DB::table('inscriptions as i')->join('eleves as e', 'e.id', '=', 'i.eleve_id')->join('niveaux as n', 'n.id', '=', 'i.niveau_id')
            ->join('annees_scolaires as a', 'a.id', '=', 'i.annee_scolaire_id')
            ->where('i.annee_scolaire_id', $anneeId)->where('i.etablissement_id', app(TenantContext::class)->id())->whereNull('e.deleted_at')
            ->groupBy('n.id', 'n.libelle', 'n.ordre')->orderBy('n.ordre')
            ->selectRaw("n.libelle AS niveau,
                SUM(NOT {$ancien} AND e.sexe = 'F') AS nouveaux_f, SUM(NOT {$ancien} AND e.sexe = 'M') AS nouveaux_g,
                SUM({$ancien} AND e.sexe = 'F') AS anciens_f, SUM({$ancien} AND e.sexe = 'M') AS anciens_g,
                SUM(i.redoublant = 1 AND e.sexe = 'F') AS redoublants_f, SUM(i.redoublant = 1 AND e.sexe = 'M') AS redoublants_g,
                SUM(i.boursier = 1 AND e.sexe = 'F') AS boursiers_f, SUM(i.boursier = 1 AND e.sexe = 'M') AS boursiers_g,
                SUM(i.inscrit_en_ligne = 1 AND e.sexe = 'F') AS en_ligne_f, SUM(i.inscrit_en_ligne = 1 AND e.sexe = 'M') AS en_ligne_g,
                COUNT(*) AS total")
            ->get()->map(fn ($l) => collect((array) $l)->map(fn ($v, $k) => $k === 'niveau' ? $v : (int) $v)->all());

        return ['colonnes' => $colonnes, 'groupes' => [['titre' => null, 'lignes' => $lignes->all(), 'total' => null]],
            'total' => $lignes->isNotEmpty() ? $this->sommes($lignes, $colonnes, 'niveau') : null,
            'sous_titre' => 'Ancien : élève déjà inscrit une année précédente dans l\'établissement'];
    }

    private function matieres(int $anneeId, Periode|string $periode): array
    {
        $colonnes = [
            ['cle' => 'matiere', 'libelle' => 'Matière'],
            ['cle' => 'coefficient', 'libelle' => 'Coef.', 'type' => 'nombre'],
            ['cle' => 'notes', 'libelle' => 'Élèves notés', 'type' => 'nombre'],
            ['cle' => 'moyenne', 'libelle' => 'Moyenne', 'type' => 'moyenne', 'fort' => true],
            ['cle' => 'maximum', 'libelle' => 'Plus forte', 'type' => 'moyenne'],
            ['cle' => 'minimum', 'libelle' => 'Plus faible', 'type' => 'moyenne'],
            ['cle' => 'au_dessus', 'libelle' => '≥ 10', 'type' => 'nombre'],
            ['cle' => 'taux', 'libelle' => 'Taux ≥ 10', 'type' => 'pourcent', 'fort' => true],
        ];
        $groupes = $this->classes($anneeId)->map(function (Classe $c) use ($periode) {
            $r = $this->calcul($c, $periode);
            $lignes = collect($r['matieres'])->map(function ($m) use ($r) {
                $moyennes = collect($r['eleves'])->map(fn ($e) => $e['moyennes'][$m['id']]['moyenne'] ?? null)->filter(fn ($v) => $v !== null);
                $n = $moyennes->count();
                $dessus = $moyennes->filter(fn ($v) => $v >= 10)->count();

                return [
                    'matiere' => $m['libelle'], 'coefficient' => $m['coefficient'], 'notes' => $n,
                    'moyenne' => $n ? round($moyennes->avg(), 2) : null, 'maximum' => $n ? $moyennes->max() : null, 'minimum' => $n ? $moyennes->min() : null,
                    'au_dessus' => $dessus, 'taux' => $n ? round($dessus * 100 / $n, 2) : null,
                ];
            })->filter(fn ($l) => $l['notes'] > 0)->values();

            return ['titre' => $c->libelle, 'lignes' => $lignes->all(), 'total' => null];
        })->filter(fn ($g) => $g['lignes'])->values();

        return ['colonnes' => $colonnes, 'groupes' => $groupes->all(), 'total' => null];
    }

    private function distinctions(int $anneeId, Periode|string $periode): array
    {
        $colonnes = [
            ['cle' => 'rang', 'libelle' => 'Rang', 'type' => 'centre'],
            ['cle' => 'matricule', 'libelle' => 'Matricule'],
            ['cle' => 'eleve', 'libelle' => 'Nom et prénoms'],
            ['cle' => 'sexe', 'libelle' => 'Sexe', 'type' => 'centre'],
            ['cle' => 'moyenne', 'libelle' => 'Moyenne', 'type' => 'moyenne', 'fort' => true],
            ['cle' => 'distinction', 'libelle' => 'Distinction'],
        ];
        $lignes = $this->classes($anneeId)->flatMap(fn (Classe $c) => collect($this->calcul($c, $periode)['eleves'])
            ->filter(fn ($l) => ! empty($l['distinction']))->sortBy(fn ($l) => $l['rang'] ?? 9999)
            ->map(fn ($l) => ['classe' => $c->libelle, 'rang' => $l['rang'] ? $l['rang'].($l['rang'] === 1 ? 'er' : 'e') : '—',
                'matricule' => $l['matricule'], 'eleve' => $l['nom'].' '.$l['prenoms'], 'sexe' => $l['sexe'], 'moyenne' => $l['moyenne'], 'distinction' => $l['distinction']]))->values();
        $rapport = $this->grouper($lignes, 'classe', $colonnes, null);
        $rapport['sous_titre'] = $lignes->count().' élève'.($lignes->count() > 1 ? 's' : '').' · tableau d\'honneur dès 12, encouragements dès 14, félicitations dès 16';

        return $rapport;
    }

    private function difficulte(int $anneeId, Periode|string $periode): array
    {
        $colonnes = [
            ['cle' => 'rang', 'libelle' => 'Rang', 'type' => 'centre'],
            ['cle' => 'matricule', 'libelle' => 'Matricule'],
            ['cle' => 'eleve', 'libelle' => 'Nom et prénoms'],
            ['cle' => 'sexe', 'libelle' => 'Sexe', 'type' => 'centre'],
            ['cle' => 'redoublant', 'libelle' => 'Red.', 'type' => 'centre'],
            ['cle' => 'moyenne', 'libelle' => 'Moyenne', 'type' => 'moyenne', 'fort' => true],
            ['cle' => 'sanctions', 'libelle' => 'Sanctions'],
        ];
        $lignes = $this->classes($anneeId)->flatMap(fn (Classe $c) => collect($this->calcul($c, $periode)['eleves'])
            ->filter(fn ($l) => $l['moyenne'] !== null && $l['moyenne'] < 10)->sortBy('moyenne')
            ->map(fn ($l) => ['classe' => $c->libelle, 'rang' => $l['rang'] ? $l['rang'].'e' : '—', 'matricule' => $l['matricule'], 'eleve' => $l['nom'].' '.$l['prenoms'],
                'sexe' => $l['sexe'], 'redoublant' => $l['redoublant'] ? 'Oui' : '', 'moyenne' => $l['moyenne'], 'sanctions' => implode(', ', $l['sanctions'])]))->values();
        $rapport = $this->grouper($lignes, 'classe', $colonnes, null);
        $rapport['sous_titre'] = $lignes->count().' élève'.($lignes->count() > 1 ? 's' : '').' sous 10 de moyenne';

        return $rapport;
    }

    private function absents(int $anneeId, ?Periode $periode): array
    {
        $colonnes = [
            ['cle' => 'matricule', 'libelle' => 'Matricule'],
            ['cle' => 'eleve', 'libelle' => 'Nom et prénoms'],
            ['cle' => 'sexe', 'libelle' => 'Sexe', 'type' => 'centre'],
            ['cle' => 'absences', 'libelle' => 'Absences', 'type' => 'nombre'],
            ['cle' => 'heures', 'libelle' => 'Heures', 'type' => 'nombre'],
            ['cle' => 'non_justifiees', 'libelle' => 'Non justifiées', 'type' => 'nombre', 'fort' => true],
        ];
        $nonJustifiees = 'SUM(CASE WHEN a.is_justifiee THEN 0 ELSE a.nombre_heures END)';
        $lignes = DB::table('absences as a')->join('eleves as e', 'e.id', '=', 'a.eleve_id')->join('classes as c', 'c.id', '=', 'a.classe_id')->join('niveaux as n', 'n.id', '=', 'c.niveau_id')
            ->where('a.etablissement_id', app(TenantContext::class)->id())->where('a.annee_scolaire_id', $anneeId)
            ->when($periode, fn ($q) => $q->where('a.periode_id', $periode->id))
            ->groupBy('e.id', 'e.matricule', 'e.nom', 'e.prenoms', 'e.sexe', 'c.libelle', 'n.ordre')
            ->havingRaw($nonJustifiees.' > 0')
            ->orderBy('n.ordre')->orderBy('c.libelle')->orderByRaw($nonJustifiees.' DESC')
            ->selectRaw("c.libelle AS classe, e.matricule, CONCAT(e.nom, ' ', e.prenoms) AS eleve, e.sexe, COUNT(*) AS absences, SUM(a.nombre_heures) AS heures, {$nonJustifiees} AS non_justifiees")
            ->get()->map(fn ($l) => ['classe' => $l->classe, 'matricule' => $l->matricule, 'eleve' => $l->eleve, 'sexe' => $l->sexe,
                'absences' => (int) $l->absences, 'heures' => (float) $l->heures, 'non_justifiees' => (float) $l->non_justifiees]);

        return $this->grouper($lignes, 'classe', $colonnes, fn (Collection $g) => $this->sommes($g, $colonnes, 'matricule'));
    }

    private function financesClasses(int $anneeId): array
    {
        $colonnes = [
            ['cle' => 'classe', 'libelle' => 'Classe'],
            ['cle' => 'eleves', 'libelle' => 'Élèves', 'type' => 'nombre'],
            ['cle' => 'du', 'libelle' => 'Dû', 'type' => 'montant'],
            ['cle' => 'reduit', 'libelle' => 'Réduit', 'type' => 'montant'],
            ['cle' => 'paye', 'libelle' => 'Payé', 'type' => 'montant', 'fort' => true],
            ['cle' => 'reste', 'libelle' => 'Reste', 'type' => 'montant', 'fort' => true],
            ['cle' => 'soldes', 'libelle' => 'Soldés', 'type' => 'nombre'],
            ['cle' => 'taux', 'libelle' => 'Recouvrement', 'type' => 'pourcent'],
        ];
        $lignes = DB::table('inscriptions as i')->join('eleves as e', 'e.id', '=', 'i.eleve_id')->join('niveaux as n', 'n.id', '=', 'i.niveau_id')->leftJoin('classes as c', 'c.id', '=', 'i.classe_id')
            ->where('i.annee_scolaire_id', $anneeId)->where('i.etablissement_id', app(TenantContext::class)->id())->whereNull('e.deleted_at')
            ->groupBy('n.id', 'n.libelle', 'n.ordre', 'c.id', 'c.libelle')->orderBy('n.ordre')->orderBy('c.libelle')
            ->selectRaw("n.libelle AS niveau, COALESCE(c.libelle, CONCAT(n.libelle, ' (sans classe)')) AS classe, COUNT(*) AS eleves,
                SUM(i.montant_total_du) AS du, SUM(i.montant_total_reduit) AS reduit, SUM(i.montant_total_paye) AS paye,
                SUM(GREATEST(i.montant_total_du - i.montant_total_reduit - i.montant_total_paye, 0)) AS reste,
                SUM(i.statut = 3) AS soldes")
            ->get()->map(fn ($l) => $this->taux(collect((array) $l)->map(fn ($v, $k) => in_array($k, ['classe', 'niveau'], true) ? $v : (int) $v)->all()));

        return $this->grouper($lignes, 'niveau', $colonnes, fn (Collection $g) => $this->taux($this->sommes($g, $colonnes, 'classe')));
    }

    private function recettesFrais(int $anneeId): array
    {
        $colonnes = [
            ['cle' => 'frais', 'libelle' => 'Type de frais'],
            ['cle' => 'eleves', 'libelle' => 'Élèves', 'type' => 'nombre'],
            ['cle' => 'du', 'libelle' => 'Dû', 'type' => 'montant'],
            ['cle' => 'reduit', 'libelle' => 'Réduit', 'type' => 'montant'],
            ['cle' => 'paye', 'libelle' => 'Encaissé', 'type' => 'montant', 'fort' => true],
            ['cle' => 'reste', 'libelle' => 'Reste', 'type' => 'montant', 'fort' => true],
            ['cle' => 'taux', 'libelle' => 'Recouvrement', 'type' => 'pourcent'],
        ];
        $lignes = DB::table('frais_eleves as f')->join('inscriptions as i', 'i.id', '=', 'f.inscription_id')->join('types_frais as t', 't.id', '=', 'f.type_frais_id')
            ->where('i.annee_scolaire_id', $anneeId)->where('i.etablissement_id', app(TenantContext::class)->id())->whereNull('f.quantite_due')
            ->groupBy('t.id', 't.libelle', 't.nature', 't.ordre')->orderBy('t.ordre')
            ->selectRaw('t.libelle AS frais, t.nature, COUNT(DISTINCT i.id) AS eleves, SUM(f.montant_du) AS du, SUM(f.montant_reduit) AS reduit, SUM(f.montant_paye) AS paye,
                SUM(GREATEST(f.montant_du - f.montant_reduit - f.montant_paye, 0)) AS reste')
            ->havingRaw('SUM(f.montant_du) > 0')
            ->get()->map(fn ($l) => $this->taux(['groupe' => $l->nature === 'annexe' ? 'Frais annexes' : 'Inscription et scolarité', 'frais' => $l->frais,
                'eleves' => (int) $l->eleves, 'du' => (int) $l->du, 'reduit' => (int) $l->reduit, 'paye' => (int) $l->paye, 'reste' => (int) $l->reste]));
        $total = fn (Collection $g) => $this->taux(collect($colonnes)->mapWithKeys(fn ($c) => [$c['cle'] => $c['cle'] === 'frais' ? 'Total' : (($c['type'] ?? '') === 'montant' ? $g->sum($c['cle']) : null)])->all());
        $rapport = $this->grouper($lignes, 'groupe', $colonnes, $total);
        $dettes = (int) DB::table('reglement_lignes as rl')->join('reglements as g', 'g.id', '=', 'rl.reglement_id')
            ->where('g.etablissement_id', app(TenantContext::class)->id())->where('g.annee_scolaire_id', $anneeId)->whereNotNull('rl.dette_id')->sum('rl.montant');
        $rapport['sous_titre'] = 'Dettes des années précédentes encaissées cette année : '.number_format($dettes, 0, ',', ' ').' F (hors total)';

        return $rapport;
    }

    private function recettesMois(int $anneeId): array
    {
        $colonnes = [
            ['cle' => 'mois', 'libelle' => 'Mois'],
            ['cle' => 'paiements', 'libelle' => 'Paiements', 'type' => 'nombre'],
            ['cle' => 'frais', 'libelle' => 'Frais de l\'année', 'type' => 'montant'],
            ['cle' => 'dettes', 'libelle' => 'Dettes', 'type' => 'montant'],
            ['cle' => 'recettes', 'libelle' => 'Total recettes', 'type' => 'montant', 'fort' => true],
            ['cle' => 'depenses', 'libelle' => 'Dépenses', 'type' => 'montant'],
            ['cle' => 'solde', 'libelle' => 'Solde', 'type' => 'montant', 'fort' => true],
        ];
        $etab = app(TenantContext::class)->id();
        $recettes = DB::table('reglement_lignes as rl')->join('reglements as g', 'g.id', '=', 'rl.reglement_id')
            ->where('g.etablissement_id', $etab)->where('g.annee_scolaire_id', $anneeId)
            ->groupByRaw("DATE_FORMAT(g.date_paiement, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(g.date_paiement, '%Y-%m') AS mois, COUNT(DISTINCT g.id) AS paiements,
                SUM(CASE WHEN rl.dette_id IS NULL THEN rl.montant ELSE 0 END) AS frais, SUM(CASE WHEN rl.dette_id IS NULL THEN 0 ELSE rl.montant END) AS dettes")
            ->get()->keyBy('mois');
        $depenses = DB::table('depenses')->where('etablissement_id', $etab)->where('annee_scolaire_id', $anneeId)
            ->groupByRaw("DATE_FORMAT(date_depense, '%Y-%m')")->selectRaw("DATE_FORMAT(date_depense, '%Y-%m') AS mois, SUM(montant) AS montant")->pluck('montant', 'mois');
        $lignes = collect(BilanCaisse::moisDeLAnnee(AnneeScolaire::find($anneeId)))->map(function ($m) use ($recettes, $depenses) {
            $r = $recettes->get($m['valeur']);
            $frais = (int) ($r->frais ?? 0);
            $dettes = (int) ($r->dettes ?? 0);
            $dep = (int) ($depenses[$m['valeur']] ?? 0);

            return ['mois' => $m['libelle'], 'paiements' => (int) ($r->paiements ?? 0), 'frais' => $frais, 'dettes' => $dettes,
                'recettes' => $frais + $dettes, 'depenses' => $dep, 'solde' => $frais + $dettes - $dep];
        });
        $total = collect($colonnes)->mapWithKeys(fn ($c) => [$c['cle'] => $c['cle'] === 'mois' ? 'Total' : $lignes->sum($c['cle'])])->all();

        return ['colonnes' => $colonnes, 'groupes' => [['titre' => null, 'lignes' => $lignes->all(), 'total' => null]], 'total' => $lignes->isNotEmpty() ? $total : null];
    }

    private function reductions(int $anneeId): array
    {
        $colonnes = [
            ['cle' => 'niveau', 'libelle' => 'Niveau'],
            ['cle' => 'reductions_n', 'libelle' => 'Réductions', 'type' => 'nombre'],
            ['cle' => 'reductions', 'libelle' => 'Montant', 'type' => 'montant'],
            ['cle' => 'cas_n', 'libelle' => 'Cas sociaux', 'type' => 'nombre'],
            ['cle' => 'cas', 'libelle' => 'Montant', 'type' => 'montant'],
            ['cle' => 'dettes_n', 'libelle' => 'Réd. de dette', 'type' => 'nombre'],
            ['cle' => 'dettes', 'libelle' => 'Montant', 'type' => 'montant'],
            ['cle' => 'total', 'libelle' => 'Total', 'type' => 'montant', 'fort' => true],
        ];
        $toutes = Reductions::requete($anneeId, ['criteres' => []])->get()->map(fn ($l) => Reductions::presenter($l));
        $lignes = $toutes->sortBy('niveau_ordre')->groupBy('niveau')->map(function (Collection $l, $niveau) {
            $cas = $l->where('code', 'CAS');
            $dettes = $l->where('cible', 'dette');
            $reductions = $l->where('cible', 'inscription')->where('code', '!=', 'CAS');

            return ['niveau' => $niveau, 'reductions_n' => $reductions->count(), 'reductions' => (int) $reductions->sum('montant'),
                'cas_n' => $cas->count(), 'cas' => (int) $cas->sum('montant'), 'dettes_n' => $dettes->count(), 'dettes' => (int) $dettes->sum('montant'),
                'total' => (int) $l->sum('montant')];
        })->values();

        return ['colonnes' => $colonnes, 'groupes' => [['titre' => null, 'lignes' => $lignes->all(), 'total' => null]],
            'total' => $lignes->isNotEmpty() ? $this->sommes($lignes, $colonnes, 'niveau') : null];
    }

    /** Taux de recouvrement : paye / (du - reduit). */
    private function taux(array $l): array
    {
        $net = ($l['du'] ?? 0) - ($l['reduit'] ?? 0);
        $l['taux'] = $net > 0 ? round(($l['paye'] ?? 0) * 100 / $net, 2) : null;

        return $l;
    }

    // ------------------------------------------------------------ Outils

    /** Codes des rapports retires du catalogue par l'etablissement. */
    private function masques(TenantContext $tenant): array
    {
        return array_values((array) (Etablissement::find($tenant->id())?->parametre('rapports_masques') ?? []));
    }

    /** Chiffres cles de l'accueil des rapports. */
    public function indicateurs(int $anneeId): array
    {
        $etab = app(TenantContext::class)->id();
        $inscrits = DB::table('inscriptions as i')->join('eleves as e', 'e.id', '=', 'i.eleve_id')
            ->where('i.etablissement_id', $etab)->where('i.annee_scolaire_id', $anneeId)->whereNotNull('i.classe_id')->whereNull('e.deleted_at')
            ->selectRaw("COUNT(*) AS total, SUM(e.sexe = 'F') AS filles, SUM(i.affecte = 1) AS affectes")->first();
        $absences = DB::table('absences')->where('etablissement_id', $etab)->where('annee_scolaire_id', $anneeId)
            ->selectRaw('COALESCE(SUM(nombre_heures), 0) AS heures, COALESCE(SUM(CASE WHEN is_justifiee THEN 0 ELSE nombre_heures END), 0) AS non_justifiees')->first();

        return [
            'eleves' => (int) $inscrits->total,
            'filles' => (int) $inscrits->filles,
            'affectes' => (int) $inscrits->affectes,
            'classes' => Classe::where('annee_scolaire_id', $anneeId)->count(),
            'heures_absence' => (float) $absences->heures,
            'heures_non_justifiees' => (float) $absences->non_justifiees,
            'notes' => DB::table('notes as n')->join('periodes as p', 'p.id', '=', 'n.periode_id')->where('p.annee_scolaire_id', $anneeId)->count(),
        ];
    }

    /** Classes de l'annee, par niveau puis libelle. */
    private function classes(int $anneeId): Collection
    {
        return Classe::where('annee_scolaire_id', $anneeId)->with('niveau')->get()
            ->sortBy(fn (Classe $c) => [$c->niveau?->ordre, $c->libelle])->values();
    }

    /** Resultats d'une classe, calcules une fois par requete (un rapport compile en reutilise plusieurs). */
    private array $calculs = [];

    private function calcul(Classe $c, Periode|string $periode): array
    {
        $cle = $c->id.'-'.($periode instanceof Periode ? $periode->id : $periode);

        return $this->calculs[$cle] ??= $periode === 'annuel'
            ? Resultats::annuel($c, Periode::where('annee_scolaire_id', $c->annee_scolaire_id)->orderBy('numero')->get())
            : Resultats::periode($c, $periode);
    }

    /** Lignes regroupees (niveau, classe) avec sous-totaux et total general. */
    private function grouper(Collection $lignes, string $cle, array $colonnes, ?callable $total): array
    {
        $groupes = $lignes->groupBy($cle)->map(fn (Collection $g, $titre) => [
            'titre' => (string) $titre,
            'lignes' => $g->values()->all(),
            'total' => $total && $g->count() > 1 ? $total($g) : null,
        ])->values();

        return [
            'colonnes' => $colonnes,
            'groupes' => $groupes->all(),
            'total' => $total && $lignes->isNotEmpty() ? $total($lignes) : null,
        ];
    }

    /** Somme des colonnes numeriques, libelle "Total" dans la premiere. */
    private function sommes(Collection $lignes, array $colonnes, string $premiere): array
    {
        return collect($colonnes)->mapWithKeys(fn ($c) => [
            $c['cle'] => $c['cle'] === $premiere ? 'Total' : (in_array($c['type'] ?? null, ['nombre', 'montant'], true) ? $lignes->sum($c['cle']) : null),
        ])->all();
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('rapports.voir'), 403);
    }
}
