<?php

namespace App\Support;

/**
 * Lance une commande artisan detachee de la requete web (elle continue si
 * l'utilisateur change de page ou ferme l'application) ; sa sortie va dans
 * $journal, suivie de « FIN <code> » a la fin.
 */
class ArrierePlan
{
    /**
     * @param  list<string>  $arguments  arguments apres « php artisan » (ex : ['migration:v1', '--essai'])
     */
    public static function artisan(array $arguments, string $journal): void
    {
        $dossier = dirname($journal);
        if (! is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }
        $commande = '"'.self::php().'" '.implode(' ', array_map(
            fn ($a) => preg_match('/[\s"]/', $a) ? '"'.str_replace('"', '', $a).'"' : $a,
            array_merge(['artisan'], $arguments)
        ));
        $base = pathinfo($journal, PATHINFO_DIRNAME).'/'.pathinfo($journal, PATHINFO_FILENAME);

        if (PHP_OS_FAMILY === 'Windows') {
            file_put_contents("$base.bat", "@echo off\r\ncd /d \"".base_path()."\"\r\n$commande > \"$journal\" 2>&1\r\necho FIN %ERRORLEVEL% >> \"$journal\"\r\n");
            pclose(popen('start "" /B cmd /c "'.$base.'.bat"', 'r'));
        } else {
            file_put_contents("$base.sh", "cd '".base_path()."'\n$commande > '$journal' 2>&1\necho FIN \$? >> '$journal'\n");
            exec("nohup sh '$base.sh' > /dev/null 2>&1 &");
        }
    }

    /** php en ligne de commande (pas le serveur web). */
    public static function php(): string
    {
        if (preg_match('/php(\.exe)?$/i', basename(PHP_BINARY))) {
            return PHP_BINARY;
        }
        $laragon = glob('C:/laragon/bin/php/php-'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'*/php.exe') ?: [];

        return $laragon ? end($laragon) : 'php';
    }
}
