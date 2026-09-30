import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams, HttpResponse } from '@angular/common/http';
import { ModalController } from '@ionic/angular';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export type FormatDocument = 'pdf' | 'doc' | 'xlsx';

/** Un document genere par l'API (recu, bilan, reste a payer...). */
export interface DemandeDocument {
  /** Titre de la visionneuse (ex. "Reçu de paiement 2627-00001"). */
  titre: string;
  /** Chemin de l'API, sans le prefixe (ex. "/reglements/14/recu"). */
  chemin: string;
  /** Criteres du document (le format est ajoute par la visionneuse). */
  params?: HttpParams;
  /** Nom du fichier telecharge, sans extension. */
  nomFichier: string;
}

/**
 * Documents de l'API affiches dans la visionneuse (modale) : apercu PDF,
 * telechargement Word / Excel / PDF, impression ; la modale ne se ferme
 * qu'avec le bouton Terminer.
 */
@Injectable({ providedIn: 'root' })
export class DocumentService {
  private readonly http = inject(HttpClient);
  private readonly modales = inject(ModalController);

  async afficher(demande: DemandeDocument): Promise<void> {
    // Import differe : evite une dependance circulaire service <-> composant.
    const { VisionneuseDocumentComponent } = await import('../../shared/visionneuse-document.component');
    const modale = await this.modales.create({
      component: VisionneuseDocumentComponent,
      componentProps: { demande },
      cssClass: 'me-modale-formulaire tres-large visionneuse-document',
      backdropDismiss: false,
      // Seul le bouton Terminer ferme la visionneuse (ni clic a cote, ni Echap, ni geste).
      canDismiss: async (_donnees?: unknown, role?: string) => role === 'terminer',
    });
    await modale.present();
  }

  /** Fichier du document dans le format demande. */
  telecharger(demande: DemandeDocument, format: FormatDocument): Observable<HttpResponse<Blob>> {
    const params = (demande.params ?? new HttpParams()).set('format', format);
    return this.http.get(`${environment.apiUrl}${demande.chemin}`, { params, responseType: 'blob', observe: 'response' });
  }

  /** Enregistre un fichier recu de l'API sur le poste. */
  enregistrer(fichier: Blob, nom: string): void {
    const url = URL.createObjectURL(fichier);
    const lien = document.createElement('a');
    lien.href = url;
    lien.download = nom;
    document.body.appendChild(lien);
    lien.click();
    lien.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30_000);
  }
}
