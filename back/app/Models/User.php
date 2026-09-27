<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'etablissement_id', 'matricule', 'name', 'prenoms', 'email', 'telephone',
    'sexe', 'photo_path', 'password', 'statut', 'doit_changer_mot_de_passe',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'derniere_connexion_at' => 'datetime',
            'password' => 'hashed',
            'doit_changer_mot_de_passe' => 'boolean',
        ];
    }

    /**
     * Etablissement principal / par defaut de l'utilisateur.
     */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /**
     * Tous les etablissements auxquels cet utilisateur a acces (ex: fondateur
     * d'un groupe scolaire gerant plusieurs etablissements).
     */
    public function etablissements(): BelongsToMany
    {
        return $this->belongsToMany(Etablissement::class)
            ->withPivot('is_defaut')
            ->withTimestamps();
    }

    public function personnel(): HasOne
    {
        return $this->hasOne(Personnel::class);
    }

    public function tuteur(): HasOne
    {
        return $this->hasOne(Tuteur::class);
    }
}
