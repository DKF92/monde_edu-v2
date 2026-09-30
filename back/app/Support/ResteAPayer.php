<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reste a payer des eleves inscrits (V1 "Point scolarite" : reste dette,
 * reste inscription, reste total), une ligne par inscription de l'annee :
 * - inscription : montant avant reduction, reduction, montant a payer, paye, reste ;
 * - dettes des annees precedentes (non annulees) : montant, reduction, apres
 *   reduction, paye, reste ;
 * - assistance : "cas" (type de reduction qui couvre les frais principaux,
 *   V1 cas social) ou "reduction", sinon vide.
 */
class ResteAPayer
{
    /** Libelles des etapes (inscriptions.statut, V1 inscr_termine). */
    public const ETAPES = [
        1 => 'En attente du 1er versement',
        2 => 'En attente de solder',
        3 => 'Soldés',
    ];

    /** Liste des dettes (V1 liste_dette) : etat de la dette de l'eleve. */
    public const ETATS_DETTE = [
        1 => 'Dette à payer',
        2 => 'Dette soldée',
    ];

    /** Reste de la dette (apres reduction et paiements). */
    private const RESTE_DETTE = 'GREATEST(COALESCE(d.dette, 0) - COALESCE(d.dette_reduite, 0) - COALESCE(d.dette_payee, 0), 0)';

