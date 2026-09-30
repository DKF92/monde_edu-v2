<?php

namespace App\Support;

use App\Models\Dette;
use App\Models\FraisEleve;
use App\Models\Inscription;
use App\Models\Reduction;
use App\Models\TypeReduction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reductions accordees (V1 pages/Reduction et Dette/traitement_dette) :
 * - reduction d'inscription : bornes du type de reduction (TypeReduction::bornes),
 *   imputee d'abord sur les frais principaux (inscription / scolarite), puis,
 *   pour un type "total" (cas social), sur les frais annexes ; dans chaque
 *   groupe, le frais paye en dernier est reduit en premier ;
 * - reduction de dette : au plus le reste de la dette ; "annuler la dette"
 *   la reduit entierement et la marque annulee (V1 dette_annule).
 * Les lignes d'une meme reduction partagent un "lot".
 */
class Reductions
{
    /** @return list<array{frais: FraisEleve, montant: int}> */
    public static function repartition(Inscription $inscription, TypeReduction $type, int $montant): array
    {
        $frais = $inscription->fraisEleves()->with('typeFrais')->whereNull('quantite_due')->get()
            ->filter(fn (FraisEleve $f) => $f->resteAPayer() > 0)
            ->sortByDesc(fn (FraisEleve $f) => [$f->typeFrais->ordre, $f->id]);
        $principaux = $frais->filter(fn (FraisEleve $f) => in_array($f->typeFrais->nature, ['inscription', 'scolarite'], true));
        $ordre = $type->base === 'total' ? $principaux->concat($frais->diffKeys($principaux)) : $principaux;

        $plan = [];
        $reste = $montant;
        foreach ($ordre as $f) {
            $part = (int) min($reste, $f->resteAPayer());
            if ($part > 0) {
                $plan[] = ['frais' => $f, 'montant' => $part];
                $reste -= $part;
            }
        }

        return $plan;
    }

    public static function accorderInscription(Inscription $inscription, TypeReduction $type, int $montant, string $motif, int $userId): string
    {
        $bornes = $type->bornes($inscription);
        if ($bornes['max'] <= 0) {
            throw ValidationException::withMessages(['montant' => ['Plus rien à réduire : les frais concernés sont déjà payés ou réduits.']]);
        }
        if ($montant < $bornes['min'] || $montant > $bornes['max']) {
            throw ValidationException::withMessages(['montant' => ['Le montant doit être compris entre '.self::f($bornes['min']).' et '.self::f($bornes['max']).'.']]);
        }
        $lot = (string) Str::uuid();
        DB::transaction(function () use ($inscription, $type, $montant, $motif, $userId, $lot) {
            foreach (self::repartition($inscription, $type, $montant) as $ligne) {
                Reduction::create([
                    'lot' => $lot, 'type_reduction_id' => $type->id, 'frais_eleve_id' => $ligne['frais']->id,
                    'montant' => $ligne['montant'], 'motif' => $motif, 'accorde_par_id' => $userId, 'is_active' => true,
                ]);
            }
        });

        return $lot;
    }

    public static function accorderDette(Dette $dette, int $montant, bool $annuler, string $motif, int $userId): string
    {
        $reste = (int) $dette->resteAPayer();
        if ($reste <= 0) {
            throw ValidationException::withMessages(['montant' => ['Cette dette est déjà épurée.']]);
        }
        $montant = $annuler ? $reste : $montant;
        if ($montant < 1 || $montant > $reste) {
            throw ValidationException::withMessages(['montant' => ['Le montant doit être compris entre 1 F et '.self::f($reste).'.']]);
        }
        $lot = (string) Str::uuid();
        DB::transaction(function () use ($dette, $montant, $annuler, $motif, $userId, $lot) {
            Reduction::create(['lot' => $lot, 'dette_id' => $dette->id, 'montant' => $montant, 'motif' => $motif, 'accorde_par_id' => $userId, 'is_active' => true]);
            if ($annuler) {
                $dette->refresh()->update(['is_annulee' => true]);
            }
        });

        return $lot;
    }

