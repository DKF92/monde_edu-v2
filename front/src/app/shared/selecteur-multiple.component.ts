import {
  Component,
  ElementRef,
  EventEmitter,
  HostListener,
  Input,
  Output,
  inject,
  signal,
} from '@angular/core';
import { IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chevronDownOutline, checkbox, squareOutline, closeCircle } from 'ionicons/icons';

export interface OptionMultiple {
  valeur: number;
  libelle: string;
  detail?: string | null;
}

let compteur = 0;

/**
 * Liste deroulante a choix multiple, meme charte que app-selecteur : la liste
 * fait la largeur du champ, reste ouverte pendant qu'on coche, et le champ
 * ferme resume la selection ("6EME, 5EME" ou "4 matières").
 */
@Component({
  selector: 'app-selecteur-multiple',
  standalone: true,
  imports: [IonIcon],
  templateUrl: './selecteur-multiple.component.html',
  styleUrls: ['./selecteur.component.scss', './selecteur-multiple.component.scss'],
})
export class SelecteurMultipleComponent {
  private readonly hote = inject<ElementRef<HTMLElement>>(ElementRef);

  @Input() options: OptionMultiple[] = [];
  @Input() valeurs: number[] = [];
  @Input() icone = '';
  @Input() placeholder = 'Sélectionner…';
  /** Nom au pluriel pour le resume : "4 matières". */
  @Input() nomPluriel = 'éléments';
  @Input() desactive = false;
  @Input() invalide = false;
  @Input() idChamp = `selecteur-multiple-${++compteur}`;
  @Output() valeursChange = new EventEmitter<number[]>();

  readonly ouvert = signal(false);
  readonly versLeHaut = signal(false);
  readonly indexActif = signal(-1);

  constructor() {
    addIcons({ chevronDownOutline, checkbox, squareOutline, closeCircle });
  }

  get idListe(): string {
    return `${this.idChamp}-liste`;
  }

  get grise(): boolean {
    return this.desactive || this.options.length === 0;
  }

  get resume(): string | null {
    const choisies = this.options.filter((o) => this.valeurs.includes(o.valeur));
    if (!choisies.length) {
      return null;
    }
    if (choisies.length <= 3) {
      return choisies.map((o) => o.libelle).join(', ');
    }
    return `${choisies.length} ${this.nomPluriel}`;
  }

  estChoisie(option: OptionMultiple): boolean {
    return this.valeurs.includes(option.valeur);
  }

  basculer(): void {
    if (this.ouvert()) {
      this.ouvert.set(false);
    } else {
      this.ouvrir();
    }
  }

  ouvrir(): void {
    if (this.grise) {
      return;
    }
    const rect = this.hote.nativeElement.getBoundingClientRect();
    const hauteurListe = Math.min(300, this.options.length * 48 + 56);
    const placeDessous = window.innerHeight - rect.bottom;
    this.versLeHaut.set(placeDessous < hauteurListe + 16 && rect.top > placeDessous);
    this.indexActif.set(0);
    this.ouvert.set(true);
  }

  cocher(option: OptionMultiple): void {
    const suivantes = this.estChoisie(option)
      ? this.valeurs.filter((v) => v !== option.valeur)
      : [...this.valeurs, option.valeur];
    // Garde l'ordre des options (ordre des niveaux, alphabetique...).
    this.valeursChange.emit(this.options.filter((o) => suivantes.includes(o.valeur)).map((o) => o.valeur));
  }

  toutCocher(): void {
    const tout = this.options.every((o) => this.estChoisie(o));
    this.valeursChange.emit(tout ? [] : this.options.map((o) => o.valeur));
  }

  vider(evenement: Event): void {
    evenement.stopPropagation();
    this.valeursChange.emit([]);
  }

  clavier(evenement: KeyboardEvent): void {
    if (this.grise) {
      return;
    }
    const dernier = this.options.length - 1;
    switch (evenement.key) {
      case 'ArrowDown':
      case 'ArrowUp': {
        evenement.preventDefault();
        if (!this.ouvert()) {
          this.ouvrir();
          return;
        }
        const pas = evenement.key === 'ArrowDown' ? 1 : -1;
        this.indexActif.update((i) => Math.min(dernier, Math.max(0, i + pas)));
        queueMicrotask(() =>
          this.hote.nativeElement
            .querySelector(`#${this.idChamp}-option-${this.indexActif()}`)
            ?.scrollIntoView({ block: 'nearest' }),
        );
        break;
      }
      case 'Enter':
      case ' ':
        evenement.preventDefault();
        if (this.ouvert() && this.options[this.indexActif()]) {
          this.cocher(this.options[this.indexActif()]);
        } else {
          this.ouvrir();
        }
        break;
      case 'Escape':
        if (this.ouvert()) {
          evenement.preventDefault();
          evenement.stopPropagation();
          this.ouvert.set(false);
        }
        break;
      case 'Tab':
        this.ouvert.set(false);
        break;
    }
  }

  @HostListener('document:click', ['$event'])
  clicAilleurs(evenement: MouseEvent): void {
    if (this.ouvert() && !this.hote.nativeElement.contains(evenement.target as Node)) {
      this.ouvert.set(false);
    }
  }
}
