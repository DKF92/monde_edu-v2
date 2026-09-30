<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Etablissement;
use App\Models\Niveau;
use App\Models\Periode;
use App\Support\Document;
use App\Support\PorteePedagogique;
use App\Support\Resultats;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Rapports parametrables (droit rapports.voir) : une liste d'eleves dont
 * l'etablissement choisit les colonnes (champs de la base ou colonnes libres
 * a remplir a la main : "Signature", "Observation"...), le regroupement
 * (etablissement, cycle, niveau ou classe), les filtres et le tri.
 *
 * Les modeles enregistres (parametre rapports_personnalises) apparaissent dans
 * les rapports, rubrique "Mes rapports" ; seul etablissement.gerer les
 * enregistre ou les supprime, tout detenteur de rapports.voir les imprime.
 */
class RapportPersonnaliseController extends Controller
{
    /** Champs proposes : cle => [libelle, rubrique, type d'affichage]. */
    public const CHAMPS = [
        'matricule' => ['Matricule', 'Élève', 'texte'],
        'nom' => ['Nom', 'Élève', 'texte'],
        'prenoms' => ['Prénoms', 'Élève', 'texte'],
        'nom_prenoms' => ['Nom et prénoms', 'Élève', 'texte'],
        'sexe' => ['Sexe', 'Élève', 'centre'],
        'date_naissance' => ['Date de naissance', 'Élève', 'centre'],
        'lieu_naissance' => ['Lieu de naissance', 'Élève', 'texte'],
        'age' => ['Âge', 'Élève', 'nombre'],
        'nationalite' => ['Nationalité', 'Élève', 'texte'],
        'telephone' => ['Téléphone de l\'élève', 'Élève', 'texte'],
        'quartier' => ['Quartier', 'Élève', 'texte'],
        'parent' => ['Parent (contact principal)', 'Parents', 'texte'],
        'parent_telephone' => ['Téléphone du parent', 'Parents', 'texte'],
        'classe' => ['Classe', 'Scolarité', 'texte'],
        'niveau' => ['Niveau', 'Scolarité', 'texte'],
        'statut' => ['Statut (affecté)', 'Scolarité', 'centre'],
        'redoublant' => ['Redoublant', 'Scolarité', 'centre'],
        'boursier' => ['Boursier', 'Scolarité', 'centre'],
        'ancien' => ['Nouveau / ancien', 'Scolarité', 'centre'],
        'lv2' => ['LV2', 'Scolarité', 'centre'],
        'date_inscription' => ['Date d\'inscription', 'Scolarité', 'centre'],
        'origine' => ['Établissement d\'origine', 'Scolarité', 'texte'],
        'moyenne_periode' => ['Moyenne de la période', 'Résultats', 'moyenne'],
        'rang_periode' => ['Rang de la période', 'Résultats', 'centre'],
        'moyenne_annuelle' => ['Moyenne annuelle', 'Résultats', 'moyenne'],
        'decision' => ['Décision de fin d\'année', 'Résultats', 'centre'],
        'montant_du' => ['Montant dû', 'Finances', 'montant'],
        'reduction' => ['Réduction', 'Finances', 'montant'],
        'paye' => ['Payé', 'Finances', 'montant'],
        'reste' => ['Reste à payer', 'Finances', 'montant'],
        'paiement' => ['Situation de paiement', 'Finances', 'centre'],
    ];

    public const REGROUPEMENTS = ['etablissement' => 'Établissement', 'cycle' => 'Cycle', 'niveau' => 'Niveau', 'classe' => 'Classe'];

    public const TRIS = ['nom' => 'Nom et prénoms', 'matricule' => 'Matricule', 'date_naissance' => 'Date de naissance', 'moyenne' => 'Moyenne de la période (mérite)'];

    private const CYCLES = ['maternelle' => 'Maternelle', 'primaire' => 'Primaire', 'college' => 'Collège', 'lycee' => 'Lycée'];

