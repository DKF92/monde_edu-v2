<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classe;
use App\Models\Periode;
use App\Support\ArretNotes;
use App\Support\PorteePedagogique;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Parametres > Arret des notes (droit notes.arreter, reserve au directeur) :
 * arreter / rouvrir les notes d'une classe (ou d'une matiere), toutes classes
 * d'un coup, et cloturer / rouvrir une periode. Voir App\Support\ArretNotes.
 */
class ArretNoteController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $periodes = Periode::where('annee_scolaire_id', $anneeId)->orderBy('numero')->get();
        abort_if($periodes->isEmpty(), 422, 'Aucune période pour l\'année en cours (Paramètres > Années scolaires).');
        $periode = $periodes->firstWhere('id', $request->integer('periode_id'))
            ?? $periodes->firstWhere('is_active', true) ?? $periodes->first();

        $classes = ArretNotes::etat($anneeId, $periode);

        return response()->json([
            'periodes' => $periodes->map(fn (Periode $p) => $this->presenterPeriode($p))->values(),
            'periode' => $this->presenterPeriode($periode),
            'classes' => $classes,
            'totaux' => [
                'classes' => count($classes),
                'classes_arretees' => collect($classes)->filter(fn ($c) => $c['matieres_notees'] > 0 && $c['matieres_arretees'] >= $c['matieres_notees'])->count(),
                'classes_notees' => collect($classes)->where('notes', '>', 0)->count(),
                'notes' => collect($classes)->sum('notes'),
            ],
        ]);
    }

    /** Arrete une classe (classe_id), une matiere (+ matiere_id) ou toutes les classes (sans classe_id). */
    public function arreter(Request $request, TenantContext $tenant)
    {
        [$periode, $classes] = $this->contexte($request, $tenant);
        $nombre = 0;
        foreach ($classes as $classe) {
            $matieres = ArretNotes::matieres($classe, $request->integer('matiere_id') ?: null);
            $nombre += ArretNotes::arreter($classe, $periode, $matieres, $request->user()->id);
        }

        return response()->json(['message' => $nombre ? $nombre.' moyenne'.($nombre > 1 ? 's' : '').' arrêtée'.($nombre > 1 ? 's' : '').'.' : 'Aucune nouvelle moyenne à arrêter.', 'nombre' => $nombre]);
    }

    public function rouvrir(Request $request, TenantContext $tenant)
    {
        [$periode, $classes] = $this->contexte($request, $tenant);
        $nombre = 0;
        foreach ($classes as $classe) {
            $nombre += ArretNotes::rouvrir($classe, $periode, ArretNotes::matieres($classe, $request->integer('matiere_id') ?: null));
        }

        return response()->json(['message' => $nombre ? $nombre.' moyenne'.($nombre > 1 ? 's' : '').' rouverte'.($nombre > 1 ? 's' : '').'.' : 'Aucune moyenne arrêtée.', 'nombre' => $nombre]);
    }

    /** Cloture (arrete tout puis bloque la saisie) ou rouvre une periode. */
    public function cloturer(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request);
        $data = $request->validate(['cloturee' => ['required', 'boolean']]);
        $periode = Periode::where('annee_scolaire_id', PorteePedagogique::anneeId($tenant))->findOrFail($id);

        if ($data['cloturee']) {
            abort_if($periode->is_cloturee, 422, 'La période '.$periode->libelle.' est déjà clôturée.');
            $nombre = ArretNotes::cloturer($periode, $request->user()->id);
            $message = $periode->libelle.' clôturé'.($nombre ? ' ('.$nombre.' moyenne'.($nombre > 1 ? 's' : '').' arrêtée'.($nombre > 1 ? 's' : '').')' : '').'.';
        } else {
            $periode->update(['is_cloturee' => false]);
            $message = $periode->libelle.' rouvert : les notes arrêtées restent arrêtées.';
        }

        return response()->json(['message' => $message, 'periode' => $this->presenterPeriode($periode->fresh())]);
    }

    /** @return array{0: Periode, 1: \Illuminate\Support\Collection<int, Classe>} */
    private function contexte(Request $request, TenantContext $tenant): array
    {
        $this->autoriser($request);
        $request->validate(['periode_id' => ['required', 'integer'], 'classe_id' => ['nullable', 'integer'], 'matiere_id' => ['nullable', 'integer']]);
        $anneeId = PorteePedagogique::anneeId($tenant);
        $periode = Periode::where('annee_scolaire_id', $anneeId)->findOrFail($request->integer('periode_id'));
        abort_if($periode->is_cloturee, 422, 'La période '.$periode->libelle.' est clôturée : rouvrez-la d\'abord.');
        $classes = Classe::where('annee_scolaire_id', $anneeId)
            ->when($request->filled('classe_id'), fn ($q) => $q->whereKey($request->integer('classe_id')))->get();
        abort_if($classes->isEmpty(), 404, 'Classe introuvable.');

        return [$periode, $classes];
    }

    private function presenterPeriode(Periode $p): array
    {
        return [
            'id' => $p->id, 'libelle' => $p->libelle, 'numero' => $p->numero,
            'date_debut' => $p->date_debut?->toDateString(), 'date_fin' => $p->date_fin?->toDateString(),
            'active' => (bool) $p->is_active, 'cloturee' => (bool) $p->is_cloturee,
        ];
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('notes.arreter'), 403);
    }
}
