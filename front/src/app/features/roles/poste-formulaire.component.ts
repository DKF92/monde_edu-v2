import { Component, EventEmitter, Input, OnChanges, Output, SimpleChanges, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { IonModal, IonIcon, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  closeOutline,
  briefcaseOutline,
  checkbox,
  squareOutline,
  lockClosed,
  alertCircleOutline,
  informationCircleOutline,
} from 'ionicons/icons';
import { PosteService } from '../../core/services/poste.service';
import { CatalogueDroits, Droit, Poste, PosteSaisie } from '../../core/models/poste.model';

/**
 * Creation / modification d'un poste : libelle, description, options
 * (protege, niveaux, matieres) et droits, regroupes par module.
 */
@Component({
  selector: 'app-poste-formulaire',
  standalone: true,
  imports: [FormsModule, IonModal, IonIcon, IonSpinner],
  templateUrl: './poste-formulaire.component.html',
  styleUrl: './poste-formulaire.component.scss',
})
export class PosteFormulaireComponent implements OnChanges {
  private readonly service = inject(PosteService);

  @Input() isOpen = false;
  /** null = creation. */
  @Input() poste: Poste | null = null;
  @Input() catalogue: CatalogueDroits | null = null;
  @Output() ferme = new EventEmitter<void>();
  @Output() enregistre = new EventEmitter<{ poste: Poste; creation: boolean }>();

  nom = '';
  description = '';
  readonly estSensible = signal(false);
  readonly lieNiveaux = signal(false);
  readonly lieMatieres = signal(false);
  readonly droits = signal<Set<string>>(new Set());

  readonly enregistrement = signal(false);
  readonly tentative = signal(false);
  readonly erreurs = signal<Record<string, string>>({});
  readonly erreurGenerale = signal<string | null>(null);

  constructor() {
    addIcons({ closeOutline, briefcaseOutline, checkbox, squareOutline, lockClosed, alertCircleOutline, informationCircleOutline });
  }

  get estCreation(): boolean {
    return !this.poste;
  }

  /** Le Super admin garde tous ses droits : ils ne se modifient pas. */
  get droitsVerrouilles(): boolean {
    return !!this.poste?.super_admin;
  }

  get peutProteger(): boolean {
    return !!this.catalogue?.peut_gerer_sensibles && !this.poste?.super_admin;
  }

  ngOnChanges(changements: SimpleChanges): void {
    const ouverture = changements['isOpen']?.currentValue === true;
    const autre = this.isOpen && !!changements['poste'];
    if (!ouverture && !autre) {
      return;
    }
    const p = this.poste;
    this.nom = p?.nom ?? '';
    this.description = p?.description ?? '';
    this.estSensible.set(p?.est_sensible ?? false);
    this.lieNiveaux.set(p?.lie_niveaux ?? false);
    this.lieMatieres.set(p?.lie_matieres ?? false);
    this.droits.set(new Set(p?.permissions ?? []));
    this.erreurs.set({});
    this.erreurGenerale.set(null);
    this.tentative.set(false);
  }

  aLeDroit(d: Droit): boolean {
    return this.droits().has(d.nom);
  }

  /** Un droit qu'on n'a pas soi-meme ne s'accorde pas (ni ne se retire). */
  droitBloque(d: Droit): boolean {
    return this.droitsVerrouilles || !d.accordable;
  }

  basculerDroit(d: Droit): void {
    if (this.droitBloque(d)) {
      return;
    }
    this.droits.update((set) => {
      const suivant = new Set(set);
      suivant.has(d.nom) ? suivant.delete(d.nom) : suivant.add(d.nom);
      return suivant;
    });
    this.effacerErreur('permissions');
  }

  groupeComplet(droits: Droit[]): boolean {
    return droits.filter((d) => !this.droitBloque(d)).every((d) => this.droits().has(d.nom));
  }

  basculerGroupe(droits: Droit[]): void {
    const modifiables = droits.filter((d) => !this.droitBloque(d));
    const cocher = !this.groupeComplet(droits);
    this.droits.update((set) => {
      const suivant = new Set(set);
      modifiables.forEach((d) => (cocher ? suivant.add(d.nom) : suivant.delete(d.nom)));
      return suivant;
    });
  }

  compteGroupe(droits: Droit[]): number {
    return droits.filter((d) => this.droits().has(d.nom)).length;
  }

  erreur(champ: string): string | null {
    const serveur = this.erreurs()[champ];
    if (serveur) {
      return serveur;
    }
    if (champ === 'nom' && this.tentative() && !this.nom.trim()) {
      return 'Le nom du poste est obligatoire.';
    }
    return null;
  }

  effacerErreur(champ: string): void {
    if (this.erreurs()[champ]) {
      const { [champ]: _, ...reste } = this.erreurs();
      this.erreurs.set(reste);
    }
  }

  enregistrer(): void {
    this.tentative.set(true);
    if (!this.nom.trim()) {
      return;
    }

    const saisie: PosteSaisie = {
      nom: this.nom.trim(),
      description: this.description.trim() || null,
      est_sensible: this.estSensible(),
      lie_niveaux: this.lieNiveaux(),
      lie_matieres: this.lieMatieres(),
      permissions: [...this.droits()],
    };

    this.enregistrement.set(true);
    this.erreurGenerale.set(null);

    const echec = (erreur: HttpErrorResponse) => {
      this.enregistrement.set(false);
      if (erreur.status === 422 && erreur.error?.errors) {
        const parChamp: Record<string, string> = {};
        for (const [champ, messages] of Object.entries(erreur.error.errors as Record<string, string[]>)) {
          parChamp[champ.split('.')[0]] = messages[0];
        }
        this.erreurs.set(parChamp);
        if (parChamp['permissions'] || parChamp['est_sensible']) {
          this.erreurGenerale.set(parChamp['permissions'] ?? parChamp['est_sensible']);
        }
      } else if (erreur.status === 403) {
        this.erreurGenerale.set(erreur.error?.message || "Vous n'avez pas le droit de modifier ce poste.");
      } else {
        this.erreurGenerale.set('Enregistrement impossible. Vérifiez votre connexion puis réessayez.');
      }
    };

    const requete = this.poste ? this.service.modifier(this.poste.id, saisie) : this.service.creer(saisie);
    const creation = !this.poste;
    requete.subscribe({
      next: (poste) => {
        this.enregistrement.set(false);
        this.enregistre.emit({ poste, creation });
      },
      error: echec,
    });
  }

  fermer(): void {
    if (!this.enregistrement()) {
      this.ferme.emit();
    }
  }
}
