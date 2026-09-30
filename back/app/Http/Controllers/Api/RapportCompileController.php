<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Etablissement;
use App\Models\Periode;
use App\Support\Document;
use App\Support\PorteePedagogique;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rapports officiels (droit rapports.voir) : rapport de rentree, de fin de
 * periode (trimestre / semestre) et de fin d'annee, attendus par le
 * ministere (DRENA). Un seul document organise comme un memoire : page de
 * garde, sommaire, introduction, chapitres (tableaux des rapports
 * pedagogiques avec un commentaire chiffre), observations et conclusion.
 *
 * Les textes libres (introduction, observations, difficultes, perspectives,
 * conclusion, signataire) sont rediges par l'etablissement et gardes dans le
 * parametre rapports_textes, par annee, type et periode.
 */
class RapportCompileController extends Controller
{
    public const TYPES = [
        'rentree' => ['Rapport de rentrée', 'Identification, encadrement, structure pédagogique et effectifs à la rentrée.'],
        'periode' => ['Rapport de fin de période', 'Bilan d\'un trimestre ou d\'un semestre : effectifs, résultats scolaires, assiduité et discipline.'],
        'annuel' => ['Rapport de fin d\'année', 'Effectifs, résultats annuels, décisions de fin d\'année, assiduité, bilan et perspectives.'],
    ];

    public const TEXTES = ['introduction', 'observations', 'difficultes', 'perspectives', 'conclusion', 'signataire'];

    public function index(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $periodes = Periode::where('annee_scolaire_id', $anneeId)->orderBy('numero')->get();
        $textes = (array) (Etablissement::findOrFail($tenant->id())->parametre('rapports_textes') ?? []);

        return response()->json([
            'types' => collect(self::TYPES)->map(fn ($t, $code) => ['code' => $code, 'titre' => $t[0], 'description' => $t[1]])->values(),
            'periodes' => $periodes->map(fn (Periode $p) => ['id' => $p->id, 'libelle' => $p->libelle, 'active' => (bool) $p->is_active]),
            // Textes deja rediges pour l'annee : cle "type" ou "periode-{id}".
            'textes' => collect($textes)->filter(fn ($v, $cle) => str_starts_with((string) $cle, $anneeId.'-'))
                ->mapWithKeys(fn ($v, $cle) => [substr((string) $cle, strlen($anneeId.'-')) => $v]),
        ]);
    }

    /** Enregistre les textes libres d'un rapport (vides : texte propose automatiquement). */
    public function textes(Request $request, TenantContext $tenant, string $type)
    {
        $this->autoriser($request);
        abort_unless(isset(self::TYPES[$type]), 404);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $periode = $this->periode($request, $anneeId, $type);
        $regles = collect(self::TEXTES)->mapWithKeys(fn ($t) => [$t => ['nullable', 'string', 'max:'.($t === 'signataire' ? 150 : 6000)]])->all();
        $data = $request->validate($regles);

        $etablissement = Etablissement::findOrFail($tenant->id());
        $tous = (array) ($etablissement->parametre('rapports_textes') ?? []);
        $tous[$this->cle($anneeId, $type, $periode)] = array_map(fn ($v) => is_string($v) ? (trim($v) ?: null) : null, array_merge(array_fill_keys(self::TEXTES, null), $data));
        $etablissement->definirParametres(['rapports_textes' => $tous]);

        return response()->json(['message' => 'Textes du rapport enregistrés.', 'textes' => $tous[$this->cle($anneeId, $type, $periode)]]);
    }

    public function document(Request $request, TenantContext $tenant, string $type)
    {
        $this->autoriser($request);
        abort_unless(isset(self::TYPES[$type]), 404);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $periode = $this->periode($request, $anneeId, $type);
        $etablissement = Etablissement::findOrFail($tenant->id());
        $textes = ((array) ($etablissement->parametre('rapports_textes') ?? []))[$this->cle($anneeId, $type, $periode)] ?? [];
        $annee = AnneeScolaire::findOrFail($anneeId);

        $rapport = $this->construire($type, $annee, $periode, $etablissement, $textes);
        $fichier = 'rapport-'.$type.($periode ? '-'.str_replace(' ', '-', mb_strtolower($periode->libelle)) : '').'-'.$annee->libelle;

        return Document::repondre('pdf.rapport-compile', Document::entete($etablissement) + [
            'r' => $rapport,
            'annee' => $annee->libelle,
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], $fichier, $request->query('format'));
    }

