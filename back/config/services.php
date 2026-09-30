<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    /*
     * Fournisseur SMS de la plateforme (V1 : table parametre_global), commun a
     * tous les etablissements. Chaque etablissement a son expediteur et son
     * credit (voir Parametres > SMS).
     * fournisseur : letexto | bulksmsonline (vide = envoi desactive).
     */
    'sms' => [
        'fournisseur' => env('SMS_FOURNISSEUR'),
        'url' => env('SMS_URL'),
        'token' => env('SMS_TOKEN'),
        'dlr_url' => env('SMS_DLR_URL'),
        'response_url' => env('SMS_RESPONSE_URL'),
        // true en developpement : aucun SMS reel (journal seulement).
        'simulation' => env('SMS_SIMULATION', false),
    ],

    /*
     * Inscription en ligne sur le site de l'Etat (recu de preinscription SIGFNE),
     * voir App\Services\InscriptionEnLigneService.
     */
    'inscription_en_ligne' => [
        'url' => env('INSCRIPTION_EN_LIGNE_URL', 'https://agfne.sigfne.net/vas/interface-edition-documents-sigfne/'),
        'timeout' => env('INSCRIPTION_EN_LIGNE_TIMEOUT', 20),
    ],

    /*
     * Assistant de support (API Claude, AppHttpControllersApiAssistantController).
     * Sans cle : mode guide (fiches d'aide et dossier d'un eleve par matricule).
     */
    'anthropic' => [
        'cle' => env('ANTHROPIC_API_KEY'),
        'modele' => env('ASSISTANT_MODELE', 'claude-sonnet-5'),
        'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com'),
    ],

];
