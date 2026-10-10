<?php

namespace App\Support;

/**
 * Etat du scan « Mettre a jour » (inscription en ligne) d'un etablissement
 * pour une annee, partage entre la commande qui tourne en arriere-plan et
 * l'ecran (fichier storage/app/private/maj-en-ligne/<etab>-<annee>.json).
 *
 * Une commande qui ne donne plus signe de vie depuis 5 minutes (serveur
 * arrete...) est consideree comme interrompue.
 */
class ScanEnLigne
{
    public const DERNIERES = 200;

    public static function lire(int $etablissementId, int $anneeId): array
    {
        $f = self::fichier($etablissementId, $anneeId);
        $etat = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $etat += ['en_cours' => false, 'total' => 0, 'traitees' => 0, 'trouvees' => 0, 'introuvables' => 0,
            'erreur' => null, 'debut' => null, 'fin' => null, 'vu_le' => null, 'par' => null, 'dernieres' => []];
        if ($etat['en_cours'] && $etat['vu_le'] && now()->diffInMinutes($etat['vu_le'], true) > 5) {
            $etat['en_cours'] = false;
            $etat['erreur'] ??= 'Mise à jour interrompue (le serveur s\'est arrêté). Relancez-la.';
        }

        return $etat;
    }

    public static function ecrire(int $etablissementId, int $anneeId, array $etat): void
    {
        $etat['vu_le'] = now()->toDateTimeString();
        $etat['dernieres'] = array_slice($etat['dernieres'] ?? [], -self::DERNIERES);
        $f = self::fichier($etablissementId, $anneeId);
        if (! is_dir(dirname($f))) {
            mkdir(dirname($f), 0775, true);
        }
        file_put_contents("$f.tmp", json_encode($etat, JSON_UNESCAPED_UNICODE));
        rename("$f.tmp", $f);
    }

    public static function demanderArret(int $etablissementId, int $anneeId): void
    {
        touch(self::fichier($etablissementId, $anneeId).'.arret');
    }

    public static function arretDemande(int $etablissementId, int $anneeId): bool
    {
        return is_file(self::fichier($etablissementId, $anneeId).'.arret');
    }

    public static function effacerArret(int $etablissementId, int $anneeId): void
    {
        @unlink(self::fichier($etablissementId, $anneeId).'.arret');
    }

    public static function journal(int $etablissementId, int $anneeId): string
    {
        return self::fichier($etablissementId, $anneeId).'.log';
    }

    private static function fichier(int $etablissementId, int $anneeId): string
    {
        return storage_path("app/private/maj-en-ligne/{$etablissementId}-{$anneeId}.json");
    }
}
