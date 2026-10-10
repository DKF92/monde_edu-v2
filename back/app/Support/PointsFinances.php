<?php

namespace App\Support;

use App\Models\Depense;
use App\Models\Entree;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Points des depenses et des entrees, au format des points (App\Support\Points) :
 * sections de lignes, colonnes caisse / banque / total.
 *
 * - Entrees caisse = paiements des eleves (reglements) ; entrees banque =
 *   table « entrees ». Les versements de la caisse a la banque sont un
 *   transfert : ils sont montres mais hors du total (deja comptes en caisse).
 * - Depenses : sorties de caisse (V1) et depenses payees par la banque.
 */
class PointsFinances
{
    public const COLONNES = ['caisse' => 'Caisse', 'banque' => 'Banque', 'total' => 'Total'];

    // ------------------------------------------------------------ Entrees

    public static function entrees(int $anneeId, array $periode, bool $general = false): array
    {
        $etab = app(TenantContext::class)->id();
        // Paiements des eleves : frais de l'annee et dettes, par caissier, mois et mode.
        $caisse = self::dansPeriode(DB::table('reglement_lignes as rl')->join('reglements as g', 'g.id', '=', 'rl.reglement_id')
            ->where('g.etablissement_id', $etab)->where('g.annee_scolaire_id', $anneeId), 'g.date_paiement', $periode)
            ->groupBy('g.caissier_id', 'mois', 'g.mode_paiement', 'nature')
            ->selectRaw("g.caissier_id, DATE_FORMAT(g.date_paiement, '%Y-%m') AS mois, g.mode_paiement AS mode,
                CASE WHEN rl.dette_id IS NULL THEN 'frais' ELSE 'dettes' END AS nature, SUM(rl.montant) AS montant, COUNT(DISTINCT g.id) AS nombre")
            ->get();
        $banque = self::dansPeriode(DB::table('entrees as x')->leftJoin('banques as b', 'b.id', '=', 'x.banque_id')
            ->where('x.etablissement_id', $etab)->where('x.annee_scolaire_id', $anneeId), 'x.date_entree', $periode)
            ->groupBy('x.categorie', 'x.banque_id', 'b.nom', 'b.sigle', 'mois', 'x.mode_paiement')
            ->selectRaw("x.categorie, x.banque_id, TRIM(CONCAT(COALESCE(CONCAT(b.sigle, ' · '), ''), b.nom)) AS banque, DATE_FORMAT(x.date_entree, '%Y-%m') AS mois,
                x.mode_paiement AS mode, SUM(x.montant) AS montant, COUNT(*) AS nombre")
            ->get();
        // Versements de la caisse : transfert interne, hors des totaux.
        $transferts = $banque->where('categorie', 'versement_caisse');
        $recettes = $banque->where('categorie', '!=', 'versement_caisse');
        $nombre = (int) DB::table('reglements')->where('etablissement_id', $etab)->where('annee_scolaire_id', $anneeId)
            ->when($periode['du'] ?? null, fn ($q, $d) => $q->whereDate('date_paiement', '>=', $d))
            ->when($periode['au'] ?? null, fn ($q, $d) => $q->whereDate('date_paiement', '<=', $d))->count();

        $ligne = fn (string $cle, string $libelle, int $caisse, int $banque) => ['cle' => $cle, 'libelle' => $libelle, 'caisse' => $caisse, 'banque' => $banque, 'total' => $caisse + $banque];
        $somme = fn (Collection $l) => (int) $l->sum('montant');

        $lignes = [
            $ligne('frais', 'Paiements des élèves · frais de l\'année', $somme($caisse->where('nature', 'frais')), 0),
            $ligne('dettes', 'Paiements des élèves · dettes', $somme($caisse->where('nature', 'dettes')), 0),
        ];
        foreach (Entree::CATEGORIES as $cle => $libelle) {
            if ($cle !== 'versement_caisse' && $recettes->where('categorie', $cle)->isNotEmpty()) {
                $lignes[] = $ligne('cat-'.$cle, $libelle, 0, $somme($recettes->where('categorie', $cle)));
            }
        }
        $sections = [[
            'cle' => 'total', 'titre' => 'Total des entrées', 'partie' => 'Total des entrées',
            'sous_titre' => ucfirst($periode['libelle'] ?? '').' · '.$nombre.' paiement'.($nombre > 1 ? 's' : '').' d\'élèves · '.(int) $recettes->sum('nombre').' entrée'.((int) $recettes->sum('nombre') > 1 ? 's' : '').' banque'
                .($transferts->isNotEmpty() ? ' · versements de la caisse à la banque ('.number_format($somme($transferts), 0, ',', ' ').' F) hors total : déjà comptés en caisse' : ''),
            'lignes' => $lignes,
            'total' => $ligne('total', 'Total des entrées', $somme($caisse), $somme($recettes)),
        ]];

        // Caisse : total de chaque caissier ; banque : total de chaque banque (versements compris).
        $caissiers = DB::table('users')->whereIn('id', $caisse->pluck('caissier_id')->filter()->unique())->orderBy('name')->get(['id', 'name', 'prenoms']);
        $parCaissier = $caissiers->map(fn ($u) => $ligne('caissier-'.$u->id, trim($u->name.' '.$u->prenoms), $somme($caisse->where('caissier_id', $u->id)), 0))->values();
        if ($caisse->whereNull('caissier_id')->isNotEmpty()) {
            $parCaissier->push($ligne('caissier-0', 'Caissier non renseigné', $somme($caisse->whereNull('caissier_id')), 0));
        }
        $sections[] = self::section('caissiers', 'Entrées caisse par caissier', 'Paiements des élèves encaissés par chacun', $parCaissier, 'Total caisse');
        $parBanque = $banque->groupBy('banque_id')->map(fn (Collection $l) => $ligne('banque-'.$l->first()->banque_id, $l->first()->banque ?: '—', 0, $somme($l)))->sortBy('libelle')->values();
        $sections[] = self::section('banques', 'Entrées banque par banque', $transferts->isNotEmpty() ? 'Versements de la caisse compris' : null, $parBanque, 'Total banque');

        if ($general) {
            $sections[] = self::section('versements', 'Versements de la caisse à la banque', 'Transfert interne : hors du total des entrées',
                $transferts->groupBy('banque_id')->map(fn (Collection $l) => $ligne('versement-'.$l->first()->banque_id, $l->first()->banque ?: '—', 0, $somme($l)))->values(), 'Total versé');
            $sections[] = self::section('mois', 'Entrées par mois', 'Hors versements de la caisse', self::parMois($anneeId, $periode, $caisse, $recettes), 'Total');
            $sections[] = self::section('modes', 'Entrées par mode de paiement', 'Hors versements de la caisse', collect(Depense::MODES)
                ->map(fn ($libelle, $mode) => $ligne('mode-'.$mode, $libelle, $somme($caisse->where('mode', $mode)), $somme($recettes->where('mode', $mode))))
                ->filter(fn ($l) => $l['total'] > 0)->values(), 'Total');
        }

        return ['unite' => 'montant', 'colonnes' => self::COLONNES, 'sections' => $sections];
    }

    // ------------------------------------------------------------ Depenses

    public static function depenses(int $anneeId, array $periode, bool $general = false): array
    {
        $lignes = self::dansPeriode(DB::table('depenses as d')->leftJoin('banques as b', 'b.id', '=', 'd.banque_id')->leftJoin('users as u', 'u.id', '=', 'd.payeur_id')
            ->where('d.etablissement_id', app(TenantContext::class)->id())->where('d.annee_scolaire_id', $anneeId), 'd.date_depense', $periode)
            ->groupBy('d.categorie', 'd.source', 'd.banque_id', 'b.nom', 'b.sigle', 'mois', 'd.mode_paiement', 'd.payeur_id', 'u.name', 'u.prenoms')
            ->selectRaw("d.categorie, COALESCE(d.source, 'caisse') AS source, d.banque_id, TRIM(CONCAT(COALESCE(CONCAT(b.sigle, ' · '), ''), b.nom)) AS banque,
                DATE_FORMAT(d.date_depense, '%Y-%m') AS mois, d.mode_paiement AS mode, d.payeur_id, TRIM(CONCAT(COALESCE(u.name, ''), ' ', COALESCE(u.prenoms, ''))) AS payeur,
                SUM(d.montant) AS montant, COUNT(*) AS nombre")
            ->get();
        $ligne = function (string $cle, string $libelle, Collection $l) {
            $caisse = (int) $l->where('source', 'caisse')->sum('montant');
            $banque = (int) $l->where('source', 'banque')->sum('montant');

            return ['cle' => $cle, 'libelle' => $libelle, 'caisse' => $caisse, 'banque' => $banque, 'total' => $caisse + $banque];
        };
        $nombre = (int) $lignes->sum('nombre');

        $sections = [array_merge(self::section('total', 'Dépenses par catégorie', ucfirst($periode['libelle'] ?? '').' · '.$nombre.' dépense'.($nombre > 1 ? 's' : ''),
            collect(Depense::CATEGORIES)->map(fn ($libelle, $cle) => $ligne('cat-'.$cle, $libelle, $lignes->where('categorie', $cle)))->filter(fn ($l) => $l['total'] > 0)->values(),
            'Total des dépenses', $ligne('total', 'Total des dépenses', $lignes)), ['partie' => 'Dépenses par catégorie']),
        ];
        $sections[] = self::section('banques', 'Dépenses banque par banque', null,
            $lignes->where('source', 'banque')->groupBy('banque_id')->map(fn (Collection $l) => $ligne('banque-'.$l->first()->banque_id, $l->first()->banque ?: '—', $l))->sortBy('libelle')->values(), 'Total banque');

        if ($general) {
            $sections[] = self::section('personnes', 'Dépenses par personne ayant saisi', null,
                $lignes->groupBy('payeur_id')->map(fn (Collection $l) => $ligne('payeur-'.($l->first()->payeur_id ?? 0), trim((string) $l->first()->payeur) ?: '—', $l))->sortBy('libelle')->values(), 'Total');
            $mois = collect(BilanCaisse::moisDeLAnnee(\App\Models\AnneeScolaire::find($anneeId)))
                ->filter(fn ($m) => $lignes->where('mois', $m['valeur'])->isNotEmpty())
                ->map(fn ($m) => $ligne('mois-'.$m['valeur'], $m['libelle'], $lignes->where('mois', $m['valeur'])))->values();
            $sections[] = self::section('mois', 'Dépenses par mois', null, $mois, 'Total');
            $sections[] = self::section('modes', 'Dépenses par mode de paiement', null,
                collect(Depense::MODES)->map(fn ($libelle, $mode) => $ligne('mode-'.$mode, $libelle, $lignes->where('mode', $mode)))->filter(fn ($l) => $l['total'] > 0)->values(), 'Total');
        }

        return ['unite' => 'montant', 'colonnes' => self::COLONNES, 'sections' => $sections];
    }

    // ------------------------------------------------------------ Outils

    private static function dansPeriode(Builder $q, string $colonne, array $periode): Builder
    {
        return $q->when($periode['du'] ?? null, fn (Builder $x, $d) => $x->whereDate($colonne, '>=', $d))
            ->when($periode['au'] ?? null, fn (Builder $x, $d) => $x->whereDate($colonne, '<=', $d));
    }

    /** Section : total = somme des lignes (sauf total fourni). */
    private static function section(string $cle, string $titre, ?string $sousTitre, Collection $lignes, string $libelleTotal, ?array $total = null): array
    {
        $total ??= ['cle' => 'total', 'libelle' => $libelleTotal] + collect(array_keys(self::COLONNES))->mapWithKeys(fn ($c) => [$c => (int) $lignes->sum($c)])->all();

        return ['cle' => $cle, 'titre' => $titre, 'sous_titre' => $sousTitre, 'partie' => $titre, 'lignes' => $lignes->values()->all(), 'total' => $total];
    }

    private static function parMois(int $anneeId, array $periode, Collection $caisse, Collection $banque): Collection
    {
        return collect(BilanCaisse::moisDeLAnnee(\App\Models\AnneeScolaire::find($anneeId)))
            ->filter(fn ($m) => $caisse->where('mois', $m['valeur'])->isNotEmpty() || $banque->where('mois', $m['valeur'])->isNotEmpty())
            ->map(function ($m) use ($caisse, $banque) {
                $c = (int) $caisse->where('mois', $m['valeur'])->sum('montant');
                $b = (int) $banque->where('mois', $m['valeur'])->sum('montant');

                return ['cle' => 'mois-'.$m['valeur'], 'libelle' => $m['libelle'], 'caisse' => $c, 'banque' => $b, 'total' => $c + $b];
            })->values();
    }
}
