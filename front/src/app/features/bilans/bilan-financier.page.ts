import { Component, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { IonContent, IonIcon, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { printOutline, cloudOfflineOutline, statsChartOutline, enterOutline, receiptOutline, cashOutline } from 'ionicons/icons';
import { environment } from '../../../environments/environment';
import { ColonneRapport, Rapport } from '../../core/services/pedagogie.service';
import { DocumentService } from '../../core/services/document.service';
import { AuthService } from '../../core/services/auth.service';

interface BilanFinancier {
  annee: string;
  sections: { titre: string; rapport: Omit<Rapport, 'code' | 'titre' | 'annee' | 'periode'> }[];
}

/**
 * Bilan financier general de l'annee (menu Bilans) : synthese (frais, dettes,
 * caisse, banque, ensemble) puis le detail (recettes, niveaux, modes,
 * caissiers, mois, depenses, entrees banque, reductions), comme le document.
 */
@Component({
  selector: 'app-bilan-financier',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent],
  templateUrl: './bilan-financier.page.html',
  styleUrls: ['../rapports/rapports.page.scss', './bilan-financier.page.scss'],
})
export class BilanFinancierPage {
  private readonly http = inject(HttpClient);
  private readonly documents = inject(DocumentService);
  private readonly auth = inject(AuthService);
  readonly router = inject(Router);

  readonly bilan = signal<BilanFinancier | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal<string | null>(null);
  readonly impression = signal(false);

  /** Raccourcis vers les points detailles (selon les droits). */
  readonly raccourcis = [
    { libelle: 'Point paiements', route: '/tabs/paiements', icone: 'cash-outline', droits: ['reglements.encaisser'] },
    { libelle: 'Point dépenses', route: '/tabs/bilans/depenses', icone: 'receipt-outline', droits: ['depenses.voir'] },
    { libelle: 'Point entrées', route: '/tabs/bilans/entrees', icone: 'enter-outline', droits: ['entrees.voir'] },
  ].filter((r) => r.droits.some((d) => this.auth.aPermission(d)));

  constructor() {
    addIcons({ printOutline, cloudOfflineOutline, statsChartOutline, enterOutline, receiptOutline, cashOutline });
  }

  ionViewWillEnter(): void {
    this.charger();
  }

  charger(evenement?: CustomEvent): void {
    this.chargement.set(true);
    this.erreur.set(null);
    this.http.get<BilanFinancier>(`${environment.apiUrl}/bilan-financier`).subscribe({
      next: (b) => {
        this.bilan.set(b);
        this.chargement.set(false);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
      error: (e: HttpErrorResponse) => {
        this.chargement.set(false);
        this.erreur.set(e.error?.message || 'Impossible de charger le bilan financier.');
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
    });
  }

  async imprimer(): Promise<void> {
    this.impression.set(true);
    await this.documents.afficher({ titre: 'Bilan financier général · ' + (this.bilan()?.annee ?? ''), chemin: '/rapports/bilan_financier/document', nomFichier: 'bilan-financier' });
    this.impression.set(false);
  }

  valeur(ligne: Record<string, unknown> | null, c: ColonneRapport): string {
    const v = ligne?.[c.cle];
    if (v === null || v === undefined || v === '') return c.type === 'moyenne' || c.type === 'pourcent' ? '—' : '';
    if (c.type === 'moyenne' || c.type === 'pourcent') return (v as number).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (c.type === 'pourcent' ? ' %' : '');
    if (c.type === 'nombre') return (v as number).toLocaleString('fr-FR', { maximumFractionDigits: 1 });
    if (c.type === 'montant') return (v as number).toLocaleString('fr-FR') + ' F';
    return String(v);
  }

  alignement(c: ColonneRapport): string {
    return c.type === 'nombre' || c.type === 'moyenne' || c.type === 'pourcent' || c.type === 'montant' ? 'd' : c.type === 'centre' ? 'c' : 'g';
  }
}
