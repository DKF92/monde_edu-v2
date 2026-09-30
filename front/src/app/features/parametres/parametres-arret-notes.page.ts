import { Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, Router } from '@angular/router';
import { AlertController, ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { Observable } from 'rxjs';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  informationCircleOutline,
  lockClosedOutline,
  lockOpenOutline,
  cloudOfflineOutline,
  calendarOutline,
  schoolOutline,
  checkmarkCircleOutline,
  createOutline,
  flagOutline,
} from 'ionicons/icons';
import { ClasseArret, PageArretNotes, PedagogieService } from '../../core/services/pedagogie.service';

/**
 * Parametres > Arret des notes (droit notes.arreter, reserve au directeur) :
 * arreter / rouvrir les notes par classe ou pour toutes les classes, et mettre
 * fin a une periode (cloture : toutes les notes sont arretees, plus de saisie).
 */
@Component({
  selector: 'app-parametres-arret-notes',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner],
  templateUrl: './parametres-arret-notes.page.html',
  styleUrls: ['./parametres.commun.scss', './parametres-arret-notes.page.scss'],
})
export class ParametresArretNotesPage {
  private readonly service = inject(PedagogieService);
  private readonly toasts = inject(ToastController);
  private readonly alertes = inject(AlertController);
  private readonly route = inject(ActivatedRoute);
  readonly router = inject(Router);

  readonly page = signal<PageArretNotes | null>(null);
  readonly chargement = signal(false);
  readonly erreur = signal(false);
  /** Action en cours : 'tout', 'cloture' ou id de classe. */
  readonly action = signal<string | number | null>(null);

  readonly periode = computed(() => this.page()?.periode ?? null);

  constructor() {
    addIcons({ chevronBackOutline, informationCircleOutline, lockClosedOutline, lockOpenOutline, cloudOfflineOutline, calendarOutline, schoolOutline, checkmarkCircleOutline, createOutline, flagOutline });
  }

  ionViewWillEnter(): void {
    const demandee = Number(this.route.snapshot.queryParamMap.get('periode')) || this.periode()?.id;
    this.charger(demandee);
  }

  charger(periodeId?: number): void {
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.arretNotes(periodeId).subscribe({
      next: (p) => {
        this.page.set(p);
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  etat(c: ClasseArret): 'vide' | 'arretee' | 'partielle' | 'ouverte' {
    if (!c.matieres_arretees) return c.notes ? 'ouverte' : 'vide';
    return c.matieres_arretees >= c.matieres_notees ? 'arretee' : 'partielle';
  }

  pourcentage(c: ClasseArret): number {
    return c.matieres_notees ? Math.min(100, Math.round((c.matieres_arretees / c.matieres_notees) * 100)) : 0;
  }

  async arreterClasse(c: ClasseArret, rouvrir = false): Promise<void> {
    const p = this.periode();
    if (!p) return;
    const ok = await this.confirmer(
      rouvrir ? `Rouvrir les notes de ${c.libelle}` : `Arrêter les notes de ${c.libelle}`,
      rouvrir
        ? `Les professeurs pourront de nouveau modifier les notes de ${c.libelle} (${p.libelle}).`
        : `Les moyennes de toutes les matières de ${c.libelle} (${p.libelle}) seront figées : plus aucune note ne pourra être modifiée.`,
      rouvrir ? 'Rouvrir' : 'Arrêter',
    );
    if (!ok) return;
    this.executer(c.id, rouvrir ? this.service.rouvrir(p.id, c.id) : this.service.arreter(p.id, c.id));
  }

  async arreterTout(): Promise<void> {
    const p = this.periode();
    if (!p || !(await this.confirmer('Arrêter toutes les notes', `Les moyennes de toutes les classes seront figées pour ${p.libelle}. La période reste ouverte (absences, conduite).`, 'Tout arrêter'))) return;
    this.executer('tout', this.service.arreter(p.id, null));
  }

  async cloturer(rouvrir = false): Promise<void> {
    const p = this.periode();
    if (!p) return;
    const ok = await this.confirmer(
      rouvrir ? `Rouvrir ${p.libelle}` : `Mettre fin au ${p.libelle}`,
      rouvrir
        ? 'La saisie des absences et de la conduite redevient possible. Les notes arrêtées restent arrêtées : rouvrez-les classe par classe si besoin.'
        : 'Toutes les notes de toutes les classes seront arrêtées, puis la période sera clôturée : plus aucune note, absence ni conduite ne pourra être saisie.',
      rouvrir ? 'Rouvrir' : 'Clôturer',
    );
    if (!ok) return;
    this.executer('cloture', this.service.cloturerPeriode(p.id, !rouvrir));
  }

  date(d: string | null): string {
    return d ? new Date(d + 'T00:00:00').toLocaleDateString('fr-FR') : '…';
  }

  private executer(cle: string | number, requete: Observable<{ message: string }>): void {
    this.action.set(cle);
    requete.subscribe({
      next: (r) => {
        this.action.set(null);
        this.notifier(r.message);
        this.charger(this.periode()?.id);
      },
      error: (e: HttpErrorResponse) => {
        this.action.set(null);
        this.notifier(e.error?.message || 'Action impossible.', true);
      },
    });
  }

  private async confirmer(header: string, message: string, bouton: string): Promise<boolean> {
    const a = await this.alertes.create({ header, message, buttons: [{ text: 'Annuler', role: 'cancel' }, { text: bouton, role: 'confirm' }] });
    await a.present();
    return (await a.onDidDismiss()).role === 'confirm';
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
