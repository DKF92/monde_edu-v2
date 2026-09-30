import { Component, EventEmitter, Input, Output } from '@angular/core';
import { IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chevronBackOutline, chevronForwardOutline } from 'ionicons/icons';

export const TAILLES_PAGE = [10, 20, 50];

/** Page d'une liste deja chargee (pagination cote navigateur). */
export function paginer<T>(liste: T[], page: number, taille: number): T[] {
  const derniere = Math.max(1, Math.ceil(liste.length / taille));
  const courante = Math.min(Math.max(1, page), derniere);
  return liste.slice((courante - 1) * taille, courante * taille);
}

/**
 * Pagination commune a tous les tableaux : "1–10 sur 42", pages avec
 * points de suspension, nombre de lignes par page. La page affichee est
 * ramenee dans les bornes si la liste raccourcit (filtre, suppression...).
 */
@Component({
  selector: 'app-pagination',
  standalone: true,
  imports: [IonIcon],
  template: `
    @if (total > 0) {
      <nav class="pagination" aria-label="Pagination">
        <span class="resume">{{ debut }}–{{ fin }} sur {{ total }}</span>

        @if (dernierePage > 1) {
          <div class="pages">
            <button type="button" class="page fleche" aria-label="Page précédente" [disabled]="courante === 1" (click)="aller(courante - 1)">
              <ion-icon name="chevron-back-outline"></ion-icon>
            </button>
            @for (p of numeros; track $index) {
              @if (p === null) {
                <span class="ellipse">…</span>
              } @else {
                <button type="button" class="page" [class.actif]="p === courante" [attr.aria-current]="p === courante ? 'page' : null" (click)="aller(p)">{{ p }}</button>
              }
            }
            <button type="button" class="page fleche" aria-label="Page suivante" [disabled]="courante === dernierePage" (click)="aller(courante + 1)">
              <ion-icon name="chevron-forward-outline"></ion-icon>
            </button>
          </div>
        }

        @if (total > tailles[0]) {
          <label class="taille">
            <span>Lignes</span>
            <select [value]="taille" (change)="changerTaille($any($event.target).value)" aria-label="Lignes par page">
              @for (t of tailles; track t) {
                <option [value]="t" [selected]="t === taille">{{ t }}</option>
              }
            </select>
          </label>
        }
      </nav>
    }
  `,
  styles: `
    .pagination {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px 16px;
      padding: 12px 16px;
      border-top: 1px solid var(--me-bordure);
      font-size: 13px;
      color: var(--me-texte-doux);
    }
    .resume {
      font-variant-numeric: tabular-nums;
    }
    .pages {
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .page {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 34px;
      height: 34px;
      padding: 0 8px;
      border: 1px solid var(--me-bordure);
      border-radius: 10px;
      background: var(--me-surface);
      color: var(--me-texte);
      font: inherit;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
    }
    .page:hover:not(:disabled):not(.actif) {
      border-color: #b9c6ea;
    }
    .page.actif {
      border-color: var(--me-marine);
      background: var(--me-marine);
      color: #fff;
    }
    .page:disabled {
      opacity: 0.4;
      cursor: default;
    }
    .fleche ion-icon {
      font-size: 16px;
    }
    .ellipse {
      padding: 0 2px;
      color: var(--me-texte-leger);
    }
    .taille {
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .taille select {
      height: 34px;
      padding: 0 8px;
      border: 1px solid var(--me-bordure);
      border-radius: 10px;
      background: var(--me-surface);
      color: var(--me-texte);
      font: inherit;
      font-size: 13px;
      cursor: pointer;
    }
    @media (max-width: 480px) {
      .pagination {
        justify-content: center;
      }
      .resume {
        width: 100%;
        text-align: center;
      }
    }
  `,
})
export class PaginationComponent {
  @Input() total = 0;
  @Input() page = 1;
  @Input() taille = TAILLES_PAGE[0];
  @Input() tailles = TAILLES_PAGE;
  @Output() pageChange = new EventEmitter<number>();
  @Output() tailleChange = new EventEmitter<number>();

  constructor() {
    addIcons({ chevronBackOutline, chevronForwardOutline });
  }

  get dernierePage(): number {
    return Math.max(1, Math.ceil(this.total / this.taille));
  }

  get courante(): number {
    return Math.min(Math.max(1, this.page), this.dernierePage);
  }

  get debut(): number {
    return (this.courante - 1) * this.taille + 1;
  }

  get fin(): number {
    return Math.min(this.total, this.courante * this.taille);
  }

  /** 1 … 4 5 6 … 12 */
  get numeros(): (number | null)[] {
    const n = this.dernierePage;
    const c = this.courante;
    if (n <= 7) {
      return Array.from({ length: n }, (_, i) => i + 1);
    }
    const autour = [c - 1, c, c + 1].filter((p) => p > 1 && p < n);
    const liste: (number | null)[] = [1];
    if (autour[0] > 2) {
      liste.push(null);
    }
    liste.push(...autour);
    if (autour[autour.length - 1] < n - 1) {
      liste.push(null);
    }
    liste.push(n);
    return liste;
  }

  aller(page: number): void {
    if (page !== this.courante) {
      this.pageChange.emit(page);
    }
  }

  changerTaille(valeur: string): void {
    this.tailleChange.emit(Number(valeur));
    this.pageChange.emit(1);
  }
}
