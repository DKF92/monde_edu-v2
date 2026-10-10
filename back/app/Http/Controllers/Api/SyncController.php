<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Synchro\Applicateur;
use App\Support\Synchro\Lot;
use App\Support\Synchro\Synchroniseur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Synchronisation, cote SERVEUR EN LIGNE, appelee par le serveur de l'ecole
 * (qui seul initie les echanges) avec la cle partagee (en-tete X-Sync-Cle) :
 * - GET  /api/sync/journal  : changements faits en ligne depuis un curseur ;
 * - POST /api/sync/recevoir : changements envoyes par l'ecole ;
 * - POST /api/sync/fichiers/verifier puis /api/sync/fichier : fichiers
 *   (photos, logo, justificatifs) absents ou differents ici.
 */
class SyncController extends Controller
{
    public function journal(Request $request)
    {
        $this->autoriser($request);
        $depuis = (int) $request->query('depuis', 0);
        $limite = min(1000, max(1, (int) $request->query('limite', config('serveur.synchro.lot', 500))));
        $entrees = DB::table('sync_journal')->where('id', '>', $depuis)->orderBy('id')->limit($limite + 1)->get();
        $reste = $entrees->count() > $limite;
        $entrees = $entrees->take($limite);

        return response()->json([
            'changements' => Lot::depuisJournal($entrees),
            'dernier' => $entrees->last()->id ?? $depuis,
            'reste' => $reste,
        ]);
    }

    public function recevoir(Request $request)
    {
        $this->autoriser($request);
        $request->validate(['changements' => ['present', 'array'], 'changements.*.table' => ['required', 'string'],
            'changements.*.operation' => ['required', 'in:ecriture,suppression'], 'changements.*.cle' => ['required', 'array']]);

        // Corps brut : lignes completes, sans les retouches de Laravel (chaines
        // vides changees en null, espaces retires) qui altereraient la copie.
        $changements = json_decode($request->getContent(), true)['changements'] ?? [];
        $bilan = (new Applicateur(aLEcole: false))->appliquer($changements);
        Synchroniseur::noter('derniere_reception', now()->toDateTimeString());

        return response()->json($bilan);
    }

    /** Parmi les fichiers annonces (chemin + sha1), ceux qui manquent ici ou ont change. */
    public function verifierFichiers(Request $request)
    {
        $this->autoriser($request);
        $data = $request->validate(['fichiers' => ['present', 'array'], 'fichiers.*.chemin' => ['required', 'string'], 'fichiers.*.sha1' => ['required', 'string']]);
        $disque = Storage::disk('public');

        return response()->json(['manquants' => collect($data['fichiers'])
            ->filter(fn ($f) => self::cheminValide($f['chemin']) && (! $disque->exists($f['chemin']) || sha1_file($disque->path($f['chemin'])) !== $f['sha1']))
            ->pluck('chemin')->values()]);
    }

    public function recevoirFichier(Request $request)
    {
        $this->autoriser($request);
        $data = $request->validate(['chemin' => ['required', 'string'], 'sha1' => ['required', 'string'],
            'contenu' => ['required', 'file', 'max:'.intdiv((int) config('serveur.synchro.taille_max_fichier'), 1024)]]);
        abort_unless(self::cheminValide($data['chemin']), 422, 'Chemin de fichier refusé.');
        abort_unless(sha1_file($request->file('contenu')->getRealPath()) === $data['sha1'], 422, 'Fichier altéré pendant l\'envoi.');
        Storage::disk('public')->put($data['chemin'], file_get_contents($request->file('contenu')->getRealPath()));

        return response()->json(['ok' => true]);
    }

    /** Chemin relatif sous les dossiers de l'application, sans remontee (« .. »). */
    private static function cheminValide(string $chemin): bool
    {
        return (bool) preg_match('#^(eleves|users|logos|depenses|entrees|documents|documents_eleves)/[A-Za-z0-9_./-]+$#', $chemin) && ! str_contains($chemin, '..');
    }

    private function autoriser(Request $request): void
    {
        abort_unless(config('serveur.role') === 'en_ligne', 404);
        $cle = (string) config('serveur.synchro.cle');
        abort_unless($cle !== '' && hash_equals($cle, (string) $request->header('X-Sync-Cle')), 401, 'Clé de synchronisation invalide.');
    }
}
