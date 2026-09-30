<?php

namespace App\Support;

use App\Models\AnneeScolaire;
use App\Models\Etablissement;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Reader\Html as LecteurHtml;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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

    public static function pdf(string $html, string $orientation = 'portrait'): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return $dompdf->output();
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
