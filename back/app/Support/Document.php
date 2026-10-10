<?php

namespace App\Support;

use App\Models\AnneeScolaire;
use App\Models\Etablissement;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\Html as LecteurHtml;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documents generes a la demande (rien n'est stocke), a partir d'une vue
 * Blade, dans trois formats :
 * - pdf  : dompdf (portrait ou paysage) ;
 * - doc  : la meme page HTML ouverte par Word (mise en page conservee) ;
 * - xlsx : les tableaux de la page lus par PhpSpreadsheet (vrai classeur Excel).
 */
class Document
{
    public const FORMATS = ['pdf', 'doc', 'xlsx'];

    /** En-tete commun : logo (data URI) et contacts de l'etablissement. */
    public static function entete(Etablissement $etablissement): array
    {
        $logo = null;
        if ($etablissement->logo_path && Storage::disk('public')->exists($etablissement->logo_path)) {
            $chemin = Storage::disk('public')->path($etablissement->logo_path);
            $logo = 'data:'.(mime_content_type($chemin) ?: 'image/png').';base64,'.base64_encode(file_get_contents($chemin));
        }

        $anneeId = app(TenantContext::class)->anneeScolaireId()
            ?? AnneeScolaire::where('etablissement_id', $etablissement->id)->where('is_active', true)->value('id');

        return [
            'etablissement' => $etablissement,
            'logo' => $logo,
            // Gauche de l'en-tete (ministere, direction regionale, adresse, telephone) et annee a droite.
            'tutelle' => $etablissement->enteteTutelle(),
            'anneeEntete' => $anneeId ? AnneeScolaire::withoutGlobalScopes()->whereKey($anneeId)->value('libelle') : null,
            'contacts' => collect([$etablissement->boite_postale, $etablissement->ville, $etablissement->telephone1, $etablissement->telephone2, $etablissement->email])
                ->filter()->implode(' · '),
        ];
    }

    /**
     * Tailles d'ecriture des PDF alignees sur les documents de la V1 (demande
     * du 04/10/2026, remplace la reduction de 0.9 du 30/09) : quatre tailles
     * seulement, quelle que soit la taille ecrite dans la vue.
     * - moins de 6.5 pt : gardee (en-tete officiel : 6 pt et 5 pt) ;
     * - texte et tableaux : 7 pt ; informations : 8 pt ; titres : 10 pt.
     */
    public static function taillePolice(float $taille): float
    {
        return match (true) {
            $taille < 6.5 => $taille,
            $taille < 7.75 => 7,
            $taille < 9.75 => 8,
            default => 10,
        };
    }

    /** Au-dela de ce nombre de lignes dans un tableau, le PDF est rendu par lots. */
    private const LIGNES_PAR_LOT = 250;

    /** Lots rendus en meme temps (un processus chacun). */
    private const PROCESSUS = 4;

    /**
     * PDF de la page. Une grosse liste (ex. reste a payer de tout
     * l'etablissement) est decoupee en lots de lignes rendus en parallele
     * puis assembles : dompdf garde tout le document en memoire (1,6 Go et
     * plus d'une minute pour 1 500 eleves en un seul rendu).
     */
    public static function pdf(string $html, string $orientation = 'portrait'): string
    {
        $html = preg_replace_callback('/font-size:\s*([\d.]+)pt/', fn ($m) => 'font-size: '.self::taillePolice((float) $m[1]).'pt', $html);
        $lots = self::lots($html);
        if (count($lots) > 1) {
            return self::assembler(self::rendreLots($lots, $orientation), $html);
        }

        $dompdf = self::dompdf($html, $orientation);
        self::piedDePage($dompdf, $html);

        return $dompdf->output();
    }

    /** Un lot, sans pied de page (ajoute a l'assemblage) : commande pdf:lot. */
    public static function rendreLot(string $html, string $orientation): string
    {
        return self::dompdf($html, $orientation)->output();
    }

    private static function dompdf(string $html, string $orientation): Dompdf
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return $dompdf;
    }

    /**
     * Decoupe la page sur le plus grand tableau : chaque lot reprend l'en-tete
     * du tableau (thead), le premier lot garde ce qui precede le tableau, le
     * dernier ce qui le suit. Coupure de preference avant une ligne de groupe
     * (class « classe »).
     *
     * @return list<string>
     */
    private static function lots(string $html): array
    {
        if (! preg_match_all('#<tbody[^>]*>#', $html, $ouvertures, PREG_OFFSET_CAPTURE)) {
            return [$html];
        }
        $plusGrand = null;
        foreach ($ouvertures[0] as [$balise, $position]) {
            $debut = $position + strlen($balise);
            $fin = strpos($html, '</tbody>', $debut);
            $corps = $fin === false ? '' : substr($html, $debut, $fin - $debut);
            $lignes = preg_match_all('#<tr[\s>]#', $corps);
            if ($fin !== false && ! str_contains($corps, '<table') && $lignes > ($plusGrand['lignes'] ?? 0)) {
                $plusGrand = compact('debut', 'fin', 'corps', 'lignes');
            }
        }
        if (! $plusGrand || $plusGrand['lignes'] <= self::LIGNES_PAR_LOT * 1.3) {
            return [$html];
        }
        $table = strrpos(substr($html, 0, $plusGrand['debut']), '<table');
        $finTable = strpos($html, '</table>', $plusGrand['fin']);
        if ($table === false || $finTable === false || ! preg_match('#<body[^>]*>#', $html, $b, PREG_OFFSET_CAPTURE) || $b[0][1] > $table) {
            return [$html];
        }
        $body = $b[0][1] + strlen($b[0][0]);
        $finTable += strlen('</table>');
        $finBody = strrpos($html, '</body>') ?: strlen($html);
        $tete = substr($html, 0, $body);
        $avant = substr($html, $body, $table - $body);
        $ouverture = substr($html, $table, $plusGrand['debut'] - $table);
        $fermeture = substr($html, $plusGrand['fin'], $finTable - $plusGrand['fin']);
        $suite = substr($html, $finTable, max(0, $finBody - $finTable));

        $lignes = preg_split('#(?=<tr[\s>])#', $plusGrand['corps'], -1, PREG_SPLIT_NO_EMPTY);
        $groupe = fn (string $l) => (bool) preg_match('#^<tr[^>]*class="[^"]*\bclasse\b#', $l);
        $avecGroupes = (bool) array_filter($lignes, $groupe);
        $paquets = [[]];
        foreach ($lignes as $ligne) {
            $n = count($paquets[array_key_last($paquets)]);
            $couper = $avecGroupes
                ? ($n >= self::LIGNES_PAR_LOT * 0.6 && $groupe($ligne)) || $n >= self::LIGNES_PAR_LOT * 1.4
                : $n >= self::LIGNES_PAR_LOT;
            if ($couper && str_starts_with($ligne, '<tr')) {
                $paquets[] = [];
            }
            $paquets[array_key_last($paquets)][] = $ligne;
        }
        $dernier = count($paquets) - 1;

        return array_map(fn ($paquet, $i) => $tete.($i === 0 ? $avant : '').$ouverture.implode('', $paquet).$fermeture
            .($i === $dernier ? $suite : '').'</body></html>', $paquets, array_keys($paquets));
    }

    /**
     * Rend les lots dans des processus paralleles (commande pdf:lot) ; un lot
     * en echec est rendu ici.
     *
     * @param  list<string>  $lots
     * @return list<string>
     */
    private static function rendreLots(array $lots, string $orientation): array
    {
        $dossier = storage_path('app/private/pdf-lots/'.Str::uuid());
        File::ensureDirectoryExists($dossier);
        try {
            $attente = array_keys($lots);
            $actifs = [];
            while ($attente || $actifs) {
                while ($attente && count($actifs) < self::PROCESSUS) {
                    $i = array_shift($attente);
                    file_put_contents("$dossier/$i.html", $lots[$i]);
                    try {
                        $actifs[$i] = new Process([ArrierePlan::php(), '-d', 'memory_limit=1G', base_path('artisan'), 'pdf:lot', "$dossier/$i.html", "$dossier/$i.pdf", $orientation], base_path(), null, null, 600);
                        $actifs[$i]->start();
                    } catch (\Throwable) {
                        // Processus interdits (proc_open) : le lot sera rendu ici, apres les autres.
                        unset($actifs[$i]);
                    }
                }
                foreach ($actifs as $i => $processus) {
                    if (! $processus->isRunning()) {
                        unset($actifs[$i]);
                    }
                }
                usleep(50_000);
            }

            return array_map(fn ($i) => is_file("$dossier/$i.pdf") && filesize("$dossier/$i.pdf") > 0
                ? file_get_contents("$dossier/$i.pdf")
                : self::rendreLot($lots[$i], $orientation), array_keys($lots));
        } finally {
            File::deleteDirectory($dossier);
        }
    }

    /** Pages des lots les unes a la suite, puis le pied de page (meme contenu que piedDePage). */
    private static function assembler(array $pdfs, string $html): string
    {
        $pied = self::pied($html);
        $sortie = new Fpdi();
        $sortie->SetAutoPageBreak(false);
        $sources = array_map(fn ($pdf) => StreamReader::createByString($pdf), $pdfs);
        $total = array_sum(array_map(fn ($s) => (new Fpdi())->setSourceFile($s), $sources));
        $page = 0;
        foreach ($sources as $source) {
            $nombre = $sortie->setSourceFile($source);
            for ($p = 1; $p <= $nombre; $p++) {
                $modele = $sortie->importPage($p);
                $taille = $sortie->getTemplateSize($modele);
                $sortie->AddPage($taille['orientation'], [$taille['width'], $taille['height']]);
                $sortie->useTemplate($modele);
                $page++;
                if ($pied) {
                    [$gauche, $cote] = $pied;
                    $largeur = $taille['width'];
                    $y = $taille['height'] - 5.8;
                    $sortie->SetDrawColor(217, 222, 234);
                    $sortie->SetLineWidth(0.18);
                    $sortie->Line($cote, $y - 3, $largeur - $cote, $y - 3);
                    $sortie->SetFont('Helvetica', '', 7);
                    $sortie->SetTextColor(87, 97, 122);
                    $sortie->Text($cote, $y, (string) iconv('UTF-8', 'windows-1252//TRANSLIT', $gauche));
                    $droite = "Page $page / $total";
                    $sortie->Text($largeur - $cote - $sortie->GetStringWidth($droite), $y, $droite);
                }
            }
        }

        return $sortie->Output('S');
    }

    /**
     * Texte de gauche du pied (en-tete commun _entete-caisse, commentaire
     * pied-document) et marge laterale en mm (derniere regle @page de la vue).
     *
     * @return array{0: string, 1: float}|null
     */
    private static function pied(string $html): ?array
    {
        if (! preg_match('/<!--pied-document:([A-Za-z0-9+\/=]*)-->/', $html, $m)) {
            return null;
        }
        $cote = 12;
        if (preg_match_all('/@page\s*\{\s*margin:\s*([\d.]+)mm(?:\s+([\d.]+)mm)?/', $html, $p)) {
            $dernier = count($p[1]) - 1;
            $cote = (float) ($p[2][$dernier] !== '' ? $p[2][$dernier] : $p[1][$dernier]);
        }

        return [(string) base64_decode($m[1]), $cote];
    }

    /**
     * Pied de chaque page (demande du 05/10/2026) : nom de l'ecole et annee
     * scolaire a gauche, « Page n / N » a droite, dans la marge du bas et
     * alignes sur les marges laterales de la vue.
     */
    private static function piedDePage(Dompdf $dompdf, string $html): void
    {
        if (! $pied = self::pied($html)) {
            return;
        }
        [$gauche, $cote] = $pied;
        $cote *= 72 / 25.4;

        $dompdf->getCanvas()->page_script(function (int $page, int $total, $canvas, $metriques) use ($gauche, $cote) {
            $police = $metriques->getFont('DejaVu Sans');
            $taille = 7;
            $gris = [0.34, 0.38, 0.48];
            $largeur = $canvas->get_width();
            $y = $canvas->get_height() - 22;
            $canvas->line($cote, $y - 3, $largeur - $cote, $y - 3, [0.85, 0.87, 0.92], 0.5);
            $canvas->text($cote, $y, $gauche, $police, $taille, $gris);
            $droite = 'Page '.$page.' / '.$total;
            $canvas->text($largeur - $cote - $metriques->getTextWidth($droite, $police, $taille), $y, $droite, $police, $taille, $gris);
        });
    }

    /**
     * @param  string  $nom  nom du fichier sans extension
     */
    public static function repondre(string $vue, array $donnees, string $nom, ?string $format, string $orientation = 'portrait'): Response
    {
        $format = in_array($format, self::FORMATS, true) ? $format : 'pdf';
        $html = view($vue, $donnees + ['format' => $format])->render();

        [$contenu, $type, $extension] = match ($format) {
            'doc' => [self::word($html, $orientation), 'application/msword', 'doc'],
            'xlsx' => [self::excel($html), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
            default => [self::pdf($html, $orientation), 'application/pdf', 'pdf'],
        };

        return response($contenu, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => ($format === 'pdf' ? 'inline' : 'attachment').'; filename="'.$nom.'.'.$extension.'"',
            // Orientation lue par la visionneuse (modale plus large en paysage).
            'X-Orientation' => $orientation,
            'Access-Control-Expose-Headers' => 'Content-Disposition, X-Orientation',
        ]);
    }

    /** Page HTML lisible par Word : en-tetes Office et orientation de la page. */
    private static function word(string $html, string $orientation): string
    {
        $page = $orientation === 'landscape'
            ? '@page Section1 { size: 841.9pt 595.3pt; mso-page-orientation: landscape; margin: 36pt; }'
            : '@page Section1 { size: 595.3pt 841.9pt; margin: 42pt; }';
        $office = '<meta name="ProgId" content="Word.Document"><style>'.$page.' div.Section1 { page: Section1; } .saut { page-break-before: always; }</style>';
        $html = str_replace('</head>', $office.'</head>', $html);
        $html = preg_replace('/<body([^>]*)>/', '<body$1><div class="Section1">', $html, 1);
        $html = str_replace('</body>', '</div></body>', $html);

        return str_replace('<html', '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word"', $html);
    }

    /** Classeur Excel : les tableaux de la page, les uns sous les autres. */
    private static function excel(string $html): string
    {
        // Les images (logo) et styles d'impression n'ont pas de sens dans un classeur.
        $html = preg_replace('/<img[^>]*>/i', '', $html);
        $classeur = (new LecteurHtml())->loadFromString($html);
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Document');
        foreach ($feuille->getColumnIterator() as $colonne) {
            $feuille->getColumnDimension($colonne->getColumnIndex())->setAutoSize(true);
        }
        $flux = fopen('php://memory', 'r+');
        (new Xlsx($classeur))->save($flux);
        rewind($flux);
        $contenu = stream_get_contents($flux);
        fclose($flux);

        return $contenu;
    }
}
