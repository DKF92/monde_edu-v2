<?php

namespace App\Support\MigrationV1;

/**
 * Regles de conversion V1 -> V2 de la reprise des donnees (voir
 * MIGRATION_V1_V2.md, sections 4 et 4.19). Fonctions pures, sans base.
 */
class Conversions
{
    /** Matieres V1 (libelle) -> [code V2, libelle, groupe du bulletin]. */
    public const MATIERES = [
        'FRANCAIS' => ['FR', 'Français', 'LITTERAIRE'],
        'ANGLAIS' => ['ANG', 'Anglais', 'LITTERAIRE'],
        'ALLEMAND' => ['ALL', 'Allemand', 'LITTERAIRE'],
        'ESPAGNOL' => ['ESP', 'Espagnol', 'LITTERAIRE'],
        'HISTOIRE-GEOGRAPHIE' => ['HG', 'Histoire-Géographie', 'LITTERAIRE'],
        'EDHC' => ['EDHC', 'EDHC', 'LITTERAIRE'],
        'PHILOSOPHIE' => ['PHILO', 'Philosophie', 'LITTERAIRE'],
        'MATHEMATIQUE' => ['MATH', 'Mathématiques', 'SCIENTIFIQUE'],
        'PHYSIQUE-CHIMIE' => ['PC', 'Physique-Chimie', 'SCIENTIFIQUE'],
        'SVT' => ['SVT', 'SVT', 'SCIENTIFIQUE'],
        'INFORMATIQUE' => ['INFO', 'Informatique', 'SCIENTIFIQUE'],
        'EPS' => ['EPS', 'EPS', 'AUTRES'],
        'DESSIN' => ['AP', 'Arts plastiques', 'AUTRES'],
        'MUSIQUE' => ['MUS', 'Musique', 'AUTRES'],
        'CONDUITE' => ['COND', 'Conduite', 'AUTRES'],
        // Composantes du francais au 1er cycle (E2) : hors groupe, en attendant
        // le lien « matiere parente » qui calculera la moyenne de francais.
        'ORTHOGRAPHE' => ['ORTHO', 'Orthographe', null],
        'COMPOSITION-FRANCAISE' => ['CF', 'Composition française', null],
        'EXPRESSION-ORALE' => ['EO', 'Expression orale', null],
    ];

    /** Types de frais V1 (type_frais.code_type_frais / frais.type_frais) -> code V2. */
    public const TYPES_FRAIS = [
        'ANNEXE' => 'FRAIS_ANNEXES', 'INCRIPTION' => 'INSCRIPTION', 'INSCRIPTION' => 'INSCRIPTION', 'SCOLARITE' => 'SCOLARITE',
        'DROIT-EXAMEN' => 'DROIT_EXAMEN', 'LIVRET-SCOLAIRE' => 'LIVRET_SCOLAIRE', 'PHOTO' => 'PHOTO', 'TSHIRT' => 'TEESHIRT',
        'TENUE-EPS' => 'TENUE_EPS', 'MACARON' => 'MACARON', 'CARTE-ACCES' => 'CARTE_ACCES', 'INFORMATIQUE' => 'INFORMATIQUE',
        'LOGICIEL' => 'LOGICIEL', 'CRAIE' => 'CRAIE', 'RAME' => 'RAME',
    ];

    /** Colonnes cout_* de V1 (grille et inscription) -> code V2 des annexes detaillees. */
    public const COUTS_ANNEXES = [
        'cout_droit_examen' => 'DROIT_EXAMEN', 'cout_livret_scolaire' => 'LIVRET_SCOLAIRE', 'cout_photo' => 'PHOTO',
        'cout_teeshirt' => 'TEESHIRT', 'cout_tenueEPS' => 'TENUE_EPS', 'cout_marcaron' => 'MACARON',
        'cout_carte_acces' => 'CARTE_ACCES', 'cout_informatique' => 'INFORMATIQUE', 'cout_logiciel' => 'LOGICIEL',
    ];

