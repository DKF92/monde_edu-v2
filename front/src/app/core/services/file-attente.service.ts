import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient, HttpContext, HttpContextToken, HttpErrorResponse, HttpHeaders, HttpRequest } from '@angular/common/http';
import { ToastController } from '@ionic/angular';
import { firstValueFrom } from 'rxjs';
import { stockage } from './stockage-hors-ligne';
import { ReseauService } from './reseau.service';
import { AuthService } from './auth.service';
import { SANS_CONTEXTE } from '../interceptors/auth.interceptor';

/** Requete rejouee depuis la file : jamais remise en attente par l'intercepteur. */
export const REJEU = new HttpContextToken<boolean>(() => false);

/** Saisie pedagogique faite sans reseau, envoyee au retour du reseau. */
export interface SaisieEnAttente {
  id: string;
  utilisateur: number;
  libelle: string;
  methode: string;
  /** Chemin de l'API (ex : /notes) et parametres. */
  chemin: string;
  corps: unknown;
  /** Contexte d'origine (etablissement, annee, periode, poste). */
  entetes: Record<string, string>;
  date: string;
  statut: 'en_attente' | 'rejetee';
  erreur?: string;
}

/**
 * File d'attente hors ligne (IndexedDB) : seules les ecritures pedagogiques y
 * entrent (voir reseauInterceptor). A la reconnexion, elles sont envoyees dans
 * l'ordre, avec leur contexte d'origine ; une saisie refusee par le serveur
 * (ex : notes arretees entre-temps) reste visible dans le Profil avec le motif.
 */
@Injectable({ providedIn: 'root' })
export class FileAttenteService {
  private readonly http = inject(HttpClient);
  private readonly reseau = inject(ReseauService);
  private readonly auth = inject(AuthService);
  private readonly toasts = inject(ToastController);

  private readonly toutes = signal<SaisieEnAttente[]>([]);
  readonly elements = computed(() => this.toutes().filter((s) => s.utilisateur === this.auth.user()?.id));
  readonly enAttente = computed(() => this.elements().filter((s) => s.statut === 'en_attente').length);
  readonly rejetees = computed(() => this.elements().filter((s) => s.statut === 'rejetee'));
  readonly envoi = signal(false);

  constructor() {
    void this.charger().then(() => this.envoyer());
    this.reseau.reconnexion.subscribe(() => void this.envoyer());
  }

  async ajouter(req: HttpRequest<unknown>, chemin: string, libelle: string): Promise<void> {
    const entetes: Record<string, string> = {};
    for (const cle of req.headers.keys()) {
      if (cle.toLowerCase().startsWith('x-')) entetes[cle] = req.headers.get(cle)!;
    }
    const saisie: SaisieEnAttente = {
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      utilisateur: this.auth.user()?.id ?? 0,
      libelle,
      methode: req.method,
      chemin: chemin + (req.params.keys().length ? `?${req.params.toString()}` : ''),
      corps: req.body,
      entetes,
      date: new Date().toISOString(),
      statut: 'en_attente',
    };
    await stockage.ecrire('file', saisie);
    this.toutes.update((l) => [...l, saisie]);
  }

  /** Envoie les saisies en attente de l'utilisateur connecte, dans l'ordre. */
  async envoyer(): Promise<void> {
    if (this.envoi() || !this.reseau.connecte() || !this.auth.token()) return;
    const aEnvoyer = this.elements().filter((s) => s.statut === 'en_attente').sort((a, b) => a.date.localeCompare(b.date));
    if (!aEnvoyer.length) return;
    this.envoi.set(true);
    let envoyees = 0;
    try {
      for (const s of aEnvoyer) {
        try {
          await firstValueFrom(this.http.request(s.methode, this.reseau.base() + s.chemin, {
            body: s.corps,
            headers: new HttpHeaders(s.entetes),
            context: new HttpContext().set(SANS_CONTEXTE, true).set(REJEU, true),
          }));
          await stockage.supprimer('file', s.id);
          this.toutes.update((l) => l.filter((x) => x.id !== s.id));
          envoyees++;
        } catch (e) {
          const erreur = e as HttpErrorResponse;
          if (erreur.status === 0) break; // toujours sans reseau : on reessaiera
          const rejet: SaisieEnAttente = { ...s, statut: 'rejetee', erreur: erreur.error?.message || `Refusée par le serveur (${erreur.status}).` };
          await stockage.ecrire('file', rejet);
          this.toutes.update((l) => l.map((x) => (x.id === s.id ? rejet : x)));
        }
      }
    } finally {
      this.envoi.set(false);
    }
    const refusees = this.rejetees().length;
    if (envoyees || refusees) {
      const message = [envoyees ? `${envoyees} saisie${envoyees > 1 ? 's' : ''} hors ligne envoyée${envoyees > 1 ? 's' : ''}` : '', refusees ? `${refusees} refusée${refusees > 1 ? 's' : ''} (voir Profil)` : ''].filter(Boolean).join(' · ');
      const toast = await this.toasts.create({ message: message + '.', duration: 4000, position: 'bottom', color: refusees ? 'warning' : 'success' });
      await toast.present();
    }
  }

  /** Abandonne une saisie (refusee, ou que l'utilisateur ne veut plus envoyer). */
  async retirer(id: string): Promise<void> {
    await stockage.supprimer('file', id);
    this.toutes.update((l) => l.filter((x) => x.id !== id));
  }

  private async charger(): Promise<void> {
    this.toutes.set(await stockage.tout<SaisieEnAttente>('file'));
  }
}