    /**
     * @param  array{recherche?: ?string, statut?: ?int, criteres?: array, enfants?: ?array, dettes?: bool, etat_dette?: ?int}  $filtres
     *   dettes : liste des dettes, eleves inscrits de l'annee (tous statuts) qui ont
     *   une dette non annulee ; etat_dette : 1 a payer, 2 soldee.
     */
    public static function requete(int $anneeId, array $filtres): Builder
    {
        $etablissement = app(TenantContext::class)->id();
        $modeDettes = (bool) ($filtres['dettes'] ?? false);
        $dettes = DB::table('dettes as dt')
            ->leftJoin('annees_scolaires as ad', 'ad.id', '=', 'dt.annee_scolaire_id')
            ->where('dt.etablissement_id', $etablissement)->where('dt.is_annulee', false)
            ->groupBy('dt.eleve_id')
            ->selectRaw("dt.eleve_id, SUM(dt.montant) AS dette, SUM(dt.montant_reduit) AS dette_reduite, SUM(dt.montant_paye) AS dette_payee,
                GROUP_CONCAT(DISTINCT ad.libelle ORDER BY ad.libelle SEPARATOR ', ') AS dette_annees");
        $assistance = DB::table('reductions as rd')
            ->join('frais_eleves as fe', 'fe.id', '=', 'rd.frais_eleve_id')
            ->leftJoin('types_reductions as tr', 'tr.id', '=', 'rd.type_reduction_id')
            ->where('rd.is_active', true)
            ->groupBy('fe.inscription_id')
            ->selectRaw('fe.inscription_id, MAX(COALESCE(tr.minimum_frais_principaux, 0)) AS cas, COUNT(*) AS nombre');

        $c = BilanCaisse::criteres($filtres['criteres'] ?? []);
        $recherche = trim((string) ($filtres['recherche'] ?? ''));

        return DB::table('inscriptions as i')
            ->join('eleves as e', 'e.id', '=', 'i.eleve_id')
            ->leftJoin('classes as c', 'c.id', '=', 'i.classe_id')
            ->leftJoin('niveaux as n', 'n.id', '=', 'i.niveau_id')
            ->leftJoinSub($dettes, 'd', 'd.eleve_id', '=', 'i.eleve_id')
            ->leftJoinSub($assistance, 'a', 'a.inscription_id', '=', 'i.id')
            ->where('i.etablissement_id', $etablissement)
            ->where('i.annee_scolaire_id', $anneeId)
            // Statut 0 (en attente de choix de classe) : frais pas encore fixes, hors reste a payer
            // (mais sa dette figure dans la liste des dettes).
            ->when(! $modeDettes, fn (Builder $q) => $q->where('i.statut', '>=', 1))
            ->when($modeDettes, fn (Builder $q) => $q->where('d.dette', '>', 0))
            ->when($modeDettes && ($filtres['etat_dette'] ?? null) === 1, fn (Builder $q) => $q->whereRaw(self::RESTE_DETTE.' > 0'))
            ->when($modeDettes && ($filtres['etat_dette'] ?? null) === 2, fn (Builder $q) => $q->whereRaw(self::RESTE_DETTE.' = 0'))
            ->whereNull('e.deleted_at')
            ->when($recherche !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('e.matricule', 'like', "{$recherche}%")
                ->orWhere('e.nom', 'like', "%{$recherche}%")
                ->orWhere('e.prenoms', 'like', "%{$recherche}%")
                ->orWhereRaw("CONCAT(e.nom, ' ', e.prenoms) LIKE ?", ["%{$recherche}%"])))
            ->when(isset($filtres['statut']), fn (Builder $q) => $q->where('i.statut', $filtres['statut']))
            ->when($c['sexe'], fn (Builder $q, $s) => $q->where('e.sexe', $s))
            ->when($c['redoublant'] !== null, fn (Builder $q) => $q->where('i.redoublant', $c['redoublant']))
            ->when($c['classe_id'], fn (Builder $q, $id) => $q->where('i.classe_id', $id))
            ->when($c['niveau_id'], fn (Builder $q, $id) => $q->where('i.niveau_id', $id))
            ->when($c['cycle'], fn (Builder $q, $cy) => $q->where('n.cycle', $cy))
            ->when(($filtres['enfants'] ?? null) !== null, fn (Builder $q) => $q->whereIn('i.eleve_id', $filtres['enfants'] ?: [0]))
            ->select([
                'i.id', 'i.eleve_id', 'e.matricule', 'e.nom', 'e.prenoms', 'e.sexe',
                'c.libelle as classe', 'n.libelle as niveau', 'n.cycle', 'n.ordre as niveau_ordre',
                'i.affecte', 'i.redoublant', 'i.statut',
                'i.montant_total_du as montant_du', 'i.montant_total_reduit as reduction', 'i.montant_total_paye as paye',
            ])
            ->selectRaw('i.montant_total_du - i.montant_total_reduit AS a_payer')
            ->selectRaw('GREATEST(i.montant_total_du - i.montant_total_reduit - i.montant_total_paye, 0) AS reste_inscription')
            ->selectRaw('COALESCE(d.dette, 0) AS dette, COALESCE(d.dette_reduite, 0) AS dette_reduite, COALESCE(d.dette_payee, 0) AS dette_payee')
            ->selectRaw('COALESCE(d.dette, 0) - COALESCE(d.dette_reduite, 0) AS dette_apres')
            ->selectRaw(self::RESTE_DETTE.' AS reste_dette')
            ->addSelect('d.dette_annees')
            ->selectRaw('GREATEST(i.montant_total_du - i.montant_total_reduit - i.montant_total_paye, 0)
                + GREATEST(COALESCE(d.dette, 0) - COALESCE(d.dette_reduite, 0) - COALESCE(d.dette_payee, 0), 0) AS reste')
            ->selectRaw("CASE WHEN a.cas = 1 THEN 'cas' WHEN a.nombre > 0 THEN 'reduction' ELSE NULL END AS assistance");
    }

    /** Ligne pour l'ecran et les documents (montants entiers). */
    public static function presenter(object $l): array
    {
        $entier = fn ($v) => (int) round((float) $v);

        return [
            'id' => (int) $l->id,
            'eleve_id' => (int) $l->eleve_id,
            'matricule' => $l->matricule,
            'nom' => $l->nom,
            'prenoms' => $l->prenoms,
            'sexe' => $l->sexe,
            'classe' => $l->classe,
            'niveau' => $l->niveau,
            'niveau_ordre' => $l->niveau_ordre !== null ? (int) $l->niveau_ordre : null,
            'cycle' => $l->cycle,
            'affecte' => (bool) $l->affecte,
            'redoublant' => (bool) $l->redoublant,
            'statut' => (int) $l->statut,
            'assistance' => $l->assistance,
            'montant_du' => $entier($l->montant_du),
            'reduction' => $entier($l->reduction),
            'a_payer' => $entier($l->a_payer),
            'paye' => $entier($l->paye),
            'reste_inscription' => $entier($l->reste_inscription),
            'dette' => $entier($l->dette),
            'dette_reduite' => $entier($l->dette_reduite),
            'dette_apres' => $entier($l->dette_apres),
            'dette_payee' => $entier($l->dette_payee),
            'reste_dette' => $entier($l->reste_dette),
            'reste' => $entier($l->reste),
            'dette_annees' => $l->dette_annees,
            'etat_dette' => $entier($l->dette) > 0 ? ($entier($l->reste_dette) > 0 ? 1 : 2) : null,
        ];
    }

    /** Totaux des colonnes de montants. */
    public static function totaux(Collection $lignes): array
    {
        $cles = ['montant_du', 'reduction', 'a_payer', 'paye', 'reste_inscription', 'dette', 'dette_reduite', 'dette_apres', 'dette_payee', 'reste_dette', 'reste'];

        return collect($cles)->mapWithKeys(fn ($k) => [$k => (int) $lignes->sum($k)])->all();
    }

    /**
     * Liste imprimee : classe apres classe (ordre des niveaux puis des classes),
     * eleves par nom et prenoms ; les eleves sans classe a la fin.
     *
     * @return list<array{classe: string, lignes: list<array>, totaux: array}>
     */
    public static function parClasse(Collection $lignes): array
    {
        return $lignes
            ->sortBy(fn ($l) => [$l['classe'] === null ? 1 : 0, $l['niveau_ordre'] ?? 99, $l['classe'] ?? '', $l['nom'], $l['prenoms']])
            ->groupBy(fn ($l) => $l['classe'] ?? 'Sans classe'.($l['niveau'] ? ' ('.$l['niveau'].')' : ''))
            ->map(fn (Collection $g, $classe) => ['classe' => $classe, 'lignes' => $g->values()->all(), 'totaux' => self::totaux($g)])
            ->values()->all();
    }

    /**
     * Statistiques : nombre d'eleves par etape (1er versement attendu, solde
     * attendu, soldes) selon le sexe, le statut, le redoublement, le niveau et le cycle.
     *
     * Liste des dettes : colonnes ETATS_DETTE sur la cle "etat_dette".
     *
     * @return list<array{titre: string, colonnes: array, lignes: list<array{libelle: string, valeurs: array, total: int}>}>
     */
    public static function statistiques(Collection $lignes, array $colonnes = self::ETAPES, string $cle = 'statut'): array
    {
        $etapes = collect($colonnes);
        $tableau = function (string $titre, Collection $groupes) use ($etapes, $cle) {
            $rangees = $groupes->map(fn (Collection $g, $libelle) => [
                'libelle' => (string) $libelle,
                'valeurs' => $etapes->map(fn ($_, $e) => $g->where($cle, $e)->count())->all(),
                'total' => $g->count(),
            ])->values();
            $rangees->push([
                'libelle' => 'Total',
                'valeurs' => $etapes->map(fn ($_, $e) => (int) $rangees->sum(fn ($r) => $r['valeurs'][$e]))->all(),
                'total' => (int) $rangees->sum('total'),
            ]);

            return ['titre' => $titre, 'colonnes' => $etapes->all(), 'lignes' => $rangees->all()];
        };
        $ordonne = fn (Collection $g, array $ordre) => collect($ordre)->mapWithKeys(fn ($libelle, $cle) => [$libelle => $g->get($cle, collect())]);
        $parNiveau = $lignes->sortBy('niveau_ordre')->groupBy(fn ($l) => $l['niveau'] ?? '—');
        $parCycle = $lignes->sortBy('niveau_ordre')->groupBy(fn ($l) => BilanCaisse::CYCLES[$l['cycle']] ?? '—');

        return [
            $tableau('Par sexe', $ordonne($lignes->groupBy('sexe'), ['F' => 'Filles', 'M' => 'Garçons'])),
            $tableau('Par statut', $ordonne($lignes->groupBy(fn ($l) => $l['affecte'] ? 'a' : 'n'), ['a' => 'Affectés', 'n' => 'Non affectés'])),
            $tableau('Par redoublement', $ordonne($lignes->groupBy(fn ($l) => $l['redoublant'] ? 'r' : 'n'), ['n' => 'Non redoublants', 'r' => 'Redoublants'])),
            $tableau('Par niveau', $parNiveau),
            $tableau('Par cycle', $parCycle),
        ];
    }
}
