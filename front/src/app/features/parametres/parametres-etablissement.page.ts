import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { NgTemplateOutlet } from '@angular/common';
import { Router } from '@angular/router';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  businessOutline,
  callOutline,
  locationOutline,
  imageOutline,
  cloudUploadOutline,
  trashOutline,
  cloudOfflineOutline,
  shieldCheckmarkOutline,
  alertCircleOutline,
  documentTextOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { ParametreService } from '../../core/services/parametre.service';
import { InfosEtablissement, InfosEtablissementSaisie } from '../../core/models/parametre.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';

type Champ = keyof InfosEtablissementSaisie;

/**
 * Parametres > Etablissement : identite, coordonnees et logo imprimes sur les
 * documents (bulletins, recus...). V1 : info_etablissement.
 */
@Component({
  selector: 'app-parametres-etablissement',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, SelecteurComponent, NgTemplateOutlet],
  templateUrl: './parametres-etablissement.page.html',
  styleUrls: ['./parametres.commun.scss', './parametres-etablissement.page.scss'],
})
export class ParametresEtablissementPage implements OnInit {
  private readonly service = inject(ParametreService);
  private readonly auth = inject(AuthService);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly types: { valeur: string; libelle: string }[] = [
    { valeur: 'maternelle', libelle: 'Maternelle' },
    { valeur: 'primaire', libelle: 'Primaire' },
    { valeur: 'college', libelle: 'Collège' },
    { valeur: 'lycee', libelle: 'Lycée' },
    { valeur: 'college_lycee', libelle: 'Collège et lycée' },
    { valeur: 'superieur', libelle: 'Supérieur' },
    { valeur: 'mixte', libelle: 'Mixte (plusieurs cycles)' },
  ];
  readonly optionsTypes: OptionSelecteur[] = this.types.map((t, i) => ({ valeur: i, libelle: t.libelle }));

  readonly infos = signal<InfosEtablissement | null>(null);
  readonly saisie = signal<InfosEtablissementSaisie | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal(false);
  readonly enregistrement = signal(false);
  readonly envoiLogo = signal(false);
  readonly erreurs = signal<Record<string, string>>({});

  readonly modifie = computed(() => {
    const i = this.infos();
    const s = this.saisie();
    return !!i && !!s && (Object.keys(s) as Champ[]).some((c) => (s[c] ?? '') !== (i[c] ?? ''));
  });

  readonly indexType = computed(() => this.types.findIndex((t) => t.valeur === this.saisie()?.type_etablissement));

  constructor() {
    addIcons({
      chevronBackOutline,
      businessOutline,
      callOutline,
      locationOutline,
      imageOutline,
      cloudUploadOutline,
      trashOutline,
      cloudOfflineOutline,
      shieldCheckmarkOutline,
      alertCircleOutline,
      documentTextOutline,
    });
  }

  ngOnInit(): void {
    this.charger();
  }

  charger(): void {
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.etablissement().subscribe({
      next: (e) => {
        this.appliquer(e);
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  adresseParDefaut(): string {
    const s = this.saisie();
    return [s?.boite_postale, s?.ville].filter(Boolean).join(' ') || '01 BP 1234 Abidjan 01';
  }

  telephoneParDefaut(): string {
    const s = this.saisie();
    return [s?.telephone1, s?.telephone2].filter(Boolean).join(' / ') || '27 22 00 00 00';
  }

  valeur(champ: Champ): string {
    return (this.saisie()?.[champ] as string | null) ?? '';
  }

  saisir(champ: Champ, valeur: string): void {
    this.saisie.update((s) => (s ? { ...s, [champ]: valeur } : s));
    if (this.erreurs()[champ]) {
      const { [champ]: _, ...reste } = this.erreurs();
      this.erreurs.set(reste);
    }
  }

  choisirType(index: number): void {
    this.saisir('type_etablissement', this.types[index].valeur);
  }

  annuler(): void {
    const i = this.infos();
    if (i) {
      this.appliquer(i);
    }
  }

  enregistrer(): void {
    const s = this.saisie();
    if (!s) {
      return;
    }
    const nettoye = Object.fromEntries(
      Object.entries(s).map(([k, v]) => [k, typeof v === 'string' ? v.trim() || null : v]),
    ) as InfosEtablissementSaisie;
    this.enregistrement.set(true);
    this.service.enregistrerEtablissement(nettoye).subscribe({
      next: (e) => {
        this.enregistrement.set(false);
        this.appliquer(e);
        this.auth.majEtablissementActif({ nom: e.nom, sigle: e.sigle, ville: e.ville, type_etablissement: e.type_etablissement });
        this.notifier('Informations enregistrées.');
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrement.set(false);
        if (e.status === 422 && e.error?.errors) {
          this.erreurs.set(Object.fromEntries(Object.entries(e.error.errors as Record<string, string[]>).map(([k, v]) => [k, v[0]])));
          this.notifier('Corrigez les champs en rouge.', true);
        } else {
          this.notifier('Enregistrement impossible. Vérifiez votre connexion puis réessayez.', true);
        }
      },
    });
  }

  choisirLogo(evenement: Event): void {
    const champ = evenement.target as HTMLInputElement;
    const fichier = champ.files?.[0];
    champ.value = '';
    if (!fichier) {
      return;
    }
    if (fichier.size > 2 * 1024 * 1024) {
      this.notifier('Le logo ne doit pas dépasser 2 Mo.', true);
      return;
    }
    this.envoiLogo.set(true);
    this.service.envoyerLogo(fichier).subscribe({
      next: (e) => {
        this.envoiLogo.set(false);
        this.infos.update((i) => (i ? { ...i, logo_url: e.logo_url } : i));
        this.auth.majEtablissementActif({ logo_path: e.logo_url });
        this.notifier('Logo enregistré.');
      },
      error: (e: HttpErrorResponse) => {
        this.envoiLogo.set(false);
        this.notifier(e.error?.errors?.logo?.[0] ?? "Envoi du logo impossible.", true);
      },
    });
  }

  supprimerLogo(): void {
    this.envoiLogo.set(true);
    this.service.supprimerLogo().subscribe({
      next: () => {
        this.envoiLogo.set(false);
        this.infos.update((i) => (i ? { ...i, logo_url: null } : i));
        this.auth.majEtablissementActif({ logo_path: null });
        this.notifier('Logo supprimé.');
      },
      error: () => {
        this.envoiLogo.set(false);
        this.notifier('Suppression impossible.', true);
      },
    });
  }

  libelleStatut(statut: string): string {
    return statut === 'actif' ? 'Actif' : statut === 'suspendu' ? 'Suspendu' : 'Expiré';
  }

  dateLisible(date: string | null): string {
    return date ? new Date(date).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) : 'Non renseignée';
  }

  private appliquer(e: InfosEtablissement): void {
    this.infos.set(e);
    const { code, logo_url, statut, date_expiration_abonnement, ...saisie } = e;
    this.saisie.set(saisie);
    this.erreurs.set({});
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
