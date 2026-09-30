import { RecuEnLigne } from '../../core/models/inscription.model';

/** Ecart entre notre fiche et le recu de l'inscription en ligne. */
export interface Ecart {
  cle: string;
  libelle: string;
  notre: string;
  etat: string;
}

/** Ce qu'on compare avec le recu : identite de l'eleve et statut affecte. */
export interface FicheComparee {
  nom: string;
  prenoms: string;
  date_naissance: string | null;
  lieu_naissance: string | null;
  sexe: 'M' | 'F' | null;
}

/** Differences entre notre fiche et le recu du site de l'Etat. */
export function calculerEcarts(recu: RecuEnLigne, fiche: FicheComparee, affecte: boolean | null): Ecart[] {
  const liste: Ecart[] = [];
  const comparer = (cle: string, libelle: string, notre: string | null | undefined, etat: string | null | undefined) => {
    if (etat && normaliser(notre) !== normaliser(etat)) {
      liste.push({ cle, libelle, notre: notre || '—', etat });
    }
  };
  comparer('nom', 'Nom', fiche.nom, recu.nom);
  comparer('prenoms', 'Prénoms', fiche.prenoms, recu.prenoms);
  comparer('date_naissance', 'Date de naissance', dateFr(fiche.date_naissance), dateFr(recu.date_naissance ?? null));
  comparer('lieu_naissance', 'Lieu de naissance', fiche.lieu_naissance, recu.lieu_naissance);
  comparer('sexe', 'Sexe', libelleSexe(fiche.sexe), libelleSexe(recu.sexe ?? null));
  if (recu.affecte !== undefined && affecte !== null && recu.affecte !== affecte) {
    liste.push({ cle: 'affecte', libelle: 'Statut', notre: affecte ? 'Affecté(e)' : 'Non affecté(e)', etat: recu.affecte ? 'Affecté(e)' : 'Non affecté(e)' });
  }
  return liste;
}

export function dateFr(date: string | null | undefined): string {
  if (!date) {
    return '';
  }
  const [a, m, j] = date.slice(0, 10).split('-');
  return `${j}/${m}/${a}`;
}

export function libelleSexe(sexe: string | null | undefined): string {
  return sexe === 'M' ? 'Masculin' : sexe === 'F' ? 'Féminin' : '';
}

function normaliser(texte: string | null | undefined): string {
  return (texte ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toUpperCase();
}
