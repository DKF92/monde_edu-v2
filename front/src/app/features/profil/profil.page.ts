import { Component, inject } from '@angular/core';
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
  briefcaseOutline,
  logOutOutline,
  personCircleOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { EspaceTravailService } from '../../core/services/espace-travail.service';

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
  readonly peutChoisirPeriode = this.auth.peutChoisirPeriode;
  readonly poste = this.auth.poste;
  readonly espaceTravail = inject(EspaceTravailService);

  constructor() {
    addIcons({ businessOutline, calendarOutline, bookOutline, briefcaseOutline, logOutOutline, personCircleOutline });
  }

  seDeconnecter(): void {
    this.auth.logout();
    this.router.navigateByUrl('/login');
  }
}
