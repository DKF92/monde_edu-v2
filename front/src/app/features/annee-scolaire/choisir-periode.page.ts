import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { IonContent, IonList, IonItem, IonLabel, IonIcon, IonBadge, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { bookOutline, chevronForwardOutline } from 'ionicons/icons';
import { PeriodeService } from '../../core/services/periode.service';
import { AuthService } from '../../core/services/auth.service';
import { Periode } from '../../core/models/user.model';

@Component({
  selector: 'app-choisir-periode',
  standalone: true,
  imports: [CommonModule, IonContent, IonList, IonItem, IonLabel, IonIcon, IonBadge, IonSpinner],
  templateUrl: './choisir-periode.page.html',
  styleUrl: './choisir-periode.page.scss',
})
export class ChoisirPeriodePage implements OnInit {
  private readonly periodeService = inject(PeriodeService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly periodes = signal<Periode[]>([]);
  readonly chargement = signal(true);

  constructor() {
    addIcons({ bookOutline, chevronForwardOutline });
  }

  ngOnInit(): void {
    const annee = this.auth.anneeScolaire();
    if (!annee) {
      this.router.navigateByUrl('/tabs/dashboard');
      return;
    }

    this.periodeService.lister(annee.id).subscribe({
      next: (periodes) => {
        this.periodes.set(periodes);
        this.chargement.set(false);
      },
      error: () => this.chargement.set(false),
    });
  }

  choisir(periode: Periode): void {
    this.auth.choisirPeriode(periode);
    this.router.navigateByUrl('/tabs/profil');
  }
}
