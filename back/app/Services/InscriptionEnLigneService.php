<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inscription en ligne sur le site de l'Etat (MENA / DESPS, plateforme SIGFNE) :
 * le recu de preinscription est une page HTML obtenue par
 *   POST typedoc=recu, annee=2627 (2026-2027), matricule=18583394P
 * Matricule inconnu : la page du formulaire revient avec le message
 * "Aucun paiement trouve pour ce matricule".
 *
 * Le gabarit du recu change selon les annees : on lit donc le TEXTE visible,
 * libelle par libelle ("Nom :", "Prénom(s) :"...), plutot que la structure
 * HTML. Attention : le script de la page remplit le recu avec un eleve
 * d'exemple dans un navigateur, seules les valeurs du HTML brut sont fiables.
 */
class InscriptionEnLigneService
{
    /** Valeurs de "Décision de fin d'année" sur le recu. */
    private const DECISIONS = [
        'A' => 'ADMIS', 'ADMIS' => 'ADMIS', 'ADMISE' => 'ADMIS',
        'R' => 'REDOUBLE', 'RE' => 'REDOUBLE', 'RED' => 'REDOUBLE', 'REDOUBLE' => 'REDOUBLE',
        'E' => 'EXCLU', 'EX' => 'EXCLU', 'EXCLU' => 'EXCLU', 'EXCLUE' => 'EXCLU',
    ];

    private const MOIS = [
        'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7,
        'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
    ];

    /**
     * @param  string  $annee  libelle de l'annee scolaire (ex : 2026-2027)
     * @return array{trouve: bool, erreur?: string, donnees?: array<string, mixed>}
     */
    public function rechercher(string $matricule, string $annee): array
    {
        $code = $this->codeAnnee($annee);
        if (! $code) {
            return ['trouve' => false, 'erreur' => 'Année scolaire invalide.'];
        }

        // Seules les vraies reponses (trouve / introuvable) sont mises en cache,
        // pas les pannes du site.
        $cle = "inscription-en-ligne:{$code}:{$matricule}";
        if ($enCache = Cache::get($cle)) {
            return $enCache;
        }

        try {
            $reponse = Http::asForm()
                ->withOptions(['verify' => $this->certificats()])
                ->timeout((int) config('services.inscription_en_ligne.timeout', 20))
                ->post(config('services.inscription_en_ligne.url'), [
                    'typedoc' => 'recu',
                    'annee' => $code,
                    'matricule' => $matricule,
                ]);
        } catch (Throwable $e) {
            Log::warning('Inscription en ligne injoignable', ['matricule' => $matricule, 'erreur' => $e->getMessage()]);

            return ['trouve' => false, 'erreur' => 'Le site de l\'inscription en ligne ne répond pas. Réessayez plus tard.'];
        }

        if (! $reponse->successful()) {
            return ['trouve' => false, 'erreur' => "Le site de l'inscription en ligne a répondu une erreur ({$reponse->status()})."];
        }

        $donnees = $this->lire($reponse->body());
        $resultat = $donnees && ($donnees['matricule'] ?? null) === $matricule
            ? ['trouve' => true, 'donnees' => $donnees + ['annee' => $annee]]
            : ['trouve' => false];
        Cache::put($cle, $resultat, now()->addMinutes(10));

        return $resultat;
    }

    /**
     * Le serveur de l'Etat n'envoie pas son certificat intermediaire (Sectigo
     * DV R36) : les navigateurs le telechargent seuls, pas PHP/OpenSSL. On
     * verifie donc avec les autorites du serveur + cet intermediaire
     * (resources/certificats), sans desactiver la verification.
     */
    private function certificats(): string|bool
    {
        $bundle = storage_path('app/certificats/inscription-en-ligne.pem');
        if (is_file($bundle)) {
            return $bundle;
        }

        $locations = openssl_get_cert_locations();
        $systeme = collect([ini_get('curl.cainfo'), ini_get('openssl.cafile'), $locations['default_cert_file'] ?? null, '/etc/ssl/certs/ca-certificates.crt'])
            ->first(fn ($f) => $f && is_file($f));
        if (! $systeme) {
            return true;
        }

        if (! is_dir(dirname($bundle))) {
            mkdir(dirname($bundle), 0755, true);
        }
        file_put_contents($bundle, file_get_contents($systeme)."\n".file_get_contents(resource_path('certificats/sectigo-dv-r36.pem')));

        return $bundle;
    }

