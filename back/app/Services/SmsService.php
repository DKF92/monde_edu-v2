<?php

namespace App\Services;

use App\Models\Etablissement;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Envoi de SMS, repris de la V1 (facades/SmsFacade.php::create) :
 * - numeros a 8 chiffres convertis en 10 chiffres, prefixes par l'indicatif
 *   de l'etablissement ;
 * - fournisseurs "letexto" (campagne puis planification) et "bulksmsonline" ;
 * - chaque envoi est historise (sms_messages) et decompte du credit ;
 * - SMS_SIMULATION=true (developpement, tests) : rien n'est envoye ni
 *   decompte, le message est seulement ecrit dans le journal Laravel.
 */
class SmsService
{
    /** Nombre de SMS factures pour un message (160 caracteres par SMS). */
    public static function segments(string $message): int
    {
        return max(1, (int) ceil(mb_strlen($message) / 160));
    }

    public function configure(): bool
    {
        return filled(config('services.sms.fournisseur')) && filled(config('services.sms.url')) && filled(config('services.sms.token'));
    }

    /**
     * @param  array<int, array{numero: string, eleve_id?: int|null}>  $destinataires
     * @return int nombre de SMS envoyes
     */
    public function envoyer(Etablissement $etablissement, array $destinataires, string $message, ?string $campagne, ?int $envoyeParId): int
    {
        if (config('services.sms.simulation')) {
            Log::info('SMS simule (SMS_SIMULATION=true)', [
                'etablissement' => $etablissement->id,
                'numeros' => array_map(fn ($d) => $this->normaliser($d['numero']), $destinataires),
                'message' => $message,
            ]);

            return self::segments($message) * count($destinataires);
        }
        if (! $this->configure()) {
            throw new RuntimeException("Le fournisseur SMS de la plateforme n'est pas encore configuré.");
        }
        if (! $etablissement->sms_actif) {
            throw new RuntimeException("L'envoi de SMS est désactivé pour l'établissement.");
        }
        if (! $etablissement->sms_expediteur) {
            throw new RuntimeException("Renseignez d'abord le nom de l'expéditeur.");
        }

        $parSms = self::segments($message);
        $total = $parSms * count($destinataires);
        if ($etablissement->sms_credit < $total) {
            throw new RuntimeException("Crédit SMS insuffisant : {$total} SMS nécessaires, {$etablissement->sms_credit} restants.");
        }

        $numeros = array_map(fn ($d) => $this->normaliser($d['numero']), $destinataires);
        $erreur = $this->appelerFournisseur($etablissement, $numeros, $message, $campagne ?? 'Monde Educatif');

        DB::transaction(function () use ($etablissement, $destinataires, $numeros, $message, $campagne, $envoyeParId, $parSms, $total, $erreur) {
            foreach ($destinataires as $i => $d) {
                SmsMessage::create([
                    'etablissement_id' => $etablissement->id,
                    'eleve_id' => $d['eleve_id'] ?? null,
                    'numero' => $numeros[$i],
                    'message' => $message,
                    'campagne' => $campagne,
                    'nombre_sms' => $parSms,
                    'statut' => $erreur ? 'echec' : 'envoye',
                    'erreur' => $erreur ? mb_substr($erreur, 0, 255) : null,
                    'envoye_par_id' => $envoyeParId,
                ]);
            }
            if (! $erreur) {
                $etablissement->decrement('sms_credit', $total);
            }
        });

        if ($erreur) {
            throw new RuntimeException("Le fournisseur SMS a refusé l'envoi : {$erreur}");
        }

        return $total;
    }

    /** V1 convertNumber8To10 : anciens numeros ivoiriens a 8 chiffres. */
    public function normaliser(string $numero): string
    {
        $chiffres = preg_replace('/\D/', '', $numero);
        // Operateur deduit du 2e chiffre : 1-3 Moov (01), 4-6 MTN (05), 7-9 Orange (07).
        if (strlen($chiffres) === 8) {
            $chiffres = match (true) {
                in_array($chiffres[1], ['1', '2', '3'], true) => '01',
                in_array($chiffres[1], ['4', '5', '6'], true) => '05',
                in_array($chiffres[1], ['7', '8', '9'], true) => '07',
                default => '',
            }.$chiffres;
        }

        return $chiffres;
    }

    /** @return string|null message d'erreur, null si l'envoi est accepte */
    private function appelerFournisseur(Etablissement $etablissement, array $numeros, string $message, string $campagne): ?string
    {
        $indicatif = $etablissement->indicatif_telephonique ?: '225';
        $url = config('services.sms.url');
        $token = config('services.sms.token');

        try {
            if (config('services.sms.fournisseur') === 'bulksmsonline') {
                $reponse = Http::timeout(30)->withHeaders(['token' => $token])->post($url, [
                    'from' => $etablissement->sms_expediteur,
                    'to' => array_map(fn ($n) => $indicatif.$n, $numeros),
                    'type' => 'Text',
                    'content' => $message,
                ]);

                return $reponse->successful() ? null : 'HTTP '.$reponse->status();
            }

            // letexto : creation de la campagne, puis envoi immediat.
            $campagneCreee = Http::timeout(30)->withToken($token)->post($url, [
                'sender' => $etablissement->sms_expediteur,
                'name' => $campagne,
                'campaignType' => 'SIMPLE',
                'recipientSource' => 'CUSTOM',
                'saveAsModel' => false,
                'destination' => 'NAT_INTER',
                'message' => $message,
                'recipients' => array_map(fn ($n) => ['phone' => $indicatif.$n], $numeros),
                'sendAt' => [],
                'dlrUrl' => config('services.sms.dlr_url'),
                'responseUrl' => config('services.sms.response_url'),
            ]);
            $id = $campagneCreee->json('id');
            if (! $campagneCreee->successful() || ! $id) {
                return 'HTTP '.$campagneCreee->status();
            }
            $envoi = Http::timeout(30)->withToken($token)->post($url.'/'.$id.'/schedules');

            return $envoi->successful() ? null : 'HTTP '.$envoi->status();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
