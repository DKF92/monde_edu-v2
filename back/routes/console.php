<?php

use App\Support\Synchro\Declencheurs;
use App\Support\Synchro\Synchroniseur;
use App\Support\Synchro\Tables;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ------------------------------------------------------------------ Synchronisation
// Serveur de l'ecole (fait foi) <-> serveur en ligne (copie). Voir config/serveur.php.

Artisan::command('sync:preparer', function () {
    Synchroniseur::noter('role', config('serveur.role'));
    $this->info('Rôle de ce serveur : '.config('serveur.role').' ; déclencheurs du journal : '.Declencheurs::creer().'.');
})->purpose('Enregistre le rôle du serveur et (re)crée les déclencheurs du journal de synchronisation');

Artisan::command('sync:executer', function () {
    $b = (new Synchroniseur)->executer();
    $r = $b['recus'];
    $this->info("Reçus du serveur en ligne : {$r['appliques']} appliqués, {$r['rejetes']} refusés, {$r['plus_recents_ici']} déjà plus récents ici, {$r['ignores']} ignorés.");
    $this->info("Envoyés au serveur en ligne : {$b['envoyes']} changements, {$b['fichiers']} fichiers.");
    foreach ([...$r['erreurs'], ...$b['erreurs_envoi']] as $e) {
        $this->warn("Erreur {$e['table']} ".json_encode($e['cle'] ?? null).' : '.$e['erreur']);
    }
    // Journal deja transmis : garde 30 jours pour le diagnostic.
    DB::table('sync_journal')->whereNotNull('envoye_le')->where('envoye_le', '<', now()->subDays(30))->delete();
})->purpose('Synchronise le serveur de l\'école avec le serveur en ligne');

Artisan::command('sync:initialiser {--force : sans demander de confirmation}', function () {
    if (! $this->option('force') && ! $this->confirm('Envoyer TOUTES les données de l\'école au serveur en ligne (première mise en service) ?')) {
        return;
    }
    $n = 0;
    foreach (Tables::synchronisees() as $table) {
        $genre = Tables::genre($table);
        $cols = Tables::clePrimaire($table);
        DB::table($table)->orderBy($cols[0])->chunk(1000, function ($lignes) use ($table, $genre, $cols, &$n) {
            DB::table('sync_journal')->insert($lignes->map(fn ($l) => [
                'table_nom' => $table, 'operation' => 'ecriture', 'origine' => 'local',
                'ligne_id' => in_array($genre, ['id', 'pedagogie'], true) ? $l->id : null,
                'ligne_cle' => match ($genre) { 'pedagogie' => $l->uuid, 'jetons' => $l->token, 'pivot' => collect($cols)->map(fn ($c) => $l->{$c})->join('|'), default => null },
            ])->all());
            $n += $lignes->count();
        });
    }
    $this->info("$n lignes mises en attente d'envoi : lancez « php artisan sync:executer ».");
})->purpose('Met toutes les données de l\'école en attente d\'envoi (première copie vers le serveur en ligne)');

Artisan::command('sync:etat', function () {
    $this->table(['Élément', 'Valeur'], [
        ['Rôle', config('serveur.role')],
        ['Serveur en ligne', config('serveur.synchro.url') ?: '(SYNC_URL non renseigné)'],
        ['Changements en attente d\'envoi', DB::table('sync_journal')->whereNull('envoye_le')->count()],
        ['Dernière synchronisation', Synchroniseur::lire('derniere_synchro') ?? 'jamais'],
        ['Dernière erreur', Synchroniseur::lire('derniere_erreur') ?? 'aucune'],
        ['Saisies en ligne refusées (non vues)', DB::table('sync_rejets')->whereNull('vu_le')->count()],
    ]);
})->purpose('État de la synchronisation');

// Toutes les 2 minutes, a l'ecole seulement et une fois le serveur en ligne configure
// (necessite « php artisan schedule:work » ou une tache planifiee Windows).
if (config('serveur.role') === 'local' && config('serveur.synchro.url')) {
    Schedule::command('sync:executer')->everyTwoMinutes()->withoutOverlapping();
}
