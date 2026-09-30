<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Etablissement;
use App\Support\BilanCaisse;
use App\Support\Document;
use App\Support\PorteePedagogique;
use App\Support\Points;
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

        return response()->json(['data' => $d + Points::inscriptions($d['annee_id'], $d['periode'], $d['criteres'], $this->niveaux($request, $d['annee_id']), $request->boolean('general'))]);
    }

    public function inscriptionsDocument(Request $request, TenantContext $tenant)
    {
        $d = $this->contexte($request, $tenant, 'inscriptions');
        $general = $request->boolean('general');
        $d += Points::inscriptions($d['annee_id'], $d['periode'], $d['criteres'], $this->niveaux($request, $d['annee_id']), $general);

        return $this->document($request, $tenant, $d, $general ? 'Point général des inscrits' : 'Point des inscrits', 'point-inscrits');
    }

    /** Eleves d'une case (oeil) : etat paye / cas / non_paye, colonne affecte. */
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
                + Document::entete(Etablissement::findOrFail($tenant->id())), 'liste-inscrits', $request->query('format'), 'landscape');
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

    // ------------------------------------------------------------ Outils

    private function contexte(Request $request, TenantContext $tenant, string $point): array
    {
        $u = $request->user();
        abort_unless($point === 'inscriptions'
            ? ($u->can('inscriptions.gerer') || $u->can('rapports.voir'))
            : ($u->can('reglements.voir') || $u->can('dettes.gerer')), 403);

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
        ]);
        $annee = AnneeScolaire::find(PorteePedagogique::anneeId($tenant));
        abort_unless($annee, 422, 'Aucune année scolaire active.');
        // Par defaut : toute l'annee (un point d'inscriptions a la journee est souvent vide).
        $filtres['periode'] ??= 'annee';
        $periode = BilanCaisse::periode($filtres, $annee);
        $criteres = BilanCaisse::criteres($filtres);
        $libelle = BilanCaisse::libelleCriteres($criteres);
        $caissierId = $point === 'dettes' && ! empty($filtres['caissier_id']) ? (int) $filtres['caissier_id'] : null;
        $caissier = $caissierId ? DB::table('users')->where('id', $caissierId)->first(['name', 'prenoms']) : null;

        return [
            'titre' => ($point === 'inscriptions' ? 'Point des inscrits de ' : 'Point des dettes de ').$periode['libelle'].($libelle ? ' · '.$libelle : ''),
            'annee' => $annee->libelle,
            'annee_id' => $annee->id,
            'periode' => $periode,
            'criteres' => $criteres,
            'criteres_libelle' => $libelle,
            'caissier_id' => $caissierId,
            'caissier' => $caissier ? trim($caissier->name.' '.$caissier->prenoms) : null,
            'mois_disponibles' => BilanCaisse::moisDeLAnnee($annee),
            'criteres_disponibles' => BilanCaisse::criteresDisponibles($annee->id, $point === 'inscriptions' ? $this->niveaux($request, $annee->id) : null),
            'genere_le' => now()->toDateTimeString(),
        ];
    }

    private function document(Request $request, TenantContext $tenant, array $d, string $titre, string $fichier)
    {
        return Document::repondre('pdf.point', ['p' => $d, 'titreDocument' => $titre, 'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms)]
            + Document::entete(Etablissement::findOrFail($tenant->id())), $fichier, $request->query('format'));
    }

    /** Educateur (poste rattache a des niveaux) : ses niveaux seulement, aucun s'il n'en a pas. */
    private function niveaux(Request $request, int $anneeId): ?array
    {
        return PorteePedagogique::niveaux($request, $anneeId);
    }
}
