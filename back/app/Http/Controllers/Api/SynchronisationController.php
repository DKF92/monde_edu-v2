<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Synchro\Synchroniseur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Synchronisation, cote utilisateurs :
 * - chacun voit ses saisies faites en ligne et refusees a l'ecole (notes
 *   arretees, periode cloturee) et les marque comme vues ;
 * - l'administrateur (etablissement.gerer) voit l'etat de la synchronisation,
 *   tous les refus, et peut la lancer tout de suite (serveur de l'ecole).
 */
class SynchronisationController extends Controller
{
    /** Saisies refusees de l'utilisateur connecte, non vues ; tous=1 : toutes (administrateur). */
    public function rejets(Request $request)
    {
        $tous = $request->boolean('tous') && $request->user()->can('etablissement.gerer');
        $rejets = DB::table('sync_rejets as r')->leftJoin('users as u', 'u.id', '=', 'r.auteur_id')
            ->when(! $tous, fn ($q) => $q->where('r.auteur_id', $request->user()->id)->whereNull('r.vu_le'))
            ->orderByDesc('r.id')->limit($tous ? 50 : 20)
            ->get(['r.*', 'u.name as auteur_nom', 'u.prenoms as auteur_prenoms']);

        return response()->json(['data' => $rejets->map(fn ($r) => [
            'id' => $r->id,
            'libelle' => $this->libelle($r->table_nom, json_decode($r->donnees, true) ?: []),
            'motif' => $r->motif,
            'auteur' => trim(($r->auteur_prenoms ?? '').' '.($r->auteur_nom ?? '')) ?: null,
            'date' => $r->cree_le,
            'vu' => (bool) $r->vu_le,
        ])]);
    }

    public function marquerVu(Request $request, int $id)
    {
        $rejet = DB::table('sync_rejets')->where('id', $id)->first();
        abort_unless($rejet && ($rejet->auteur_id === $request->user()->id || $request->user()->can('etablissement.gerer')), 404);
        DB::table('sync_rejets')->where('id', $id)->update(['vu_le' => now()]);

        return response()->json(['message' => 'Refus marqué comme vu.']);
    }

    public function etat(Request $request)
    {
        abort_unless($request->user()->can('etablissement.gerer'), 403);

        return response()->json(['data' => [
            'role' => config('serveur.role'),
            'serveur_en_ligne' => config('serveur.role') === 'local' ? config('serveur.synchro.url') : null,
            'configuree' => (bool) config('serveur.synchro.cle') && (config('serveur.role') === 'en_ligne' || (bool) config('serveur.synchro.url')),
            'en_attente' => DB::table('sync_journal')->whereNull('envoye_le')->count(),
            'derniere_synchro' => Synchroniseur::lire('derniere_synchro'),
            'derniere_reception' => Synchroniseur::lire('derniere_reception'),
            'derniere_erreur' => Synchroniseur::lire('derniere_erreur'),
            'rejets_non_vus' => DB::table('sync_rejets')->whereNull('vu_le')->count(),
        ]]);
    }

    /** Lance une synchronisation tout de suite (serveur de l'ecole). */
    public function lancer(Request $request)
    {
        abort_unless($request->user()->can('etablissement.gerer'), 403);
        try {
            $b = (new Synchroniseur)->executer();
        } catch (Throwable $e) {
            return response()->json(['message' => 'Synchronisation impossible : '.$e->getMessage()], 422);
        }
        $r = $b['recus'];

        return response()->json([
            'message' => "Synchronisation faite : {$r['appliques']} changement(s) reçu(s)".($r['rejetes'] ? ", {$r['rejetes']} refusé(s)" : '')
                .", {$b['envoyes']} envoyé(s), {$b['fichiers']} fichier(s).",
            'bilan' => $b,
        ]);
    }

    /** Description lisible d'une saisie refusee. */
    private function libelle(string $table, array $d): string
    {
        $eleve = isset($d['eleve_id']) ? DB::table('eleves')->where('id', $d['eleve_id'])->first(['nom', 'prenoms']) : null;
        $nomEleve = $eleve ? trim($eleve->nom.' '.$eleve->prenoms) : null;
        $matiere = isset($d['matiere_id']) ? DB::table('matieres')->where('id', $d['matiere_id'])->value('libelle') : null;
        $periode = isset($d['periode_id']) ? DB::table('periodes')->where('id', $d['periode_id'])->value('libelle') : null;
        $classe = isset($d['classe_id']) ? DB::table('classes')->where('id', $d['classe_id'])->value('libelle') : null;
        $ou = implode(' · ', array_filter([$classe, $periode]));

        return match ($table) {
            'notes' => "Note de {$matiere} (évaluation n°".($d['numero'] ?? '?').') : '.($nomEleve ?? 'élève').' = '.($d['valeur'] ?? '—').($ou ? " · $ou" : ''),
            'moyennes' => ($matiere === 'Conduite' ? 'Note de conduite' : "Moyenne de {$matiere}").' : '.($nomEleve ?? 'élève').' = '.($d['moyenne'] ?? '—').($ou ? " · $ou" : ''),
            'absences' => 'Absence de '.($d['nombre_heures'] ?? '?').' h : '.($nomEleve ?? 'élève').' le '.($d['date_debut'] ?? '?'),
            'moyennes_generales' => 'Résultat de '.($nomEleve ?? 'élève').($ou ? " · $ou" : ''),
            default => 'Saisie ('.$table.')',
        };
    }
}
