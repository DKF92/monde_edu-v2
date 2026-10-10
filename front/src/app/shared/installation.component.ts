import { Component, computed, effect, inject, signal } from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { filter, map } from 'rxjs';
import { IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { closeOutline, downloadOutline, shareOutline, ellipsisVertical, addCircleOutline, openOutline } from 'ionicons/icons';
import { InstallationService } from '../core/services/installation.service';

/**
 * Proposition d'installation, sur telephone seulement : petite carte en bas
 * de l'ecran (au-dessus de la barre d'onglets), qui n'empeche pas d'utiliser
 * la page. Croix : masquee 14 jours (le profil permet de la rappeler).
 */
@Component({
  selector: 'app-installation',
  standalone: true,
  imports: [IonIcon],
  template: `
    @if (visible()) {
      <aside class="carte-installation" [class.dans-onglets]="dansOnglets()" role="dialog" aria-labelledby="installation-titre">
        <div class="entete">
          <img src="icons/icon-96x96.png" alt="" class="icone-app" />
          <div class="texte">
            <strong id="installation-titre">Installer Monde Éducatif</strong>
            <span>Ouverture directe depuis l'écran d'accueil, en plein écran.</span>
          </div>
          <button type="button" class="fermer" aria-label="Fermer" (click)="fermer()"><ion-icon name="close-outline"></ion-icon></button>
        </div>
        @if (etapes() && service.navigateurIntegre) {
          <!-- Lien ouvert depuis WhatsApp, Gmail... : ce navigateur ne sait pas installer. -->
          @if (service.plateforme === 'ios') {
            <p class="etapes">Ce lien est ouvert dans une autre application. Touchez le menu <strong>···</strong> puis <strong>Ouvrir dans Safari</strong>, et installez depuis Safari.</p>
          } @else {
            <p class="etapes">Ce lien est ouvert dans une autre application, qui ne sait pas installer. Ouvrez-le dans Chrome :</p>
            <a class="installer" [href]="service.lienChrome"><ion-icon name="open-outline"></ion-icon> Ouvrir dans Chrome</a>
          }
        } @else if (etapes()) {
          <ol class="etapes">
            @if (service.plateforme === 'ios') {
              <li>Dans Safari, touchez <ion-icon name="share-outline"></ion-icon> <strong>Partager</strong></li>
              <li>Choisissez <ion-icon name="add-circle-outline"></ion-icon> <strong>Sur l'écran d'accueil</strong></li>
            } @else {
              <li>Ouvrez le menu <ion-icon name="ellipsis-vertical"></ion-icon> du navigateur</li>
              <li>Choisissez <strong>Installer l'application</strong> (ou « Ajouter à l'écran d'accueil »)</li>
            }
          </ol>
          @if (service.adresseNonSecurisee && service.plateforme !== 'ios') {
            <p class="note">Adresse non sécurisée (http) : le navigateur ne propose pas l'installation automatique, mais le raccourci fonctionne.</p>
          }
        } @else {
          <button type="button" class="installer" (click)="installer()" [disabled]="preparation()">
            <ion-icon name="download-outline"></ion-icon> {{ preparation() ? 'Préparation…' : 'Installer' }}
          </button>
        }
      </aside>
    }
  `,
  styles: `
    .carte-installation {
      position: fixed;
      z-index: 900; /* sous les fenetres Ionic : elle ne cache jamais une modale */
      left: 12px;
      right: 12px;
      bottom: calc(12px + env(safe-area-inset-bottom));
      max-width: 440px;
      margin: 0 auto;
      padding: 12px 12px 12px 14px;
      border-radius: 16px;
      background: var(--me-surface, #fff);
      border: 1px solid var(--me-bordure, #e3e8f0);
      box-shadow: 0 8px 28px rgba(20, 35, 70, 0.18);
      animation: entree 0.35s ease-out;
    }
    .carte-installation.dans-onglets {
      bottom: calc(60px + 10px + env(safe-area-inset-bottom));
    }
    @keyframes entree {
      from { transform: translateY(24px); opacity: 0; }
      to { transform: none; opacity: 1; }
    }
    .entete {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .icone-app {
      width: 44px;
      height: 44px;
      border-radius: 11px;
      flex-shrink: 0;
    }
    .texte {
      display: flex;
      flex-direction: column;
      gap: 2px;
      min-width: 0;
      flex: 1;
      strong { font-size: 14px; color: var(--me-texte, #1a2440); }
      span { font-size: 12.5px; color: var(--me-texte-doux, #5b6478); line-height: 1.35; }
    }
    .fermer {
      align-self: flex-start;
      display: grid;
      place-items: center;
      width: 30px;
      height: 30px;
      margin: -4px -4px 0 0;
      border: none;
      border-radius: 50%;
      background: none;
      color: var(--me-texte-doux, #5b6478);
      font-size: 20px;
      cursor: pointer;
    }
    .installer {
      box-sizing: border-box;
      text-decoration: none;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      width: 100%;
      margin-top: 10px;
      padding: 10px;
      border: none;
      border-radius: 10px;
      background: var(--me-marine, #1b3a6b);
      color: #fff;
      font: inherit;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
    }
    .installer:disabled { opacity: 0.75; cursor: wait; }
    .etapes {
      margin: 10px 0 0;
      padding: 10px 12px 10px 30px;
      border-radius: 10px;
      background: var(--me-bleu-fond, #eef3ff);
      font-size: 13px;
      line-height: 1.6;
      color: var(--me-texte, #1a2440);
      ion-icon { vertical-align: -2px; font-size: 16px; }
    }
    .note {
      margin: 8px 2px 0;
      font-size: 11.5px;
      color: var(--me-texte-doux, #5b6478);
    }
  `,
})
export class InstallationComponent {
  readonly service = inject(InstallationService);
  private readonly router = inject(Router);

  /** Laisse la page s'afficher avant de proposer l'installation. */
  private readonly delaiEcoule = signal(false);
  /** Geste manuel affiche (pas d'installation native possible). */
  readonly etapes = signal(false);

  private readonly url = toSignal(
    this.router.events.pipe(filter((e): e is NavigationEnd => e instanceof NavigationEnd), map((e) => e.urlAfterRedirects)),
    { initialValue: this.router.url },
  );
  /** Pages avec la barre d'onglets du bas : la carte se place au-dessus. */
  readonly dansOnglets = computed(() => this.url().startsWith('/tabs'));
  readonly visible = computed(() => this.service.aProposer() && (this.delaiEcoule() || this.service.demandee()));

  /** Attente de l'invitation du navigateur apres le clic. */
  readonly preparation = signal(false);

  constructor() {
    addIcons({ closeOutline, downloadOutline, shareOutline, ellipsisVertical, addCircleOutline, openOutline });
    setTimeout(() => this.delaiEcoule.set(true), 3000);
    // L'installation directe devient possible apres coup : le bouton revient.
    effect(() => {
      if (this.service.installationDirecte() && !this.service.navigateurIntegre) this.etapes.set(false);
    });
  }

  async installer(): Promise<void> {
    this.preparation.set(true);
    try {
      if (!(await this.service.installer())) {
        this.etapes.set(true);
      }
    } finally {
      this.preparation.set(false);
    }
  }

  fermer(): void {
    this.etapes.set(false);
    this.service.fermer();
  }
}
