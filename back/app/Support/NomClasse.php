<?php

namespace App\Support;

/**
 * Nom d'une classe (demande du 06/10/2026) : libelle du niveau suivi du
 * numero en exposant (« TleA2³ », « 6eme¹² ») ; numerotation en lettres :
 * « 6eme A ». L'exposant distingue le numero de la classe d'un chiffre de la
 * serie (TleA1 classe 2 = « TleA1² »).
 */
class NomClasse
{
    private const EXPOSANTS = ['0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴', '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸', '9' => '⁹'];

    public static function composer(string $niveau, string $nom): string
    {
        $nom = trim($nom);
        if ($nom === '') {
            return trim($niveau);
        }

        return ctype_digit($nom) ? trim($niveau).strtr($nom, self::EXPOSANTS) : trim($niveau).' '.mb_strtoupper($nom);
    }

    /** Numero (ou lettre) de la classe, sans le niveau : « TleA2³ » -> « 3 ». */
    public static function nom(string $libelle, ?string $niveau): string
    {
        $libelle = self::sansExposant($libelle);
        if ($niveau !== null && str_starts_with(mb_strtoupper($libelle), mb_strtoupper(trim($niveau)))) {
            // Classe sans numero (libelle = niveau) : nom vide.
            return trim(mb_substr($libelle, mb_strlen(trim($niveau))));
        }

        return preg_match('/(\d+|[A-Z])$/u', $libelle, $m) ? $m[1] : $libelle;
    }

    /** Cle de tri naturel : « TleA1² » avant « TleA1¹⁰ », « 6eme 2 » avant « 6eme 10 ». */
    public static function cleTri(string $libelle): string
    {
        $exposants = implode('', self::EXPOSANTS);
        $libelle = preg_replace_callback('/['.$exposants.']+/u', fn ($m) => ' '.self::sansExposant($m[0]), $libelle);

        return preg_replace_callback('/\d+/', fn ($m) => str_pad($m[0], 4, '0', STR_PAD_LEFT), $libelle);
    }

    /** Chiffres en exposant remis en chiffres (tri naturel, comparaisons). */
    public static function sansExposant(string $texte): string
    {
        return strtr($texte, array_flip(self::EXPOSANTS));
    }
}
