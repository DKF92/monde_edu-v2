import { Injectable, signal } from '@angular/core';

/**
 * Ouverture a la demande de la modale "espace de travail" (montee une seule
 * fois dans la coquille) depuis n'importe quel ecran : barre du haut,
 * accueil, profil...
 */
@Injectable({ providedIn: 'root' })
export class EspaceTravailService {
  private readonly demandeSignal = signal(false);

  readonly changementDemande = this.demandeSignal.asReadonly();

  ouvrir(): void {
    this.demandeSignal.set(true);
  }

  fermer(): void {
    this.demandeSignal.set(false);
  }
}
