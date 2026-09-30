import { Component, ElementRef, computed, inject, signal, viewChild } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { IonIcon, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chatbubblesOutline, closeOutline, sendOutline, refreshOutline, playCircleOutline, arrowForwardOutline, expandOutline, sparklesOutline, bookOutline } from 'ionicons/icons';
import { environment } from '../../../environments/environment';
import { AuthService } from '../../core/services/auth.service';

interface Demo {
  id: string;
  titre: string;
}

interface Fiche {
  titre: string;
  menu: string;
  route: string;
}

interface Message {
  role: 'user' | 'assistant';
  texte: string;
  demos: Demo[];
  fiches: Fiche[];
  /** Consultation en cours ("Consultation du dossier de l'eleve..."). */
  statut: string | null;
  enCours: boolean;
  erreur: boolean;
}

interface EtatAssistant {
  ia: boolean;
  demos: (Demo & { duree: string })[];
  suggestions: string[];
}

/**
 * Assistant de support (bouton flottant sur toutes les pages) : questions en
 * langage courant, reponse en direct (flux), videos de demonstration et
 * liens vers les ecrans. Il consulte la base mais n'agit jamais a la place de
 * l'utilisateur (voir AssistantController).
 */
@Component({
  selector: 'app-assistant',
  standalone: true,
  imports: [IonIcon, IonSpinner],
  templateUrl: './assistant.component.html',
  styleUrl: './assistant.component.scss',
})
export class AssistantComponent {
  private readonly http = inject(HttpClient);
  readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly fil = viewChild<ElementRef<HTMLElement>>('fil');
  private readonly champ = viewChild<ElementRef<HTMLTextAreaElement>>('champ');

  readonly ouvert = signal(false);
  readonly etat = signal<EtatAssistant | null>(null);
  readonly messages = signal<Message[]>([]);
  readonly saisie = signal('');
  readonly envoi = signal(false);
  readonly videoAgrandie = signal<Demo | null>(null);
  readonly voirDemos = signal(false);
  readonly peutEnvoyer = computed(() => !!this.saisie().trim() && !this.envoi());
  private controleur: AbortController | null = null;

  constructor() {
    addIcons({ chatbubblesOutline, closeOutline, sendOutline, refreshOutline, playCircleOutline, arrowForwardOutline, expandOutline, sparklesOutline, bookOutline });
  }

  basculer(): void {
    this.ouvert.set(!this.ouvert());
    if (this.ouvert()) {
      if (!this.etat()) {
        this.http.get<EtatAssistant>(`${environment.apiUrl}/assistant`).subscribe({ next: (e) => this.etat.set(e), error: () => this.etat.set({ ia: false, demos: [], suggestions: [] }) });
      }
      setTimeout(() => this.champ()?.nativeElement.focus(), 150);
    }
  }

  nouvelle(): void {
    this.controleur?.abort();
    this.messages.set([]);
    this.envoi.set(false);
    this.voirDemos.set(false);
  }

  poser(question: string): void {
    this.saisie.set(question);
    this.envoyer();
  }

  touche(evenement: KeyboardEvent): void {
    if (evenement.key === 'Enter' && !evenement.shiftKey) {
      evenement.preventDefault();
      this.envoyer();
    }
  }

  async envoyer(): Promise<void> {
    const question = this.saisie().trim();
    if (!question || this.envoi()) return;
    this.saisie.set('');
    this.voirDemos.set(false);
    const historique = [...this.messages().filter((m) => m.texte && !m.erreur), this.nouveau('user', question)];
    this.messages.set([...historique, this.nouveau('assistant', '', true)]);
    this.envoi.set(true);
    this.defiler();

    this.controleur = new AbortController();
    try {
      const reponse = await fetch(`${environment.apiUrl}/assistant/messages`, {
        method: 'POST',
        headers: this.entetes(),
        body: JSON.stringify({ messages: historique.map((m) => ({ role: m.role, content: m.texte.slice(0, 4000) })) }),
        signal: this.controleur.signal,
      });
      if (!reponse.ok || !reponse.body) {
        this.majDernier({ texte: reponse.status === 429 ? 'Trop de questions en une minute : patientez un instant.' : 'L\'assistant ne répond pas pour le moment. Réessayez.', erreur: true });
        return;
      }
      const lecteur = reponse.body.getReader();
      const decodeur = new TextDecoder();
      let tampon = '';
      for (;;) {
        const { done, value } = await lecteur.read();
        if (done) break;
        tampon += decodeur.decode(value, { stream: true });
        let pos: number;
        while ((pos = tampon.indexOf('\n\n')) >= 0) {
          this.traiter(tampon.slice(0, pos));
          tampon = tampon.slice(pos + 2);
        }
      }
    } catch (e) {
      if ((e as Error).name !== 'AbortError') {
        this.majDernier({ texte: 'Connexion interrompue. Vérifiez votre connexion puis réessayez.', erreur: true });
      }
    } finally {
      this.majDernier({ enCours: false, statut: null });
      this.envoi.set(false);
      this.controleur = null;
      this.defiler();
    }
  }