    public function index(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        $anneeId = PorteePedagogique::anneeId($tenant);

        return response()->json([
            'champs' => collect(self::CHAMPS)->map(fn ($c, $cle) => ['cle' => $cle, 'libelle' => $c[0], 'rubrique' => $c[1]])->values(),
            'regroupements' => collect(self::REGROUPEMENTS)->map(fn ($l, $v) => ['valeur' => $v, 'libelle' => $l])->values(),
            'tris' => collect(self::TRIS)->map(fn ($l, $v) => ['valeur' => $v, 'libelle' => $l])->values(),
            'cycles' => Niveau::whereIn('id', Classe::where('annee_scolaire_id', $anneeId)->select('niveau_id'))->distinct()->pluck('cycle')
                ->map(fn ($c) => ['valeur' => $c, 'libelle' => self::CYCLES[$c] ?? $c])->values(),
            'niveaux' => Niveau::whereIn('id', Classe::where('annee_scolaire_id', $anneeId)->select('niveau_id'))->orderBy('ordre')->get(['id', 'libelle', 'cycle']),
            'classes' => Classe::where('annee_scolaire_id', $anneeId)->join('niveaux', 'niveaux.id', '=', 'classes.niveau_id')
                ->orderBy('niveaux.ordre')->orderBy('classes.libelle')->get(['classes.id', 'classes.libelle', 'classes.niveau_id']),
            'periodes' => Periode::where('annee_scolaire_id', $anneeId)->orderBy('numero')->get(['id', 'libelle']),
            'modeles' => array_values($this->modeles($tenant)),
            'peut_enregistrer' => $request->user()->can('etablissement.gerer'),
        ]);
    }

    /** Apercu a l'ecran (memes donnees que le document). */
    public function apercu(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        $config = $this->valider($request->all());

        return response()->json($this->construire($config, PorteePedagogique::anneeId($tenant)));
    }

    /** Document : modele enregistre (modele=id) ou configuration en cours (config=JSON). */
    public function document(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        if ($request->filled('modele')) {
            $config = $this->modeles($tenant)[$request->query('modele')] ?? abort(404, 'Rapport introuvable.');
        } else {
            $config = $this->valider((array) json_decode((string) $request->query('config'), true));
        }
        $anneeId = PorteePedagogique::anneeId($tenant);
        $rapport = $this->construire($config, $anneeId);
        $largeur = collect($rapport['colonnes'])->count();

        return Document::repondre('pdf.rapport-personnalise', Document::entete(Etablissement::findOrFail($tenant->id())) + [
            'rapport' => $rapport,
            'annee' => AnneeScolaire::find($anneeId)?->libelle,
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], Str::slug($rapport['titre']), $request->query('format'), $largeur > 7 ? 'landscape' : 'portrait');
    }

    public function store(Request $request, TenantContext $tenant)
    {
        $this->autoriserGestion($request);
        $config = $this->valider($request->all());
        $config['id'] = (string) Str::uuid();

        return response()->json(['message' => 'Rapport « '.$config['titre'].' » enregistré.', 'modele' => $this->sauver($tenant, $config)], 201);
    }

    public function update(Request $request, TenantContext $tenant, string $id)
    {
        $this->autoriserGestion($request);
        abort_unless(isset($this->modeles($tenant)[$id]), 404, 'Rapport introuvable.');
        $config = $this->valider($request->all());
        $config['id'] = $id;

        return response()->json(['message' => 'Rapport « '.$config['titre'].' » modifié.', 'modele' => $this->sauver($tenant, $config)]);
    }

    public function destroy(Request $request, TenantContext $tenant, string $id)
    {
        $this->autoriserGestion($request);
        $modeles = $this->modeles($tenant);
        abort_unless(isset($modeles[$id]), 404, 'Rapport introuvable.');
        $titre = $modeles[$id]['titre'];
        unset($modeles[$id]);
        Etablissement::findOrFail($tenant->id())->definirParametres(['rapports_personnalises' => $modeles]);

        return response()->json(['message' => 'Rapport « '.$titre.' » supprimé.']);
    }

