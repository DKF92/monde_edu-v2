import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import {
  IonContent,
  IonList,
  IonItem,
  IonLabel,
  IonIcon,
  IonAvatar,
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import { businessOutline, chevronForwardOutline } from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { Etablissement } from '../../core/models/user.model';

@Component({
  selector: 'app-select-etablissement',
  standalone: true,
  imports: [CommonModule, IonContent, IonList, IonItem, IonLabel, IonIcon, IonAvatar],
  templateUrl: './select-etablissement.page.html',
  styleUrl: './select-etablissement.page.scss',
})
export class SelectEtablissementPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly etablissements = this.auth.etablissements;

  constructor() {
    addIcons({ businessOutline, chevronForwardOutline });
  }

  choisir(etablissement: Etablissement): void {
    this.auth.selectionnerEtablissement(etablissement);
    this.router.navigateByUrl('/tabs/dashboard');
  }
}
