import { Component, inject } from '@angular/core';
import { IonTabs, IonTabBar, IonTabButton, IonIcon, IonLabel } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { homeOutline, peopleOutline, personCircleOutline } from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { ChoisirAnneeModalComponent } from '../annee-scolaire/choisir-annee-modal.component';

@Component({
  selector: 'app-tabs',
  standalone: true,
  imports: [IonTabs, IonTabBar, IonTabButton, IonIcon, IonLabel, ChoisirAnneeModalComponent],
  templateUrl: './tabs.page.html',
})
export class TabsPage {
  private readonly auth = inject(AuthService);

  // L'accueil et le profil sont toujours visibles ; les autres onglets
  // n'apparaissent que si l'utilisateur a la permission correspondante.
  readonly peutVoirEleves = () => this.auth.aPermission('eleves.voir');

  // Modale bloquante : tant qu'aucune annee scolaire n'est choisie, elle reste
  // affichee au-dessus des onglets (aucun moyen de la fermer sans choisir).
  readonly anneeScolaireManquante = () => !this.auth.anneeScolaire();

  constructor() {
    addIcons({ homeOutline, peopleOutline, personCircleOutline });
  }
}
