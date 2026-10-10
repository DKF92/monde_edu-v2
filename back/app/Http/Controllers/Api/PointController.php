<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Etablissement;
use App\Support\BilanCaisse;
use App\Support\Document;
use App\Support\PorteePedagogique;
use App\Support\Points;
use App\Support\PointsFinances;
use App\Support\Reductions;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Point des inscriptions (droits inscriptions.gerer ou rapports.voir ; un
 * educateur ne voit que ses niveaux) et point des dettes (reglements.voir ou
 * dettes.gerer), avec les filtres du point de caisse : periode (jour, dates,
 * mois, annee) et criteres eleve. Voir App\Support\Points.
 */
class PointController extends Controller
{
    // ------------------------------------------------------------ Inscriptions

    public function inscriptions(Request $request, TenantContext $tenant)
    {
        $d = $this->contexte($request, $tenant, 'inscriptions');

        return response()->json(['data' => $d + Points::inscriptions($d['annee_id'], $d['periode'], $d['criteres'], $this->niveaux($request, $d['annee_id']), $request->boolean('general'), $d['agent'] === 'tous')]);
    }

    public function inscriptionsDocument(Request $request, TenantContext $tenant)
    {
        $d = $this->contexte($request, $tenant, 'inscriptions');
        $general = $request->boolean('general');
        $d += Points::inscriptions($d['annee_id'], $d['periode'], $d['criteres'], $this->niveaux($request, $d['annee_id']), $general, $d['agent'] === 'tous');

        return $this->document($request, $tenant, $d, $general ? 'Point général des inscrits' : 'Point des inscrits', 'point-inscrits');
    }