    /** Postes V1 (administration.role_code) -> code du poste V2 ; null = pas de poste V2. */
    public const POSTES = [
        'superAdmin' => 'super_admin', 'administrateur' => 'super_admin', 'fondateur' => 'fondateur',
        'directeurEtude' => 'directeur_etudes', 'directeurEtudAdj' => 'adjoint_directeur_etudes',
        'directeurAdmin' => 'directeur_administratif', 'dirAdmin' => 'directeur_administratif',
        'comptable' => 'comptable', 'econome' => 'econome', 'caissier' => 'caissier',
        // Poste « Caisse fictive » a creer en V2 (E6) : rien pour l'instant.
        'caisseFict' => null,
        'educateur' => 'educateur', 'educateurNiveau' => 'educateur', 'professeur' => 'professeur',
        'informaticien' => 'informaticien', 'corspFich' => 'correspondant_fichier',
    ];

    /** Comptes V1 de test, jamais repris. */
    public const POSTES_IGNORES = ['bbb'];

    /** Prefixe ajoute a un numero a 8 chiffres (bascule ivoirienne du 31/01/2021), selon ses 2 premiers chiffres. */
    private const PREFIXES_MOBILES = [
        '07' => ['07', '08', '09', '47', '48', '49', '57', '58', '59', '67', '68', '69', '77', '78', '79', '87', '88', '89', '97', '98'],
        '05' => ['04', '05', '06', '44', '45', '46', '54', '55', '56', '64', '65', '66', '74', '75', '76', '84', '85', '86', '94', '95', '96'],
        '01' => ['01', '02', '03', '40', '41', '42', '43', '50', '51', '52', '53', '70', '71', '72', '73'],
    ];

    /**
     * Numero au format a 10 chiffres.
     *
     * @return array{0: ?string, 1: string} [numero, etat] ; etat : vide, garde, converti, anomalie
     */
    public static function telephone(?string $brut): array
    {
        $chiffres = preg_replace('/\D/', '', (string) $brut);
        if ($chiffres === '' || preg_match('/^0+$/', $chiffres)) {
            return [null, 'vide'];
        }
        if (strlen($chiffres) > 10 && str_starts_with($chiffres, '225')) {
            $chiffres = substr($chiffres, 3);
            if (str_starts_with($chiffres, '0') === false && strlen($chiffres) === 9) {
                $chiffres = '0'.$chiffres;
            }
        }
        if (strlen($chiffres) === 10 && preg_match('/^(01|05|07|21|25|27)/', $chiffres)) {
            return [$chiffres, 'garde'];
        }
        if (strlen($chiffres) === 8) {
            $debut = substr($chiffres, 0, 2);
            foreach (self::PREFIXES_MOBILES as $prefixe => $debuts) {
                if (in_array($debut, $debuts, true)) {
                    return [$prefixe.$chiffres, 'converti'];
                }
            }
            if (preg_match('/^[23]/', $debut)) {
                $prefixe = match ($chiffres[2]) { '0' => '25', '8' => '21', default => '27' };

                return [$prefixe.$chiffres, 'converti'];
            }
        }

        return [trim((string) $brut), 'anomalie'];
    }

    /** Nationalite V2 : Ivoirienne, Burkinabè, Guinéenne, Malienne, Ghanéenne, sinon Étrangère (decision Q8). */
    public static function nationalite(?string $brut): ?string
    {
        $v = strtoupper(trim(self::sansAccents((string) $brut)));

        return match (true) {
            $v === '' => null,
            in_array($v, ['IV', 'IVOIRIEN', 'IVOIRIENNE', 'CI'], true) => 'Ivoirienne',
            str_starts_with($v, 'BURKIN') || str_starts_with($v, 'BUKIN') => 'Burkinabè',
            str_starts_with($v, 'GUIN') => 'Guinéenne',
            str_starts_with($v, 'MALI') => 'Malienne',
            str_starts_with($v, 'GHAN') => 'Ghanéenne',
            default => 'Étrangère',
        };
    }

