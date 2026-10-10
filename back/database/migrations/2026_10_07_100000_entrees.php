<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Entrees (nouveaute V2, pas en V1), pendant des depenses :
 * - entrees caisse = les paiements des eleves (table reglements) : rien a
 *   saisir, la page Entrees en donne le total par caissier ;
 * - entrees banque = argent qui arrive sur un compte de l'etablissement
 *   (versement de la caisse, subvention, don...) : table « entrees », la
 *   banque (memes banques que les depenses) tient la place du beneficiaire ;
 * - entrees_historique : modification / suppression avec motif obligatoire ;
 * - droits parametrables : entrees.gerer (enregistrer, voit ses entrees),
 *   entrees.voir (toutes les entrees et le point), entrees.modifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entrees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('annee_scolaire_id')->nullable()->constrained('annees_scolaires')->nullOnDelete();
            $table->string('numero', 30)->nullable()->comment('E2627-00001');
            $table->date('date_entree');
            $table->foreignId('banque_id')->constrained('banques')->restrictOnDelete()->comment('banque qui recoit (tient lieu de beneficiaire)');
            $table->string('categorie', 100);
            $table->string('libelle', 300)->comment('objet');
            $table->decimal('montant', 12, 0);
            $table->enum('mode_paiement', ['especes', 'mobile_money', 'cheque', 'virement'])->default('especes');
            $table->string('provenance', 150)->nullable()->comment('qui verse : caisse, Etat, APE, donateur...');
            $table->foreignId('verse_par_id')->nullable()->comment('personne qui a fait le versement')->constrained('users')->nullOnDelete();
            $table->string('piece_comptable', 100)->nullable();
            $table->string('piece_justificative_path')->nullable();
            $table->foreignId('saisi_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['etablissement_id', 'numero']);
            $table->index(['etablissement_id', 'date_entree']);
        });

        Schema::create('entrees_historique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            // Pas de cle etrangere : l'entree supprimee n'existe plus.
            $table->unsignedBigInteger('entree_id')->index();
            $table->string('numero', 30)->nullable();
            $table->enum('action', ['modification', 'suppression']);
            $table->string('motif', 500);
            $table->json('avant');
            $table->json('apres')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $maintenant = now();
        $droits = [
            'entrees.voir' => ['fondateur', 'super_admin', 'directeur_administratif', 'comptable', 'econome'],
            'entrees.gerer' => ['fondateur', 'super_admin', 'directeur_administratif', 'comptable', 'econome'],
            'entrees.modifier' => ['fondateur', 'super_admin', 'econome'],
        ];
        foreach ($droits as $nom => $postes) {
            DB::table('permissions')->insertOrIgnore(['name' => $nom, 'guard_name' => 'web', 'created_at' => $maintenant, 'updated_at' => $maintenant]);
            $permissionId = DB::table('permissions')->where('name', $nom)->value('id');
            foreach (DB::table('roles')->whereIn('code', $postes)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('entrees_historique');
        Schema::dropIfExists('entrees');
        foreach (['entrees.voir', 'entrees.gerer', 'entrees.modifier'] as $nom) {
            $id = DB::table('permissions')->where('name', $nom)->value('id');
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
