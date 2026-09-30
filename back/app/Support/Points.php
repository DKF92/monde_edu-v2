<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Points (bilans) presentes comme le point de caisse : lignes x colonnes
 * affectes / non affectes / total, par sections.
 *
 * - Point des inscriptions (V1 Point_inscription) : eleves inscrits sur la
 *   periode (date d'inscription), selon qu'ils sont passes a la caisse (au
 *   moins un paiement a la fin de la periode), qu'ils sont un "cas" (reduction
 *   de type CAS, V1 is_cas = 2) ou qu'ils ne sont pas passes a la caisse.
 * - Point des dettes (V1 Point_dette) : dettes des annees precedentes
 *   encaissees sur la periode (par annee d'origine), puis situation a ce jour
 *   des dettes des eleves inscrits cette annee.
 *
 * Section : ['cle', 'titre', 'sous_titre', 'lignes' => [[cle, libelle,
 * affecte, non_affecte, total, unite?]], 'total' => ligne|null].
 */
class Points
{
    public const ETATS_INSCRIPTION = [
        'paye' => 'Passés à la caisse',
        'cas' => 'Cas sociaux',
        'non_paye' => 'Pas passés à la caisse',
    ];

    // ------------------------------------------------------------ Inscriptions

    /**
     * @param  array{du: ?string, au: ?string}  $periode
     * @param  ?array<int>  $niveaux  niveaux autorises (educateur), null = tous
     */
    public static function inscriptions(int $anneeId, array $periode, array $criteres, ?array $niveaux, bool $general = false): array
    {
        $lignes = self::requeteInscriptions($anneeId, $periode, $criteres, $niveaux)
            ->groupBy('i.niveau_id', 'i.classe_id', 'i.affecte', 'e.sexe', 'i.enregistre_par_id', 'etat')
            ->selectRaw('i.niveau_id, i.classe_id, i.affecte, e.sexe, i.enregistre_par_id, '.self::etatSql($periode['au'] ?? null).' AS etat, COUNT(*) AS n')
            ->get();

        // 'partie' : grand titre du document general (une partie regroupe ses sections).
        $section = fn (string $cle, string $titre, Collection $l, ?string $partie = null, ?string $sousTitre = null) => self::sectionInscriptions($cle, $titre, $l, $sousTitre) + ['partie' => $partie ?? $titre];
        $sections = [$section('total', 'Total des inscrits', $lignes, null, $periode['libelle'] ?? null)];

        $niveauxListe = DB::table('niveaux')->whereIn('id', $lignes->pluck('niveau_id')->unique())->orderBy('ordre')->get(['id', 'libelle']);
        // A l'ecran, seul le point total (les filtres font le reste) ; le detail
        // par genre, niveau, classe et agent est reserve a l'impression generale.
        if ($general) {
            foreach (['F' => 'filles', 'M' => 'garçons'] as $sexe => $titre) {
                $sections[] = $section('sexe-'.$sexe, 'Total inscrits '.$titre, $lignes->where('sexe', $sexe), 'Total inscrits par genre');
            }
            foreach ($niveauxListe as $n) {
                $sections[] = $section('niveau-'.$n->id, 'Total inscrits '.$n->libelle, $lignes->where('niveau_id', $n->id), 'Total inscrits par niveau') + ['niveau_id' => $n->id];
            }
            $classes = DB::table('classes')->whereIn('id', $lignes->pluck('classe_id')->filter()->unique())->get(['id', 'libelle', 'niveau_id'])
                ->sortBy(fn ($c) => [$niveauxListe->search(fn ($n) => $n->id === $c->niveau_id), $c->libelle]);
            foreach ($classes as $c) {
                $sections[] = $section('classe-'.$c->id, 'Total inscrits '.$c->libelle, $lignes->where('classe_id', $c->id), 'Total inscrits par classe') + ['classe_id' => $c->id];
            }
            if ($lignes->whereNull('classe_id')->isNotEmpty()) {
                $sections[] = $section('sans-classe', 'Total inscrits sans classe', $lignes->whereNull('classe_id'), 'Total inscrits par classe');
            }
            $agents = DB::table('users')->whereIn('id', $lignes->pluck('enregistre_par_id')->filter()->unique())->get(['id', 'name', 'prenoms']);
            foreach ($agents->sortBy('name') as $u) {
                $sections[] = $section('agent-'.$u->id, 'Total inscrits par '.trim($u->name.' '.$u->prenoms), $lignes->where('enregistre_par_id', $u->id), 'Total inscrits par agent');
            }
        }

        return [
            'unite' => 'eleves',
            'colonnes' => ['affecte' => 'Inscrits affectés', 'non_affecte' => 'Inscrits non affectés', 'total' => 'Total'],
            'sections' => $sections,
        ];
    }

    /** Eleves d'une case du point des inscriptions (oeil). */
    public static function elevesInscriptions(int $anneeId, array $periode, array $criteres, ?array $niveaux, ?string $etat, ?bool $affecte): Collection
    {
        return self::requeteInscriptions($anneeId, $periode, $criteres, $niveaux)
            ->when($etat, fn (Builder $q) => $q->whereRaw(self::etatSql($periode['au'] ?? null).' = ?', [$etat]))
            ->when($affecte !== null, fn (Builder $q) => $q->where('i.affecte', $affecte))
            ->leftJoin('classes as c', 'c.id', '=', 'i.classe_id')
            ->join('niveaux as n', 'n.id', '=', 'i.niveau_id')
            ->orderBy('n.ordre')->orderBy('c.libelle')->orderBy('e.nom')->orderBy('e.prenoms')
            ->get(['i.id as inscription_id', 'e.id as eleve_id', 'e.matricule', 'e.nom', 'e.prenoms', 'e.sexe', 'i.affecte', 'i.redoublant',
                'n.libelle as niveau', 'c.libelle as classe', 'i.date_inscription', 'i.montant_total_du', 'i.montant_total_reduit', 'i.montant_total_paye',
                DB::raw(self::etatSql($periode['au'] ?? null).' AS etat')])
            ->map(fn ($l) => [
                'inscription_id' => (int) $l->inscription_id,
                'eleve_id' => (int) $l->eleve_id,
                'matricule' => $l->matricule,
                'nom' => $l->nom,
                'prenoms' => $l->prenoms,
                'sexe' => $l->sexe,
                'affecte' => (bool) $l->affecte,
                'redoublant' => (bool) $l->redoublant,
                'niveau' => $l->niveau,
                'classe' => $l->classe,
                'date_inscription' => substr((string) $l->date_inscription, 0, 10),
                'du' => (int) $l->montant_total_du - (int) $l->montant_total_reduit,
                'paye' => (int) $l->montant_total_paye,
                'reste' => max(0, (int) $l->montant_total_du - (int) $l->montant_total_reduit - (int) $l->montant_total_paye),
                'etat' => $l->etat,
            ]);
    }

    private static function requeteInscriptions(int $anneeId, array $periode, array $criteres, ?array $niveaux): Builder
    {
        $q = DB::table('inscriptions as i')
            ->join('eleves as e', 'e.id', '=', 'i.eleve_id')
            ->where('i.etablissement_id', app(TenantContext::class)->id())
            ->where('i.annee_scolaire_id', $anneeId)
            ->when($periode['du'] ?? null, fn (Builder $q, $d) => $q->whereDate('i.date_inscription', '>=', $d))
            ->when($periode['au'] ?? null, fn (Builder $q, $d) => $q->whereDate('i.date_inscription', '<=', $d))
            ->when($niveaux !== null, fn (Builder $q) => $q->whereIn('i.niveau_id', $niveaux ?: [0]));
        return self::criteresInscription($q, $criteres);
    }

    /** 'cas' (reduction de type CAS), 'paye' (paiement jusqu'a $fin) ou 'non_paye'. */
    private static function etatSql(?string $fin): string
    {
        $cas = "EXISTS (SELECT 1 FROM reductions r JOIN frais_eleves fe ON fe.id = r.frais_eleve_id JOIN types_reductions tr ON tr.id = r.type_reduction_id
            WHERE fe.inscription_id = i.id AND r.is_active = 1 AND tr.code = 'CAS')";
        $fin = $fin ? " AND DATE(g.date_paiement) <= '".preg_replace('/[^0-9-]/', '', $fin)."'" : '';
        $paye = "EXISTS (SELECT 1 FROM reglements g WHERE g.inscription_id = i.id{$fin})";

        return "(CASE WHEN {$cas} THEN 'cas' WHEN {$paye} THEN 'paye' ELSE 'non_paye' END)";
    }

    private static function sectionInscriptions(string $cle, string $titre, Collection $lignes, ?string $sousTitre): array
    {
        $compte = fn (string $etat, ?bool $affecte) => (int) $lignes->filter(fn ($l) => $l->etat === $etat && ($affecte === null || (bool) $l->affecte === $affecte))->sum('n');
        $rangs = collect(self::ETATS_INSCRIPTION)->map(fn ($libelle, $etat) => [
            'cle' => $etat,
            'libelle' => $libelle,
            'affecte' => $compte($etat, true),
            'non_affecte' => $compte($etat, false),
            'total' => $compte($etat, null),
        ])->values()->all();
        $total = ['cle' => 'total', 'libelle' => 'Total', 'affecte' => 0, 'non_affecte' => 0, 'total' => 0];
        foreach ($rangs as $r) {
            foreach (['affecte', 'non_affecte', 'total'] as $c) {
                $total[$c] += $r[$c];
            }
        }

        return ['cle' => $cle, 'titre' => $titre, 'sous_titre' => $sousTitre, 'lignes' => $rangs, 'total' => $total];
    }

    // ------------------------------------------------------------ Dettes

    /**
     * @param  array{du: ?string, au: ?string}  $periode
     */
    public static function dettes(int $anneeId, array $periode, array $criteres, ?int $caissierId, bool $general = false): array
    {
        // Encaissements de dettes sur la periode, statut de l'inscription de l'annee.
        $encaisse = self::criteresInscription(DB::table('reglement_lignes as rl')
            ->join('reglements as g', 'g.id', '=', 'rl.reglement_id')
            ->join('dettes as d', 'd.id', '=', 'rl.dette_id')
            ->leftJoin('annees_scolaires as a', 'a.id', '=', 'd.annee_scolaire_id')
            ->leftJoin('inscriptions as i', fn ($j) => $j->on('i.eleve_id', '=', 'g.eleve_id')->where('i.annee_scolaire_id', $anneeId))
            ->join('eleves as e', 'e.id', '=', 'g.eleve_id')
            ->where('g.etablissement_id', app(TenantContext::class)->id())
            ->where('g.annee_scolaire_id', $anneeId)
            ->when($caissierId, fn (Builder $q, $id) => $q->where('g.caissier_id', $id))
            ->when($periode['du'] ?? null, fn (Builder $q, $d) => $q->whereDate('g.date_paiement', '>=', $d))
            ->when($periode['au'] ?? null, fn (Builder $q, $d) => $q->whereDate('g.date_paiement', '<=', $d)), $criteres)
            ->groupBy('a.libelle', 'i.affecte', 'g.caissier_id', 'i.niveau_id')
            ->selectRaw('a.libelle AS annee, COALESCE(i.affecte, 0) AS affecte, g.caissier_id, i.niveau_id, SUM(rl.montant) AS montant, COUNT(DISTINCT g.id) AS paiements')
            ->get();

        // Situation a ce jour des dettes des eleves inscrits cette annee.
        $situation = self::criteresInscription(DB::table('dettes as d')
            ->join('inscriptions as i', fn ($j) => $j->on('i.eleve_id', '=', 'd.eleve_id')->where('i.annee_scolaire_id', $anneeId))
            ->join('eleves as e', 'e.id', '=', 'd.eleve_id')
            ->where('d.etablissement_id', app(TenantContext::class)->id()), $criteres)
            ->groupBy('i.affecte', 'i.niveau_id', 'i.classe_id', 'd.is_annulee')
            ->selectRaw('i.affecte, i.niveau_id, i.classe_id, d.is_annulee, SUM(d.montant) AS montant, SUM(d.montant_reduit) AS reduit, SUM(d.montant_paye) AS paye,
                SUM(GREATEST(d.montant - d.montant_reduit - d.montant_paye, 0)) AS reste,
                COUNT(DISTINCT CASE WHEN d.montant - d.montant_reduit - d.montant_paye > 0 THEN d.eleve_id END) AS endettes')
            ->get();

        $nombre = (int) $encaisse->sum('paiements');
        $sections = [
            self::sectionEncaisse('encaisse', 'Total des dettes encaissées · '.($periode['libelle'] ?? ''), $encaisse,
                $nombre.' paiement'.($nombre > 1 ? 's' : '').' de dette'.($caissierId ? '' : ' · tous les caissiers')) + ['partie' => 'Total des dettes encaissées'],
        ];

        // A l'ecran, seul le tableau des dettes encaissees ; la situation et le
        // detail par caissier, niveau et classe vont dans l'impression generale
        // ('partie' = grand titre qui regroupe les sections).
        if ($general) {
            $sections[] = self::sectionSituation('situation', 'Situation des dettes à ce jour', $situation) + ['partie' => 'Situation des dettes à ce jour'];
            $caissiers = DB::table('users')->whereIn('id', $encaisse->pluck('caissier_id')->unique())->get(['id', 'name', 'prenoms']);
            foreach ($caissiers->sortBy('name') as $u) {
                $sections[] = self::sectionEncaisse('caissier-'.$u->id, 'Total encaissé par '.trim($u->name.' '.$u->prenoms), $encaisse->where('caissier_id', $u->id), null)
                    + ['partie' => 'Dettes encaissées par caissier'];
            }
            $niveaux = DB::table('niveaux')->whereIn('id', $situation->pluck('niveau_id')->unique())->orderBy('ordre')->get(['id', 'libelle']);
            foreach ($niveaux as $n) {
                $sections[] = self::sectionSituation('niveau-'.$n->id, 'Situation des dettes '.$n->libelle, $situation->where('niveau_id', $n->id))
                    + ['partie' => 'Situation des dettes par niveau'];
            }
            $classes = DB::table('classes')->whereIn('id', $situation->pluck('classe_id')->filter()->unique())->get(['id', 'libelle', 'niveau_id'])
                ->sortBy(fn ($c) => [$niveaux->search(fn ($n) => $n->id === $c->niveau_id), $c->libelle]);
            foreach ($classes as $c) {
                $sections[] = self::sectionSituation('classe-'.$c->id, 'Situation des dettes '.$c->libelle, $situation->where('classe_id', $c->id))
                    + ['partie' => 'Situation des dettes par classe'];
            }
        }

        return [
            'unite' => 'montant',
            'colonnes' => ['affecte' => 'Élèves affectés', 'non_affecte' => 'Élèves non affectés', 'total' => 'Total'],
            'sections' => $sections,
            'nombre_paiements' => $nombre,
        ];
    }

    private static function sectionEncaisse(string $cle, string $titre, Collection $lignes, ?string $sousTitre): array
    {
        $somme = fn (Collection $l, ?bool $affecte) => (int) $l->filter(fn ($x) => $affecte === null || (bool) $x->affecte === $affecte)->sum('montant');
        $rangs = $lignes->groupBy(fn ($l) => $l->annee ?? '—')->sortKeysDesc()->map(fn (Collection $l, $annee) => [
            'cle' => 'annee-'.$annee,
            'libelle' => 'Dettes '.$annee,
            'affecte' => $somme($l, true),
            'non_affecte' => $somme($l, false),
            'total' => $somme($l, null),
        ])->values()->all();

        return ['cle' => $cle, 'titre' => $titre, 'sous_titre' => $sousTitre, 'lignes' => $rangs,
            'total' => ['cle' => 'total', 'libelle' => 'Total encaissé', 'affecte' => $somme($lignes, true), 'non_affecte' => $somme($lignes, false), 'total' => $somme($lignes, null)]];
    }

    private static function sectionSituation(string $cle, string $titre, Collection $lignes): array
    {
        $ouvertes = $lignes->filter(fn ($l) => ! $l->is_annulee);
        $ligne = function (string $cle, string $libelle, string $champ, Collection $source, ?string $unite = null) {
            $somme = fn (?bool $affecte) => (int) $source->filter(fn ($x) => $affecte === null || (bool) $x->affecte === $affecte)->sum($champ);

            return array_filter(['cle' => $cle, 'libelle' => $libelle, 'affecte' => $somme(true), 'non_affecte' => $somme(false), 'total' => $somme(null), 'unite' => $unite], fn ($v) => $v !== null);
        };
        $rangs = [
            $ligne('montant', 'Montant des dettes', 'montant', $ouvertes),
            $ligne('reduit', 'Réductions accordées', 'reduit', $ouvertes),
            $ligne('paye', 'Déjà payé', 'paye', $ouvertes),
            $ligne('annulee', 'Dettes annulées', 'montant', $lignes->filter(fn ($l) => $l->is_annulee)),
            $ligne('endettes', 'Élèves qui doivent encore', 'endettes', $ouvertes, 'eleves'),
        ];

        return ['cle' => $cle, 'titre' => $titre, 'sous_titre' => 'Élèves inscrits cette année', 'lignes' => $rangs,
            'total' => $ligne('reste', 'Reste à payer', 'reste', $ouvertes)];
    }

    // ------------------------------------------------------------ Criteres

    /** Criteres eleve (BilanCaisse::criteres) sur l'inscription "i" et l'eleve "e". */
    public static function criteresInscription(Builder $q, array $c): Builder
    {
        return $q
            ->when($c['sexe'] ?? null, fn (Builder $q, $s) => $q->where('e.sexe', $s))
            ->when(($c['redoublant'] ?? null) !== null, fn (Builder $q) => $q->where('i.redoublant', $c['redoublant']))
            ->when($c['classe_id'] ?? null, fn (Builder $q, $id) => $q->where('i.classe_id', $id))
            ->when($c['niveau_id'] ?? null, fn (Builder $q, $id) => $q->where('i.niveau_id', $id))
            ->when($c['cycle'] ?? null, fn (Builder $q, $cycle) => $q->whereIn('i.niveau_id', DB::table('niveaux')->where('cycle', $cycle)->select('id')));
    }
}
