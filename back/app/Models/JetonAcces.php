<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Jeton de connexion (Sanctum) retrouve par son empreinte seule, sans le
 * numero de ligne place devant (« 12|... ») : les sessions sont recopiees d'un
 * serveur a l'autre (synchronisation) et n'y ont pas le meme numero.
 */
class JetonAcces extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    public static function findToken($token)
    {
        $secret = str_contains($token, '|') ? explode('|', $token, 2)[1] : $token;

        return static::where('token', hash('sha256', $secret))->first();
    }
}