    /** Supprime une reduction (toutes ses lignes) ; une dette annulee est retablie. */
    public static function supprimer(string $lot): void
    {
        DB::transaction(function () use ($lot) {
            $lignes = Reduction::where('lot', $lot)->orWhere(fn ($q) => $q->whereNull('lot')->whereKey((int) str_replace('id-', '', $lot)))->get();
            abort_if($lignes->isEmpty(), 404, 'Réduction introuvable.');
            foreach ($lignes as $r) {
                $detteId = $r->dette_id;
                $r->delete();
                if ($detteId) {
                    Dette::whereKey($detteId)->where('is_annulee', true)->update(['is_annulee' => false]);
                }
            }
        });
    }

    // ------------------------------------------------------------ Liste

    public const CIBLES = ['inscription' => 'Réduction d\'inscription', 'dette' => 'Réduction de dette'];

    /**
     * Reductions de l'annee (une ligne par lot) : celles des frais de l'annee
     * et celles des dettes des eleves inscrits cette annee.
     *
     * @param  array{cible?: ?string, type_reduction_id?: ?int, du?: ?string, au?: ?string, affecte?: ?bool, recherche?: ?string, criteres: array}  $f
     */
    public static function requete(int $anneeId, array $f): Builder
    {
        $q = DB::table('reductions as r')
            ->leftJoin('frais_eleves as fe', 'fe.id', '=', 'r.frais_eleve_id')
            ->leftJoin('inscriptions as ir', 'ir.id', '=', 'fe.inscription_id')
            ->leftJoin('dettes as d', 'd.id', '=', 'r.dette_id')
            ->leftJoin('annees_scolaires as ad', 'ad.id', '=', 'd.annee_scolaire_id')
            ->join('inscriptions as i', fn ($j) => $j->on('i.eleve_id', '=', DB::raw('COALESCE(ir.eleve_id, d.eleve_id)'))->where('i.annee_scolaire_id', $anneeId))
            ->join('eleves as e', 'e.id', '=', 'i.eleve_id')
            ->leftJoin('classes as c', 'c.id', '=', 'i.classe_id')
            ->join('niveaux as n', 'n.id', '=', 'i.niveau_id')
            ->leftJoin('types_reductions as tr', 'tr.id', '=', 'r.type_reduction_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.accorde_par_id')
            ->where('r.etablissement_id', app(TenantContext::class)->id())
            ->where('r.is_active', true)
            ->where(fn ($w) => $w->whereNull('r.frais_eleve_id')->orWhere('ir.annee_scolaire_id', $anneeId))
            ->when(($f['cible'] ?? null) === 'inscription', fn (Builder $q) => $q->whereNotNull('r.frais_eleve_id'))
            ->when(($f['cible'] ?? null) === 'dette', fn (Builder $q) => $q->whereNotNull('r.dette_id'))
            ->when($f['type_reduction_id'] ?? null, fn (Builder $q, $id) => $q->where('r.type_reduction_id', $id))
            ->when($f['du'] ?? null, fn (Builder $q, $d) => $q->whereDate('r.created_at', '>=', $d))
            ->when($f['au'] ?? null, fn (Builder $q, $d) => $q->whereDate('r.created_at', '<=', $d))
            ->when(($f['affecte'] ?? null) !== null, fn (Builder $q) => $q->where('i.affecte', $f['affecte']))
            ->when(trim((string) ($f['recherche'] ?? '')) !== '', function (Builder $q) use ($f) {
                $t = trim($f['recherche']);
                $q->where(fn ($w) => $w->where('e.matricule', 'like', "{$t}%")->orWhere('e.nom', 'like', "%{$t}%")->orWhere('e.prenoms', 'like', "%{$t}%")
                    ->orWhereRaw("CONCAT(e.nom, ' ', e.prenoms) LIKE ?", ["%{$t}%"]));
            });

        return Points::criteresInscription($q, $f['criteres'] ?? [])
            ->groupBy(DB::raw("COALESCE(r.lot, CONCAT('id-', r.id))"))
            ->selectRaw("COALESCE(r.lot, CONCAT('id-', r.id)) AS lot, MAX(r.created_at) AS date, SUM(r.montant) AS montant,
                MAX(r.frais_eleve_id IS NOT NULL) AS sur_frais, MAX(r.dette_id) AS dette_id, MAX(ad.libelle) AS annee_dette, MAX(d.is_annulee) AS dette_annulee,
                MAX(tr.libelle) AS type, MAX(tr.code) AS code, MAX(r.type_reduction_id) AS type_reduction_id, MAX(r.motif) AS motif,
                MAX(TRIM(CONCAT(COALESCE(u.name, ''), ' ', COALESCE(u.prenoms, '')))) AS accorde_par,
                MAX(i.id) AS inscription_id, MAX(e.id) AS eleve_id, MAX(e.matricule) AS matricule, MAX(e.nom) AS nom, MAX(e.prenoms) AS prenoms, MAX(e.sexe) AS sexe,
                MAX(i.affecte) AS affecte, MAX(i.niveau_id) AS niveau_id, MAX(i.classe_id) AS classe_id, MAX(n.libelle) AS niveau, MAX(n.ordre) AS niveau_ordre, MAX(c.libelle) AS classe,
                COUNT(*) AS lignes");
    }

    public static function presenter(object $l): array
    {
        $dette = ! $l->sur_frais;

        return [
            'lot' => $l->lot,
            'date' => substr((string) $l->date, 0, 10),
            'montant' => (int) $l->montant,
            'cible' => $dette ? 'dette' : 'inscription',
            'type' => $dette ? 'Dette '.($l->annee_dette ?? '') : ($l->type ?? 'Réduction'),
            'code' => $dette ? 'DETTE' : $l->code,
            'dette_annulee' => (bool) $l->dette_annulee,
            'motif' => $l->motif,
            'accorde_par' => trim((string) $l->accorde_par) ?: null,
            'inscription_id' => (int) $l->inscription_id,
            'eleve_id' => (int) $l->eleve_id,
            'matricule' => $l->matricule,
            'nom' => $l->nom,
            'prenoms' => $l->prenoms,
            'sexe' => $l->sexe,
            'affecte' => (bool) $l->affecte,
            'niveau_id' => (int) $l->niveau_id,
            'classe_id' => $l->classe_id ? (int) $l->classe_id : null,
            'niveau' => $l->niveau,
            'niveau_ordre' => (int) $l->niveau_ordre,
            'classe' => $l->classe,
        ];
    }

    /**
     * Point general des reductions : par type, par genre, par niveau et par
     * classe (nombre d'eleves et montants, affectes / non affectes).
     */
    public static function sections(Collection $lignes, bool $general): array
    {
        $section = function (string $cle, string $titre, Collection $groupes) {
            $ligne = fn (string $c, string $libelle, Collection $l) => [
                'cle' => $c, 'libelle' => $libelle,
                'affecte' => (int) $l->where('affecte', true)->sum('montant'),
                'non_affecte' => (int) $l->where('affecte', false)->sum('montant'),
                'total' => (int) $l->sum('montant'),
            ];
            $rangs = $groupes->map(fn (Collection $l, $libelle) => $ligne(Str::slug($libelle), $libelle.' ('.$l->count().')', $l))->values()->all();
            $tout = $groupes->flatten(1);

            return ['cle' => $cle, 'titre' => $titre, 'sous_titre' => $tout->count().' réduction'.($tout->count() > 1 ? 's' : '').' · '.$tout->unique('eleve_id')->count().' élève'.($tout->unique('eleve_id')->count() > 1 ? 's' : ''),
                'lignes' => $rangs, 'total' => $ligne('total', 'Total', $tout)];
        };

        $sections = [$section('type', 'Réductions par type', $lignes->groupBy('type'))];
        if ($general) {
            $sections[] = $section('genre', 'Réductions par genre', $lignes->groupBy(fn ($l) => $l['sexe'] === 'F' ? 'Filles' : 'Garçons'));
            $sections[] = $section('cible', 'Réductions d\'inscription et de dette', $lignes->groupBy(fn ($l) => self::CIBLES[$l['cible']]));
            $sections[] = $section('niveau', 'Réductions par niveau', $lignes->sortBy('niveau_ordre')->groupBy('niveau'));
            $sections[] = $section('classe', 'Réductions par classe', $lignes->sortBy(fn ($l) => [$l['niveau_ordre'], $l['classe']])->groupBy(fn ($l) => $l['classe'] ?? $l['niveau'].' (sans classe)'));
            $sections[] = $section('agent', 'Réductions par donneur d\'ordre', $lignes->groupBy(fn ($l) => $l['motif'] ?: '—'));
        }

        return $sections;
    }

    private static function f(int $v): string
    {
        return number_format($v, 0, ',', ' ').' F';
    }
}
