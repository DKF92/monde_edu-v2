<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:8100,http://localhost:4200')),

    /*
     * En developpement, le front est teste depuis d'autres appareils du reseau
     * local (telephone, tablette) via l'IP LAN de la machine de dev, qui peut
     * changer (DHCP). Plutot que de mettre a jour CORS_ALLOWED_ORIGINS a chaque
     * fois, on autorise n'importe quelle IP privee (192.168.x.x, 10.x.x.x,
     * 172.16-31.x.x) sur les ports habituels du front (Angular/Ionic).
     * A retirer en production au profit d'une liste stricte de domaines.
     */
    'allowed_origins_patterns' => env('APP_ENV') === 'local' ? [
        '#^http://(localhost|127\.0\.0\.1|192\.168\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}):(4200|8100)$#',
    ] : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