    // ------------------------------------------------------------ Construction

    private function construire(string $type, AnneeScolaire $annee, ?Periode $periode, Etablissement $etab, array $textes): array
    {
        $r = app(RapportController::class);
        $t = fn (string $code, Periode|string|null $p = null) => $r->contenu($code, $annee->id, $p);
        $pedagogique = $type === 'annuel' ? 'annuel' : $periode;
        $chapitres = [];

        // I. Presentation.
        $chapitres[] = ['titre' => 'Présentation de l\'établissement', 'sections' => array_values(array_filter([
            ['titre' => 'Identification', 'identite' => $this->identite($etab, $annee)],
            ['titre' => 'Personnel', 'texte' => 'Comptes du personnel ouverts dans Monde Éducatif, par poste.', 'tableau' => $this->personnel($etab)],
            $type === 'rentree' ? ['titre' => 'Structure pédagogique et encadrement des classes', 'texte' => null, 'tableau' => $this->encadrement($annee->id)] : null,
        ]))];

        // II. Effectifs.
        $effectifs = $t('effectifs');
        $sections = [['titre' => 'Effectifs par niveau et par classe', 'texte' => $this->commentaireEffectifs($effectifs), 'tableau' => $effectifs]];
        $nouveaux = $t('nouveaux');
        $sections[] = ['titre' => 'Nouveaux et anciens élèves', 'texte' => $this->commentaireNouveaux($nouveaux), 'tableau' => $nouveaux];
        if ($type === 'rentree') {
            $sections[] = ['titre' => 'Répartition des élèves par âge', 'texte' => null, 'tableau' => $t('ages')];
        }
        $chapitres[] = ['titre' => 'Effectifs', 'sections' => $sections];

        if ($type !== 'rentree') {
            // III. Resultats scolaires.
            $resultats = $t('resultats', $pedagogique);
            $distinctions = $t('distinctions', $pedagogique);
            $difficulte = $t('difficulte', $pedagogique);
            $chapitres[] = ['titre' => $type === 'annuel' ? 'Résultats scolaires de l\'année' : 'Résultats scolaires', 'sections' => [
                ['titre' => 'Résultats par classe', 'texte' => $this->commentaireResultats($resultats), 'tableau' => $resultats],
                ['titre' => 'Moyennes par matière', 'texte' => null, 'tableau' => $t('matieres', $pedagogique)],
                ['titre' => 'Majors de classe et de niveau', 'texte' => null, 'tableau' => $t('majors', $pedagogique)],
                ['titre' => 'Tableau d\'honneur', 'texte' => $distinctions['sous_titre'] ?? null, 'tableau' => $distinctions],
                ['titre' => 'Élèves en difficulté', 'texte' => $difficulte['sous_titre'] ?? null, 'tableau' => $difficulte],
            ]];

            if ($type === 'annuel') {
                $decisions = $t('decisions');
                $chapitres[] = ['titre' => 'Décisions de fin d\'année', 'sections' => [
                    ['titre' => 'Admissions, redoublements et exclusions', 'texte' => $this->commentaireDecisions($decisions), 'tableau' => $decisions],
                ]];
            }

            // Assiduite : la periode, ou toute l'annee.
            $absences = $t('absences', $type === 'periode' ? $periode : null);
            $chapitres[] = ['titre' => 'Assiduité et discipline', 'sections' => [
                ['titre' => 'Absences par classe', 'texte' => $this->commentaireAbsences($absences), 'tableau' => $absences],
                ['titre' => 'Élèves les plus absents', 'texte' => 'Élèves qui ont des heures d\'absence non justifiées.', 'tableau' => $t('absents', $type === 'periode' ? $periode : null)],
            ]];
        }

        $titre = match ($type) {
            'rentree' => 'Rapport de rentrée scolaire',
            'periode' => 'Rapport de fin du '.mb_strtolower($periode->libelle),
            default => 'Rapport de fin d\'année scolaire',
        };

        return [
            'type' => $type,
            'titre' => $titre,
            'periode' => $periode?->libelle,
            'chapitres' => $chapitres,
            'introduction' => $textes['introduction'] ?? null ?: $this->introduction($type, $etab, $annee, $periode, $effectifs),
            'observations' => array_filter([
                'Observations' => $textes['observations'] ?? null,
                'Difficultés rencontrées' => $textes['difficultes'] ?? null,
                'Perspectives et suggestions' => $textes['perspectives'] ?? null,
            ]),
            'conclusion' => $textes['conclusion'] ?? null,
            'signataire' => $textes['signataire'] ?? null ?: 'Le Chef d\'établissement',
        ];
    }

