import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  IonHeader,
  IonToolbar,
  IonTitle,
  IonContent,
  IonRefresher,
  IonRefresherContent,
  IonIcon,
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  peopleOutline,
  schoolOutline,
  cashOutline,
  walletOutline,
} from 'ionicons/icons';
import { DashboardService } from '../../core/services/dashboard.service';
import { AuthService } from '../../core/services/auth.service';
import { StatsDashboard } from '../../core/models/eleve.model';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [
    CommonModule,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonContent,
    IonRefresher,
    IonRefresherContent,
    IonIcon,
  ],
  templateUrl: './dashboard.page.html',
  styleUrl: './dashboard.page.scss',
})
export class DashboardPage implements OnInit {
  private readonly dashboardService = inject(DashboardService);
  private readonly auth = inject(AuthService);

  readonly stats = signal<StatsDashboard | null>(null);
  readonly etablissement = this.auth.etablissementActif;

  constructor() {
    addIcons({ peopleOutline, schoolOutline, cashOutline, walletOutline });
  }

  ngOnInit(): void {
    this.charger();
  }

  charger(evenement?: CustomEvent): void {
    this.dashboardService.stats().subscribe({
      next: (stats) => {
        this.stats.set(stats);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
      error: () => (evenement?.target as HTMLIonRefresherElement | undefined)?.complete(),
    });
  }

  formaterMontant(montant: number): string {
    return new Intl.NumberFormat('fr-FR').format(montant) + ' F';
  }
}
