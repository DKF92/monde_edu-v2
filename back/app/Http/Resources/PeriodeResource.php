<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PeriodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'annee_scolaire_id' => $this->annee_scolaire_id,
            'type_decoupage' => $this->type_decoupage,
            'numero' => $this->numero,
            'libelle' => $this->libelle,
            'is_active' => $this->is_active,
            'is_cloturee' => $this->is_cloturee,
        ];
    }
}
