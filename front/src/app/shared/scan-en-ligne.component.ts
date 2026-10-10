import { Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { IonIcon, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { globeOutline } from 'ionicons/icons';
import { MiseAJourEnLigneService } from '../core/services/mise-a-jour-en-ligne.service';

/**
 * Indicateur affiche sur toutes les pages pendant que « Mettre a jour »
 * (inscription en ligne) tourne sur le serveur ; un clic ouvre la liste des
 * inscriptions (bilan detaille).
 */
@Component({
  selector: 'app-scan-en-ligne',
  standalone: true,
  imports: [IonIcon, IonSpinner],
  template: `
    @if (maj.enCours()) {
      <button type="button" class="scan" (click)="router.navigateByUrl('/tabs/inscriptions')" title="Mise à jour depuis l'inscription en ligne : ouvrir le bilan">
        <ion-spinner name="crescent"></ion-spinner>
        <span>
          <strong>Mise à jour en ligne {{ maj.progression() }} %</strong>
          <small>{{ maj.nbTraitees() }} / {{ maj.total() }} · {{ maj.trouvees() }} trouvée{{ maj.trouvees() > 1 ? 's' : '' }}</small>
        </span>
        <span class="jauge"><span [style.width.%]="maj.progression()"></span></span>
      </button>
    }
  `,
  styles: `
    :host {
      position: fixed;
      right: 16px;
      bottom: 16px;
      z-index: 50;
    }
    .scan {
      position: relative;
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 14px 12px;
      border: 1px solid var(--me-bordure);
      border-radius: 14px;
      background: var(--me-surface);
      color: var(--me-texte);
      box-shadow: 0 6px 20px rgba(15, 23, 42, 0.14);
      cursor: pointer;
      text-align: left;
      overflow: hidden;
    }
    ion-spinner { width: 20px; height: 20px; color: var(--me-marine); }
    span:not(.jauge) { display: flex; flex-direction: column; }
    strong { font-size: 13px; }
    small { font-size: 11.5px; color: var(--me-texte-doux); }
    .jauge { position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: var(--me-fond); }
    .jauge span { display: block; height: 100%; background: var(--me-marine); transition: width 0.4s; }
    @media (max-width: 768px) {
      :host { bottom: 76px; right: 12px; }
    }
  `,
})
export class ScanEnLigneComponent {
  readonly maj = inject(MiseAJourEnLigneService);
  readonly router = inject(Router);

  constructor() {
    addIcons({ globeOutline });
  }
}
