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
import { IonIcon, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chevronDownOutline, checkmarkCircle, ellipseOutline } from 'ionicons/icons';

export interface OptionSelecteur {
  valeur: number;
  libelle: string;
  /** Petite ligne sous le libelle (ex: ville de l'etablissement). */
  detail?: string | null;
  badge?: { texte: string; ton: 'succes' | 'neutre' | 'attention' } | null;
  /** Visible mais non selectionnable (ex : classe complete). */
  desactivee?: boolean;
}

let compteur = 0;

/**
 * Liste deroulante de la charte : contrairement a un <select> natif, la liste
 * ouverte fait exactement la largeur du champ, et reprend le style des cartes
 * de choix (coche, badges). S'ouvre vers le haut s'il manque de place en bas.
 * Un selecteur a une seule option (ou desactive) est affiche grise.
 */
@Component({
  selector: 'app-selecteur',
  standalone: true,
  imports: [IonIcon, IonSpinner],
  templateUrl: './selecteur.component.html',
  styleUrl: './selecteur.component.scss',
})
export class SelecteurComponent {
  private readonly hote = inject<ElementRef<HTMLElement>>(ElementRef);

  @Input() options: OptionSelecteur[] = [];
  @Input() valeur: number | null = null;
  @Input() icone = '';
  @Input() placeholder = 'Sélectionner…';
  @Input() desactive = false;
  @Input() chargement = false;
  @Input() idChamp = `selecteur-${++compteur}`;
  @Output() valeurChange = new EventEmitter<number>();

  readonly ouvert = signal(false);
  readonly versLeHaut = signal(false);
  /** Option survolee au clavier. */
  readonly indexActif = signal(-1);

  get idListe(): string {
    return `${this.idChamp}-liste`;
  }

  constructor() {
    addIcons({ chevronDownOutline, checkmarkCircle, ellipseOutline });
  }

  get grise(): boolean {
    return this.desactive || this.chargement || this.options.length <= 1;
  }

  get selection(): OptionSelecteur | undefined {
    return this.options.find((o) => o.valeur === this.valeur);
  }

  basculer(): void {
    if (this.ouvert()) {
      this.fermer();
    } else {
      this.ouvrir();
    }
  }

  ouvrir(): void {
    if (this.grise) {
      return;
    }
    // Hauteur max de la liste (voir .liste) : s'ouvre vers le haut si elle
    // deborderait de l'ecran par le bas et qu'il y a plus de place au-dessus.
    const rect = this.hote.nativeElement.getBoundingClientRect();
    const hauteurListe = Math.min(280, this.options.length * 52 + 12);
    const placeDessous = window.innerHeight - rect.bottom;
    this.versLeHaut.set(placeDessous < hauteurListe + 16 && rect.top > placeDessous);

    this.indexActif.set(Math.max(0, this.options.findIndex((o) => o.valeur === this.valeur)));
    this.ouvert.set(true);
  }

  fermer(): void {
    this.ouvert.set(false);
  }

  choisir(option: OptionSelecteur): void {
    if (option.desactivee) {
      return;
    }
    if (option.valeur !== this.valeur) {
      this.valeurChange.emit(option.valeur);
    }
    this.fermer();
    this.hote.nativeElement.querySelector<HTMLButtonElement>('.declencheur')?.focus();
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
        this.defilerVersActif();
        break;
      }
      case 'Enter':
      case ' ':
        evenement.preventDefault();
        if (this.ouvert() && this.options[this.indexActif()]) {
          this.choisir(this.options[this.indexActif()]);
        } else {
          this.ouvrir();
        }
        break;
      case 'Escape':
        if (this.ouvert()) {
          // Ferme la liste sans fermer la modale qui la contient.
          evenement.preventDefault();
          evenement.stopPropagation();
          this.fermer();
        }
        break;
      case 'Tab':
        this.fermer();
        break;
    }
  }

  @HostListener('document:click', ['$event'])
  clicAilleurs(evenement: MouseEvent): void {
    if (this.ouvert() && !this.hote.nativeElement.contains(evenement.target as Node)) {
      this.fermer();
    }
  }

  private defilerVersActif(): void {
    queueMicrotask(() =>
      this.hote.nativeElement
        .querySelector(`#${this.idChamp}-option-${this.indexActif()}`)
        ?.scrollIntoView({ block: 'nearest' }),
    );
  }
}