    /**
     * Telecharge la photo du recu en ligne. Uniquement depuis le site de
     * l'Etat (meme hote que le recu) et seulement si c'est une vraie image :
     * le site renvoie parfois une reponse vide quand l'eleve n'a pas de photo.
     *
     * @return array{contenu: string, extension: string}|null
     */
    public function telechargerPhoto(string $url): ?array
    {
        $hote = parse_url((string) config('services.inscription_en_ligne.url'), PHP_URL_HOST);
        if (! $hote || parse_url($url, PHP_URL_HOST) !== $hote || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        try {
            $reponse = Http::withOptions(['verify' => $this->certificats()])
                ->timeout((int) config('services.inscription_en_ligne.timeout', 20))
                ->get($url);
        } catch (Throwable $e) {
            Log::warning('Photo de l\'inscription en ligne injoignable', ['url' => $url, 'erreur' => $e->getMessage()]);

            return null;
        }

        $contenu = $reponse->body();
        if (! $reponse->successful() || strlen($contenu) < 200 || strlen($contenu) > 4 * 1024 * 1024) {
            return null;
        }
        $type = @getimagesizefromstring($contenu);
        $extension = match ($type['mime'] ?? null) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        return $extension ? ['contenu' => $contenu, 'extension' => $extension] : null;
    }

    /** "2026-2027" -> "2627". */
    public function codeAnnee(string $annee): ?string
    {
        return preg_match('/^\d{2}(\d{2})-\d{2}(\d{2})$/', $annee, $m) ? $m[1].$m[2] : null;
    }

    /**
     * Extrait les informations du recu. Null si la page n'est pas un recu.
     *
     * @return array<string, mixed>|null
     */
    public function lire(string $html): ?array
    {
        // Photo de l'eleve (les deux gabarits : photo-eleve / identity-photozone).
        $photo = preg_match('/<img[^>]+class="[^"]*(?:photo-eleve|identity-photozone)[^"]*"[^>]*src="(https?:[^"]+)"/i', $html, $p) ? $p[1] : null;

        $morceaux = $this->morceauxDeTexte($html);
        $texte = implode(' | ', $morceaux);

        if (! preg_match('/Matricule\s+élève\s*:?\s*\|?\s*([0-9A-Z]{6,15})/u', $texte, $m)) {
            return null;
        }
        $matricule = $m[1];

        $lu = fn (string $libelle) => $this->valeurApres($texte, $libelle);

        [$dateNaissance, $lieuNaissance] = $this->naissance($lu('Date\s+et\s+lieu\s+de\s*\|?\s*naissance'));
        $sexe = strtoupper((string) ($lu('Sexe') ?? $lu('Genre')));
        $statut = strtoupper((string) $lu('Statut'));
        $decisionBrute = strtoupper(trim((string) $lu('Décision\s+de\s+fin\s+d(?:\'|’|&rsquo;)année')));
        // Nouveau gabarit : le libelle du niveau suivant est vide (" : TLE"
        // juste apres la decision) ; ancien gabarit : "Niveau 2025-2026 : TLE".
        $niveauSuivant = $this->dernierNiveau($texte)
            ?? (preg_match('/Décision\s+de\s+fin\s+d(?:\'|’)année\s*:\s*\|\s*[^|]+\|\s*:\s*\|\s*([^|]+)/u', $texte, $n) ? trim($n[1]) : null);
        [$datePaiement, $transaction] = $this->paiement($lu('Date'));

        return array_filter([
            'numero_recu' => preg_match('/Re[çc]u\s*\|?\s*N\s*\|?\s*o?\s*\|?\s*(\d{4,})/u', $texte, $r) ? $r[1] : null,
            'annee' => preg_match('/ANNEE\s+SCOLAIRE\s*\|?\s*(\d{4}-\d{4})/u', $texte, $a) ? $a[1] : null,
            'matricule' => $matricule,
            'nom' => $lu('Nom'),
            'prenoms' => $lu('Prénom\(s\)'),
            'date_naissance' => $dateNaissance,
            'lieu_naissance' => $lieuNaissance,
            'sexe' => str_starts_with($sexe, 'F') ? 'F' : (str_starts_with($sexe, 'M') ? 'M' : null),
            'affecte' => $statut === '' ? null : ! str_contains($statut, 'NON'),
            'statut' => $statut ?: null,
            'etablissement' => $lu('Etablissement\s*\|?\s*d\'inscription'),
            'code_etablissement' => $lu('Code'),
            'type_enseignement' => $lu('Type\s+d\'Ens\.'),
            'drena' => $lu('DRENA'),
            'somme_payee' => ($somme = $lu('Somme\s+payée')) !== null ? (int) preg_replace('/\D/', '', $somme) : null,
            'date_paiement' => $datePaiement,
            'transaction' => $transaction,
            'contact_parent' => ($contact = $lu('Contact\s+parent')) ? preg_replace('/\D/', '', $contact) : null,
            'niveau_precedent' => $lu('Niveau\s+Précédent'),
            'moyenne' => ($mga = $lu('MGA')) !== null && is_numeric($v = str_replace(',', '.', $mga)) ? (float) $v : null,
            'decision_code' => $decisionBrute ?: null,
            'decision' => self::DECISIONS[$decisionBrute] ?? ($decisionBrute ?: null),
            'niveau_suivant' => $niveauSuivant,
            'photo_url' => $photo,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @return list<string> textes visibles de la page, dans l'ordre. */
    private function morceauxDeTexte(string $html): array
    {
        $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<img\b[^>]*>#i', ' ', $html);
        $morceaux = preg_split('/<[^>]+>/', $html);

        return array_values(array_filter(array_map(
            fn ($t) => trim(preg_replace('/\s+/u', ' ', html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
            $morceaux
        ), fn ($t) => $t !== ''));
    }

    /** Valeur qui suit "Libelle :" dans le texte (separateur | entre elements HTML). */
    private function valeurApres(string $texte, string $libelle): ?string
    {
        if (! preg_match('/(?:^|\|)\s*'.$libelle.'\s*\|?\s*:\s*\|?\s*([^|]+)/iu', $texte, $m)) {
            return null;
        }
        $valeur = trim($m[1]);

        return $valeur === '' || str_ends_with($valeur, ':') ? null : $valeur;
    }

    /** "20/10/2005 à MAN" -> ['2005-10-20', 'MAN']. */
    private function naissance(?string $valeur): array
    {
        if (! $valeur || ! preg_match('#(\d{2})/(\d{2})/(\d{4})(?:\s+à\s+(.+))?#u', $valeur, $m)) {
            return [null, null];
        }

        return [checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? "{$m[3]}-{$m[2]}-{$m[1]}" : null, isset($m[4]) ? trim($m[4]) : null];
    }

    /** "25 août 2026 13:42:11 Transaction: T_SSJ3..." -> ['2026-08-25 13:42:11', 'T_SSJ3...']. */
    private function paiement(?string $valeur): array
    {
        if (! $valeur) {
            return [null, null];
        }
        $transaction = preg_match('/Transaction\s*:\s*(\S+)/iu', $valeur, $t) ? $t[1] : null;
        $date = null;
        if (preg_match('/(\d{1,2})\s+(\p{L}+)\s+(\d{4})(?:\s+(\d{2}:\d{2}(?::\d{2})?))?/u', $valeur, $m)) {
            $mois = self::MOIS[strtolower(str_replace(['é', 'û', 'è'], ['e', 'u', 'e'], mb_strtolower($m[2])))] ?? null;
            if ($mois) {
                $date = Carbon::create((int) $m[3], $mois, (int) $m[1])->format('Y-m-d').(isset($m[4]) ? ' '.$m[4] : '');
            }
        }

        return [$date, $transaction];
    }

    /** Ancien gabarit : "Niveau 2025-2026 :" puis la valeur. */
    private function dernierNiveau(string $texte): ?string
    {
        return preg_match('/Niveau\s+\d{4}-\d{4}\s*:\s*\|?\s*([^|]+)/u', $texte, $m) ? trim($m[1]) : null;
    }
}
