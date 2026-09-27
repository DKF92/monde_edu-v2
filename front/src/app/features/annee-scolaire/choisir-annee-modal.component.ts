import { Component, EventEmitter, Input, OnChanges, Output, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  IonModal,
  IonSelect,
  IonSelectOption,
  IonButton,
  IonIcon,
  IonSpinner,
  IonText,
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import { calendarOutline, closeOutline } from 'ionicons/icons';
import { AnneeScolaireService } from '../../core/services/annee-scolaire.service';
import { PeriodeService } from '../../core/services/periode.service';
import { AuthService } from '../../core/services/auth.service';
import { AnneeScolaire } from '../../core/models/user.model';

/**
 * Modale de choix de l'annee scolaire.
 *
 * - obligatoire=true (juste apres connexion, montee dans TabsPage) : aucun
 *   moyen de la fermer sans choisir (pas de bouton annuler, pas de fermeture
 *   au clic sur le fond, pas de bouton retour). Reste ouverte tant que
 *   auth.anneeScolaire() est nul.
 * - obligatoire=false (depuis le profil, pour "changer d'annee") : peut etre
 *   annulee sans rien changer.
 */
@Component({
  selector: 'app-choisir-annee-modal',
  standalone: true,
  imports: [
    CommonModule,
    IonModal,
    IonSelect,
    IonSelectOption,
    IonButton,
    IonIcon,
    IonSpinner,
    IonText,
  ],
  templateUrl: './choisir-annee-modal.component.html',
  styleUrl: './choisir-annee-modal.component.scss',
})
export class ChoisirAnneeModalComponent implements OnChanges {
  private readonly anneeScolaireService = inject(AnneeScolaireService);
  private readonly periodeService = inject(PeriodeService);
  private readonly auth = inject(AuthService);

  @Input() isOpen = false;
  @Input() obligatoire = true;
  @Output() ferme = new EventEmitter<void>();

  readonly annees = signal<AnneeScolaire[]>([]);
  readonly chargement = signal(false);
  readonly enregistrement = signal(false);
  readonly selectionId = signal<number | null>(null);

  constructor() {
    addIcons({ calendarOutline, closeOutline });
  }

  /**
   * IMPORTANT : Ionic applique canDismiss aussi bien aux fermetures
   * programmatiques (isOpen qui repasse a false) qu'aux gestes utilisateur
   * (backdrop, retour, swipe). Une valeur statique `false` bloquerait donc
   * AUSSI la fermeture automatique une fois l'annee choisie. On calcule donc
   * dynamiquement : en mode obligatoire, la fermeture n'est autorisee que
   * lorsque l'annee a bien ete choisie (ce qui correspond exactement au
   * moment ou l'on veut que la modale se ferme).
   */
  readonly peutFermer = async (): Promise<boolean> => !this.obligatoire || !!this.auth.anneeScolaire();

  ngOnChanges(): void {
    if (this.isOpen && this.annees().length === 0) {
      this.charger();
    }
  }

  private charger(): void {
    this.chargement.set(true);
    this.anneeScolaireService.lister().subscribe({
      next: (annees) => {
        this.annees.set(annees);
        this.chargement.set(false);
        this.selectionId.set(this.auth.anneeScolaire()?.id ?? null);
      },
      error: () => this.chargement.set(false),
    });
  }

  annuler(): void {
    if (!this.obligatoire) {
      this.ferme.emit();
    }
  }

  confirmer(): void {
    const annee = this.annees().find((a) => a.id === this.selectionId());
    if (!annee) {
      return;
    }

    this.enregistrement.set(true);
    this.auth.choisirAnneeScolaire(annee);

    // Selectionne automatiquement le trimestre en cours (modifiable ensuite
    // depuis le profil) pour ne pas bloquer les profils non pedagogiques ici.
    this.periodeService.lister(annee.id).subscribe({
      next: (periodes) => {
        const active = periodes.find((p) => p.is_active) ?? periodes[0];
        if (active) {
          this.auth.choisirPeriode(active);
        }
        this.enregistrement.set(false);
        this.ferme.emit();
      },
      error: () => {
        this.enregistrement.set(false);
        this.ferme.emit();
      },
    });
  }
}
