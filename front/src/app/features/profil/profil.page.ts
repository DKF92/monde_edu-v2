import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import {
  IonHeader,
  IonToolbar,
  IonTitle,
  IonContent,
  IonList,
  IonItem,
  IonLabel,
  IonAvatar,
  IonIcon,
  IonChip,
  IonButton,
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  businessOutline,
  calendarOutline,
  bookOutline,
  logOutOutline,
  personCircleOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { ChoisirAnneeModalComponent } from '../annee-scolaire/choisir-annee-modal.component';

@Component({
  selector: 'app-profil',
  standalone: true,
  imports: [
    CommonModule,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonContent,
    IonList,
    IonItem,
    IonLabel,
    IonAvatar,
    IonIcon,
    IonChip,
    IonButton,
    ChoisirAnneeModalComponent,
  ],
  templateUrl: './profil.page.html',
  styleUrl: './profil.page.scss',
})
export class ProfilPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly user = this.auth.user;
  readonly etablissement = this.auth.etablissementActif;
  readonly anneeScolaire = this.auth.anneeScolaire;
  readonly periode = this.auth.periode;
  readonly estProfilPedagogique = this.auth.estProfilPedagogique;
  readonly plusieursEtablissements = () => this.auth.etablissements().length > 1;
  readonly afficherModaleAnnee = signal(false);

  constructor() {
    addIcons({ businessOutline, calendarOutline, bookOutline, logOutOutline, personCircleOutline });
  }

  changerEtablissement(): void {
    this.router.navigateByUrl('/select-etablissement');
  }

  changerAnneeScolaire(): void {
    this.afficherModaleAnnee.set(true);
  }

  changerPeriode(): void {
    this.router.navigateByUrl('/choisir-periode');
  }

  seDeconnecter(): void {
    this.auth.logout();
    this.router.navigateByUrl('/login');
  }
}
