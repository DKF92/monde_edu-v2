import { Component } from '@angular/core';
import { IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { peopleOutline, barChartOutline, walletOutline } from 'ionicons/icons';
import { LogoComponent } from './logo.component';

/**
 * Panneau bleu marine des ecrans d'authentification : colonne de gauche sur
 * bureau, bandeau du haut sur mobile (les points forts n'y sont pas affiches).
 */
@Component({
  selector: 'app-auth-marque',
  standalone: true,
  imports: [IonIcon, LogoComponent],
  template: `
    <div class="marque">
      <app-logo variante="clair" [taille]="52"></app-logo>
      <p class="slogan">L'éducation au quotidien,<br />partout avec vous.</p>

      <ul class="points-forts">
        <li>
          <span><ion-icon name="people-outline"></ion-icon></span>
          <div>
            <strong>Scolarité</strong>
            <small>Inscriptions, classes et suivi des élèves</small>
          </div>
        </li>
        <li>
          <span><ion-icon name="bar-chart-outline"></ion-icon></span>
          <div>
            <strong>Évaluations</strong>
            <small>Notes, moyennes et bulletins</small>
          </div>
        </li>
        <li>
          <span><ion-icon name="wallet-outline"></ion-icon></span>
          <div>
            <strong>Finances</strong>
            <small>Frais scolaires, paiements et relances</small>
          </div>
        </li>
      </ul>

      <svg class="deco" viewBox="0 0 400 400" aria-hidden="true">
        <circle cx="330" cy="70" r="120" />
        <circle cx="40" cy="380" r="90" />
      </svg>
    </div>
  `,
  styles: `
    :host {
      display: block;
    }
    .marque {
      position: relative;
      overflow: hidden;
      height: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 14px;
      padding: calc(28px + env(safe-area-inset-top)) 24px 52px;
      color: #fff;
      text-align: center;
      background:
        radial-gradient(circle at 80% 10%, rgba(255, 255, 255, 0.12), transparent 45%),
        linear-gradient(160deg, var(--me-marine) 0%, var(--me-marine-fonce) 100%);
    }
    .slogan {
      position: relative;
      z-index: 1;
      margin: 0;
      font-size: 15px;
      line-height: 1.5;
      opacity: 0.85;
    }
    .points-forts {
      display: none;
    }
    .deco {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      pointer-events: none;
    }
    .deco circle {
      fill: rgba(255, 255, 255, 0.05);
    }
    app-logo {
      position: relative;
      z-index: 1;
    }

    @media (min-width: 992px) {
      .marque {
        align-items: flex-start;
        text-align: left;
        gap: 22px;
        padding: 56px clamp(40px, 5vw, 80px);
      }
      .slogan {
        font-size: 22px;
        font-weight: 600;
        opacity: 0.95;
      }
      .points-forts {
        position: relative;
        z-index: 1;
        display: flex;
        flex-direction: column;
        gap: 18px;
        margin: 22px 0 0;
        padding: 0;
        list-style: none;
      }
      li {
        display: flex;
        align-items: center;
        gap: 14px;
      }
      li span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.12);
        font-size: 21px;
        flex-shrink: 0;
      }
      li div {
        display: flex;
        flex-direction: column;
      }
      li strong {
        font-size: 15px;
      }
      li small {
        font-size: 13px;
        opacity: 0.75;
      }
    }
  `,
})
export class AuthMarqueComponent {
  constructor() {
    addIcons({ peopleOutline, barChartOutline, walletOutline });
  }
}
