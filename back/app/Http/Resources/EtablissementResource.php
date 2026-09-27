<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EtablissementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'nom' => $this->nom,
            'sigle' => $this->sigle,
            'type_etablissement' => $this->type_etablissement,
            'logo_path' => $this->logo_path,
            'ville' => $this->ville,
            'statut' => $this->statut,
        ];
    }
}