    private function identite(Etablissement $e, AnneeScolaire $annee): array
    {
        $types = ['maternelle' => 'Maternelle', 'primaire' => 'Primaire', 'college' => 'Collège', 'lycee' => 'Lycée',
            'college_lycee' => 'Collège et lycée', 'superieur' => 'Supérieur', 'mixte' => 'Mixte'];
        $tutelle = $e->enteteTutelle();

        return array_filter([
            'Nom de l\'établissement' => $e->nom.($e->sigle ? ' ('.$e->sigle.')' : ''),
            'Code MENA' => $e->parametre('code_mena'),
            'Ordre d\'enseignement' => $types[$e->type_etablissement] ?? null,
            'Direction régionale' => $tutelle['direction'],
            'Adresse' => collect([$e->quartier, $e->ville, $e->region])->filter()->implode(', ') ?: null,
            'Adresse postale' => $tutelle['adresse'],
            'Téléphone' => collect([$e->telephone1, $e->telephone2])->filter()->implode(' / ') ?: null,
            'E-mail' => $e->email,
            'Année scolaire' => $annee->libelle,
        ]);
    }

    /** Personnel par poste (comptes de l'etablissement). */
    private function personnel(Etablissement $e): array
    {
        $lignes = DB::table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')
            ->join('users as u', 'u.id', '=', 'm.model_id')
            ->where('m.etablissement_id', $e->id)->where('m.model_type', \App\Models\User::class)
            ->whereNotIn('r.code', ['parent', 'super_admin'])
            ->groupBy('r.name')->orderBy('r.name')
            ->selectRaw('r.name AS poste, COUNT(DISTINCT u.id) AS nombre')
            ->get()->map(fn ($l) => ['poste' => $l->poste, 'nombre' => (int) $l->nombre]);

        return [
            'colonnes' => [['cle' => 'poste', 'libelle' => 'Poste'], ['cle' => 'nombre', 'libelle' => 'Nombre', 'type' => 'nombre', 'fort' => true]],
            'groupes' => [['titre' => null, 'lignes' => $lignes->all(), 'total' => null]],
            'total' => $lignes->isNotEmpty() ? ['poste' => 'Total', 'nombre' => $lignes->sum('nombre')] : null,
        ];
    }

