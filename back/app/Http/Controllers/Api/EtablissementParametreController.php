<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Parametres > Etablissement (droit "etablissement.gerer") : identite,
 * coordonnees et logo imprimes sur les documents (V1 info_etablissement).
 * Le code, le statut et l'abonnement sont geres par la plateforme : lecture seule.
 */
class EtablissementParametreController extends Controller
{
    public const ENTETE = ['entete_ministere', 'entete_direction', 'entete_adresse', 'entete_telephone'];

    public const TYPES = ['maternelle', 'primaire', 'college', 'lycee', 'college_lycee', 'superieur', 'mixte'];

    public function show(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        return response()->json(['data' => $this->presenter(Etablissement::findOrFail($tenant->id()))]);
    }

    public function update(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        $data = $request->validate([
            'nom' => ['required', 'string', 'max:300'],
            'sigle' => ['nullable', 'string', 'max:30'],
            'type_etablissement' => ['required', Rule::in(self::TYPES)],
            'slogan' => ['nullable', 'string', 'max:300'],
            'telephone1' => ['nullable', 'string', 'max:20'],
            'telephone2' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'site_web' => ['nullable', 'string', 'max:255'],
            'boite_postale' => ['nullable', 'string', 'max:30'],
            'quartier' => ['nullable', 'string', 'max:150'],
            'ville' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'pays' => ['required', 'string', 'max:100'],
            'indicatif_telephonique' => ['required', 'regex:/^\d{1,4}$/'],
            'entete_ministere' => ['nullable', 'string', 'max:200'],
            'entete_direction' => ['nullable', 'string', 'max:200'],
            'entete_adresse' => ['nullable', 'string', 'max:150'],
            'entete_telephone' => ['nullable', 'string', 'max:60'],
        ], [
            'nom.required' => 'Le nom de l\'établissement est obligatoire.',
            'email.email' => 'Cette adresse e-mail n\'est pas valide.',
            'indicatif_telephonique.regex' => 'Indicatif en chiffres, sans + (ex : 225).',
        ]);

        $etablissement = Etablissement::findOrFail($tenant->id());
        // En-tete des documents : dans les parametres (vide = valeur par defaut).
        $entete = array_intersect_key($data, array_flip(self::ENTETE));
        $etablissement->update(array_diff_key($data, $entete));
        $etablissement->definirParametres(array_merge(array_fill_keys(self::ENTETE, null), $entete));

        return response()->json(['data' => $this->presenter($etablissement)]);
    }

    public function enregistrerLogo(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        $request->validate(
            ['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']],
            ['logo.max' => 'Le logo ne doit pas dépasser 2 Mo.', 'logo.image' => 'Choisissez une image (PNG, JPG ou WEBP).', 'logo.mimes' => 'Choisissez une image (PNG, JPG ou WEBP).']
        );

        $etablissement = Etablissement::findOrFail($tenant->id());
        $ancien = $etablissement->logo_path;
        $etablissement->update(['logo_path' => $request->file('logo')->store('logos', 'public')]);
        if ($ancien) {
            Storage::disk('public')->delete($ancien);
        }

        return response()->json(['data' => $this->presenter($etablissement)]);
    }

    public function supprimerLogo(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        $etablissement = Etablissement::findOrFail($tenant->id());
        if ($etablissement->logo_path) {
            Storage::disk('public')->delete($etablissement->logo_path);
            $etablissement->update(['logo_path' => null]);
        }

        return response()->json(['data' => $this->presenter($etablissement)]);
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('etablissement.gerer'), 403);
    }

    private function presenter(Etablissement $e): array
    {
        return [
            'code' => $e->code,
            'nom' => $e->nom,
            'sigle' => $e->sigle,
            'type_etablissement' => $e->type_etablissement,
            'slogan' => $e->slogan,
            'telephone1' => $e->telephone1,
            'telephone2' => $e->telephone2,
            'email' => $e->email,
            'site_web' => $e->site_web,
            'boite_postale' => $e->boite_postale,
            'quartier' => $e->quartier,
            'ville' => $e->ville,
            'region' => $e->region,
            'pays' => $e->pays,
            'indicatif_telephonique' => $e->indicatif_telephonique,
            'entete_ministere' => $e->parametre('entete_ministere'),
            'entete_direction' => $e->parametre('entete_direction'),
            'entete_adresse' => $e->parametre('entete_adresse'),
            'entete_telephone' => $e->parametre('entete_telephone'),
            // Adresse construite sur l'hote de la requete (fonctionne aussi depuis
            // un telephone du reseau local, contrairement a APP_URL).
            'logo_url' => $e->logo_path ? url('storage/'.$e->logo_path) : null,
            'statut' => $e->statut,
            'date_expiration_abonnement' => $e->date_expiration_abonnement?->toDateString(),
        ];
    }
}
