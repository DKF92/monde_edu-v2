import { Component, Input } from '@angular/core';

/**
 * Logo "Monde educatif" (livre ouvert + nom sur deux lignes).
 * - variante "sombre" : sur fond clair (menu lateral, en-tete mobile)
 * - variante "clair"  : sur fond bleu marine (ecrans de connexion)
 */
@Component({
  selector: 'app-logo',
  standalone: true,
  template: `
    <span class="logo" [class.clair]="variante === 'clair'" [style.--taille.px]="taille">
      <svg viewBox="0 0 48 40" aria-hidden="true">
        <path
          d="M24 8.5C19.6 4.6 12.8 3 4 3.4v28.2c8.8-.4 15.6 1.2 20 5.1 4.4-3.9 11.2-5.5 20-5.1V3.4C35.2 3 28.4 4.6 24 8.5Z"
          class="couverture"
        />
        <path d="M24 8.5v28.2" class="reliure" />
        <path d="M8.5 9.5c4.6 0 8.6 1 11.5 3M8.5 15.5c4.6 0 8.6 1 11.5 3M39.5 9.5c-4.6 0-8.6 1-11.5 3M39.5 15.5c-4.6 0-8.6 1-11.5 3" class="lignes" />
      </svg>
      @if (avecTexte) {
        <span class="nom">Monde<br />éducatif</span>
      }
    </span>
  `,
  styles: `
    .logo {
      --taille: 36px;
      display: inline-flex;
      align-items: center;
      gap: calc(var(--taille) * 0.28);
      color: var(--me-marine);
    }
    .logo.clair {
      color: #fff;
    }
    svg {
      width: var(--taille);
      height: calc(var(--taille) * 0.84);
      flex-shrink: 0;
    }
    .couverture {
      fill: currentColor;
    }
    .reliure,
    .lignes {
      fill: none;
      stroke: #fff;
      stroke-width: 1.8;
      stroke-linecap: round;
    }
    .clair .reliure,
    .clair .lignes {
      stroke: var(--me-marine);
    }
    .nom {
      font-weight: 800;
      font-size: calc(var(--taille) * 0.47);
      line-height: 1.02;
      letter-spacing: -0.01em;
    }
  `,
})
export class LogoComponent {
  @Input() taille = 36;
  @Input() variante: 'sombre' | 'clair' = 'sombre';
  @Input() avecTexte = true;
}
