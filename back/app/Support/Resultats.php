<?php

namespace App\Support;

use App\Http\Controllers\Api\InscriptionController;
use App\Models\Classe;
use App\Models\Matiere;
use App\Models\Moyenne;
use App\Models\Note;
use App\Models\Periode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calcul des resultats scolaires (V1 enr_moy, calcul_moy_clas, rang_trim,
 * bulletin.php) :
 * - moyenne d'une matiere = somme des notes / somme des poids, le poids d'une
 *   evaluation etant son bareme / 20 (notee sur 10 : 0,5 ; sur 40 : 2) ;
 *   une moyenne "arretee" est figee dans la table moyennes (la conduite y est
 *   saisie directement par l'educateur) ;
 * - moyenne generale = somme (moyenne x coefficient) / somme des coefficients
 *   des matieres ou l'eleve a une moyenne ; bilans lettres et sciences selon
 *   le groupe de la matiere ;
 * - rangs avec ex aequo (meme moyenne, meme rang) ;
 * - annuelle : premiere periode poids 1, les suivantes poids 2 (V1 :
 *   (T1 + 2 T2 + 2 T3) / 5) ;
 * - appreciations, distinctions et sanctions aux seuils de la V1.
 */
class Resultats
{
    public const CONDUITE = 'COND';

    /** Appreciation d'une moyenne de matiere (V1 bulletin.php). */
    private const APPRECIATIONS = [
        [18, 'Excellent'], [16, 'Très bien'], [14, 'Bien'], [12, 'Assez bien'],
        [10, 'Passable'], [8, 'Insuffisant'], [6, 'Très insuffisant'], [0, 'Médiocre'],
    ];

    /** Appreciation du conseil de classe sur la moyenne generale. */
    private const APPRECIATIONS_CONSEIL = [
        [18, 'Excellent travail'], [16, 'Très bon travail'], [14, 'Bon travail'], [12, 'Assez bon travail'],
        [10, 'Travail passable'], [8.5, 'Travail insuffisant'], [0, 'Mauvais travail'],
    ];

    public static function appreciation(?float $moyenne): ?string
    {
        return self::seuil($moyenne, self::APPRECIATIONS);
    }

    public static function appreciationConseil(?float $moyenne): ?string
    {
        return self::seuil($moyenne, self::APPRECIATIONS_CONSEIL);
    }

    /** Distinction (V1) : tableau d'honneur a partir de 12. */
    public static function distinction(?float $moyenne): ?string
    {
        return match (true) {
            $moyenne === null, $moyenne < 12 => null,
            $moyenne < 14 => "Tableau d'honneur",
            $moyenne < 16 => "Tableau d'honneur + Encouragements",
            default => "Tableau d'honneur + Félicitations",
        };
    }

    /** Sanctions (V1) : travail sous 10, conduite sous 14. */
    public static function sanctions(?float $moyenne, ?float $conduite): array
    {
        return array_values(array_filter([
            $moyenne === null ? null : ($moyenne < 8.5 ? 'Blâme travail' : ($moyenne < 10 ? 'Avertissement travail' : null)),
            $conduite === null ? null : ($conduite < 10 ? 'Blâme conduite' : ($conduite < 14 ? 'Avertissement conduite' : null)),
        ]));
    }

    /** Poids d'une periode dans l'annuelle : 1 pour la premiere, 2 ensuite. */
    public static function poidsAnnuel(Periode $periode): int
    {
        return (int) $periode->numero === 1 ? 1 : 2;
    }

    /** Moyenne /20 d'une liste de notes (valeur, bareme). */
    public static function moyenneNotes(Collection $notes): ?float
    {
        $poids = $notes->sum(fn ($n) => $n->bareme / 20);

        return $poids > 0 ? round($notes->sum('valeur') / $poids, 2) : null;
    }

    /**
     * Matieres du niveau (Parametres > Matieres) : lettres, sciences, autres,
     * la conduite en dernier.
     */
    public static function matieres(int $niveauId): Collection
    {
        $ordreGroupe = ['LITTERAIRE' => 1, 'SCIENTIFIQUE' => 2, 'AUTRES' => 3];
        $langues = array_map('mb_strtolower', InscriptionController::LANGUES);

        return Matiere::join('matiere_niveau as mn', 'mn.matiere_id', '=', 'matieres.id')
            ->where('mn.niveau_id', $niveauId)
            ->get(['matieres.id', 'matieres.code', 'matieres.libelle', 'matieres.groupe_bulletin', 'mn.coefficient', 'mn.is_obligatoire'])
            ->map(fn ($m) => [
                'id' => (int) $m->id,
                'code' => $m->code,
                'libelle' => $m->libelle,
                'groupe' => $m->groupe_bulletin ?: 'AUTRES',
                'coefficient' => (int) $m->coefficient,
                'obligatoire' => (bool) $m->is_obligatoire,
                'conduite' => $m->code === self::CONDUITE,
                // LV2 : seuls les eleves qui suivent la langue (inscription.langue_vivante_2).
                'langue' => in_array(mb_strtolower($m->libelle), $langues, true) ? $m->libelle : null,
            ])
            ->sortBy(fn ($m) => [$m['conduite'] ? 9 : ($ordreGroupe[$m['groupe']] ?? 4), $m['libelle']])
            ->values();
    }

    /** Eleves de la classe par ordre alphabetique. */
    public static function eleves(Classe $classe): Collection
    {
        return DB::table('inscriptions as i')->join('eleves as e', 'e.id', '=', 'i.eleve_id')
            ->where('i.classe_id', $classe->id)->whereNull('e.deleted_at')
            ->orderBy('e.nom')->orderBy('e.prenoms')->orderBy('e.matricule')
            ->get(['i.id as inscription_id', 'i.eleve_id', 'e.matricule', 'e.nom', 'e.prenoms', 'e.sexe', 'e.date_naissance', 'e.lieu_naissance',
                'i.affecte', 'i.redoublant', 'i.langue_vivante_2', 'i.decision_finale', 'i.moyenne_annuelle'])
            ->map(fn ($e) => [
                'inscription_id' => (int) $e->inscription_id,
                'eleve_id' => (int) $e->eleve_id,
                'matricule' => $e->matricule,
                'nom' => $e->nom,
                'prenoms' => $e->prenoms,
                'sexe' => $e->sexe,
                'date_naissance' => $e->date_naissance ? substr((string) $e->date_naissance, 0, 10) : null,
                'lieu_naissance' => $e->lieu_naissance,
                'affecte' => (bool) $e->affecte,
                'redoublant' => (bool) $e->redoublant,
                'langue_vivante_2' => $e->langue_vivante_2,
                'decision' => $e->decision_finale,
                'moyenne_annuelle' => $e->moyenne_annuelle !== null ? (float) $e->moyenne_annuelle : null,
            ]);
    }

    /** L'eleve est-il note dans cette matiere (LV2) ? */
    public static function concerne(array $eleve, array $matiere): bool
    {
        return $matiere['langue'] === null || mb_strtolower((string) $eleve['langue_vivante_2']) === mb_strtolower($matiere['langue']);
    }

    /**
     * Resultats d'une classe pour une periode : moyennes par matiere (arretees
     * ou provisoires), moyenne generale, bilans, rangs, appreciations.
     */
    public static function periode(Classe $classe, Periode $periode): array
    {
        $matieres = self::matieres($classe->niveau_id);
        $eleves = self::eleves($classe);
        $ids = $eleves->pluck('eleve_id');

        $notes = Note::where('periode_id', $periode->id)->whereIn('eleve_id', $ids)->whereIn('matiere_id', $matieres->pluck('id'))
            ->get(['eleve_id', 'matiere_id', 'valeur', 'bareme', 'numero'])->groupBy(fn ($n) => $n->eleve_id.'-'.$n->matiere_id);
        $stockees = Moyenne::where('periode_id', $periode->id)->whereIn('eleve_id', $ids)->whereIn('matiere_id', $matieres->pluck('id'))
            ->get()->keyBy(fn ($m) => $m->eleve_id.'-'.$m->matiere_id);
        $evaluations = Note::where('periode_id', $periode->id)->where('classe_id', $classe->id)
            ->selectRaw('matiere_id, COUNT(DISTINCT numero) AS nombre')->groupBy('matiere_id')->pluck('nombre', 'matiere_id');

        $lignes = $eleves->map(function (array $e) use ($matieres, $notes, $stockees) {
            $moyennes = [];
            foreach ($matieres as $m) {
                if (! self::concerne($e, $m)) {
                    continue;
                }
                $cle = $e['eleve_id'].'-'.$m['id'];
                $stockee = $stockees->get($cle);
                if ($stockee && ($stockee->is_arretee || $m['conduite'])) {
                    $moyennes[$m['id']] = ['moyenne' => (float) $stockee->moyenne, 'arretee' => (bool) $stockee->is_arretee];
                } elseif (! $m['conduite'] && ($valeur = self::moyenneNotes($notes->get($cle, collect()))) !== null) {
                    $moyennes[$m['id']] = ['moyenne' => $valeur, 'arretee' => false];
                }
            }

            return $e + ['moyennes' => $moyennes];
        });

        $lignes = self::generales($lignes, $matieres);
        $conduite = $matieres->firstWhere('conduite', true);

        return [
            'matieres' => $matieres->map(fn ($m) => $m + [
                'evaluations' => (int) ($evaluations[$m['id']] ?? 0),
                'arretee' => $m['conduite'] || ($lignes->contains(fn ($l) => isset($l['moyennes'][$m['id']]))
                    && $lignes->every(fn ($l) => ! isset($l['moyennes'][$m['id']]) || $l['moyennes'][$m['id']]['arretee'])),
            ])->values()->all(),
            'eleves' => $lignes->map(fn ($l) => $l + [
                'conduite' => $conduite ? ($l['moyennes'][$conduite['id']]['moyenne'] ?? null) : null,
                'distinction' => self::distinction($l['moyenne']),
                'sanctions' => self::sanctions($l['moyenne'], $conduite ? ($l['moyennes'][$conduite['id']]['moyenne'] ?? null) : null),
                'appreciation' => self::appreciationConseil($l['moyenne']),
            ])->values()->all(),
            'statistiques' => self::statistiques($lignes),
        ];
    }

    /**
     * Annuelle : moyennes par matiere et generale ponderees par periode
     * (1, 2, 2), avec la moyenne generale de chaque periode.
     */
    public static function annuel(Classe $classe, Collection $periodes): array
    {
        $parPeriode = $periodes->mapWithKeys(fn (Periode $p) => [$p->id => ['periode' => $p, 'resultats' => self::periode($classe, $p)]]);
        $matieres = self::matieres($classe->niveau_id);

        $lignes = self::eleves($classe)->map(function (array $e) use ($parPeriode, $matieres) {
            $moyennes = [];
            $generales = [];
            foreach ($matieres as $m) {
                $somme = 0;
                $poids = 0;
                foreach ($parPeriode as $pp) {
                    $ligne = collect($pp['resultats']['eleves'])->firstWhere('eleve_id', $e['eleve_id']);
                    if (isset($ligne['moyennes'][$m['id']])) {
                        $w = self::poidsAnnuel($pp['periode']);
                        $somme += $w * $ligne['moyennes'][$m['id']]['moyenne'];
                        $poids += $w;
                    }
                }
                if ($poids > 0) {
                    $moyennes[$m['id']] = ['moyenne' => round($somme / $poids, 2), 'arretee' => true];
                }
            }
            foreach ($parPeriode as $id => $pp) {
                $generales[$id] = collect($pp['resultats']['eleves'])->firstWhere('eleve_id', $e['eleve_id'])['moyenne'] ?? null;
            }

            return $e + ['moyennes' => $moyennes, 'periodes' => $generales];
        });

        $lignes = self::generales($lignes, $matieres);
        // Moyenne generale annuelle = moyennes generales des periodes ponderees (V1 "TRIMESTRE" annuel).
        $lignes = $lignes->map(function (array $l) use ($parPeriode) {
            $somme = 0;
            $poids = 0;
            foreach ($parPeriode as $id => $pp) {
                if ($l['periodes'][$id] !== null) {
                    $w = self::poidsAnnuel($pp['periode']);
                    $somme += $w * $l['periodes'][$id];
                    $poids += $w;
                }
            }
            $l['moyenne'] = $poids > 0 ? round($somme / $poids, 2) : null;

            return $l;
        });
        $lignes = self::classer($lignes, 'moyenne', 'rang');
        $conduite = $matieres->firstWhere('conduite', true);

        return [
            'matieres' => $matieres->map(fn ($m) => $m + ['evaluations' => 0, 'arretee' => true])->values()->all(),
            'periodes' => $periodes->map(fn (Periode $p) => ['id' => $p->id, 'libelle' => $p->libelle, 'poids' => self::poidsAnnuel($p)])->values()->all(),
            'eleves' => $lignes->map(fn ($l) => $l + [
                'conduite' => $conduite ? ($l['moyennes'][$conduite['id']]['moyenne'] ?? null) : null,
                'distinction' => self::distinction($l['moyenne']),
                'sanctions' => self::sanctions($l['moyenne'], $conduite ? ($l['moyennes'][$conduite['id']]['moyenne'] ?? null) : null),
                'appreciation' => self::appreciationConseil($l['moyenne']),
                // Proposition de decision (V1 : admis a partir de 10).
                'decision_proposee' => $l['moyenne'] === null ? null : ($l['moyenne'] >= 10 ? 'ADMIS' : 'REDOUBLE'),
            ])->values()->all(),
            'statistiques' => self::statistiques($lignes),
        ];
    }

    /** Totaux, moyenne generale, bilans et rangs (general et par matiere). */
    private static function generales(Collection $lignes, Collection $matieres): Collection
    {
        $parId = $matieres->keyBy('id');
        $lignes = $lignes->map(function (array $l) use ($parId) {
            $points = 0;
            $coefs = 0;
            $groupes = ['LITTERAIRE' => [0, 0], 'SCIENTIFIQUE' => [0, 0]];
            foreach ($l['moyennes'] as $id => $m) {
                $matiere = $parId[$id];
                $c = $matiere['coefficient'];
                $points += $m['moyenne'] * $c;
                $coefs += $c;
                if (isset($groupes[$matiere['groupe']])) {
                    $groupes[$matiere['groupe']][0] += $m['moyenne'] * $c;
                    $groupes[$matiere['groupe']][1] += $c;
                }
            }

            return $l + [
                'total_points' => round($points, 2),
                'total_coefficients' => $coefs,
                'moyenne' => $coefs > 0 ? round($points / $coefs, 2) : null,
                'lettres' => $groupes['LITTERAIRE'][1] ? round($groupes['LITTERAIRE'][0] / $groupes['LITTERAIRE'][1], 2) : null,
                'sciences' => $groupes['SCIENTIFIQUE'][1] ? round($groupes['SCIENTIFIQUE'][0] / $groupes['SCIENTIFIQUE'][1], 2) : null,
            ];
        });
        $lignes = self::classer($lignes, 'moyenne', 'rang');

        // Rang et appreciation par matiere.
        foreach ($matieres as $m) {
            $valeurs = $lignes->map(fn ($l) => $l['moyennes'][$m['id']]['moyenne'] ?? null)->filter(fn ($v) => $v !== null)->sortDesc()->values();
            $lignes = $lignes->map(function (array $l) use ($m, $valeurs) {
                if (isset($l['moyennes'][$m['id']])) {
                    $v = $l['moyennes'][$m['id']]['moyenne'];
                    $l['moyennes'][$m['id']]['rang'] = $valeurs->search(fn ($x) => $x == $v) + 1;
                    $l['moyennes'][$m['id']]['appreciation'] = self::appreciation($v);
                }

                return $l;
            });
        }

        return $lignes->values();
    }

    /** Rang avec ex aequo sur une cle (null = non classe). */
    public static function classer(Collection $lignes, string $cle, string $rang): Collection
    {
        $valeurs = $lignes->pluck($cle)->filter(fn ($v) => $v !== null)->sortDesc()->values();

        return $lignes->map(function (array $l) use ($cle, $rang, $valeurs) {
            $l[$rang] = $l[$cle] === null ? null : $valeurs->search(fn ($x) => $x == $l[$cle]) + 1;
            $l[$rang.'_ex_aequo'] = $l[$cle] !== null && $valeurs->filter(fn ($x) => $x == $l[$cle])->count() > 1;

            return $l;
        });
    }

    /** Resultats de la classe (V1 bulletin : moyenne de classe, maxi, mini). */
    public static function statistiques(Collection $lignes): array
    {
        $classes = $lignes->filter(fn ($l) => $l['moyenne'] !== null);
        $admis = $classes->filter(fn ($l) => $l['moyenne'] >= 10);
        $parSexe = fn (string $s) => [
            'classes' => $classes->where('sexe', $s)->count(),
            'admis' => $admis->where('sexe', $s)->count(),
        ];

        return [
            'effectif' => $lignes->count(),
            'classes' => $classes->count(),
            'moyenne_classe' => $classes->count() ? round($classes->avg('moyenne'), 2) : null,
            'maximum' => $classes->max('moyenne'),
            'minimum' => $classes->min('moyenne'),
            'moyenne_10' => $admis->count(),
            'taux_reussite' => $classes->count() ? round($admis->count() * 100 / $classes->count(), 2) : null,
            'filles' => $parSexe('F'),
            'garcons' => $parSexe('M'),
        ];
    }

    private static function seuil(?float $valeur, array $seuils): ?string
    {
        if ($valeur === null) {
            return null;
        }
        foreach ($seuils as [$minimum, $libelle]) {
            if ($valeur >= $minimum) {
                return $libelle;
            }
        }

        return null;
    }
}