  ouvrirFiche(f: Fiche): void {
    this.router.navigateByUrl(f.route);
    if (window.innerWidth < 768) this.ouvert.set(false);
  }

  /** Video WebM enregistree sans duree : la calcule (sinon la barre de lecture ne progresse pas). */
  corrigerDuree(evenement: Event): void {
    const v = evenement.target as HTMLVideoElement;
    if (v.duration !== Infinity) return;
    const retour = () => {
      v.removeEventListener('timeupdate', retour);
      v.currentTime = 0;
    };
    v.addEventListener('timeupdate', retour);
    v.currentTime = 1e101;
  }

  urlDemo(id: string): string {
    return `demos/${id}.webm`;
  }

  /** Mise en forme simple : gras, listes, paragraphes (texte echappe). */
  html(texte: string): string {
    const echappe = texte.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const lignes = echappe.split('\n');
    let sortie = '';
    let liste: 'ol' | 'ul' | null = null;
    const fermer = () => {
      if (liste) sortie += `</${liste}>`;
      liste = null;
    };
    for (const brute of lignes) {
      const ligne = brute.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
      const numero = ligne.match(/^\s*\d+[.)]\s+(.*)$/);
      const puce = ligne.match(/^\s*[-•*]\s+(.*)$/);
      if (numero) {
        if (liste !== 'ol') { fermer(); sortie += '<ol>'; liste = 'ol'; }
        sortie += `<li>${numero[1]}</li>`;
      } else if (puce) {
        if (liste !== 'ul') { fermer(); sortie += '<ul>'; liste = 'ul'; }
        sortie += `<li>${puce[1]}</li>`;
      } else if (ligne.trim()) {
        fermer();
        sortie += `<p>${ligne}</p>`;
      } else {
        fermer();
      }
    }
    fermer();
    return sortie;
  }

  // ------------------------------------------------ Flux

  private traiter(bloc: string): void {
    const type = bloc.match(/^event: (.*)$/m)?.[1];
    const brut = bloc.match(/^data: (.*)$/m)?.[1];
    if (!type || !brut) return;
    const d = JSON.parse(brut);
    const dernier = this.messages().at(-1);
    if (!dernier) return;
    switch (type) {
      case 'texte':
        this.majDernier({ texte: dernier.texte + d.texte, statut: null });
        break;
      case 'outil':
        this.majDernier({ statut: d.libelle });
        break;
      case 'demo':
        if (!dernier.demos.some((x) => x.id === d.id)) this.majDernier({ demos: [...dernier.demos, { id: d.id, titre: d.titre }] });
        break;
      case 'fiche':
        if (!dernier.fiches.some((x) => x.route === d.route)) this.majDernier({ fiches: [...dernier.fiches, d] });
        break;
      case 'erreur':
        this.majDernier({ texte: (dernier.texte ? dernier.texte + '\n\n' : '') + d.message, erreur: !dernier.texte });
        break;
    }
    this.defiler();
  }

  private majDernier(changement: Partial<Message>): void {
    this.messages.update((l) => (l.length ? [...l.slice(0, -1), { ...l[l.length - 1], ...changement }] : l));
  }

  private nouveau(role: Message['role'], texte: string, enCours = false): Message {
    return { role, texte, demos: [], fiches: [], statut: enCours ? 'L\'assistant réfléchit…' : null, enCours, erreur: false };
  }

  /** Memes en-tetes que l'intercepteur HTTP (fetch ne passe pas par lui). */
  private entetes(): Record<string, string> {
    const h: Record<string, string> = { Accept: 'text/event-stream', 'Content-Type': 'application/json' };
    const token = this.auth.token();
    if (token) h['Authorization'] = `Bearer ${token}`;
    const e = this.auth.etablissementActif();
    const a = this.auth.anneeScolaire();
    const p = this.auth.periode();
    const poste = this.auth.poste();
    if (e) h['X-Etablissement-Id'] = String(e.id);
    if (a) h['X-Annee-Scolaire-Id'] = String(a.id);
    if (p) h['X-Periode-Id'] = String(p.id);
    if (poste) h['X-Poste-Id'] = String(poste.id);
    return h;
  }

  private defiler(): void {
    setTimeout(() => {
      const f = this.fil()?.nativeElement;
      if (f) f.scrollTop = f.scrollHeight;
    }, 30);
  }
}
