<?php

namespace App\Console\Commands;

use App\Support\Document;
use Illuminate\Console\Command;

/**
 * Rend un lot d'un gros document PDF (voir Document::pdf) : lance en
 * parallele par la requete web, chaque lot dans son propre processus pour
 * limiter la memoire et le temps.
 */
class RendrePdfLot extends Command
{
    protected $signature = 'pdf:lot {html : page HTML du lot} {pdf : fichier PDF a ecrire} {orientation=portrait}';

    protected $description = 'Rend un lot de document PDF (usage interne)';

    public function handle(): int
    {
        $html = file_get_contents($this->argument('html'));
        file_put_contents($this->argument('pdf'), Document::rendreLot($html, $this->argument('orientation')));

        return self::SUCCESS;
    }
}
