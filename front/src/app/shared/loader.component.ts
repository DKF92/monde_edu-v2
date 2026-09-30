import { Component, inject } from '@angular/core';
import { ChargementService } from '../core/services/chargement.service';

/**
 * Loader global (voir ChargementService) : voile sur l'application et livre
 * anime au centre, pendant l'ouverture d'une page.
 */
@Component({
  selector: 'app-loader',
  standalone: true,
  template: `
    @if (chargement.actif()) {
      <div class="voile" role="status" aria-live="polite">
        <div class="boite">
          <svg viewBox="0 0 48 40" aria-hidden="true">
            <path
              d="M24 8.5C19.6 4.6 12.8 3 4 3.4v28.2c8.8-.4 15.6 1.2 20 5.1 4.4-3.9 11.2-5.5 20-5.1V3.4C35.2 3 28.4 4.6 24 8.5Z"
              class="couverture"
            />
            <path d="M24 8.5v28.2" class="reliure" />
            <path d="M8.5 9.5c4.6 0 8.6 1 11.5 3M8.5 15.5c4.6 0 8.6 1 11.5 3M8.5 21.5c4.6 0 8.6 1 11.5 3" class="lignes gauche" />
            <path d="M39.5 9.5c-4.6 0-8.6 1-11.5 3M39.5 15.5c-4.6 0-8.6 1-11.5 3M39.5 21.5c-4.6 0-8.6 1-11.5 3" class="lignes droite" />
          </svg>
          <span class="barre"><span></span></span>
          <span class="texte">Chargement…</span>
        </div>
      </div>
    }
  `,
  styles: `
    .voile {
      position: fixed;
      inset: 0;
      z-index: 30000;
      display: flex;
      align-items: center;
      justify-content: center;
      background: color-mix(in srgb, var(--me-fond, #f4f6fb) 72%, transparent);
      backdrop-filter: blur(3px);
      animation: apparition 0.15s ease-out;
    }

    .boite {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
      padding: 22px 28px;
      border-radius: 18px;
      background: var(--me-surface, #fff);
      box-shadow: 0 12px 40px rgba(20, 35, 75, 0.16);
      color: var(--me-marine, #1b2f5e);
    }

    svg {
      width: 58px;
      height: 48px;
      animation: respiration 1.2s ease-in-out infinite;
    }

    .couverture {
      fill: none;
      stroke: currentColor;
      stroke-width: 2.4;
      stroke-linejoin: round;
    }

    .reliure {
      stroke: currentColor;
      stroke-width: 2.4;
    }

    .lignes {
      fill: none;
      stroke: var(--ion-color-primary, #2f6fed);
      stroke-width: 2.2;
      stroke-linecap: round;
      stroke-dasharray: 14;
      stroke-dashoffset: 14;
      animation: ecriture 1.2s ease-in-out infinite;
    }

    .lignes.droite {
      animation-delay: 0.3s;
    }

    .barre {
      position: relative;
      width: 120px;
      height: 4px;
      overflow: hidden;
      border-radius: 999px;
      background: var(--me-bordure, #e3e7f0);

      span {
        position: absolute;
        inset: 0 auto 0 0;
        width: 40%;
        border-radius: inherit;
        background: var(--ion-color-primary, #2f6fed);
        animation: glissement 1s ease-in-out infinite;
      }
    }

    .texte {
      font-size: 13px;
      font-weight: 600;
      color: var(--me-texte-doux, #56607a);
    }

    @keyframes apparition {
      from {
        opacity: 0;
      }
    }

    @keyframes respiration {
      50% {
        transform: scale(1.06);
      }
    }

    @keyframes ecriture {
      50%,
      100% {
        stroke-dashoffset: 0;
      }
    }

    @keyframes glissement {
      from {
        transform: translateX(-100%);
      }
      to {
        transform: translateX(250%);
      }
    }

    @media (prefers-reduced-motion: reduce) {
      svg,
      .lignes,
      .barre span {
        animation-duration: 3s;
      }
    }
  `,
})
export class LoaderComponent {
  readonly chargement = inject(ChargementService);
}
