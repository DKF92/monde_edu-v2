<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['user_id', 'nom', 'prenoms', 'telephone1', 'telephone2', 'email', 'profession', 'adresse'])]
class Tuteur extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function eleves(): BelongsToMany
    {
        return $this->belongsToMany(Eleve::class, 'eleve_tuteur')
            ->withPivot('lien_parente', 'is_contact_principal', 'is_payeur')
            ->withTimestamps();
    }
}
