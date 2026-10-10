<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MigrationV1\BaseApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Parametres > Migration V1 (poste Super admin, serveur de l'ecole seulement) :
 * - lance « php artisan migration:v1 » en arriere-plan dans une base videe
 *   completement, avec les dumps V1 poses a la racine de back/ ; l'application
 *   reste sur sa base ;
 * - suit son journal en temps reel (route publique protegee par une cle :
 *   apres le basculement, l'ancienne session n'existe plus) ;
 * - liste les bases V2 du serveur et bascule l'application sur l'une d'elles
 *   (l'utilisateur est deconnecte et se reconnecte sur la base choisie).
 */
class MigrationV1Controller extends Controller
{
    private const SOURCE = 'monde_edu_v1_source';

    public function etat(Request $request)
    {
        $this->autoriser($request);
        $actuelle = BaseApplication::actuelle();
        $email = $request->user()->email;

        // Bases V2 du serveur, et bases de migration meme vides (pas la copie V1).
        $v2 = BaseApplication::basesV2();
        $noms = collect(DB::select('SHOW DATABASES'))->map(fn ($l) => (string) array_values((array) $l)[0])
            ->filter(fn ($n) => in_array($n, $v2, true) || (str_contains($n, 'migration') && $n !== self::SOURCE))->sort()->values();
        $bases = [];
        foreach ($noms as $base) {
            $estV2 = in_array($base, $v2, true);
            $etabs = $estV2 ? DB::table("$base.etablissements")->whereNull('deleted_at')->orderBy('id')->get(['code', 'nom'])
                ->map(fn ($e) => trim("{$e->code} {$e->nom}"))->all() : [];
            $bases[] = [
                'nom' => $base,
                'actuelle' => $base === $actuelle,
                'v2' => $estV2,
                // Vide : sans tables, ou tables V2 sans aucun etablissement.
                'vide' => $estV2 ? ! $etabs : ! DB::table('information_schema.tables')->where('table_schema', $base)->exists(),
                'etablissements' => $etabs,
                'eleves' => $estV2 ? DB::table("$base.eleves")->count() : 0,
                'compte_present' => $estV2 && $email && DB::table("$base.users")->where('email', $email)->where('statut', 'actif')->whereNull('deleted_at')->exists(),
            ];
        }

        return response()->json(['data' => [
            'serveur_local' => config('serveur.role') === 'local',
            'base_actuelle' => $actuelle,
            'bases' => $bases,
            'cible_defaut' => 'migration_monde_edu',
            'dumps' => array_map(fn ($f) => ['nom' => basename($f), 'taille' => filesize($f), 'date' => date('Y-m-d H:i', filemtime($f))], $this->dumps()),
            'en_cours' => $this->enCours(),
            'derniers' => $this->derniers(),
        ]]);
    }

    public function lancer(Request $request)
    {
        $this->autoriser($request);
        $data = $request->validate([
            'cible' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]*migration[a-z0-9_]*$/'],
            'essai' => ['boolean'],
            'activer' => ['boolean'],
        ], ['cible.regex' => 'Le nom de la base doit contenir « migration » (lettres minuscules, chiffres et _ seulement).']);
        $cible = $data['cible'];
        $essai = (bool) ($data['essai'] ?? false);

        abort_if($cible === BaseApplication::actuelle(), 422, "La base $cible est celle que l'application utilise : basculez d'abord sur une autre base, puis relancez.");
        abort_if($this->enCours() !== null, 422, 'Une migration est déjà en cours.');
        $dumps = $this->dumps();
        abort_if(! $dumps, 422, 'Aucun dump V1 (.sql) à la racine du dossier back.');
        abort_if(! $request->user()->email, 422, 'Votre compte n\'a pas d\'e-mail : il ne pourrait pas être recopié dans la nouvelle base.');

        $dossier = $this->dossier('suivi');
        $id = now()->format('Ymd-His');
        $journal = "$dossier/$id.log";
        // Images de l'ecole (logo, armoirie, image par defaut des eleves) deposees dans storage/app/public/logos.
        $images = realpath(storage_path('app/public/logos'));
        $arguments = array_filter([
            '-d', 'memory_limit=2G', 'artisan', 'migration:v1', '--no-ansi', '--no-interaction',
            "--cible=$cible", '--preparer-cible', '--source='.self::SOURCE,
            '--charger-source='.implode(',', $dumps),
            $images ? "--images=$images" : null,
            '--compte='.$request->user()->id,
            $essai ? '--essai' : (! empty($data['activer']) ? '--activer' : null),
        ]);
        $php = $this->php();
        $commande = '"'.$php.'" '.implode(' ', array_map(fn ($a) => str_contains($a, ' ') ? '"'.$a.'"' : $a, $arguments));

        if (PHP_OS_FAMILY === 'Windows') {
            $script = "$dossier/$id.bat";
            file_put_contents($script, "@echo off\r\ncd /d \"".base_path()."\"\r\n$commande > \"$journal\" 2>&1\r\necho FIN %ERRORLEVEL% >> \"$journal\"\r\n");
            pclose(popen('start "" /B cmd /c "'.$script.'"', 'r'));
        } else {
            $script = "$dossier/$id.sh";
            file_put_contents($script, "cd '".base_path()."'\n$commande > '$journal' 2>&1\necho FIN \$? >> '$journal'\n");
            exec("nohup sh '$script' > /dev/null 2>&1 &");
        }

        $courant = ['cle' => Str::random(40), 'id' => $id, 'cible' => $cible, 'essai' => $essai, 'debut' => now()->toDateTimeString(), 'par' => $request->user()->email];
        file_put_contents("$dossier/courant.json", json_encode($courant));

        return response()->json(['message' => 'Migration lancée.', 'data' => $courant]);
    }

    /** Journal en temps reel (public : la cle donnee au lancement suffit). */
    public function suivi(string $cle)
    {
        $courant = $this->courant();
        abort_unless($courant && hash_equals($courant['cle'], $cle), 404);
        $texte = (string) @file_get_contents($this->dossier('suivi')."/{$courant['id']}.log");
        $texte = mb_convert_encoding($texte, 'UTF-8', 'UTF-8, Windows-1252');
        $lignes = array_values(array_filter(array_map('rtrim', preg_split('/\r?\n/', $texte)), fn ($l) => $l !== ''));
        $fin = null;
        if ($lignes && preg_match('/^FIN (\d+)/', end($lignes), $m)) {
            $fin = (int) $m[1];
            array_pop($lignes);
        }
        $resume = null;
        if (preg_match('/Résumé : (rapport-[\d-]+\.json)/u', $texte, $m) && is_file($f = $this->dossier()."/{$m[1]}")) {
            $resume = json_decode(file_get_contents($f), true);
        }

        return response()->json(['data' => [
            'cible' => $courant['cible'],
            'essai' => $courant['essai'],
            'debut' => $courant['debut'],
            'lignes' => array_slice($lignes, -300),
            'termine' => $fin !== null,
            'succes' => $fin === 0,
            'activee' => str_contains($texte, 'utilise maintenant la base'),
            'resume' => $resume,
        ]]);
    }

    /** L'application utilise desormais une autre base V2 du serveur. */
    public function basculer(Request $request)
    {
        $this->autoriser($request);
        $base = $request->validate(['base' => ['required', 'string']])['base'];
        abort_unless(in_array($base, BaseApplication::basesV2(), true), 422, "La base $base n'a pas le schéma de la V2.");
        abort_if($base === BaseApplication::actuelle(), 422, "L'application utilise déjà $base.");
        abort_if($this->enCours() !== null, 422, 'Une migration est en cours : attendez sa fin.');
        abort_unless(DB::table("$base.users")->where('email', $request->user()->email)->where('statut', 'actif')->whereNull('deleted_at')->exists(), 422,
            "Votre compte n'existe pas dans $base : vous ne pourriez plus vous connecter.");

        BaseApplication::basculer($base);

        return response()->json(['message' => "L'application utilise maintenant la base $base. Reconnectez-vous."]);
    }

    /** Rapport (.md) ou mots de passe provisoires (.csv) d'une migration. */
    public function fichier(Request $request, string $nom)
    {
        $this->autoriser($request);
        abort_unless(preg_match('/^(rapport|mots-de-passe)-\d{8}-\d{6}\.(md|csv)$/', $nom) && is_file($f = $this->dossier()."/$nom"), 404);

        return response()->download($f, $nom);
    }

    // ------------------------------------------------------------------

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->roles->contains('code', 'super_admin'), 403, 'Réservé au Super admin.');
        abort_unless(config('serveur.role') === 'local', 403, 'La migration se fait sur le serveur de l\'école.');
    }

    private function dossier(string $sous = ''): string
    {
        $d = storage_path('app/private/migration-v1'.($sous ? "/$sous" : ''));
        if (! is_dir($d)) {
            mkdir($d, 0775, true);
        }

        return $d;
    }

    /** Dumps V1 poses a la racine de back/, le plus gros (base principale) d'abord. */
    private function dumps(): array
    {
        $fichiers = glob(base_path('*.sql')) ?: [];
        usort($fichiers, fn ($a, $b) => filesize($b) <=> filesize($a));

        return $fichiers;
    }

    private function courant(): ?array
    {
        $f = $this->dossier('suivi').'/courant.json';

        return is_file($f) ? json_decode(file_get_contents($f), true) : null;
    }

    /** Migration lancee et pas encore terminee (abandonnee apres 2 h). */
    private function enCours(): ?array
    {
        $c = $this->courant();
        if (! $c || now()->diffInMinutes($c['debut'], true) > 120) {
            return null;
        }
        $journal = (string) @file_get_contents($this->dossier('suivi')."/{$c['id']}.log");

        return preg_match('/^FIN \d+/m', $journal) ? null : ['cle' => $c['cle'], 'cible' => $c['cible'], 'debut' => $c['debut'], 'essai' => $c['essai']];
    }

    /** Resumes des dernieres migrations. */
    private function derniers(): array
    {
        $fichiers = glob($this->dossier().'/rapport-*.json') ?: [];
        rsort($fichiers);

        return array_map(function ($f) {
            $r = json_decode(file_get_contents($f), true) ?: [];
            $ecarts = collect($r['controles'] ?? [])->filter(fn ($c) => $c['v1'] != $c['v2'])->count();

            return [
                'date' => $r['date'] ?? null, 'cible' => $r['cible'] ?? null, 'essai' => $r['essai'] ?? false,
                'erreur' => $r['erreur'] ?? null, 'duree' => $r['duree'] ?? null, 'ecarts' => $ecarts,
                'controles' => count($r['controles'] ?? []), 'rapport' => $r['rapport'] ?? null, 'mots_de_passe' => $r['mots_de_passe'] ?? null,
            ];
        }, array_slice($fichiers, 0, 5));
    }

    /** php en ligne de commande (pas le serveur web). */
    private function php(): string
    {
        $courant = PHP_BINARY;
        if (preg_match('/php(\.exe)?$/i', basename($courant))) {
            return $courant;
        }
        $laragon = glob('C:/laragon/bin/php/php-'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'*/php.exe') ?: [];

        return $laragon ? end($laragon) : 'php';
    }
}
