<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\Classe;
use App\Models\Etablissement;
use App\Models\Inscription;
use App\Models\MoyenneGenerale;
use App\Models\Periode;
use App\Support\Document;
use App\Support\PorteePedagogique;
use App\Support\Resultats;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Resultats d'une classe (V1 moy_classe, rang_trim, matrice_moy, bulletin) :
 * - tableau des moyennes par matiere, moyenne generale, rangs, bilans ;
 * - periode (trimestre / semestre) ou annuelle (?periode=annuel) ;
 * - enregistrement des resultats de la periode et decisions de fin d'annee
 *   (moyennes.gerer) ; bulletins (bulletins.voir), matrice imprimable.
 */
class ResultatController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request, ['notes.voir', 'moyennes.gerer', 'bulletins.voir', 'notes.saisir']);
        [$classe, $periode, $periodes] = $this->contexte($request, $tenant);
        $resultats = $this->resultats($classe, $periode, $periodes);
        $enfants = PorteePedagogique::eleves($request);
        $enregistres = $periode ? MoyenneGenerale::where('classe_id', $classe->id)->where('periode_id', $periode->id)->max('updated_at') : null;

        return response()->json([
            'classe' => ['id' => $classe->id, 'libelle' => $classe->libelle, 'niveau' => $classe->niveau?->libelle],
            'periode' => $periode ? ['id' => $periode->id, 'libelle' => $periode->libelle, 'cloturee' => (bool) $periode->is_cloturee] : ['id' => null, 'libelle' => 'Annuelle', 'cloturee' => false],
            'annuel' => $periode === null,
            'enregistre_le' => $enregistres,
            'peut_gerer' => $request->user()->can('moyennes.gerer'),
            'peut_bulletins' => $request->user()->can('bulletins.voir'),
            'decisions' => InscriptionController::DECISIONS,
        ] + array_merge($resultats, [
            'eleves' => collect($resultats['eleves'])->when($enfants !== null, fn (Collection $l) => $l->whereIn('eleve_id', $enfants))->values(),
        ]));
    }

    /** Enregistre les resultats de la periode (moyennes generales et rangs classe / niveau). */
    public function enregistrer(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request, ['moyennes.gerer']);
        [$classe, $periode] = $this->contexte($request, $tenant);
        abort_unless($periode, 422, 'Choisissez une période.');

        // Rang dans le niveau : toutes les classes du niveau.
        $niveau = Classe::where('annee_scolaire_id', $classe->annee_scolaire_id)->where('niveau_id', $classe->niveau_id)->get()
            ->flatMap(fn (Classe $c) => collect(Resultats::periode($c, $periode)['eleves'])->map(fn ($l) => $l + ['classe_id' => $c->id]));
        $niveau = Resultats::classer($niveau, 'moyenne', 'rang_niveau');

        $nombre = 0;
        DB::transaction(function () use ($niveau, $classe, $periode, &$nombre) {
            foreach ($niveau->where('classe_id', $classe->id) as $l) {
                if ($l['moyenne'] === null) {
                    MoyenneGenerale::where('eleve_id', $l['eleve_id'])->where('periode_id', $periode->id)->delete();

                    continue;
                }
                MoyenneGenerale::updateOrCreate(['eleve_id' => $l['eleve_id'], 'periode_id' => $periode->id], [
                    'classe_id' => $classe->id,
                    'moyenne_generale' => $l['moyenne'],
                    'total_points' => $l['total_points'],
                    'total_coefficients' => $l['total_coefficients'],
                    'moyenne_lettres' => $l['lettres'],
                    'moyenne_sciences' => $l['sciences'],
                    'rang_classe' => $l['rang'],
                    'rang_niveau' => $l['rang_niveau'],
                    'mention' => Resultats::distinction($l['moyenne']),
                ]);
                $nombre++;
            }
        });

        return response()->json(['message' => 'Résultats de '.$classe->libelle.' enregistrés ('.$nombre.' élève'.($nombre > 1 ? 's' : '').').', 'nombre' => $nombre]);
    }

    /** Decisions de fin d'annee : {classe_id, decisions: [{inscription_id, decision|null}]}. */
    public function decisions(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request, ['moyennes.gerer']);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $classe = Classe::where('annee_scolaire_id', $anneeId)->findOrFail($request->integer('classe_id'));
        $data = $request->validate([
            'decisions' => ['present', 'array'],
            'decisions.*.inscription_id' => ['required', 'integer'],
            'decisions.*.decision' => ['nullable', Rule::in(InscriptionController::DECISIONS)],
        ]);
        $annuel = collect(Resultats::annuel($classe, Periode::where('annee_scolaire_id', $anneeId)->orderBy('numero')->get())['eleves'])->keyBy('inscription_id');

        $nombre = 0;
        DB::transaction(function () use ($data, $classe, $annuel, &$nombre) {
            foreach ($data['decisions'] as $d) {
                $ligne = $annuel->get($d['inscription_id']);
                abort_unless($ligne, 422, 'Un des élèves n\'est pas dans la classe '.$classe->libelle.'.');
                Inscription::whereKey($d['inscription_id'])->update([
                    'decision_finale' => $d['decision'] ?? null,
                    'moyenne_annuelle' => $ligne['moyenne'],
                ]);
                $nombre++;
            }
        });

        return response()->json(['message' => 'Décisions enregistrées pour '.$nombre.' élève'.($nombre > 1 ? 's' : '').'.']);
    }

    /** Matrice des moyennes imprimable (V1 matrice_moy), paysage. */
    public function document(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request, ['notes.voir', 'moyennes.gerer', 'bulletins.voir']);
        abort_if(PorteePedagogique::eleves($request) !== null, 403);
        [$classe, $periode, $periodes] = $this->contexte($request, $tenant);
        $resultats = $this->resultats($classe, $periode, $periodes);

        return Document::repondre('pdf.matrice-moyennes', Document::entete(Etablissement::findOrFail($tenant->id())) + $resultats + [
            'annee' => $classe->anneeScolaire?->libelle,
            'classe' => $classe,
            'periode_libelle' => $periode?->libelle ?? 'Annuelle',
            'annuel' => $periode === null,
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], 'resultats-'.str_replace(' ', '-', strtolower($classe->libelle)), $request->query('format'), 'landscape');
    }

    /** Bulletins de la classe (un par page), ou d'un eleve (eleve_id). */
    public function bulletins(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request, ['bulletins.voir']);
        [$classe, $periode, $periodes] = $this->contexte($request, $tenant);
        $resultats = $this->resultats($classe, $periode, $periodes);
        $enfants = PorteePedagogique::eleves($request);

        $eleves = collect($resultats['eleves'])
            ->when($request->integer('eleve_id'), fn (Collection $l, $id) => $l->where('eleve_id', $id))
            ->when($enfants !== null, fn (Collection $l) => $l->whereIn('eleve_id', $enfants))
            ->values();
        abort_if($eleves->isEmpty(), 404, 'Aucun bulletin à imprimer.');

        $absences = Absence::whereIn('eleve_id', $eleves->pluck('eleve_id'))
            ->where('annee_scolaire_id', $classe->annee_scolaire_id)
            ->when($periode, fn ($q) => $q->where('periode_id', $periode->id))
            ->selectRaw('eleve_id, SUM(CASE WHEN is_justifiee THEN nombre_heures ELSE 0 END) AS justifiees, SUM(CASE WHEN is_justifiee THEN 0 ELSE nombre_heures END) AS non_justifiees')
            ->groupBy('eleve_id')->get()->keyBy('eleve_id');
        $professeurs = DB::table('affectations_enseignants as a')->join('personnels as p', 'p.id', '=', 'a.personnel_id')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('a.classe_id', $classe->id)
            ->selectRaw("a.matiere_id, TRIM(CONCAT(u.name, ' ', COALESCE(u.prenoms, ''))) AS nom")
            ->pluck('nom', 'matiere_id');

        // array_merge : les eleves filtres remplacent ceux des resultats.
        return Document::repondre('pdf.bulletin', array_merge(Document::entete(Etablissement::findOrFail($tenant->id())), $resultats, [
            'eleves' => $eleves,
            'absences' => $absences,
            'professeurs' => $professeurs,
            'annee' => $classe->anneeScolaire?->libelle,
            'classe' => $classe->load(['professeurPrincipal.user', 'niveau']),
            'periode_libelle' => $periode?->libelle ?? 'Annuel',
            'annuel' => $periode === null,
            'genere_le' => now(),
        ]), 'bulletins-'.str_replace(' ', '-', strtolower($classe->libelle)), $request->query('format'));
    }

    // ------------------------------------------------------------ Outils

    private function resultats(Classe $classe, ?Periode $periode, Collection $periodes): array
    {
        return $periode ? Resultats::periode($classe, $periode) + ['periodes' => []] : Resultats::annuel($classe, $periodes);
    }

    /** @return array{0: Classe, 1: ?Periode, 2: Collection} */
    private function contexte(Request $request, TenantContext $tenant): array
    {
        $anneeId = PorteePedagogique::anneeId($tenant);
        $request->validate(['classe_id' => ['required', 'integer'], 'periode' => ['required']]);
        $classe = Classe::where('annee_scolaire_id', $anneeId)->with(['niveau', 'anneeScolaire'])->findOrFail($request->integer('classe_id'));
        PorteePedagogique::verifierClasse($request, $anneeId, $classe->id);
        $periodes = Periode::where('annee_scolaire_id', $anneeId)->orderBy('numero')->get();
        $periode = $request->input('periode') === 'annuel' ? null : $periodes->firstWhere('id', (int) $request->input('periode'));
        abort_if($request->input('periode') !== 'annuel' && ! $periode, 404, 'Période introuvable.');

        return [$classe, $periode, $periodes];
    }

    private function autoriser(Request $request, array $droits): void
    {
        abort_unless(collect($droits)->contains(fn ($d) => $request->user()->can($d)), 403);
    }
}
