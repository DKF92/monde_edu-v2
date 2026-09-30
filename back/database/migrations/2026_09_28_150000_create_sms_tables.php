<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * SMS aux parents, iso V1 :
 * - info_etablissement.is_used_sms / sms_sender / number_sms -> colonnes
 *   sms_actif / sms_expediteur / sms_credit de etablissements ;
 * - rechargement_sms -> sms_rechargements (achats de credit) ;
 * - sms -> sms_messages (historique des envois).
 * Le fournisseur (V1 parametre_global) est commun a la plateforme : config/services.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->boolean('sms_actif')->default(false)->after('indicatif_telephonique');
            $table->string('sms_expediteur', 11)->nullable()->after('sms_actif')->comment('nom affiche comme expediteur (11 caracteres max)');
            $table->unsignedInteger('sms_credit')->default(0)->after('sms_expediteur')->comment('SMS restants');
        });

        Schema::create('sms_rechargements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->dateTime('date_rechargement');
            $table->decimal('montant', 12, 0)->default(0);
            $table->unsignedInteger('nombre_sms');
            $table->string('commentaire', 200)->nullable();
            $table->foreignId('enregistre_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('eleve_id')->nullable()->constrained('eleves')->nullOnDelete();
            $table->string('numero', 20);
            $table->text('message');
            $table->string('campagne', 60)->nullable();
            $table->unsignedTinyInteger('nombre_sms')->default(1)->comment('segments de 160 caracteres decomptes du credit');
            $table->enum('statut', ['envoye', 'echec'])->default('envoye');
            $table->string('erreur', 255)->nullable();
            $table->foreignId('envoye_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['etablissement_id', 'created_at']);
        });

        // Nouveaux droits : SMS (Fondateur et Super admin), recharge (Super admin).
        $gerer = Permission::firstOrCreate(['name' => 'sms.gerer', 'guard_name' => 'web']);
        $recharger = Permission::firstOrCreate(['name' => 'sms.recharger', 'guard_name' => 'web']);
        foreach (DB::table('roles')->whereIn('code', ['fondateur', 'super_admin'])->get() as $role) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $gerer->id, 'role_id' => $role->id]);
            if ($role->code === 'super_admin') {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $recharger->id, 'role_id' => $role->id]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', ['sms.gerer', 'sms.recharger'])->delete();
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_rechargements');
        Schema::table('etablissements', function (Blueprint $table) {
            $table->dropColumn(['sms_actif', 'sms_expediteur', 'sms_credit']);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