    /** Classes avec effectif, professeur principal, educateur et salle. */
    private function encadrement(int $anneeId): array
    {
        $nom = "NULLIF(TRIM(CONCAT(COALESCE(%s.name, ''), ' ', COALESCE(%s.prenoms, ''))), '')";
        $lignes = DB::table('classes as c')->join('niveaux as n', 'n.id', '=', 'c.niveau_id')
            ->leftJoin('personnels as pp', 'pp.id', '=', 'c.professeur_principal_id')->leftJoin('users as upp', 'upp.id', '=', 'pp.user_id')
            ->leftJoin('personnels as pe', 'pe.id', '=', 'c.educateur_id')->leftJoin('users as upe', 'upe.id', '=', 'pe.user_id')
            ->where('c.annee_scolaire_id', $anneeId)
            ->orderBy('n.ordre')->orderBy('c.libelle')
            ->selectRaw('c.id, c.libelle AS classe, n.libelle AS niveau, c.salle, c.langue_vivante_2 AS lv2, '
                .sprintf($nom, 'upp', 'upp').' AS professeur, '.sprintf($nom, 'upe', 'upe').' AS educateur,
                (SELECT COUNT(*) FROM inscriptions i WHERE i.classe_id = c.id) AS effectif')
            ->get()->map(fn ($l) => ['niveau' => $l->niveau, 'classe' => $l->classe, 'effectif' => (int) $l->effectif,
                'professeur' => $l->professeur ?? '—', 'educateur' => $l->educateur ?? '—', 'salle' => $l->salle ?: '—', 'lv2' => $l->lv2 ?: '—']);
        $colonnes = [
            ['cle' => 'classe', 'libelle' => 'Classe'],
            ['cle' => 'effectif', 'libelle' => 'Effectif', 'type' => 'nombre', 'fort' => true],
            ['cle' => 'professeur', 'libelle' => 'Professeur principal'],
            ['cle' => 'educateur', 'libelle' => 'Éducateur'],
            ['cle' => 'salle', 'libelle' => 'Salle', 'type' => 'centre'],
            ['cle' => 'lv2', 'libelle' => 'LV2', 'type' => 'centre'],
        ];
        $groupes = $lignes->groupBy('niveau')->map(fn ($g, $titre) => ['titre' => (string) $titre, 'lignes' => $g->values()->all(),
            'total' => $g->count() > 1 ? ['classe' => 'Total', 'effectif' => $g->sum('effectif')] : null])->values();

        return ['colonnes' => $colonnes, 'groupes' => $groupes->all(),
            'total' => $lignes->isNotEmpty() ? ['classe' => 'Total', 'effectif' => $lignes->sum('effectif')] : null,
            'sous_titre' => $lignes->count().' classe'.($lignes->count() > 1 ? 's' : '')];
    }

    // ------------------------------------------------------------ Commentaires chiffres

    private function introduction(string $type, Etablissement $e, AnneeScolaire $annee, ?Periode $periode, array $effectifs): string
    {
        $total = (int) ($effectifs['total']['total'] ?? 0);
        $objet = match ($type) {
            'rentree' => 'dresse l\'état de l\'établissement à la rentrée scolaire '.$annee->libelle.' : identification, personnel, structure pédagogique et effectifs',
            'periode' => 'présente le bilan du '.mb_strtolower($periode->libelle).' de l\'année scolaire '.$annee->libelle.' : effectifs, résultats scolaires, assiduité et discipline',
            default => 'présente le bilan de l\'année scolaire '.$annee->libelle.' : effectifs, résultats annuels, décisions de fin d\'année et assiduité',
        };

        return 'Le présent rapport '.$objet.'. '.$e->nom.' accueille cette année '.$this->nombre($total).' élève'.($total > 1 ? 's' : '')
            .' en classe. Les tableaux qui suivent sont établis à partir des données saisies dans l\'application à la date d\'édition.';
    }

    private function commentaireEffectifs(array $r): ?string
    {
        $t = $r['total'] ?? null;
        if (! $t || ! $t['total']) {
            return 'Aucun élève n\'est encore placé en classe.';
        }
        $classes = collect($r['groupes'])->sum(fn ($g) => count($g['lignes']));
        $affectes = $t['aff_g'] + $t['aff_f'];

        return sprintf('L\'établissement compte %s élèves répartis dans %d classe%s (%s en moyenne par classe) : %s filles (%s) et %s garçons (%s). '
            .'%s élèves sont affectés par l\'État (%s) et %s non affectés ; %s redoublent (%s).',
            $this->nombre($t['total']), $classes, $classes > 1 ? 's' : '', $this->nombre(round($t['total'] / max(1, $classes))),
            $this->nombre($t['filles']), $this->pourcent($t['filles'], $t['total']), $this->nombre($t['garcons']), $this->pourcent($t['garcons'], $t['total']),
            $this->nombre($affectes), $this->pourcent($affectes, $t['total']), $this->nombre($t['total'] - $affectes),
            $this->nombre($t['redoublants']), $this->pourcent($t['redoublants'], $t['total']));
    }

    private function commentaireNouveaux(array $r): ?string
    {
        $t = $r['total'] ?? null;
        if (! $t || ! $t['total']) {
            return null;
        }
        $nouveaux = $t['nouveaux_f'] + $t['nouveaux_g'];

        return sprintf('%s nouveaux élèves (%s) et %s anciens ; %s boursiers ; %s inscriptions faites en ligne.',
            $this->nombre($nouveaux), $this->pourcent($nouveaux, $t['total']), $this->nombre($t['anciens_f'] + $t['anciens_g']),
            $this->nombre($t['boursiers_f'] + $t['boursiers_g']), $this->nombre($t['en_ligne_f'] + $t['en_ligne_g']));
    }

    private function commentaireResultats(array $r): ?string
    {
        $t = $r['total'] ?? null;
        if (! $t || ! ($t['classes'] ?? 0)) {
            return 'Aucune moyenne n\'est encore calculée pour cette période.';
        }
        $lignes = collect($r['groupes'])->flatMap(fn ($g) => $g['lignes'])->filter(fn ($l) => $l['taux_reussite'] !== null);
        $meilleure = $lignes->sortByDesc('taux_reussite')->first();
        $faible = $lignes->sortBy('taux_reussite')->first();
        $texte = sprintf('Sur %s élèves classés, %s ont obtenu une moyenne supérieure ou égale à 10, soit un taux de réussite de %s (%s filles et %s garçons). '
            .'La moyenne générale est de %s ; la plus forte moyenne est de %s et la plus faible de %s.',
            $this->nombre($t['classes']), $this->nombre($t['moyenne_10']), $this->decimal($t['taux_reussite']).' %', $this->nombre($t['admis_f']), $this->nombre($t['admis_g']),
            $this->decimal($t['moyenne_classe']), $this->decimal($t['maximum']), $this->decimal($t['minimum']));
        if ($meilleure && $faible && $meilleure !== $faible) {
            $texte .= sprintf(' Meilleur taux de réussite : %s (%s) ; taux le plus faible : %s (%s).',
                $meilleure['classe'], $this->decimal($meilleure['taux_reussite']).' %', $faible['classe'], $this->decimal($faible['taux_reussite']).' %');
        }

        return $texte;
    }

    private function commentaireDecisions(array $r): ?string
    {
        $t = $r['total'] ?? null;
        if (! $t) {
            return null;
        }
        $total = $t['admis_t'] + $t['redouble_t'] + $t['exclu_t'] + $t['aucune_t'];
        if (! $total) {
            return null;
        }

        return sprintf('%s admis (%s), %s redoublants (%s), %s exclus (%s)%s.',
            $this->nombre($t['admis_t']), $this->pourcent($t['admis_t'], $total), $this->nombre($t['redouble_t']), $this->pourcent($t['redouble_t'], $total),
            $this->nombre($t['exclu_t']), $this->pourcent($t['exclu_t'], $total),
            $t['aucune_t'] ? ' ; '.$this->nombre($t['aucune_t']).' élève'.($t['aucune_t'] > 1 ? 's' : '').' sans décision enregistrée' : '');
    }

    private function commentaireAbsences(array $r): ?string
    {
        $t = $r['total'] ?? null;
        if (! $t || ! $t['heures']) {
            return 'Aucune absence enregistrée.';
        }
        $plus = collect($r['groupes'])->flatMap(fn ($g) => $g['lignes'])->sortByDesc('non_justifiees')->first();

        return sprintf('%s heures d\'absence (%s absences, %s élèves concernés) : %s heures justifiées et %s heures non justifiées (%s).%s',
            $this->decimal($t['heures'], 1), $this->nombre($t['absences']), $this->nombre($t['eleves']), $this->decimal($t['justifiees'], 1),
            $this->decimal($t['non_justifiees'], 1), $this->pourcent($t['non_justifiees'], $t['heures']),
            $plus && $plus['non_justifiees'] > 0 ? ' Classe la plus touchée : '.$plus['classe'].' ('.$this->decimal($plus['non_justifiees'], 1).' h non justifiées).' : '');
    }

    // ------------------------------------------------------------ Outils

    private function periode(Request $request, int $anneeId, string $type): ?Periode
    {
        if ($type !== 'periode') {
            return null;
        }
        $request->validate(['periode' => ['required', 'integer']], ['periode.required' => 'Choisissez le trimestre ou le semestre.']);

        return Periode::where('annee_scolaire_id', $anneeId)->findOrFail($request->integer('periode'));
    }

    private function cle(int $anneeId, string $type, ?Periode $periode): string
    {
        return $anneeId.'-'.($periode ? 'periode-'.$periode->id : $type);
    }

    private function nombre(float|int $v): string
    {
        return number_format($v, 0, ',', "\u{202F}");
    }

    private function decimal(float|int|null $v, int $decimales = 2): string
    {
        return $v === null ? '—' : number_format((float) $v, $decimales, ',', "\u{202F}");
    }

    private function pourcent(float|int $part, float|int $total): string
    {
        return $total ? number_format($part * 100 / $total, 1, ',', "\u{202F}").' %' : '—';
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('rapports.voir'), 403);
    }
}
