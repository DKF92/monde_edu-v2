import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import {
  IonContent,
  IonSearchbar,
  IonList,
  IonItem,
  IonLabel,
  IonIcon,
  IonAvatar,
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import { businessOutline, chevronForwardOutline, schoolOutline } from 'ionicons/icons';
import { EtablissementService } from '../../core/services/etablissement.service';
import { AuthService } from '../../core/services/auth.service';
import { Etablissement } from '../../core/models/user.model';

@Component({
  selector: 'app-landing-etablissement',
  standalone: true,
  imports: [
    CommonModule,
    IonContent,
    IonSearchbar,
    IonList,
    IonItem,
    IonLabel,
    IonIcon,
    IonAvatar,
  ],
  templateUrl: './landing-etablissement.page.html',
  styleUrl: './landing-etablissement.page.scss',
})
export class LandingEtablissementPage implements OnInit {
  private readonly etablissementService = inject(EtablissementService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly etablissements = signal<Etablissement[]>([]);
  readonly chargement = signal(true);

  constructor() {
    addIcons({ businessOutline, chevronForwardOutline, schoolOutline });
  }

  ngOnInit(): void {
    this.rechercher('');
  }

  rechercher(valeur: string | null | undefined): void {
    this.chargement.set(true);
    this.etablissementService.listerPublic(valeur ?? '').subscribe({
      next: (liste) => {
        this.etablissements.set(liste);
        this.chargement.set(false);
      },
      error: () => this.chargement.set(false),
    });
  }

  choisir(etablissement: Etablissement): void {
    this.auth.choisirEtablissementConnexion(etablissement);
    this.router.navigateByUrl('/login');
  }
}