    /**
     * Niveau V1 (lib_niveau « TleA2 », « 6eme », ou niveau_gen « Tle ») -> [code du niveau general V2, serie].
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function niveau(?string $v1): array
    {
        $v = trim((string) $v1);

        return match (true) {
            (bool) preg_match('/^([3-6])\s*eme$/i', $v, $m) => [$m[1].'EME', null],
            (bool) preg_match('/^2nde\s*(\w*)$/i', $v, $m) => ['2NDE', self::serie($m[1])],
            (bool) preg_match('/^1ere\s*(\w*)$/i', $v, $m) => ['1ERE', self::serie($m[1])],
            (bool) preg_match('/^tle\s*(\w*)$/i', $v, $m) => ['TLE', self::serie($m[1])],
            (bool) preg_match('/^(CP[12]|CE[12]|CM[12])$/i', $v, $m) => [strtoupper($m[1]), null],
            default => [null, null],
        };
    }

    private static function serie(string $s): ?string
    {
        return $s === '' ? null : strtoupper($s);
    }

    /** Libelle de matiere V1 normalise (« EXPRESSION ORALE » = « EXPRESSION-ORALE »). */
    public static function matiere(?string $v1): string
    {
        return preg_replace('/\s+/', '-', strtoupper(trim((string) $v1)));
    }

    public static function decision(?string $v1): ?string
    {
        return match (strtoupper(trim((string) $v1))) {
            'ADMIS' => 'ADMIS',
            'DOUBLE', 'REDOUBLE' => 'REDOUBLE',
            'EXCLUS', 'EXCLU' => 'EXCLU',
            default => null,
        };
    }

    public static function langue(?string $v1): ?string
    {
        return match (strtoupper(trim((string) $v1))) {
            'ESP', 'ESPAGNOL' => 'Espagnol',
            'ALL', 'ALLEMAND' => 'Allemand',
            default => null,
        };
    }

    /** « 2025 » -> « 2025-2026 ». */
    public static function anneeScolaire(int $an): string
    {
        return $an.'-'.($an + 1);
    }

    /** Prefixe des numeros V2 : 2025-2026 -> « 2526 ». */
    public static function prefixeNumero(int $an): string
    {
        return substr((string) $an, 2, 2).substr((string) ($an + 1), 2, 2);
    }

    /** Texte en double encodage UTF-8 (« TrÃ¨s ») remis d'aplomb. */
    public static function texte(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);
        if (preg_match('/[ÃÂ][\x{0080}-\x{00BF}]/u', $v)) {
            $repare = mb_convert_encoding($v, 'ISO-8859-1', 'UTF-8');
            if (mb_check_encoding($repare, 'UTF-8')) {
                $v = $repare;
            }
        }

        return $v === '' ? null : $v;
    }

    public static function majuscules(?string $v): ?string
    {
        $v = self::texte($v);

        return $v === null ? null : mb_strtoupper(preg_replace('/\s+/', ' ', $v));
    }

    /** Date valide (annee 1900-2100) ou null. */
    public static function date(?string $v): ?string
    {
        if (! $v || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            return null;
        }
        if ((int) $m[1] < 1900 || (int) $m[1] > 2100 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return "$m[1]-$m[2]-$m[3]";
    }

    public static function dateHeure(?string $v): ?string
    {
        $date = self::date($v);
        if (! $date) {
            return null;
        }

        return preg_match('/\d{2}:\d{2}(:\d{2})?$/', (string) $v, $h) ? $date.' '.(strlen($h[0]) === 5 ? $h[0].':00' : $h[0]) : $date.' 00:00:00';
    }

    /** Nom + prenoms comparables (sans accents, majuscules, espaces simples). */
    public static function cleNom(?string $nom, ?string $prenoms = null): string
    {
        $t = strtoupper(self::sansAccents(trim((string) self::texte($nom).' '.(string) self::texte($prenoms))));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z ]/', ' ', $t)));
    }

    public static function sansAccents(string $v): string
    {
        $t = \Normalizer::normalize($v, \Normalizer::FORM_D);

        return preg_replace('/\p{Mn}/u', '', $t === false ? $v : $t);
    }

    /** Nombre V1 (decimal en texte, null) -> entier. */
    public static function montant(mixed $v): int
    {
        return (int) round((float) ($v ?? 0));
    }
}
