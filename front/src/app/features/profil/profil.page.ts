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
  downloadOutline,
  cloudDoneOutline,
  cloudOfflineOutline,
  serverOutline,
  refreshOutline,
  trashOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { EspaceTravailService } from '../../core/services/espace-travail.service';
import { InstallationService } from '../../core/services/installation.service';
import { ModeServeur, ReseauService } from '../../core/services/reseau.service';
import { FileAttenteService } from '../../core/services/file-attente.service';
import { SynchroService } from '../../core/services/synchro.service';

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
  /** Telephone, application pas encore installee : rappel de la proposition. */
  readonly installation = inject(InstallationService);
  /** Serveur utilise (en ligne / ecole), choix du mode, saisies hors ligne. */
  readonly reseau = inject(ReseauService);
  readonly file = inject(FileAttenteService);
  readonly synchro = inject(SynchroService);
  readonly modes: { valeur: ModeServeur; libelle: string }[] = [
    { valeur: 'auto', libelle: 'Automatique' },
    { valeur: 'en_ligne', libelle: 'En ligne' },
    { valeur: 'local', libelle: 'École' },
  ];

  dateHeure(iso: string): string {
    return new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  }

  constructor() {
    addIcons({ businessOutline, calendarOutline, bookOutline, briefcaseOutline, logOutOutline, personCircleOutline, downloadOutline, cloudDoneOutline, cloudOfflineOutline, serverOutline, refreshOutline, trashOutline });
  }

  seDeconnecter(): void {
    this.auth.logout();
    this.router.navigateByUrl('/login');
  }
}
