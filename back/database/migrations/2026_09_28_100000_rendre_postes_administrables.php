<?php

use App\Models\Etablissement;
use App\Services\EtablissementProvisioningService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Administration des postes (V1 : table "role" avec is_active, et
 * "role_fonction" pour les droits).
 *
 * - colonnes descriptives sur "roles" (code stable, description, actif,
 *   poste protege, rattachement niveaux/matieres) ;
 * - nouveau droit "utilisateurs.gerer_sensibles" et poste "Super admin" dans
 *   chaque etablissement. Pour que personne ne perde la main sur les comptes
 *   proteges, chaque Fondateur actuel recoit aussi le poste Super admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->after('name')->comment('identifiant stable des postes standard');
            $table->string('description', 255)->nullable()->after('code');
            $table->boolean('is_active')->default(true)->after('description');
            $table->boolean('est_sensible')->default(false)->after('is_active')->comment('poste protege : attribue uniquement par le Super admin');
            $table->boolean('lie_niveaux')->default(false)->after('est_sensible')->comment('rattache a des niveaux (educateur)');
            $table->boolean('lie_matieres')->default(false)->after('lie_niveaux')->comment('enseigne des matieres (professeur)');
            $table->unique(['etablissement_id', 'code']);
        });

        foreach (config('roles.permissions') as $nom) {
            Permission::firstOrCreate(['name' => $nom, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $provisioning = app(EtablissementProvisioningService::class);

        Etablissement::withTrashed()->each(function (Etablissement $etablissement) use ($provisioning) {
            $provisioning->provisionRoles($etablissement);

            $ids = DB::table('roles')->where('etablissement_id', $etablissement->id)->pluck('id', 'code');

            $fondateurs = DB::table('model_has_roles')
                ->where('etablissement_id', $etablissement->id)
                ->where('role_id', $ids['fondateur'] ?? 0)
                ->get(['model_type', 'model_id']);

            foreach ($fondateurs as $f) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $ids['super_admin'],
                    'model_type' => $f->model_type,
                    'model_id' => $f->model_id,
                    'etablissement_id' => $etablissement->id,
                ]);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('roles')->where('code', 'super_admin')->delete();
        Permission::where('name', 'utilisateurs.gerer_sensibles')->delete();

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['etablissement_id', 'code']);
            $table->dropColumn(['code', 'description', 'is_active', 'est_sensible', 'lie_niveaux', 'lie_matieres']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
