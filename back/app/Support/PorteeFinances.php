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
    /**
     * Montants de l'ecole (caisse, recettes, restes a payer) : reserves aux
     * postes financiers, comme en V1 (le directeur des etudes, l'informaticien
     * ou le correspondant fichier ont des rapports, mais sans les finances).
     */
    public static function voitMontants(Request $request): bool
    {
        $user = $request->user();

        return $user->can('reglements.voir') || $user->can('depenses.voir');
    }

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
