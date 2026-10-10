<?php

namespace App\Support\Synchro;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Synchronisation, cote SERVEUR DE L'ECOLE (php artisan sync:executer,
 * planifiee toutes les 2 minutes) :
 * 1. recuperer les changements faits en ligne (pedagogie, sessions...) et les
 *    appliquer ici (Applicateur, barriere d'arret des notes) ;
 * 2. envoyer au serveur en ligne tout ce qui a change ici (sync_journal non
 *    envoye), par lots.
 * L'avancement est garde dans sync_etat.
 */
class Synchroniseur
{
    public function executer(): array
    {
        if (config('serveur.role') !== 'local') {
            throw new RuntimeException('La synchronisation se lance depuis le serveur de l\'école (SERVEUR_ROLE=local).');
        }
        if (! config('serveur.synchro.url') || ! config('serveur.synchro.cle')) {
            throw new RuntimeException('SYNC_URL et SYNC_CLE ne sont pas renseignés dans le .env.');
        }
        $bilan = ['recus' => [], 'envoyes' => 0, 'fichiers' => 0, 'erreurs_envoi' => []];
        try {
            $bilan['recus'] = $this->tirer();
            [$bilan['envoyes'], $bilan['erreurs_envoi'], $bilan['fichiers']] = $this->envoyer();
            self::noter('derniere_synchro', now()->toDateTimeString());
            self::noter('derniere_erreur', null);
        } catch (Throwable $e) {
            self::noter('derniere_erreur', now()->toDateTimeString().' : '.mb_substr($e->getMessage(), 0, 500));
            throw $e;
        }

        return $bilan;
    }

    /** Changements faits en ligne depuis le dernier curseur. */
    private function tirer(): array
    {
        $total = ['appliques' => 0, 'ignores' => 0, 'plus_recents_ici' => 0, 'rejetes' => 0, 'erreurs' => []];
        do {
            $depuis = (int) self::lire('curseur_en_ligne');
            $r = $this->http()->get($this->url('/api/sync/journal'), ['depuis' => $depuis, 'limite' => config('serveur.synchro.lot')])->throw()->json();
            $bilan = (new Applicateur(aLEcole: true))->appliquer($r['changements'] ?? []);
            foreach (['appliques', 'ignores', 'plus_recents_ici', 'rejetes'] as $k) {
                $total[$k] += $bilan[$k];
            }
            $total['erreurs'] = [...$total['erreurs'], ...$bilan['erreurs']];
            self::noter('curseur_en_ligne', (string) ($r['dernier'] ?? $depuis));
        } while (! empty($r['reste']));

        return $total;
    }

    /** @return array{0: int, 1: array, 2: int} entrees envoyees, erreurs du serveur en ligne, fichiers envoyes */
    private function envoyer(): array
    {
        $envoyees = 0;
        $fichiers = 0;
        $erreurs = [];
        $deja = [];
        while (true) {
            $entrees = DB::table('sync_journal')->whereNull('envoye_le')->whereNotIn('id', $deja)
                ->orderBy('id')->limit((int) config('serveur.synchro.lot', 500))->get();
            if ($entrees->isEmpty()) {
                break;
            }
            $changements = Lot::depuisJournal($entrees);
            $r = $this->http()->post($this->url('/api/sync/recevoir'), ['changements' => $changements])->throw()->json();
            $fichiers += $this->envoyerFichiers($changements);
            // Lignes refusees par le serveur en ligne : gardees pour un nouvel
            // essai ; le reste est marque envoye.
            $echecs = collect($r['erreurs'] ?? [])->map(fn ($e) => $e['table'].'#'.json_encode($e['cle']))->all();
            $ok = $entrees->reject(fn ($e) => in_array($e->table_nom.'#'.json_encode(Tables::cleDuJournal($e->table_nom, $e->ligne_id, $e->ligne_cle)), $echecs, true));
            DB::table('sync_journal')->whereIn('id', $ok->pluck('id'))->update(['envoye_le' => now()]);
            $deja = [...$deja, ...$entrees->pluck('id')->diff($ok->pluck('id'))->all()];
            $envoyees += $ok->count();
            $erreurs = [...$erreurs, ...($r['erreurs'] ?? [])];
        }

        return [$envoyees, $erreurs, $fichiers];
    }

    /** Fichiers designes par les lignes envoyees, absents ou differents en ligne ; renvoie leur nombre. */
    private function envoyerFichiers(array $changements): int
    {
        $disque = Storage::disk('public');
        $annonces = collect($changements)->where('operation', 'ecriture')
            ->flatMap(fn ($c) => collect(config('serveur.synchro.fichiers.'.$c['table'], []))->map(fn ($col) => $c['ligne'][$col] ?? null))
            ->filter(fn ($chemin) => $chemin && $disque->exists($chemin) && $disque->size($chemin) <= config('serveur.synchro.taille_max_fichier'))
            ->unique()->map(fn ($chemin) => ['chemin' => $chemin, 'sha1' => sha1_file($disque->path($chemin))])->values();
        if ($annonces->isEmpty()) {
            return 0;
        }
        $manquants = $this->http()->post($this->url('/api/sync/fichiers/verifier'), ['fichiers' => $annonces->all()])->throw()->json('manquants') ?? [];
        foreach ($annonces->whereIn('chemin', $manquants) as $f) {
            $this->http()->attach('contenu', $disque->get($f['chemin']), basename($f['chemin']))
                ->post($this->url('/api/sync/fichier'), ['chemin' => $f['chemin'], 'sha1' => $f['sha1']])->throw();
        }

        return count($manquants);
    }

    private function http()
    {
        return Http::acceptJson()->timeout(60)->withHeaders(['X-Sync-Cle' => config('serveur.synchro.cle')]);
    }

    private function url(string $chemin): string
    {
        return rtrim((string) config('serveur.synchro.url'), '/').$chemin;
    }

    public static function lire(string $cle): ?string
    {
        return DB::table('sync_etat')->where('cle', $cle)->value('valeur');
    }

    public static function noter(string $cle, ?string $valeur): void
    {
        DB::table('sync_etat')->updateOrInsert(['cle' => $cle], ['valeur' => $valeur, 'updated_at' => now()]);
    }
}
