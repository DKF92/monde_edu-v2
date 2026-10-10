<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Depenses banque (nouveaute V2, pas en V1) : a cote des sorties de caisse
 * (inchangees, V1), des depenses payees par la banque :
 * - banques : comptes bancaires de l'etablissement (Parametres > Banques) ;
 * - depenses.source : 'caisse' (V1) ou 'banque' ; banque_id et
 *   retrait_par_id (personne qui a fait le retrait) pour une depense banque ;
 * - droit depenses.retrait_banque : personnel habilite a faire les retraits
 *   bancaires (liste du formulaire).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('nom', 100);
            $table->string('sigle', 20)->nullable();
            $table->string('numero_compte', 50)->nullable();
            $table->string('agence', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['etablissement_id', 'nom']);
        });

        Schema::table('depenses', function (Blueprint $table) {
            $table->enum('source', ['caisse', 'banque'])->default('caisse')->after('montant')->comment('caisse (V1) ou banque');
            $table->foreignId('banque_id')->nullable()->after('source')->constrained('banques')->restrictOnDelete();
            $table->foreignId('retrait_par_id')->nullable()->after('banque_id')->comment('personne qui a fait le retrait bancaire')
                ->constrained('users')->nullOnDelete();
        });

        $maintenant = now();
        DB::table('permissions')->insertOrIgnore(['name' => 'depenses.retrait_banque', 'guard_name' => 'web', 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        $permissionId = DB::table('permissions')->where('name', 'depenses.retrait_banque')->value('id');
        foreach (DB::table('roles')->whereIn('code', ['fondateur', 'super_admin', 'directeur_administratif', 'econome'])->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retrait_par_id');
            $table->dropConstrainedForeignId('banque_id');
            $table->dropColumn('source');
        });
        Schema::dropIfExists('banques');
        $id = DB::table('permissions')->where('name', 'depenses.retrait_banque')->value('id');
        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
