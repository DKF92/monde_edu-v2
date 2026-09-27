<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matricule' => $this->matricule,
            'name' => $this->name,
            'prenoms' => $this->prenoms,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'photo_path' => $this->photo_path,
            'etablissement_id' => $this->etablissement_id,
            'doit_changer_mot_de_passe' => $this->doit_changer_mot_de_passe,
            'roles' => $this->when(
                $this->relationLoaded('roles') || $this->etablissement_id !== null,
                fn () => $this->getRoleNames()
            ),
            'permissions' => $this->when(
                $this->relationLoaded('permissions') || $this->etablissement_id !== null,
                fn () => $this->getAllPermissions()->pluck('name')
            ),
        ];
    }
}
