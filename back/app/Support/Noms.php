<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;

/**
 * Noms d'eleves dans les listes PDF : quand les prenoms ne tiennent pas dans
 * la cellule, les derniers prenoms sont reduits a leur initiale suivie d'un
 * point (« KOUASSI Aya Marie Christelle » -> « KOUASSI Aya M. C. ») pour ne
 * jamais revenir a la ligne. La largeur est mesuree avec la police des PDF
 * (DejaVu Sans), a la taille reellement imprimee (Document::taillePolice()).
 */
class Noms
{
    private static ?FontMetrics $metriques = null;

    /** Prenoms seuls (colonne « Prénoms »). */
    public static function prenoms(?string $prenoms, float $largeurMm, float $taillePt): string
    {
        return self::ajuster('', $prenoms, $largeurMm, $taillePt);
    }

    /**
     * « NOM Prénoms » dans une meme cellule ; le nom n'est jamais abrege.
     * $gras : toute la cellule en gras ; $nomGras : seulement le nom.
     */
    public static function complet(?string $nom, ?string $prenoms, float $largeurMm, float $taillePt, bool $gras = false, bool $nomGras = false): string
    {
        return trim($nom.' '.self::ajuster((string) $nom, $prenoms, $largeurMm, $taillePt, $gras || $nomGras, $gras));
    }

    /**
     * Prenoms raccourcis pour que « $avant $prenoms » tienne dans la largeur
     * (padding de la cellule deja deduit par l'appelant). Le premier prenom
     * reste entier.
     */
    public static function ajuster(string $avant, ?string $prenoms, float $largeurMm, float $taillePt, bool $avantGras = false, bool $gras = false): string
    {
        $mots = preg_split('/\s+/', trim((string) $prenoms), -1, PREG_SPLIT_NO_EMPTY);
        if (count($mots) < 2) {
            return implode(' ', $mots);
        }
        $disponible = $largeurMm * 72 / 25.4;
        $taille = Document::taillePolice((float) $taillePt);
        $prefixe = $avant === '' ? 0 : self::largeur($avant.' ', $taille, $avantGras);

        // Du dernier prenom vers le deuxieme, tant que ca deborde.
        for ($i = count($mots) - 1; $i >= 1 && $prefixe + self::largeur(implode(' ', $mots), $taille, $gras) > $disponible; $i--) {
            $mots[$i] = self::initiale($mots[$i]);
        }

        return implode(' ', $mots);
    }

    /**
     * Texte libre (objet, beneficiaire...) coupe avec « … » pour tenir sur
     * une ligne dans la largeur (padding deja deduit).
     */
    public static function couper(?string $texte, float $largeurMm, float $taillePt, bool $gras = false): string
    {
        $texte = trim(preg_replace('/\s+/', ' ', (string) $texte));
        $disponible = $largeurMm * 72 / 25.4;
        $taille = Document::taillePolice($taillePt);
        if ($texte === '' || self::largeur($texte, $taille, $gras) <= $disponible) {
            return $texte;
        }
        $n = mb_strlen($texte);
        while ($n > 1 && self::largeur(rtrim(mb_substr($texte, 0, $n)).'…', $taille, $gras) > $disponible) {
            $n--;
        }

        return rtrim(mb_substr($texte, 0, $n)).'…';
    }

    /** « Marie » -> « M. », « Marie-Claire » -> « M.-C. ». */
    private static function initiale(string $mot): string
    {
        return implode('-', array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)).'.', explode('-', $mot)));
    }

    private static function largeur(string $texte, float $taille, bool $gras = false): float
    {
        self::$metriques ??= (function () {
            $options = new Options();
            $options->set('defaultFont', 'DejaVu Sans');

            return (new Dompdf($options))->getFontMetrics();
        })();
        $police = self::$metriques->getFont('DejaVu Sans', $gras ? 'bold' : 'normal');

        return (float) self::$metriques->getTextWidth($texte, $police, $taille);
    }
}