    // ------------------------------------------------------------ Construction

    /** @return array{titre: string, colonnes: array, groupes: array, total: null, sous_titre: string, saut_page: bool} */
    private function construire(array $config, int $anneeId): array
    {
        $periode = ! empty($config['periode_id']) ? Periode::where('annee_scolaire_id', $anneeId)->find($config['periode_id']) : null;
        $champs = collect($config['colonnes'])->where('type', 'champ')->pluck('cle');
        $ancien = '(EXISTS (SELECT 1 FROM inscriptions p JOIN annees_scolaires ap ON ap.id = p.annee_scolaire_id
            WHERE p.eleve_id = i.eleve_id AND p.id <> i.id AND ap.libelle < a.libelle))';
        $tuteur = fn (string $champ) => "(SELECT {$champ} FROM eleve_tuteur et JOIN tuteurs t ON t.id = et.tuteur_id
            WHERE et.eleve_id = e.id ORDER BY et.is_contact_principal DESC, et.id LIMIT 1)";
        $f = $config['filtres'];

        $lignes = DB::table('inscriptions as i')->join('eleves as e', 'e.id', '=', 'i.eleve_id')
            ->join('annees_scolaires as a', 'a.id', '=', 'i.annee_scolaire_id')
            ->join('niveaux as n', 'n.id', '=', 'i.niveau_id')->leftJoin('classes as c', 'c.id', '=', 'i.classe_id')
            ->where('i.etablissement_id', app(TenantContext::class)->id())->where('i.annee_scolaire_id', $anneeId)->whereNull('e.deleted_at')
            ->when(! $config['sans_classe'], fn ($q) => $q->whereNotNull('i.classe_id'))
            ->when($f['sexe'] ?? null, fn ($q, $v) => $q->where('e.sexe', $v))
            ->when(isset($f['affecte']), fn ($q) => $q->where('i.affecte', $f['affecte']))
            ->when(isset($f['redoublant']), fn ($q) => $q->where('i.redoublant', $f['redoublant']))
            ->when($f['cycles'] ?? [], fn ($q, $v) => $q->whereIn('n.cycle', $v))
            ->when($f['niveaux'] ?? [], fn ($q, $v) => $q->whereIn('i.niveau_id', $v))
            ->when($f['classes'] ?? [], fn ($q, $v) => $q->whereIn('i.classe_id', $v))
            ->selectRaw("i.id AS inscription_id, e.id AS eleve_id, i.classe_id, n.cycle, n.ordre AS niveau_ordre, n.libelle AS niveau, c.libelle AS classe,
                e.matricule, e.nom, e.prenoms, e.sexe, e.date_naissance, e.lieu_naissance, e.nationalite, e.telephone, e.quartier,
                TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) AS age,
                i.affecte, i.redoublant, i.boursier, i.langue_vivante_2 AS lv2, i.date_inscription, i.etablissement_origine AS origine,
                i.moyenne_annuelle, i.decision_finale AS decision, i.statut AS statut_paiement,
                i.montant_total_du AS montant_du, i.montant_total_reduit AS reduction, i.montant_total_paye AS paye,
                {$ancien} AS ancien, ".$tuteur("CONCAT(t.nom, ' ', t.prenoms)").' AS parent, '.$tuteur('t.telephone1').' AS parent_telephone')
            ->get();

        // Moyennes de la periode (calculees classe par classe, comme les resultats).
        $moyennes = collect();
        if ($periode && ($champs->intersect(['moyenne_periode', 'rang_periode'])->isNotEmpty() || $config['tri'] === 'moyenne')) {
            foreach (Classe::whereIn('id', $lignes->pluck('classe_id')->filter()->unique())->get() as $classe) {
                foreach (Resultats::periode($classe, $periode)['eleves'] as $e) {
                    $moyennes[$e['eleve_id']] = $e;
                }
            }
        }

        $decisions = ['ADMIS' => 'Admis', 'REDOUBLE' => 'Redouble', 'EXCLU' => 'Exclu'];
        $situations = [0 => 'Sans classe', 1 => 'Attente 1er vers.', 2 => 'Attente solde', 3 => 'Soldé'];
        $lignes = $lignes->map(function ($l) use ($moyennes, $decisions, $situations) {
            $m = $moyennes[$l->eleve_id] ?? null;

            return [
                'matricule' => $l->matricule, 'nom' => $l->nom, 'prenoms' => $l->prenoms, 'nom_prenoms' => $l->nom.' '.$l->prenoms,
                'sexe' => $l->sexe === 'F' ? 'F' : 'G',
                'date_naissance' => $l->date_naissance ? date('d/m/Y', strtotime($l->date_naissance)) : '',
                'lieu_naissance' => $l->lieu_naissance, 'age' => $l->age, 'nationalite' => $l->nationalite, 'telephone' => $l->telephone, 'quartier' => $l->quartier,
                'parent' => $l->parent, 'parent_telephone' => $l->parent_telephone,
                'classe' => $l->classe ?? 'Sans classe', 'niveau' => $l->niveau,
                'statut' => $l->affecte ? 'Affecté' : 'Non affecté', 'redoublant' => $l->redoublant ? 'Oui' : 'Non', 'boursier' => $l->boursier ? 'Oui' : 'Non',
                'ancien' => $l->ancien ? 'Ancien' : 'Nouveau', 'lv2' => $l->lv2,
                'date_inscription' => $l->date_inscription ? date('d/m/Y', strtotime($l->date_inscription)) : '', 'origine' => $l->origine,
                'moyenne_periode' => $m['moyenne'] ?? null, 'rang_periode' => isset($m['rang']) ? $m['rang'].($m['rang'] === 1 ? 'er' : 'e') : '',
                'moyenne_annuelle' => $l->moyenne_annuelle !== null ? (float) $l->moyenne_annuelle : null,
                'decision' => $decisions[$l->decision] ?? '',
                'montant_du' => (int) $l->montant_du, 'reduction' => (int) $l->reduction, 'paye' => (int) $l->paye,
                'reste' => max(0, (int) $l->montant_du - (int) $l->reduction - (int) $l->paye),
                'paiement' => $situations[$l->statut_paiement] ?? '',
                // Regroupement et tri.
                '_cycle' => self::CYCLES[$l->cycle] ?? $l->cycle, '_cycle_rang' => (int) array_search($l->cycle, array_keys(self::CYCLES), true), '_niveau_ordre' => $l->niveau_ordre, '_date' => $l->date_naissance,
                '_moyenne' => $m['moyenne'] ?? null,
            ];
        });

        $lignes = match ($config['tri']) {
            'matricule' => $lignes->sortBy('matricule'),
            'date_naissance' => $lignes->sortBy(fn ($l) => $l['_date'] ?? '9999'),
            'moyenne' => $lignes->sortBy(fn ($l) => [$l['_moyenne'] === null ? 1 : 0, -($l['_moyenne'] ?? 0), $l['nom_prenoms']]),
            default => $lignes->sortBy(fn ($l) => [$l['nom'], $l['prenoms'], $l['matricule']]),
        };
        $cleGroupe = match ($config['regroupement']) {
            'cycle' => fn ($l) => [$l['_cycle_rang'], $l['_cycle']],
            'niveau' => fn ($l) => [$l['_niveau_ordre'], $l['niveau']],
            'classe' => fn ($l) => [$l['_niveau_ordre'], $l['classe']],
            default => fn ($l) => [0, 'Établissement'],
        };
        $groupes = $lignes->groupBy(fn ($l) => implode('|', $cleGroupe($l)))
            ->sortKeys(SORT_NATURAL)
            ->map(function (Collection $g) use ($config, $cleGroupe) {
                $titre = $config['regroupement'] === 'etablissement' ? null : $cleGroupe($g->first())[1];
                $numero = 0;

                return [
                    'titre' => $titre,
                    'effectif' => $g->count(), 'filles' => $g->where('sexe', 'F')->count(), 'garcons' => $g->where('sexe', 'G')->count(),
                    'lignes' => $g->values()->map(fn ($l) => ['numero' => ++$numero] + $l)->all(),
                    'total' => null,
                ];
            })->values();

        $colonnes = collect($config['numeroter'] ? [['cle' => 'numero', 'libelle' => 'N°', 'type' => 'centre']] : [])
            ->merge(collect($config['colonnes'])->map(fn ($c, $i) => $c['type'] === 'champ'
                ? ['cle' => $c['cle'], 'libelle' => $c['libelle'] ?: self::CHAMPS[$c['cle']][0], 'type' => self::CHAMPS[$c['cle']][2] === 'texte' ? null : self::CHAMPS[$c['cle']][2]]
                : ['cle' => 'libre_'.$i, 'libelle' => $c['libelle'], 'type' => 'libre']))
            ->map(fn ($c) => array_filter($c, fn ($v) => $v !== null))->values();

        return [
            'titre' => $config['titre'],
            'colonnes' => $colonnes->all(),
            'groupes' => $groupes->all(),
            'total' => null,
            'effectif' => $lignes->count(),
            'sous_titre' => $this->description($config, $periode, $lignes->count()),
            'saut_page' => $config['saut_page'] && $config['regroupement'] !== 'etablissement',
            'regroupement' => $config['regroupement'],
        ];
    }

    private function description(array $config, ?Periode $periode, int $effectif): string
    {
        $f = $config['filtres'];
        $morceaux = [$effectif.' élève'.($effectif > 1 ? 's' : '')];
        if (($f['sexe'] ?? null) === 'F') $morceaux[] = 'filles';
        if (($f['sexe'] ?? null) === 'M') $morceaux[] = 'garçons';
        if (isset($f['affecte'])) $morceaux[] = $f['affecte'] ? 'affectés' : 'non affectés';
        if (isset($f['redoublant'])) $morceaux[] = $f['redoublant'] ? 'redoublants' : 'non redoublants';
        if ($f['cycles'] ?? []) $morceaux[] = collect($f['cycles'])->map(fn ($c) => self::CYCLES[$c] ?? $c)->implode(', ');
        if ($f['niveaux'] ?? []) $morceaux[] = Niveau::whereIn('id', $f['niveaux'])->orderBy('ordre')->pluck('libelle')->implode(', ');
        if ($f['classes'] ?? []) $morceaux[] = Classe::whereIn('id', $f['classes'])->orderBy('libelle')->pluck('libelle')->implode(', ');
        if ($periode) $morceaux[] = $periode->libelle;
        $morceaux[] = 'par '.mb_strtolower(self::REGROUPEMENTS[$config['regroupement']]);

        return implode(' · ', $morceaux);
    }

    // ------------------------------------------------------------ Modeles

    private function valider(array $donnees): array
    {
        $v = validator($donnees, [
            'titre' => ['required', 'string', 'max:150'],
            'regroupement' => ['required', Rule::in(array_keys(self::REGROUPEMENTS))],
            'tri' => ['nullable', Rule::in(array_keys(self::TRIS))],
            'numeroter' => ['nullable', 'boolean'],
            'saut_page' => ['nullable', 'boolean'],
            'sans_classe' => ['nullable', 'boolean'],
            'periode_id' => ['nullable', 'integer'],
            'colonnes' => ['required', 'array', 'min:1', 'max:20'],
            'colonnes.*.type' => ['required', Rule::in(['champ', 'libre'])],
            'colonnes.*.cle' => ['nullable', 'required_if:colonnes.*.type,champ', Rule::in(array_keys(self::CHAMPS))],
            'colonnes.*.libelle' => ['nullable', 'required_if:colonnes.*.type,libre', 'string', 'max:60'],
            'filtres' => ['nullable', 'array'],
            'filtres.sexe' => ['nullable', Rule::in(['M', 'F'])],
            'filtres.affecte' => ['nullable', 'boolean'],
            'filtres.redoublant' => ['nullable', 'boolean'],
            'filtres.cycles' => ['nullable', 'array'],
            'filtres.cycles.*' => [Rule::in(array_keys(self::CYCLES))],
            'filtres.niveaux' => ['nullable', 'array'],
            'filtres.niveaux.*' => ['integer'],
            'filtres.classes' => ['nullable', 'array'],
            'filtres.classes.*' => ['integer'],
        ], [
            'titre.required' => 'Donnez un titre au rapport.',
            'colonnes.required' => 'Choisissez au moins une colonne.',
            'colonnes.min' => 'Choisissez au moins une colonne.',
            'colonnes.max' => '20 colonnes au plus.',
            'colonnes.*.libelle.required_if' => 'Donnez un en-tête à chaque colonne libre.',
        ])->validate();
        $colonnes = collect($v['colonnes'])->map(fn ($c) => ['type' => $c['type'], 'cle' => $c['type'] === 'champ' ? $c['cle'] : null,
            'libelle' => isset($c['libelle']) ? trim((string) $c['libelle']) ?: null : null])->values()->all();
        $besoinPeriode = collect($colonnes)->whereIn('cle', ['moyenne_periode', 'rang_periode'])->isNotEmpty() || ($v['tri'] ?? null) === 'moyenne';
        if ($besoinPeriode && empty($v['periode_id'])) {
            abort(response()->json(['message' => 'Choisissez la période des moyennes.', 'errors' => ['periode_id' => ['Choisissez la période des moyennes.']]], 422));
        }
        $f = $v['filtres'] ?? [];

        return [
            'titre' => trim($v['titre']),
            'regroupement' => $v['regroupement'],
            'tri' => $v['tri'] ?? 'nom',
            'numeroter' => (bool) ($v['numeroter'] ?? true),
            'saut_page' => (bool) ($v['saut_page'] ?? true),
            'sans_classe' => (bool) ($v['sans_classe'] ?? false),
            'periode_id' => $besoinPeriode ? (int) $v['periode_id'] : null,
            'colonnes' => $colonnes,
            'filtres' => array_filter([
                'sexe' => $f['sexe'] ?? null,
                'affecte' => isset($f['affecte']) ? (bool) $f['affecte'] : null,
                'redoublant' => isset($f['redoublant']) ? (bool) $f['redoublant'] : null,
                'cycles' => array_values($f['cycles'] ?? []),
                'niveaux' => array_values(array_map('intval', $f['niveaux'] ?? [])),
                'classes' => array_values(array_map('intval', $f['classes'] ?? [])),
            ], fn ($x) => $x !== null && $x !== []),
        ];
    }

    /** @return array<string, array> modeles indexes par id */
    private function modeles(TenantContext $tenant): array
    {
        return (array) (Etablissement::findOrFail($tenant->id())->parametre('rapports_personnalises') ?? []);
    }

    private function sauver(TenantContext $tenant, array $config): array
    {
        $modeles = $this->modeles($tenant);
        $modeles[$config['id']] = $config;
        Etablissement::findOrFail($tenant->id())->definirParametres(['rapports_personnalises' => $modeles]);

        return $config;
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('rapports.voir'), 403);
    }

    private function autoriserGestion(Request $request): void
    {
        abort_unless($request->user()->can('rapports.voir') && $request->user()->can('etablissement.gerer'), 403);
    }
}