    /** Eleves d'une case (oeil) : etat paye / cas / non_paye / sans_classe, colonne affecte. */
    public function inscriptionsEleves(Request $request, TenantContext $tenant)
    {
        $d = $this->contexte($request, $tenant, 'inscriptions');
        $request->validate(['etat' => ['nullable', Rule::in(array_keys(Points::ETATS_INSCRIPTION))], 'affecte' => ['nullable', 'boolean']]);
        $etat = $request->query('etat') ?: null;
        $affecte = $request->filled('affecte') ? $request->boolean('affecte') : null;
        $eleves = Points::elevesInscriptions($d['annee_id'], $d['periode'], $d['criteres'], $this->niveaux($request, $d['annee_id']), $etat, $affecte);
        $titre = implode(' · ', array_filter([
            $etat ? Points::ETATS_INSCRIPTION[$etat] : 'Inscrits',
            $affecte === null ? null : ($affecte ? 'affectés' : 'non affectés'),
            $d['periode']['libelle'],
            $d['criteres_libelle'] ?: null,
        ]));

        if ($request->filled('format')) {
            return Document::repondre('pdf.point-eleves', ['titre' => $titre, 'annee' => $d['annee'], 'eleves' => $eleves, 'genere_le' => now(), 'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms)]
                + Document::entete(Etablissement::findOrFail($tenant->id())), 'liste-inscrits', $request->query('format'));
        }

        return response()->json(['titre' => $titre, 'data' => $eleves->values()]);
    }

    // ------------------------------------------------------------ Dettes

    public function dettes(Request $request, TenantContext $tenant)
    {
        $d = $this->contexte($request, $tenant, 'dettes');
        $d['caissiers'] = DB::table('reglement_lignes as rl')->join('reglements as g', 'g.id', '=', 'rl.reglement_id')->join('users as u', 'u.id', '=', 'g.caissier_id')
            ->where('g.etablissement_id', $tenant->id())->where('g.annee_scolaire_id', $d['annee_id'])->whereNotNull('rl.dette_id')
            ->distinct()->orderBy('u.name')->get(['u.id', 'u.name', 'u.prenoms'])
            ->map(fn ($u) => ['id' => $u->id, 'nom' => trim($u->name.' '.$u->prenoms)])->values();

        return response()->json(['data' => $d + Points::dettes($d['annee_id'], $d['periode'], $d['criteres'], $d['caissier_id'], $request->boolean('general'))]);
    }

    public function dettesDocument(Request $request, TenantContext $tenant)
    {
        $d = $this->contexte($request, $tenant, 'dettes');
        $general = $request->boolean('general');
        $d += Points::dettes($d['annee_id'], $d['periode'], $d['criteres'], $d['caissier_id'], $general);

        return $this->document($request, $tenant, $d, $general ? 'Point général des dettes' : 'Point des dettes', 'point-dettes');
    }

    // ------------------------------------------------------------ Reductions

    /** Point des reductions (date d'octroi) : par type ; general = genre, niveau, classe, donneur d'ordre. */
    public function reductions(Request $request, TenantContext $tenant)
    {
        $d = $this->contexte($request, $tenant, 'reductions');

        return response()->json(['data' => $d + $this->pointReductions($d, $request->boolean('general'))]);
    }

    public function reductionsDocument(Request $request, TenantContext $tenant)
    {
        $d = $this->contexte($request, $tenant, 'reductions');
        $general = $request->boolean('general');

        return $this->document($request, $tenant, $d + $this->pointReductions($d, $general), $general ? 'Point général des réductions' : 'Point des réductions', 'point-reductions');
    }

    private function pointReductions(array $d, bool $general): array
    {
        $lignes = Reductions::requete($d['annee_id'], ['du' => $d['periode']['du'], 'au' => $d['periode']['au'], 'criteres' => $d['criteres']])->get()
            ->map(fn ($l) => Reductions::presenter($l));

        return [
            'unite' => 'montant',
            'colonnes' => ['affecte' => 'Élèves affectés', 'non_affecte' => 'Élèves non affectés', 'total' => 'Total'],
            'sections' => array_map(fn ($s) => $s + ['partie' => $s['titre']], Reductions::sections($lignes, $general)),
        ];
    }

    // ------------------------------------------------------------ Depenses et entrees

    public function depenses(Request $request, TenantContext $tenant)
    {
        $d = $this->contexte($request, $tenant, 'depenses');

        return response()->json(['data' => $d + PointsFinances::depenses($d['annee_id'], $d['periode'], $request->boolean('general'))]);
    }

    public function depensesDocument(Request $request, TenantContext $tenant)
    {
        $d = $this->contexte($request, $tenant, 'depenses');
        $general = $request->boolean('general');

        return $this->document($request, $tenant, $d + PointsFinances::depenses($d['annee_id'], $d['periode'], $general), $general ? 'Point général des dépenses' : 'Point des dépenses', 'point-depenses');
    }

    public function entrees(Request $request, TenantContext $tenant)
    {
        $d = $this->contexte($request, $tenant, 'entrees');

        return response()->json(['data' => $d + PointsFinances::entrees($d['annee_id'], $d['periode'], $request->boolean('general'))]);
    }

    public function entreesDocument(Request $request, TenantContext $tenant)
    {
        $d = $this->contexte($request, $tenant, 'entrees');
        $general = $request->boolean('general');

        return $this->document($request, $tenant, $d + PointsFinances::entrees($d['annee_id'], $d['periode'], $general), $general ? 'Point général des entrées' : 'Point des entrées', 'point-entrees');
    }

    // ------------------------------------------------------------ Outils

    private const TITRES = [
        'inscriptions' => 'Point des inscrits de ',
        'dettes' => 'Point des dettes de ',
        'reductions' => 'Point des réductions de ',
        'depenses' => 'Point des dépenses de ',
        'entrees' => 'Point des entrées de ',
    ];

    private function contexte(Request $request, TenantContext $tenant, string $point): array
    {
        $u = $request->user();
        abort_unless(match ($point) {
            'inscriptions' => $u->can('inscriptions.gerer') || $u->can('rapports.voir'),
            'dettes' => $u->can('reglements.voir') || $u->can('dettes.gerer'),
            'reductions' => $u->can('reductions.voir') || $u->can('reductions.gerer'),
            'depenses' => $u->can('depenses.voir'),
            'entrees' => $u->can('entrees.voir'),
        }, 403);
        // Depenses et entrees : pas de criteres eleve.
        $criteresEleve = ! in_array($point, ['depenses', 'entrees'], true);

        $filtres = $request->validate([
            'periode' => ['nullable', Rule::in(['jour', 'dates', 'mois', 'annee'])],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'mois' => ['nullable', 'string'],
            'sexe' => ['nullable', Rule::in(['M', 'F'])],
            'redoublant' => ['nullable', 'boolean'],
            'cycle' => ['nullable', Rule::in(array_keys(BilanCaisse::CYCLES))],
            'niveau_id' => ['nullable', 'integer'],
            'classe_id' => ['nullable', 'integer'],
            'caissier_id' => ['nullable', 'integer'],
            // Point des inscrits : educateur qui a enregistre ('tous' = un tableau par educateur).
            'agent' => ['nullable', 'regex:/^(tous|\d+)$/'],
        ]);
        $annee = AnneeScolaire::find(PorteePedagogique::anneeId($tenant));
        abort_unless($annee, 422, 'Aucune année scolaire active.');
        // Par defaut : toute l'annee (un point d'inscriptions a la journee est souvent vide).
        $filtres['periode'] ??= 'annee';
        $periode = BilanCaisse::periode($filtres, $annee);
        $criteres = $criteresEleve ? BilanCaisse::criteres($filtres) : [];
        $libelle = $criteresEleve ? BilanCaisse::libelleCriteres($criteres) : '';
        $caissierId = $point === 'dettes' && ! empty($filtres['caissier_id']) ? (int) $filtres['caissier_id'] : null;
        $caissier = $caissierId ? DB::table('users')->where('id', $caissierId)->first(['name', 'prenoms']) : null;

        // Filtre educateur : pas pour un educateur connecte (il voit ses niveaux).
        $filtreAgents = $point === 'inscriptions' && ! PorteePedagogique::poste($request)?->lie_niveaux;
        $agent = $filtreAgents ? ($filtres['agent'] ?? null) : null;
        $agentNom = null;
        if ($agent !== null && $agent !== 'tous') {
            $agent = (int) $agent;
            $criteres['enregistre_par_id'] = $agent;
            $u = DB::table('users')->where('id', $agent)->first(['name', 'prenoms']);
            $agentNom = $u ? trim($u->prenoms.' '.$u->name) : null;
        }

        return [
            'titre' => self::TITRES[$point].$periode['libelle'].($libelle ? ' · '.$libelle : '')
                .($agentNom ? ' · inscrits par '.$agentNom : ''),
            'agent' => $agent,
            'agent_nom' => $agentNom,
            'agents' => $filtreAgents ? $this->agents($annee->id) : [],
            'annee' => $annee->libelle,
            'annee_id' => $annee->id,
            'periode' => $periode,
            'criteres' => $criteres,
            'criteres_libelle' => $libelle,
            'caissier_id' => $caissierId,
            'caissier' => $caissier ? trim($caissier->name.' '.$caissier->prenoms) : null,
            'mois_disponibles' => BilanCaisse::moisDeLAnnee($annee),
            'criteres_disponibles' => $criteresEleve
                ? BilanCaisse::criteresDisponibles($annee->id, $point === 'inscriptions' ? $this->niveaux($request, $annee->id) : null)
                : ['cycles' => [], 'niveaux' => [], 'classes' => []],
            'genere_le' => now()->toDateTimeString(),
        ];
    }

    private function document(Request $request, TenantContext $tenant, array $d, string $titre, string $fichier)
    {
        return Document::repondre('pdf.point', ['p' => $d, 'titreDocument' => $titre, 'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms)]
            + Document::entete(Etablissement::findOrFail($tenant->id())), $fichier, $request->query('format'));
    }

    /**
     * Liste « Educateur » du point des inscrits : les comptes a poste
     * d'educateur (rattache a des niveaux) et tous ceux qui ont enregistre
     * une inscription de l'annee.
     */
    private function agents(int $anneeId): array
    {
        $etablissementId = app(TenantContext::class)->id();
        $educateurs = DB::table('model_has_roles as mr')->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('r.etablissement_id', $etablissementId)->where('r.lie_niveaux', true)->pluck('mr.model_id');
        $inscripteurs = DB::table('inscriptions')->where('etablissement_id', $etablissementId)->where('annee_scolaire_id', $anneeId)
            ->whereNotNull('enregistre_par_id')->distinct()->pluck('enregistre_par_id');

        return DB::table('users')->whereIn('id', $educateurs->merge($inscripteurs)->unique())->whereNull('deleted_at')
            ->orderBy('name')->orderBy('prenoms')->get(['id', 'name', 'prenoms'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'nom' => trim($u->prenoms.' '.$u->name)])->values()->all();
    }

    /** Educateur (poste rattache a des niveaux) : ses niveaux seulement, aucun s'il n'en a pas. */
    private function niveaux(Request $request, int $anneeId): ?array
    {
        return PorteePedagogique::niveaux($request, $anneeId);
    }
}
