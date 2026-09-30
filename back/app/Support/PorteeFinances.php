<?php

namespace App\Support;

use App\Models\Tuteur;
use Illuminate\Http\Request;

/**
 * Portee des donnees financieres selon le poste en cours :
 * un parent (poste "parent") ne voit que ses enfants (tuteurs.user_id).
 */
class PorteeFinances
{
    /** @return list<int>|null ids des eleves du parent connecte, null si le poste n'est pas "parent" */
    public static function elevesDuParent(Request $request): ?array
    {
        $poste = $request->hasHeader('X-Poste-Id') ? $request->user()->roles->first() : null;
        if ($poste?->code !== 'parent') {
            return null;
        }

        return Tuteur::where('user_id', $request->user()->id)->with('eleves:id')->get()
            ->flatMap(fn (Tuteur $t) => $t->eleves->pluck('id'))->unique()->values()->all();
    }
}
