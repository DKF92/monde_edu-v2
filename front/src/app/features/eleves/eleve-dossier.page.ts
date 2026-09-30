import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { IonContent, IonIcon, IonSkeletonText } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  chevronDownOutline,
  chevronUpOutline,
  schoolOutline,
  personOutline,
  callOutline,
  cashOutline,
  documentTextOutline,
  timeOutline,
  globeOutline,
  warningOutline,
  cloudOfflineOutline,
  eyeOutline,
  ribbonOutline,
  openOutline,
  printOutline,
} from 'ionicons/icons';
import { EleveService } from '../../core/services/eleve.service';
import { CaisseService } from '../../core/services/caisse.service';
import { AuthService } from '../../core/services/auth.service';
import { AnneeDossier, DossierEleve } from '../../core/models/eleve.model';
import { LIBELLES_DECISION, LIBELLES_STATUT, TONS_STATUT } from '../../core/models/inscription.model';

/** Dossier d'un eleve : fiche, parents et tout son parcours dans l'etablissement. */
@Component({
  selector: 'app-eleve-dossier',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText],
  templateUrl: './eleve-dossier.page.html',
  styleUrl: './eleve-dossier.page.scss',
})
export class EleveDossierPage implements OnInit {
  private readonly service = inject(EleveService);
  private readonly auth = inject(AuthService);
  private readonly caisse = inject(CaisseService);
  private readonly route = inject(ActivatedRoute);
  readonly router = inject(Router);

  readonly libellesStatut = LIBELLES_STATUT;
  readonly libellesDecision = LIBELLES_DECISION;
  readonly liens: Record<string, string> = { pere: 'Père', mere: 'Mère', tuteur_legal: 'Tuteur', autre: 'Autre' };
  readonly modes: Record<string, string> = { especes: 'Espèces', mobile_money: 'Mobile money', cheque: 'Chèque', virement: 'Virement' };

  readonly dossier = signal<DossierEleve | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal(false);
  /** Annees dont le detail des frais est deplie. */
  readonly deplies = signal<Set<number>>(new Set());

  readonly peutVoirInscription = computed(() => this.auth.aPermission('inscriptions.gerer'));
  readonly anneeEnCours = computed(() => this.dossier()?.parcours.find((p) => p.en_cours) ?? null);
  readonly totalPaye = computed(() => (this.dossier()?.parcours ?? []).reduce((s, p) => s + (p.finances?.total_paye ?? 0), 0));
  readonly dettesOuvertes = computed(() => (this.dossier()?.dettes ?? []).filter((d) => !d.annulee && d.reste > 0));
  readonly totalDettes = computed(() => this.dettesOuvertes().reduce((s, d) => s + d.reste, 0));

  private premierAffichage = true;

  constructor() {
    addIcons({
      chevronBackOutline,
      chevronDownOutline,
      chevronUpOutline,
      schoolOutline,
      personOutline,
      callOutline,
      cashOutline,
      documentTextOutline,
      timeOutline,
      globeOutline,
      warningOutline,
      cloudOfflineOutline,
      eyeOutline,
      ribbonOutline,
      openOutline,
      printOutline,
    });
  }

  ngOnInit(): void {
    this.charger();
  }

  ionViewWillEnter(): void {
    if (this.premierAffichage) {
      this.premierAffichage = false;
      return;
    }
    this.charger();
  }

  charger(): void {
    this.erreur.set(false);
    this.service.dossier(Number(this.route.snapshot.paramMap.get('id'))).subscribe({
      next: (d) => {
        this.dossier.set(d);
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  basculer(p: AnneeDossier): void {
    const s = new Set(this.deplies());
    if (s.has(p.id)) {
      s.delete(p.id);
    } else {
      s.add(p.id);
    }
    this.deplies.set(s);
  }

  imprimerRecu(id: number): void {
    this.caisse.ouvrirRecu(id);
  }

  voirInscription(p: AnneeDossier): void {
    this.router.navigate(['/tabs/inscriptions', p.id]);
  }

  montant(v: number | null | undefined): string {
    return (v ?? 0).toLocaleString('fr-FR') + ' F';
  }

  dateFr(date: string | null | undefined): string {
    return date ? date.slice(0, 10).split('-').reverse().join('/') : '—';
  }

  ton(statut: number): string {
    return TONS_STATUT[statut] ?? '';
  }

  initiales(d: DossierEleve): string {
    return ((d.eleve.nom?.charAt(0) ?? '') + (d.eleve.prenoms?.charAt(0) ?? '')).toUpperCase();
  }

  age(date: string | null): string {
    if (!date) {
      return '';
    }
    const n = new Date(date);
    const a = new Date();
    let ans = a.getFullYear() - n.getFullYear();
    if (a.getMonth() < n.getMonth() || (a.getMonth() === n.getMonth() && a.getDate() < n.getDate())) {
      ans--;
    }
    return ` (${ans} ans)`;
  }
}
