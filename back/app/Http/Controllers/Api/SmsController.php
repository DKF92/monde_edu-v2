<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Models\SmsMessage;
use App\Models\SmsRechargement;
use App\Services\SmsService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Parametres > SMS (droit "sms.gerer") : activation, expediteur, credit,
 * rechargements (droit reserve "sms.recharger"), historique et SMS de test.
 */
class SmsController extends Controller
{
    public function show(Request $request, TenantContext $tenant, SmsService $sms)
    {
        $this->autoriser($request);

        return response()->json(['data' => $this->presenter($request, Etablissement::findOrFail($tenant->id()), $sms)]);
    }

    public function update(Request $request, TenantContext $tenant, SmsService $sms)
    {
        $this->autoriser($request);

        $data = $request->validate([
            'sms_actif' => ['required', 'boolean'],
            'sms_expediteur' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9 .\-]+$/', Rule::requiredIf((bool) $request->input('sms_actif'))],
        ], [
            'sms_expediteur.max' => 'L\'expéditeur ne peut pas dépasser 11 caractères (limite des opérateurs).',
            'sms_expediteur.regex' => 'Lettres, chiffres, espaces, points et tirets uniquement (sans accent).',
            'sms_expediteur.required' => 'Indiquez le nom de l\'expéditeur pour activer les SMS.',
        ]);

        $etablissement = Etablissement::findOrFail($tenant->id());
        $etablissement->update($data);

        return response()->json(['data' => $this->presenter($request, $etablissement, $sms)]);
    }

    public function rechargements(Request $request)
    {
        $this->autoriser($request);

        $page = SmsRechargement::with('enregistrePar:id,name,prenoms')
            ->orderByDesc('date_rechargement')
            ->orderByDesc('id')
            ->paginate($this->taille($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (SmsRechargement $r) => [
                'id' => $r->id,
                'date' => $r->date_rechargement->toIso8601String(),
                'montant' => $r->montant,
                'nombre_sms' => $r->nombre_sms,
                'commentaire' => $r->commentaire,
                'enregistre_par' => $r->enregistrePar ? trim($r->enregistrePar->prenoms.' '.$r->enregistrePar->name) : null,
            ]),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'taille' => $page->perPage(),
        ]);
    }

    public function recharger(Request $request, TenantContext $tenant, SmsService $sms)
    {
        abort_unless($request->user()->can('sms.recharger'), 403, 'Seul le Super admin peut enregistrer un rechargement.');

        $data = $request->validate([
            'nombre_sms' => ['required', 'integer', 'min:1', 'max:1000000'],
            'montant' => ['required', 'integer', 'min:0', 'max:100000000'],
            'date_rechargement' => ['nullable', 'date'],
            'commentaire' => ['nullable', 'string', 'max:200'],
        ], ['nombre_sms.min' => 'Au moins 1 SMS.', 'nombre_sms.required' => 'Indiquez le nombre de SMS achetés.']);

        $etablissement = DB::transaction(function () use ($data, $tenant, $request) {
            SmsRechargement::create($data + [
                'date_rechargement' => $data['date_rechargement'] ?? now(),
                'enregistre_par_id' => $request->user()->id,
            ]);
            $etablissement = Etablissement::lockForUpdate()->findOrFail($tenant->id());
            $etablissement->increment('sms_credit', $data['nombre_sms']);

            return $etablissement->fresh();
        });

        return response()->json(['data' => $this->presenter($request, $etablissement, $sms)], 201);
    }

    public function messages(Request $request)
    {
        $this->autoriser($request);

        $filtres = $request->validate([
            'recherche' => ['nullable', 'string', 'max:100'],
            'statut' => ['nullable', Rule::in(['envoye', 'echec'])],
        ]);

        $page = SmsMessage::with(['eleve:id,nom,prenoms,matricule', 'envoyePar:id,name,prenoms'])
            ->when($filtres['recherche'] ?? null, fn ($q, $r) => $q->where(fn ($q) => $q
                ->where('numero', 'like', "%{$r}%")
                ->orWhere('message', 'like', "%{$r}%")
                ->orWhere('campagne', 'like', "%{$r}%")))
            ->when($filtres['statut'] ?? null, fn ($q, $s) => $q->where('statut', $s))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->taille($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (SmsMessage $m) => [
                'id' => $m->id,
                'date' => $m->created_at->toIso8601String(),
                'numero' => $m->numero,
                'message' => $m->message,
                'campagne' => $m->campagne,
                'nombre_sms' => $m->nombre_sms,
                'statut' => $m->statut,
                'erreur' => $m->erreur,
                'eleve' => $m->eleve ? trim($m->eleve->nom.' '.$m->eleve->prenoms) : null,
                'envoye_par' => $m->envoyePar ? trim($m->envoyePar->prenoms.' '.$m->envoyePar->name) : null,
            ]),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'taille' => $page->perPage(),
        ]);
    }

    /** SMS de test vers un numero, pour verifier expediteur et fournisseur. */
    public function tester(Request $request, TenantContext $tenant, SmsService $sms)
    {
        $this->autoriser($request);

        $data = $request->validate([
            'numero' => ['required', 'regex:/^[\d\s.]{8,14}$/'],
            'message' => ['required', 'string', 'max:480'],
        ], ['numero.regex' => 'Numéro de 8 ou 10 chiffres attendu.', 'message.required' => 'Écrivez le message.']);

        $etablissement = Etablissement::findOrFail($tenant->id());
        try {
            $envoyes = $sms->envoyer($etablissement, [['numero' => $data['numero']]], $data['message'], 'Test', $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "SMS envoyé ({$envoyes} SMS décompté".($envoyes > 1 ? 's' : '').').',
            'data' => $this->presenter($request, $etablissement->fresh(), $sms),
        ]);
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('sms.gerer'), 403);
    }

    private function taille(Request $request): int
    {
        return in_array($request->integer('taille'), [10, 20, 50], true) ? $request->integer('taille') : 10;
    }

    private function presenter(Request $request, Etablissement $e, SmsService $sms): array
    {
        return [
            'sms_actif' => $e->sms_actif,
            'sms_expediteur' => $e->sms_expediteur,
            'sms_credit' => $e->sms_credit,
            'indicatif_telephonique' => $e->indicatif_telephonique,
            'fournisseur_configure' => $sms->configure(),
            'peut_recharger' => $request->user()->can('sms.recharger'),
            'envoyes_ce_mois' => (int) SmsMessage::where('statut', 'envoye')->where('created_at', '>=', now()->startOfMonth())->sum('nombre_sms'),
        ];
    }
}
