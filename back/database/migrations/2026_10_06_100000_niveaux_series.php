<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decision E1 (modele V1) : le NIVEAU porte la serie au 2nd cycle (2NDE A,
 * 1ERE D, TLE A2...) et pointe vers son NIVEAU GENERAL (2NDE, 1ERE, TLE).
 * Au 1er cycle, le niveau est son propre niveau general (niveau_general_id
 * null). Classes, inscriptions, coefficients et limites d'effectif : niveau ;
 * grilles de tarifs, niveaux des educateurs, inscription en ligne : niveau
 * general.
 *
 * Donnees existantes : series creees a partir des libelles de classes
 * (« TLE A2 1 » -> TLE A2) et de la serie V1 gardee par la reprise
 * (migration_v1_correspondances) ; coefficients et limites recopies du
 * niveau general vers ses series.
 */
return new class extends Migration
{
    private const SERIES = ['2NDE' => ['A', 'C'], '1ERE' => ['A', 'C', 'D'], 'TLE' => ['A1', 'A2', 'C', 'D']];

    public function up(): void
    {
        Schema::table('niveaux', function (Blueprint $table) {
            $table->foreignId('niveau_general_id')->nullable()->after('id')
                ->comment('niveau general (2NDE, 1ERE, TLE) d\'un niveau avec serie ; null = niveau general lui-meme')
                ->constrained('niveaux')->nullOnDelete();
        });

        $generaux = DB::table('niveaux')->whereIn('code', array_keys(self::SERIES))->get()->keyBy('code');
        if ($generaux->isEmpty()) {
            return;
        }

        // Series : standard + celles des classes existantes + celles de la reprise V1.
        $series = self::SERIES;
        $motif = '/^(2NDE|1ERE|TLE)\s+([A-Z][0-9]?)(?:\s+\d+)?$/';
        foreach (DB::table('classes')->pluck('libelle') as $libelle) {
            if (preg_match($motif, strtoupper(trim($libelle)), $m)) {
                $series[$m[1]][] = $m[2];
            }
        }
        $correspondances = Schema::hasTable('migration_v1_correspondances');
        if ($correspondances) {
            foreach (DB::table('migration_v1_correspondances')->where('table_v2', 'inscriptions')->whereNotNull('infos')->pluck('infos') as $infos) {
                $i = json_decode($infos, true);
                if (! empty($i['serie']) && preg_match('/^(2nde|1ere|tle)/i', (string) ($i['niveau_v1'] ?? ''), $m)) {
                    $series[strtoupper($m[1])][] = strtoupper($i['serie']);
                }
            }
        }

        $ids = [];
        foreach ($series as $code => $liste) {
            $general = $generaux[$code] ?? null;
            if (! $general) {
                continue;
            }
            foreach (array_unique($liste) as $serie) {
                $libelle = $code.' '.$serie;
                $ids[$libelle] = DB::table('niveaux')->where('libelle', $libelle)->value('id')
                    ?? DB::table('niveaux')->insertGetId([
                        'niveau_general_id' => $general->id, 'code' => $code.$serie, 'libelle' => $libelle,
                        'cycle' => $general->cycle, 'ordre' => $general->ordre, 'created_at' => now(), 'updated_at' => now(),
                    ]);
            }
        }

        // Classes : serie lue dans le libelle.
        foreach (DB::table('classes')->get(['id', 'libelle']) as $classe) {
            if (preg_match($motif, strtoupper(trim($classe->libelle)), $m) && isset($ids[$m[1].' '.$m[2]])) {
                DB::table('classes')->where('id', $classe->id)->update(['niveau_id' => $ids[$m[1].' '.$m[2]]]);
            }
        }

        // Inscriptions : niveau de la classe, sinon serie V1 de la reprise.
        DB::statement('UPDATE inscriptions i JOIN classes c ON c.id = i.classe_id SET i.niveau_id = c.niveau_id WHERE i.niveau_id <> c.niveau_id');
        if ($correspondances) {
            $parGeneral = $generaux->mapWithKeys(fn ($g) => [$g->id => $g->code]);
            DB::table('migration_v1_correspondances as m')->join('inscriptions as i', 'i.id', '=', 'm.id_v2')
                ->where('m.table_v2', 'inscriptions')->whereNull('i.classe_id')->whereIn('i.niveau_id', $parGeneral->keys())
                ->whereNotNull('m.infos')->select('i.id', 'i.niveau_id', 'm.infos')->orderBy('i.id')
                ->chunk(2000, function ($lignes) use ($parGeneral, $ids) {
                    foreach ($lignes as $l) {
                        $serie = json_decode($l->infos, true)['serie'] ?? null;
                        $cle = $parGeneral[$l->niveau_id].' '.strtoupper((string) $serie);
                        if ($serie && isset($ids[$cle])) {
                            DB::table('inscriptions')->where('id', $l->id)->update(['niveau_id' => $ids[$cle]]);
                        }
                    }
                });
        }

        // Coefficients et limites d'effectif : recopies du niveau general vers ses series.
        foreach (DB::table('niveaux')->whereNotNull('niveau_general_id')->get(['id', 'niveau_general_id']) as $serie) {
            foreach (DB::table('matiere_niveau')->where('niveau_id', $serie->niveau_general_id)->get() as $coef) {
                DB::table('matiere_niveau')->insertOrIgnore([
                    'matiere_id' => $coef->matiere_id, 'niveau_id' => $serie->id, 'coefficient' => $coef->coefficient,
                    'is_obligatoire' => $coef->is_obligatoire, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (DB::table('limites_effectifs_niveaux')->where('niveau_id', $serie->niveau_general_id)->get() as $limite) {
                DB::table('limites_effectifs_niveaux')->insertOrIgnore([
                    'etablissement_id' => $limite->etablissement_id, 'niveau_id' => $serie->id,
                    'effectif_max' => $limite->effectif_max, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $series = DB::table('niveaux')->whereNotNull('niveau_general_id')->pluck('niveau_general_id', 'id');
        foreach ($series as $id => $general) {
            DB::table('classes')->where('niveau_id', $id)->update(['niveau_id' => $general]);
            DB::table('inscriptions')->where('niveau_id', $id)->update(['niveau_id' => $general]);
            DB::table('matiere_niveau')->where('niveau_id', $id)->delete();
            DB::table('limites_effectifs_niveaux')->where('niveau_id', $id)->delete();
        }
        DB::table('niveaux')->whereIn('id', $series->keys())->delete();
        Schema::table('niveaux', function (Blueprint $table) {
            $table->dropConstrainedForeignId('niveau_general_id');
        });
    }
};
