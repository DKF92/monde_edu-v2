<?php

namespace App\Support;

/**
 * Porte le contexte de la requete en cours : etablissement (tenant), annee
 * scolaire et periode (trimestre/semestre) actuellement travailles.
 * Renseigne par les middlewares App\Http\Middleware\ResolveEtablissement puis
 * ResolveContexteScolaire, une fois l'utilisateur authentifie et les en-tetes
 * X-Etablissement-Id / X-Annee-Scolaire-Id / X-Periode-Id valides.
 *
 * Utilise par App\Models\Concerns\BelongsToEtablissement pour scoper
 * automatiquement les requetes Eloquent des modeles tenant, et par les
 * controleurs pour filtrer sur l'annee/periode choisie plutot que de
 * recalculer "l'annee active" a chaque fois.
 */
class TenantContext
{
    private ?int $etablissementId = null;

    private ?int $anneeScolaireId = null;

    private ?int $periodeId = null;

    public function set(int $etablissementId): void
    {
        $this->etablissementId = $etablissementId;
    }

    public function id(): ?int
    {
        return $this->etablissementId;
    }

    public function has(): bool
    {
        return $this->etablissementId !== null;
    }

    public function clear(): void
    {
        $this->etablissementId = null;
        $this->anneeScolaireId = null;
        $this->periodeId = null;
    }

    public function setAnneeScolaire(int $anneeScolaireId): void
    {
        $this->anneeScolaireId = $anneeScolaireId;
    }

    public function anneeScolaireId(): ?int
    {
        return $this->anneeScolaireId;
    }

    public function setPeriode(int $periodeId): void
    {
        $this->periodeId = $periodeId;
    }

    public function periodeId(): ?int
    {
        return $this->periodeId;
    }
}
