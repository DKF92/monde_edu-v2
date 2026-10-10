# Migration des données V1 → V2 — fichier de mapping

Correspondance table par table et champ par champ entre la base V1 (une base par école) et la base V2 (`monde_edu_v2`, multi-établissements), avec les règles de transformation, les anomalies relevées dans les données V1 et les évolutions à faire en V2 avant la bascule.

- **Statut** : document de conception, mis à jour les 03 et 04/10/2026 avec les décisions de l'école (§ 0). Aucune base n'a été modifiée pour l'écrire.
- **Sources V1 analysées** :
  - `monde_edu-v1/bd_principale-v1.sql` : base de l'école, données jusqu'au 24/09/2025 ;
  - `monde_edu-v1/primaire-v1.sql` : base `bd_primaire` (`info_etablissement`, `code_inscription`, `parametre_global`) ;
  - le code V1 (`controllers/`, `facades/`, `pages/`).
- **Cible** : schéma V2 actuel (migrations de `database/migrations/`, voir `BASE_DE_DONNEES.md`).
- Les chiffres ci-dessous viennent du dump analysé. **Il faudra les recalculer sur un dump frais le jour de la bascule.**

---

## 0. Décisions prises (03 et 04/10/2026)

| Sujet | Décision | Conséquence |
|---|---|---|
| Niveaux (E1) | Revenir au modèle V1 : **niveau** (avec série au 2nd cycle : 2ndeA, 2ndeC, 1ereA, 1ereD, TleA1, TleA2, TleD…) **et niveau général** (2nde, 1ere, Tle). Au 1er cycle, niveau = niveau général. | Évolution V2 E1 ; mapping des niveaux (4.3) |
| Connexion (E3) | Connexion par **numéro de téléphone** (comme en V1). E-mail facultatif, mais utilisable pour se connecter. Les comptes V1 d'une même personne (un compte par poste) sont **regroupés en un seul compte**, avec un seul numéro et plusieurs postes au choix à la connexion. | Évolution V2 E3 ; regroupement des comptes (4.4) |
| Minimum d'inscription (E5) | **Calculé** par défaut, **modifiable** dans Paramètres. | Évolution V2 E5 ; le minimum V1 est repris tel quel (4.8) |
| Notes et moyennes (Q2) | **Non reprises** : le module n'a jamais vraiment servi. | § 4.17 |
| Coupes et prélèvements (Q4) | **Non repris**. | § 4.15 |
| Caisse fictive (Q5) | Caisse dont les paiements **ne comptent pas dans le bilan financier** et **n'apparaissent pas dans la liste des paiements**. On les retrouve seulement par la recherche par matricule. | Évolution V2 E6 ; marquage des règlements (4.14) |
| Nationalité (Q8, précisée le 04/10) | `E` = **Étrangère**. Valeurs gardées : **Ivoirienne**, **Malienne**, **Guinéenne**, **Burkinabè**, **Ghanéenne** ; toute autre nationalité devient **Étrangère**. | 4.9, E9 |
| Écoles et fichiers (Q10, Q11) | **Jean Glao seulement** (`190251`). Du dossier de l'école, on ne garde que **le logo, l'armoirie et l'image par défaut des élèves**. Le reste est abandonné, **photos des élèves comprises**. | 4.1, 4.9, E11 |

Décisions du 04/10/2026 :

| Sujet | Décision | Conséquence |
|---|---|---|
| Finances 2018-2020 (Q1) | **Abandonnées** : on ne reconstitue rien. | 4.11 : ni montants dus, ni réductions, ni règlements pour ces années |
| Numéros de reçu (Q6) | Renumérotation au format V2, **en gardant le numéro de versement** de la V1. | 4.14, E7 |
| `jour_expire_inscr` (Q7) | Délai de validité du reçu, donc du prochain paiement (lu dans le code). | 4.1 |
| Mots de passe (Q9) | Un **mot de passe par défaut**, à changer à la première connexion. | E4 |
| Téléphones (Q12) | Conversion à 10 chiffres selon la **règle officielle ivoirienne de 2021**. | § 4.19 |
| Caisse fictive (Q13) | **Poste gardé.** Le total de la caisse fictive n'est visible que par **ce poste**. | E6 |

---

## 1. Principes

1. **Une école V1 = un établissement V2.** Seule Jean Glao (`code_etab` `190251`) est reprise, dans un **nouvel** `etablissements`. Les établissements de démonstration (ids 1 et 2) ne sont pas touchés.
2. **La V1 n'est jamais modifiée.** Le script lit une copie de la base V1 (dump chargé dans un schéma à part, en lecture seule) et n'écrit que dans la V2.
3. **Clé de liaison V1** : la V1 relie les tables par :
   - des clés : `matricule` (élève), `mat_admin` (personnel), `id_inscr`, `code_classe`, `code_regle`, `id_dette` ;
   - souvent par **libellé** : matière, niveau, type de frais.

   Le script garde une **table de correspondance** `table V1 / clé V1 → table V2 / id V2`. Elle rend la reprise rejouable sans doublon, et permet de retrouver un reçu V1 par son numéro. Plusieurs `mat_admin` peuvent pointer vers le même compte V2 (comptes regroupés).
4. **Les montants « cache » de la V2 sont recalculés, jamais recopiés** : `inscriptions.montant_total_*`, `frais_eleves.montant_reduit/montant_paye`, `dettes.montant_reduit/montant_paye`. Le script crée les lignes sources (frais dus, réductions, règlements, lignes de règlement), puis appelle les recalculs des modèles. Les totaux V1 (`reste`, `reste_*`, `montant_reduit`…) servent seulement de **contrôle** (§ 8).
5. **Les statistiques V1 ne sont pas reprises** : `classe.nb_*`, `moy_class_*`, `rang_class_*`. La V2 les calcule à la demande.
6. **Synchronisation** : sur le serveur de l'école, l'import se fait journal de synchronisation coupé (`SET @sync_application = 1`). On lance ensuite `php artisan sync:initialiser` pour la première copie vers le serveur en ligne (voir `SYNCHRONISATION.md`).
7. **Sauvegarde** de la base V2 (`mysqldump`) avant chaque passage du script, comme pour toute migration.

---

## 2. État des données V1 (dump analysé)

| Table V1 | Lignes | Années | Remarque |
|---|---:|---|---|
| `eleve` | 10 132 | — | matricule `99999999A` (10 129), `A99999999` (2), 1 avec un espace final |
| `inscription` | 25 514 | 2017 → 2025 | 13 sans année, 15 avec un matricule inconnu, 1 doublon élève/année |
| `classe` | 524 | 2018 → 2025 | 21 lignes vides (année `0000`, niveau `0`), inutilisées |
| `reglement` | 39 836 | 2018 → 2025 | 58 sans date, 13 dates aberrantes (1970, 0022, 2027) ; **1 583 par la caisse fictive** |
| `frais` (lignes de règlement) | 66 879 | — | types payés : `INCRIPTION`, `ANNEXE`, `SCOLARITE`, `DETTE` uniquement |
| `dette` | 23 296 | 2021 → 2025 | **20 231 lignes vides** (sans matricule ni montant) ; 3 065 vraies dettes |
| `reduction` | 708 | depuis 09/2021 | 2 560 inscriptions ont une réduction sans ligne `reduction` (surtout 2018-2020) |
| `cout_formation` (grilles) | 76 | 2020 → 2025 | aucune grille pour 2018 et 2019 |
| `depense` | 792 | 2018 → 2025 | — |
| `sms` | 12 590 | → 2022 | 2 477 sans numéro |
| `administration` (personnel) | 101 | — | un compte **par poste** ; mots de passe SHA-256 sans sel ; connexion par **téléphone** (99 numéros à 8 chiffres) |
| `enseigner` | 956 | 2018, 2019, 2024, 2025 | 71 lignes avec un enseignant inconnu |
| `choix` (niveaux des éducateurs) | 73 | 2020 → 2025 | 21 sans année |
| `photo` | 1 824 | 2021 | **non repris** (décision Q10) |
| `matiere` / `niveau` / `cours` | 203 / 12 / 18 | — | coefficients **par niveau** (donc par série) |
| `note`, `moyenne`, `coupe`, `prelevement` | 55 107 / 51 072 / 2 357 / 135 | — | **non repris** (décisions Q2, Q4) |
| Tables vides | 0 | — | `absence`, `deliberation`, `distinction`, `document`, `type_examen`, `echeance_versement`, `avance_sur_salaire`, `bulletin_de_paie`, `information_salaire`, `salaire`, `rechargement_sms`, `erreur_import`, `fonction`, `interface`, `menu`, `role_fonction`, `code_inscription` |

### Fiabilité des finances par année

Contrôle : `reste` V1 = montant réduit − (tous les paiements, dette et caisse fictive comprises).

| Année V1 (`an_inscr`) | Inscriptions | Détail dû par type de frais | Grille `cout_formation` | Reste cohérent |
|---|---:|---|---|---:|
| 2018 | 3 785 | non (seul le total) | non | 42 % |
| 2019 | 4 311 | non | non | 32 % |
| 2020 | 4 440 | non | partielle | 5 % (99,9 % avec `montant_regle`) |
| 2021 | 3 721 | oui | oui | 87 % |
| 2022 | 3 186 | oui | oui | 99,4 % |
| 2023 | 2 961 | oui | oui | 99,0 % |
| 2024 | 2 528 | oui | oui | 99,7 % |
| 2025 | 564 (en cours) | oui | oui | 99,8 % |

**Conséquence** : la reprise des finances est exacte à partir de 2021. Les finances de 2018 à 2020 sont abandonnées (décision Q1). Les impayés de 2020 restent suivis : ils sont devenus des dettes en 2021 (`an_dette = 2021`), et celles-ci sont reprises.

### Règles V1 utiles au mapping (lues dans le code)

- **Année** : `an_*` = première année de l'année scolaire (`2025` = 2025-2026).
- **Niveau** : `niveau.lib_niveau` = niveau avec série (`TleA2`), `niveau.niveau_gen` = niveau général (`Tle`). Les classes, les inscriptions et les coefficients des matières utilisent le **niveau**. Les grilles de tarifs (`cout_formation`) et les niveaux des éducateurs (`choix`) utilisent le **niveau général**. Les statistiques de classe ont un rang par niveau (`rang_class_libNiveau_*`) et un rang par niveau général (`rang_class_niveauGen_*`).
- **Inscription** :
  - `montant_inscr` = frais d'inscription + scolarité + annexes **+ dette de l'année précédente** (depuis 2021) ;
  - `montant_fr_inscr` et `montant_fr_scolar` sont **déjà diminués de la réduction** (`reductionController`) : la réduction simple porte sur l'inscription pour un affecté, sur la scolarité pour un non affecté ;
  - pour un cas social (`is_cas = 2`), les frais principaux sont mis à 0 et le reste de la réduction est retiré des annexes.
- **Règlement** : `montant_regle` = part versée sur les frais de l'année, **hors dette**. La dette réglée est une ligne `frais` de type `DETTE`. Un règlement de dette seule a `montant_regle = 0` et `num_reglement = 0`.
- **Caisse fictive** : même écran d'encaissement que la caisse normale. Le paiement **diminue le reste à payer de l'élève** : sur 1 395 inscriptions concernées, aucun reste ne l'ignore (57 incohérentes pour d'autres raisons). Un SMS est envoyé au parent comme pour tout paiement. Le paiement est seulement écarté du bilan et de la liste des paiements. 1 229 de ces élèves ont aussi payé à la caisse normale.
- **Dette** : créée à la réinscription :
  - `an_dette` = **année de la nouvelle inscription** ;
  - `mont_dette` = reste de l'année précédente ;
  - `code_classe` = classe de l'année précédente.

  La V2 date une dette de son **année d'origine** (`InscriptionController::creerDette`).
- **Statut d'inscription** : `inscr_termine` 0 = sans classe, 1 = classe choisie, 2 = au moins un paiement, 3 = soldé (identique à `inscriptions.statut` V2).
- **SMS** : numéro du père s'il existe, sinon de la mère, sinon du tuteur (`reglementFacade`).

---

## 3. Ordre de reprise

Chaque étape dépend des précédentes.

1. Établissement, paramètres, images de l'école (logo, armoirie, image par défaut des élèves)
2. Années scolaires et périodes
3. Référentiels :
   - niveaux généraux et niveaux (séries) ;
   - matières et coefficients par niveau ;
   - types de frais ;
   - types de réduction.
4. Personnel : regroupement des comptes (liste validée par l'école), comptes, fiches RH, postes, accès à l'établissement
5. Éducateurs et niveaux, classes, affectations des enseignants
6. Grilles tarifaires (minimum V1 repris)
7. Élèves et tuteurs (téléphones convertis, § 4.19)
8. Inscriptions (toutes les années), puis frais dus des élèves (2021 et après)
9. Dettes
10. Réductions (sur frais et sur dettes)
11. Règlements de 2021 et après (caisse normale et caisse fictive) et lignes de règlement, puis recalcul des montants cache
12. Dépenses
13. SMS (historique)
14. Contrôles (§ 8)

---

## 4. Mapping table par table

Notation : `→` reprise directe ; **[T]** transformation ; **[C]** valeur calculée ; **[—]** non repris.

### 4.1 `etablissement` (+ `info_etablissement` de `bd_primaire`) → `etablissements`

Quand les deux tables diffèrent, `info_etablissement` (base centrale) l'emporte pour l'abonnement et les SMS, et `etablissement` pour l'identité.

| V1 | V2 | Règle |
|---|---|---|
| `code_etab` | `code` | → (`190251`) |
| `nom_etab` | `nom` | → (celui de `etablissement` ; l'orthographe diffère dans `info_etablissement`) |
| `tel_etab`, `tel1_etab` / `tel2_etab` | `telephone1`, `telephone2` | `tel_etab` → `telephone1` ; `tel1_etab`, s'il existe, → `telephone2` |
| `bp` | `boite_postale` | → |
| `quartier`, `ville`, `region`, `pays` | idem | → ; `pays` « COTE D'IVOIRE » → « Côte d'Ivoire » |
| `email_etab` | `email` | → |
| `lien_etab` | `site_web` | → |
| `logo_etab` (`logoEcole.PNG`) | `logo_path` | **[T]** copier le fichier du dossier de l'école (voir ci-dessous) vers `storage/app/public/logos/` |
| `slogan` | `slogan` | → |
| `type_etab` (`info_`) | `type_etablissement` | **[T]** COLLEGE → `college`, LYCEE → `lycee`, PRIMAIRE → `primaire`… ; vide → `mixte` |
| `indicatif_telephonique` | `indicatif_telephonique` | → |
| `is_used_sms` (`info_`) | `sms_actif` | → |
| `sms_sender` | `sms_expediteur` | → (11 caractères max) |
| `number_sms` (`info_`) | `sms_credit` | → |
| `date_expiration` | `date_expiration_abonnement` | → |
| `activation` | `statut` | OUI → `actif` |
| `nbre_el_clas` | `parametres.effectif_max_classe` | → (40) |
| `longeur_parttern` | `parametres.formats_matricule` | **[T]** `["99999999A","A99999999"]` (formats observés) |
| — | `parametres.numerotation_classes` | `chiffres` (V1 : « 6eme 1 ») |
| `jour_expire_inscr` | `parametres` (délai de validité du reçu) | **[T]** décision Q7. Dans le code V1, la date de validité d'un reçu valait « date du règlement + `jour_expire_inscr` jours » (27). Ce calcul est aujourd'hui en commentaire : la V1 propose `date_expiration` (date fixe de l'établissement), et le caissier peut la changer. La V2 garde la date de validité saisie à l'encaissement ; reprendre les 27 jours comme valeur par défaut (à confirmer dans Paramètres) |
| `passwd_etab`, `adress_server_pdf`, `ulr_api`, `bd_*`, `user_bd`, `password_bd`, `port_bd`, `image_etab`, `date_creation/modification` | **[—]** | techniques ou propres à la V1 |
| `lib_dossier` (`jean_glao`) | **[—]** | sert à retrouver le dossier de l'école |

**Images de l'école** (décisions Q10, Q11) : seules trois images sont reprises, à prendre sur le serveur V1 de l'école. Le dossier `assets/template/image/` de la copie locale les contient aussi.

| Emplacement V1 | Contenu | Cible V2 |
|---|---|---|
| `assets/template/image/logoEcole.png` (ou `.JPG`) | logo de l'école | `etablissements.logo_path` (`storage/app/public/logos/`) |
| `assets/template/image/amoirie.jpg` | armoirie (en-tête des documents officiels) | nouvelle image de l'établissement (**E11**) |
| `assets/template/image/photoEleve.jpg` (aussi dans `assets/images/photo/190251_jean_glao/`) | image par défaut d'un élève sans photo | nouvelle image de l'établissement (**E11**) |

Tout le reste est **abandonné** :
- les photos des élèves (`assets/images/photo/190251_jean_glao/{matricule}.{ext}`, table `photo`) ;
- les dossiers des autres écoles (`071159_maya_bobo`, `071160_hybonaka`, `071160_monde_edu`, `198359_jean_glao`) ;
- les images d'autres écoles ou de l'interface V1 (`maya.jpg`, `hybonaka.jpg`, `graph*.jpg`…) ;
- les imports de notes (`assets/FichiersUpload/`).

`parametre_global` (identifiants des fournisseurs SMS) : **[—]**. Ils vont dans le `.env` (`SMS_*`), jamais en base.

### 4.2 `annee`, `trimestre` → `annees_scolaires`, `periodes`

| V1 | V2 | Règle |
|---|---|---|
| `annee.code_an` | `annees_scolaires.libelle` | **[T]** `2025` → `2025-2026` |
| années trouvées dans `inscription.an_inscr`, `classe.an_classe`, `cout_formation`, `depense.an_dep`… | `annees_scolaires` | **[C]** la table `annee` ne contient que 2023-2025 : créer aussi 2017 → 2022 |
| — | `is_active` | **[C]** 1 pour l'année en cours de la V1 à la bascule, 0 sinon |
| — | `is_cloturee` | **[C]** 1 pour toutes les années antérieures |
| `trimestre` | `periodes` | **[C]** créer 3 trimestres par année. La table V1 ne couvre que 2018-2019 et ne sert qu'aux notes, non reprises. Pour l'année en cours, la période active et le découpage sont repris de la V1 au jour J |

### 4.3 Référentiels

#### Niveaux : `niveau` → niveaux généraux + niveaux (décision E1)

La V2 doit avoir, comme la V1, deux notions (évolution **E1**) :
- le **niveau général** : 6EME … 3EME, 2NDE, 1ERE, TLE (les `niveaux` V2 actuels) ;
- le **niveau** : égal au niveau général au 1er cycle, avec la série au 2nd cycle.

| V1 `lib_niveau` (niveau) | V1 `niveau_gen` | V2 niveau général | V2 niveau (proposé) |
|---|---|---|---|
| `CM2` | `Cm2` | `CM2` | `CM2` |
| `6eme`, `5eme`, `4eme`, `3eme` | idem | `6EME` … `3EME` | identique au niveau général |
| `2ndeA`, `2ndeC` | `2nde` | `2NDE` | `2NDE A`, `2NDE C` |
| `1ereA`, `1ereC`, `1ereD` | `1ere` | `1ERE` | `1ERE A`, `1ERE C`, `1ERE D` |
| `TleA1`, `TleA2`, `TleD` | `Tle` | `TLE` | `TLE A1`, `TLE A2`, `TLE D` |

- `1ereC` n'existe pas dans la table `niveau` mais apparaît sur 1 élève (`eleve.niveau_act`) : le créer, ou corriger l'élève (anomalie A21).
- `niveau.cycle` → déjà porté par le niveau général. `eff_limite_classe` (toujours 0) → **[—]**.
- `niveau.matiere_Nobligatoire` (liste de matières) → `matiere_niveau.is_obligatoire = 0` pour ces matières, **par niveau**.

Quel niveau chaque donnée utilise (même règle qu'en V1) :

| Donnée | V1 | V2 après E1 |
|---|---|---|
| Classe | `classe.lib_niveau` | **niveau** |
| Inscription : niveau suivi, niveau à suivre | `inscription.niveau_act`, `niveau_a_suivre` | **niveau** |
| Coefficients et matières obligatoires | `matiere.id_niveau`, `niveau.matiere_Nobligatoire` | **niveau** |
| Limite d'effectif par niveau | `niveau.eff_limite_classe` | **niveau** |
| Grilles de tarifs, minimum d'inscription | `cout_formation.lib_niveau` (`Tle`) | **niveau général** |
| Niveaux suivis par un éducateur | `choix.niveau_gen`, `administration.lib_niveau` | **niveau général** (il suit toutes les séries) |
| Rangs, statistiques, rapports | `rang_class_libNiveau_*` et `rang_class_niveauGen_*` | les deux regroupements |
| Inscription en ligne de l'État | `en_ligne.niveauact_ligne` (`2NDE`) | **niveau général** |

#### Matières : `matiere` + `cours` → `matieres`, `matiere_niveau`

La V1 a une ligne `matiere` par **niveau** (`lib_mat`, `coef_mat`, `id_niveau`). La V2 a une matière par établissement, et un coefficient par niveau dans `matiere_niveau` (par **niveau avec série** après E1).

| V1 `lib_mat` (= `cours.lib_cour`) | V1 abrév. (`cours.lib_mat_abv`) | V2 `matieres.code` | `groupe_bulletin` |
|---|---|---|---|
| FRANCAIS | FR | `FR` | LITTERAIRE |
| ANGLAIS | ANG | `ANG` | LITTERAIRE |
| ALLEMAND | ALL | `ALL` | LITTERAIRE |
| ESPAGNOL | ESP | `ESP` | LITTERAIRE |
| HISTOIRE-GEOGRAPHIE | H-G | `HG` | LITTERAIRE |
| EDHC | EDHC | `EDHC` | LITTERAIRE |
| PHILOSOPHIE | PHILO | `PHILO` | LITTERAIRE |
| MATHEMATIQUE | MATH | `MATH` | SCIENTIFIQUE |
| PHYSIQUE-CHIMIE | P-C | `PC` | SCIENTIFIQUE |
| SVT | SVT | `SVT` | SCIENTIFIQUE |
| INFORMATIQUE | TICE | `INFO` | SCIENTIFIQUE |
| EPS | EPS | `EPS` | AUTRES |
| DESSIN | DESSIN | `AP` (Arts plastiques) | AUTRES |
| MUSIQUE | MUSIQUE | **à créer** `MUS` | AUTRES |
| CONDUITE | COND | `COND` | AUTRES |
| ORTHOGRAPHE | ORTHO | **à créer** `ORTHO`, composante de `FR` (**E2**) | — |
| COMPOSITION-FRANCAISE / « COMPOSITION FRANCAISE » | C.P | **à créer** `CF`, composante de `FR` (**E2**) | — |
| EXPRESSION-ORALE / « EXPRESSION ORALE » | E.O | **à créer** `EO`, composante de `FR` (**E2**) | — |

- Les libellés avec espace (« EXPRESSION ORALE », « COMPOSITION FRANCAISE ») sont des doublons des libellés avec tiret : les fusionner.
- **Composantes du français** (décision E2, règle lue dans `apiRest/traitementMoyenne.php`) : au 1er cycle (6e à 3e), on note l'orthographe, la composition française et l'expression orale.
  - Moyenne de français = Σ (moyenne de la composante × son coefficient) ÷ Σ coefficients (coef 1 chacune : moyenne simple).
  - Le français compte ensuite avec **son** coefficient dans la moyenne générale et dans le bilan lettres. Les composantes, elles, ne comptent pas dans la moyenne générale (exclues par la V1).
  - Au 2nd cycle, les composantes ont un coefficient 0 : le français y est noté directement.
  - Le calcul est fait au nom du professeur de français de la classe.
- `coef_mat` → `matiere_niveau.coefficient` pour le **niveau** V2. Exemples : MATH TLE A1/A2 = 2, TLE D = 4 ; SVT 1ERE A = 0, 1ERE D = 4.
- **`coef_mat = 0`** (78 lignes) = matière non enseignée dans ce niveau : pas de ligne `matiere_niveau`.
- `cours.ordre` (ordre sur le bulletin) : pas de colonne V2, voir **E8**.

#### Types de frais : `type_frais` → `types_frais`

| V1 `code_type_frais` | `type_frais_nature` | V2 `types_frais.code` | V2 `nature` |
|---|---|---|---|
| `ANNEXE` | ESPECE | `FRAIS_ANNEXES` | annexe |
| `INCRIPTION` (sic) | ESPECE | `INSCRIPTION` | inscription |
| `SCOLARITE` | ESPECE | `SCOLARITE` | scolarite |
| `DROIT-EXAMEN` | ESPECE | `DROIT_EXAMEN` | annexe |
| `LIVRET-SCOLAIRE` | ESPECE | `LIVRET_SCOLAIRE` | annexe |
| `PHOTO` | ESPECE | `PHOTO` | annexe |
| `TSHIRT` | ESPECE | `TEESHIRT` | annexe |
| `TENUE-EPS` | ESPECE | `TENUE_EPS` | annexe |
| `MACARON` | ESPECE | `MACARON` | annexe |
| `CARTE-ACCES` | ESPECE | `CARTE_ACCES` | annexe |
| `INFORMATIQUE` | ESPECE | `INFORMATIQUE` | annexe |
| `LOGICIEL` | ESPECE | `LOGICIEL` | annexe |
| `CRAIE` | NATURE | `CRAIE` | en_nature |
| `RAME` | NATURE | `RAME` | en_nature |
| (`frais.type_frais = 'DETTE'`) | — | pas de type : ligne sur `dette_id` | dette |

- `type_frais_ordre` → `types_frais.ordre` : **reprendre l'ordre V1**, qui est l'ordre de paiement de l'école.
- `type_reste` : **[—]** (nom de colonne V1).
- `is_active` V2 : 1 pour les types ayant un montant non nul dans au moins une grille ou inscription. Dans ce dump, seuls ANNEXE, INSCRIPTION, SCOLARITE, CRAIE et RAME sont utilisés.
- `applicable_affecte` : 0 pour SCOLARITE (V1 : scolarité à 0 dans les grilles « affecté »).

#### Types de réduction → `types_reductions`

Création par défaut : `REDUCTION` et `CAS`. Correspondance V1 : `inscription.is_cas` 0 ou 1 → `REDUCTION`, 2 → `CAS`.

### 4.4 Personnel : `administration`, `choix` → `users`, `personnels`, `model_has_roles`, `etablissement_user`, `personnel_matieres`, `educateur_niveaux`

#### Regroupement des comptes (décision E3)

En V1, une personne qui occupe deux postes a **deux comptes, avec deux numéros** (ex. comptable et caissier). En V2, elle a **un seul compte**, un seul numéro de téléphone et plusieurs postes, choisis à la connexion (modale d'espace de travail, déjà en place).

Comme les numéros diffèrent, on ne peut pas regrouper par téléphone. Le script **propose** des regroupements, et l'école les **valide** avant la reprise :

| Critère | Résultat sur le dump |
|---|---|
| Même nom et mêmes prénoms (sans accents, majuscules) | **7 personnes, 17 comptes** : ex. économe + caisse fictive + caissier ; directeur administratif + directeur des études ; éducateur + informaticien + éducateur de niveau |
| Même nom, même 1er prénom, prénoms différents | 3 paires à vérifier (caisse fictive / comptable, caissier / comptable, professeur / professeur) |
| Même nom de famille seulement | 10 groupes : homonymes probables, non regroupés sauf indication de l'école |

Règles pour un groupe validé :
- **un seul compte V2** ; ses postes = l'union des postes de ses comptes V1 actifs (et inactifs si l'école le demande) ;
- **numéro de connexion** : celui que l'école indique (converti à 10 chiffres) ; par défaut, celui du compte actif utilisé le plus récemment (dernier règlement, dernière modification) ;
- les autres numéros sont gardés comme numéros secondaires de la fiche (`telephone2`, **E3**) ;
- **toutes les opérations V1** des comptes du groupe sont rattachées au compte unique : règlements, dettes, dépenses, inscriptions… (correspondance `mat_admin` → `users.id`) ;
- l'historique garde quand même le **poste utilisé** au moment de l'opération, quand il compte : caisse fictive (4.14).

#### Champs

| V1 | V2 | Règle |
|---|---|---|
| `mat_admin` | `users.matricule` + table de correspondance | → ; pour un compte regroupé, matricule du compte principal |
| `nom_admin` / `pnom_admin` | `users.name` / `users.prenoms` | → en majuscules ; corriger le double encodage UTF-8 (« Ã¨ » → « è ») |
| `num_admin` | `users.telephone` (**identifiant de connexion**, unique) | **[T]** conversion à 10 chiffres (§ 4.19) : 70 Orange, 20 MTN, 9 Moov ; 1 déjà à 10 chiffres ; 1 à 9 chiffres, à corriger par l'école |
| `sexe_admin` | `users.sexe` | → |
| `passwd_admin` (SHA-256) | `users.password` | **[—]** décision Q9 : **mot de passe par défaut** pour tous, `doit_changer_mot_de_passe = 1` |
| `actif` | `users.statut` | 1 → `actif`, 0 ou vide → `inactif`. Les 56 comptes inactifs sont gardés : ce sont des caissiers ou des auteurs d'opérations passées |
| `mdp_modif` | **[—]** | changement toujours forcé à la 1re connexion |
| `mot_recuperation` | **[—]** | mot de récupération V1 en clair, non repris |
| — | `users.email` | vide (facultatif, utilisable à la connexion s'il est rempli) |
| `date_creation` / `date_modif` | `created_at` / `updated_at` | → |
| — | `users.etablissement_id`, `etablissement_user` | **[C]** nouvel établissement, `is_defaut = 1` |
| `role_code` | `model_has_roles` (postes) | **[T]** tableau ci-dessous ; plusieurs postes si le compte est regroupé |
| `diplome` | `personnels.diplome` | → ; `XXX` → vide |
| `statut` | `personnels.statut_contrat` | `P`, `PERMANENT` → `permanent` ; `VACATAIRE` → `vacataire` |
| `date_embauche` | `personnels.date_embauche` | → (la plus ancienne du groupe) |
| `nationalite` | `personnels.nationalite` | même règle que pour les élèves (4.9) : `IV` → Ivoirienne |
| `quartier` | `personnels.quartier` | → ; `XXX` → vide |
| `matiere_prof` | `personnels.matiere_principale_id` + `personnel_matieres` | **[T]** par le tableau des matières |
| `lib_niveau` (éducateur, niveau général) | `educateur_niveaux` (année en cours) | **[T]** seulement si aucune ligne `choix` n'existe pour cette année |
| `encadrant`, `poste`, `page_accueil` | **[—]** | `poste` = libellé du rôle ; `page_accueil` n'existe pas en V2 |

| V1 `role_code` | Comptes | V2 poste (`roles.code`) |
|---|---:|---|
| `superAdmin`, `administrateur` | 0 | `super_admin` |
| `fondateur` | 3 | `fondateur` |
| `directeurEtude` | 2 | `directeur_etudes` |
| `directeurEtudAdj` | 0 | `adjoint_directeur_etudes` |
| `directeurAdmin`, `dirAdmin` | 1 | `directeur_administratif` |
| `comptable` | 1 | `comptable` |
| `econome` | 2 | `econome` |
| `caissier` | 3 | `caissier` |
| `caisseFict` (« Fictive ») | 3, tous inactifs | **nouveau poste `caisse_fictive`** (**E6**, gardé pour l'avenir) |
| `educateur`, `educateurNiveau` | 14 + 4 | `educateur` (+ `educateur_niveaux`) |
| `professeur` | 65 | `professeur` |
| `informaticien` | 2 | `informaticien` |
| `corspFich` | 0 | `correspondant_fichier` |
| `bbb` | 1 | compte de test (même personne qu'un éducateur inactif) : non repris |

`choix` → `educateur_niveaux` :
- `annee` → année V2 ;
- `mat_admin` → compte (regroupé) → personnel ;
- `niveau_gen` → **niveau général** (`Tle` → `TLE`) ;
- les 21 lignes sans année vont à l'année de création de la ligne si elle est connue, sinon elles sont ignorées.

`role`, `fonction`, `interface`, `menu`, `role_fonction` : **[—]**. Les postes et droits V2 viennent de `config/roles.php` (iso menus V1, déjà fait).

### 4.5 `classe` → `classes`

| V1 | V2 | Règle |
|---|---|---|
| `an_classe` | `annee_scolaire_id` | **[T]** ; lignes `0000` ignorées (21, aucune inscription) |
| `lib_niveau` | **niveau** (avec série) | **[T]** tableau des niveaux (E1) |
| `lib_niveau` + `lib_classe` | `libelle` | **[T]** `{niveau} {numéro}`, ex. `6EME 1`, `TLE A2 2`, `1ERE D 1` (numérotation « chiffres ») |
| `limite_effclas` | `capacite` | → (2 classes seulement) |
| `lv2` | `langue_vivante_2` | `0`, `Sans`, vide → null |
| `num_salle` | `salle` | `0` → null |
| `mat_adminEducateur` | `educateur_id` | **[T]** personnel du compte (regroupé) |
| `mat_adminPP` ou `enseigner.pp = 1` | `professeur_principal_id` | **[T]** `mat_adminPP` en priorité |
| `eff_*`, `effectif`, `nb_*`, `moy_*`, `rang_*`, `niveau_gen`, `num_cycle` | **[—]** | statistiques recalculées en V2 |

### 4.6 `enseigner` → `affectations_enseignants`

| V1 | V2 |
|---|---|
| `an_ens` | année |
| `mat_admin` | personnel du compte regroupé |
| `code_classe` | classe |
| `id_mat` | matière V2, par le `lib_mat` de la ligne `matiere` V1 |
| `pp` | voir 4.5 |

- Les 71 lignes à enseignant inconnu sont ignorées et listées.
- Clé unique V2 (classe, matière, année) : en cas de doublon, garder la ligne la plus récente (`id_ens` max).

### 4.7 `emploi_temps`, `jour` → `emplois_du_temps`

**[—]** 5 lignes pour une seule classe, sans horaires : données de test, non reprises.

### 4.8 `cout_formation` → `grilles_tarifaires`, `grille_tarifaire_lignes`

Une ligne V1 = une grille V2 (année, **niveau général**, affecté).

| V1 | V2 | Règle |
|---|---|---|
| `an_cout_formation` | `annee_scolaire_id` | **[T]** |
| `lib_niveau` (`6eme` … `Tle`) | niveau **général** | **[T]** |
| `affecte` | `affecte` | → |
| `frais_inscr` | ligne `INSCRIPTION` | → |
| `scolarite` | ligne `SCOLARITE` | → (0 pour un affecté) |
| `frais_anexe` | ligne `FRAIS_ANNEXES` | → |
| `cout_droit_examen`, `cout_livret_scolaire`, `cout_photo`, `cout_teeshirt`, `cout_tenueEPS`, `cout_marcaron`, `cout_carte_acces`, `cout_informatique`, `cout_logiciel` | lignes du type correspondant | seulement si > 0 (tous à 0 dans ce dump) |
| `cout_craie`, `cout_rame` | lignes `CRAIE`, `RAME` (quantité) | seulement si > 0 |
| `montant_mini` | `montant_minimum_inscription` | **→ repris tel quel** et marqué « modifié à la main » (décision E5). Il diffère du minimum calculé dans 47 grilles sur 76 (ex. 6EME affecté 2021 : V1 20 000, calcul 10 000) |
| `date_create` / `date_modif` | `created_at` / `updated_at` | → |

### 4.9 `eleve` (+ dernière `inscription`) → `eleves`, `tuteurs`, `eleve_tuteur`

| V1 | V2 | Règle |
|---|---|---|
| `matricule` | `eleves.matricule` | → (espaces supprimés) ; clé unique (établissement, matricule) |
| `nom` / `pnom` | `nom` / `prenoms` | → en majuscules, double encodage corrigé |
| `date_nais` | `date_naissance` | → ; 74 dates vides ou improbables (avant 1980, après 2022) listées, reprises si valides |
| `lieu_nais` | `lieu_naissance` | → |
| `sexe` | `sexe` | → |
| `nationnalite` | `nationalite` | **[T]** décision Q8, ci-dessous |
| `num_eleve` | `telephone` | **[T]** conversion à 10 chiffres (§ 4.19) |
| `actif` | `statut` | 1 → `actif`, 0 → `inactif` |
| `niveau_act` | **[—]** | porté par l'inscription |
| dernière `inscription.handicap` | `particularites_medicales` | → |
| dernière `inscription.orphelin_pere/_mere` | `orphelin_pere/_mere` | → |
| dernière `inscription.quartier` | `quartier` | → |
| `photo.lib_photo` | `photo_path` | **[—]** photos abandonnées (décision Q10) ; un élève sans photo affiche l'image par défaut de l'établissement (E11) |

**Nationalité** (décision Q8 du 04/10) : liste fermée (E9).

| V1 | Élèves | V2 |
|---|---:|---|
| `IV`, `IVOIRIENNE` | 9 169 | Ivoirienne |
| `BURKINABE`, `BUKINABE` | 287 | Burkinabè |
| `GUINEENNE`, `GUINIENNE` | 17 | Guinéenne |
| `MALIENNE` | 13 | Malienne |
| (aucun élève ghanéen dans le dump) | 0 | Ghanéenne |
| `E` (Étrangère) | 484 | Étrangère |
| `BENINOISE`, `NIGERIANE`, `NIGERIENNE`, `TOGOLAISE`, `AUTRE` | 137 | Étrangère |
| vide | 25 | vide (à compléter) |

**Tuteurs** (la V1 n'a pas de table de tuteurs) :

| V1 | V2 `tuteurs` | `eleve_tuteur.lien_parente` |
|---|---|---|
| `eleve.pere` + `num_pere` | `nom` / `prenoms` (premier mot = nom), `telephone1` | `pere` |
| `eleve.mere` + `num_mere` | idem | `mere` |
| dernière `inscription.tuteur` + `num_tuteur` (`0` = vide) | idem | `tuteur_legal` |

- Pas de tuteur créé sans nom ni téléphone.
- **Dédoublonnage par téléphone**, **après** conversion à 10 chiffres (§ 4.19) : un même numéro = un même tuteur (fratries). Un parent noté avec l'ancien numéro sur un enfant et le nouveau sur un autre n'a ainsi qu'une fiche.
- `is_contact_principal` et `is_payeur` : règle V1 des SMS de paiement, c'est-à-dire père s'il a un numéro, sinon mère, sinon tuteur.

Élèves sans aucune inscription (58) : repris, statut `inactif`.

### 4.10 `inscription` → `inscriptions`

| V1 | V2 | Règle |
|---|---|---|
| `matricule` | `eleve_id` | **[T]** ; 15 matricules inconnus → rejet listé |
| `an_inscr` | `annee_scolaire_id` | **[T]** ; 13 sans année → déduite de `date_inscr` (sept.-déc. = même année, janv.-août = année − 1), sinon rejet |
| `date_inscr` | `date_inscription` | → ; vide → `date_create`, sinon 1er octobre de l'année |
| `niveau_act` | **niveau** (avec série) | **[T]** E1 |
| `code_classe` | `classe_id` | **[T]** ; `0` ou vide → null (132) |
| `inscr_termine` | `statut` | → (0 à 3), puis **recalculé** après les paiements |
| `redoublant` | `redoublant` | null → 0 |
| `affecte` | `affecte` | null → 0 |
| `boursier` | `boursier` | null → 0 |
| `langue` | `langue_vivante_2` | **[T]** `ESP`/`Esp` → `Espagnol` ; `ALL`/`All` → `Allemand` ; `0`, espace, vide → null |
| `etab_origine` / `classe_origine` | `etablissement_origine` / `classe_origine` | → |
| `decision_finale` | `decision_finale` | **[T]** `ADMIS` → `ADMIS` ; `DOUBLE` → `REDOUBLE` ; `EXCLUS` → `EXCLU` ; `1` → null (anomalie) |
| `niveau_a_suivre` | `niveau_a_suivre_id` (**niveau**) | **[T]** |
| `inscr_ligne` | `inscrit_en_ligne` | → |
| `en_ligne` (2 lignes 2024) | `inscription_en_ligne` (JSON) | **[T]** sur l'inscription du même matricule et de la même année |
| `nom_educateur` (texte « NOM PRÉNOMS ») | `enregistre_par_id` | **[T]** recherche du compte par nom et prénoms, sinon null |
| `date_create` / `date_modif` | `created_at` / `updated_at` | → |
| `montant_*`, `reste*`, `cout_*`, `reduction`, `dette*`, `reduction_dette`, `dette_annule`, `is_cas` | **[—]** directement | servent à créer `frais_eleves`, `reductions` et `dettes` (4.11 à 4.13), puis au contrôle |
| `handicap`, `orphelin_*`, `quartier`, `tuteur`, `num_tuteur` | → `eleves` / `tuteurs` | voir 4.9 |

Doublon élève/année (1 cas) : garder l'inscription qui a des règlements, sinon la plus récente, et y rattacher les règlements de l'autre.

### 4.11 Montants dus → `frais_eleves`

Une ligne par type de frais et par inscription. `montant_du` = montant **brut** (avant réduction) ; la réduction est une ligne `reductions`.

**Années 2021 et suivantes** (détail par type disponible) :

| Type V2 | `montant_du` |
|---|---|
| `FRAIS_ANNEXES` | `montant_fr_anex` (+ part de réduction « cas » retirée des annexes) |
| `INSCRIPTION` | `montant_fr_inscr` + `reduction` si affecté et `is_cas` < 2 |
| `SCOLARITE` | `montant_fr_scolar` + `reduction` si non affecté et `is_cas` < 2 ; pas de ligne pour un affecté |
| cas social (`is_cas = 2`) | montants de la **grille** de l'année (niveau général, affecté), les montants V1 ayant été mis à 0 |
| `CRAIE`, `RAME` | `montant_du = 0`, `quantite_due = cout_craie` / `cout_rame`, `quantite_remise = quantite_due − reste_cout_*` |
| autres annexes (`cout_*`) | seulement si > 0 |

Contrôle par inscription : somme des `montant_du` − réductions = `montant_reduit` V1 − `dette` V1.

**Années 2017 à 2020** : **abandonnées** (décision Q1).
- Ces inscriptions sont reprises comme historique scolaire : élève, niveau, classe, décision.
- Elles n'ont **aucun frais dû, aucune réduction et aucun règlement** en V2. Leurs montants dus V2 valent donc 0, et elles ne créent pas de dette à la réinscription.
- Non repris pour ces années : 15 996 règlements, environ 1 800 réductions et 3 grilles de 2020.
- Les impayés de 2020 restent suivis par les dettes de 2021 (4.12).

### 4.12 `dette` → `dettes`

Seules les 3 065 lignes avec matricule sont reprises. Les 20 231 lignes vides sont ignorées.

| V1 | V2 | Règle |
|---|---|---|
| `matricule` | `eleve_id` | **[T]** |
| `an_dette` | `annee_scolaire_id` | **[T]** **`an_dette − 1`** (V2 = année d'origine de la dette) |
| `mont_dette` | `montant` | → |
| `dette_annule` | `is_annulee` | → (null → 0) |
| `reduction` | ligne `reductions` (`dette_id`) | **[T]** si > 0 ; dette annulée : réduction = reste au moment de l'annulation (`mont_dette` − payé) |
| `mat_admin` | `enregistre_par_id` | **[T]** compte (regroupé) |
| `date_create` / `date_modif` | `created_at` / `updated_at` | → |
| `code_classe` | **[—]** | classe de l'année d'origine, déjà connue par l'inscription de cette année |
| `reste_dette`, `montant_apres_reduction` | **[—]** | contrôle (`montant` − réductions − payé = `reste_dette`) |

Unicité : une dette par élève et par année d'origine (aucun doublon dans le dump).

### 4.13 `reduction` (+ `inscription.reduction`, `is_cas`) → `reductions`

| V1 | V2 | Règle |
|---|---|---|
| `id_inscr` | `frais_eleve_id` | **[T]** frais visé : `INSCRIPTION` (affecté) ou `SCOLARITE` (non affecté). Cas social : frais principaux puis annexes, une ligne par frais, reliées par le même `lot` |
| `montant_reduction` | `montant` | → (réparti si cas social) |
| `entite_reduc` | `motif` | → (donneur d'ordre, ex. « FONDATEUR ») |
| `is_actif` | `is_active` | → |
| `date_reduction` / `date_modif` | `created_at` / `updated_at` | → |
| `is_cas` de l'inscription | `type_reduction_id` | 0/1 → `REDUCTION`, 2 → `CAS` |
| — | `accorde_par_id` | null (la V1 ne garde pas l'auteur) |
| `id_dette` | `dette_id` | toujours vide en V1 : les réductions de dette viennent de `dette.reduction` (4.12) |

- **Inscriptions avec `reduction > 0` sans ligne `reduction`** (2 560, dont 2 559 avant 2021, abandonnées par Q1) : pour la seule inscription de 2021 et après, créer une réduction « Reprise V1 » du montant `inscription.reduction`, datée de l'inscription.
- 29 inscriptions où la somme des lignes actives ≠ `inscription.reduction` : c'est `inscription.reduction` qui fait foi (valeur utilisée par les restes V1). L'écart est listé.
- 2 lignes `reduction` sur une inscription inconnue : rejet listé.

### 4.14 `reglement` + `frais` → `reglements`, `reglement_lignes`

| V1 | V2 | Règle |
|---|---|---|
| `code_regle` | **ancien n° de reçu** (table de correspondance) | c'est le numéro imprimé sur les reçus V1 (`recu-inscription.jrxml`, `recu-dette.jrxml`). Il reste consultable et sert à la recherche (**E7**) |
| — | `numero_recu` | **[C]** décision Q6 : format V2 `{aa}{aa+1}-{n° sur 5 chiffres}` (ex. `2526-00001`), numéroté par année dans l'ordre chronologique. La V2 continue ensuite au numéro maximum |
| `id_inscr` | `inscription_id`, `eleve_id`, `annee_scolaire_id` | **[T]** via l'inscription |
| `mat_admin` | `caissier_id` | **[T]** compte (regroupé) ; 175 vides → compte technique « Reprise V1 » (inactif), car la colonne est obligatoire |
| rôle V1 du `mat_admin` = `caisseFict` | **caisse fictive** (**E6**) | **[T]** 1 583 règlements (2021 : 436, 2022 : 259, 2023 : 878, 2024 : 10 ; 32,3 millions F au total). Le marquage se fait **sur le règlement**, d'après le compte V1 d'origine, et non d'après les postes actuels du compte regroupé |
| `montant_regle` + somme des `frais` `DETTE` | `montant_total` | **[C]** |
| `num_reglement` | `numero_versement` | **→ gardé** (décision Q6). Depuis 2021, il va de 1 à 8 et est cohérent. `0` (65 règlements, presque tous des paiements de dette seule) → null. **13 versements n° 7 ou 8** dépassent la limite actuelle de 6 (paramètre `versements_max`) : ils sont repris tels quels, car la limite ne s'applique qu'aux nouveaux encaissements. 68 numéros sont en double sur une même inscription : repris tels quels, listés (A24) |
| `type_reglement` (`frais`) | `mode_paiement` | `ESPECE` → `especes` (seule valeur) |
| `date_regle` | `date_paiement` | → ; 58 vides et 13 aberrantes → date de l'inscription, listées |
| `date_expiration` | `date_expiration` | → (date seule) |
| `id_dette` | lignes vers `dette_id` | voir ci-dessous |

`frais` → `reglement_lignes` :

| V1 `frais.type_frais` | V2 |
|---|---|
| `INCRIPTION` | `frais_eleve_id` = frais `INSCRIPTION` de l'inscription |
| `ANNEXE` | `frais_eleve_id` = frais `FRAIS_ANNEXES` |
| `SCOLARITE` | `frais_eleve_id` = frais `SCOLARITE` |
| `DETTE` | `dette_id` = dette V1 `reglement.id_dette` |
| `CRAIE` / `RAME` (`type_reglement = NATURE`) | pas de ligne : met à jour `frais_eleves.quantite_remise` |
| `montant_frais = 0` (1 736) | ignorée |
| `montant_frais < 0` (3) | anomalie listée, à traiter à la main |

- Les paiements de la caisse fictive **comptent dans le payé et le reste à payer** de l'élève, comme en V1.
- Après insertion : recalcul de `frais_eleves.montant_paye`, `dettes.montant_paye`, `inscriptions.montant_total_*` et `statut`.
- Règlements sans aucune ligne `frais` (538, surtout 2018-2020) : une ligne sur le premier frais non soldé de l'inscription, dans l'ordre des types de frais (règle V1).
- 4 694 règlements où `montant_regle` ≠ somme des frais hors dette : le **détail `frais` fait foi** pour la répartition. L'écart total par année est rapporté.

### 4.15 `depense` → `depenses` ; `coupe`, `prelevement` non repris

| V1 | V2 | Règle |
|---|---|---|
| `code_dep` | `numero` | **[C]** format V2 `D{aa}{aa+1}-{n°}` par année, ordre chronologique |
| `date_dep` | `date_depense` | → (date seule) |
| `libele_dep` | `libelle` | → (≤ 300 caractères, vérifié) |
| `montant_dep` | `montant` | → |
| `an_dep` | `annee_scolaire_id` | **[T]** ; `mois_dep` est incohérent avec la date dans 33 cas : c'est la date qui fait foi |
| `receveur_dep` | `beneficiaire` | → |
| `payeur_dep` / `user_id` | `payeur_id` | **[T]** compte (regroupé) |
| `piece_comptable` | `piece_comptable` | → (toujours vide) |
| — | `source`, `mode_paiement`, `categorie` | `caisse`, `especes`, `autres` |
| `date_create` / `date_modif` | `created_at` / `updated_at` | → |

`coupe` (2 357) et `prelevement` (135), de 2019 à 2021 : **non repris** (décision Q4). Les points de caisse V2 de ces années ne retireront donc pas ces sorties.

### 4.16 `sms` → `sms_messages`

| V1 | V2 | Règle |
|---|---|---|
| `matricule` | `eleve_id` | 23 inconnus → null |
| `num_sms` | `numero` | **[T]** gardé tel qu'il a été envoyé (historique) ; 2 477 vides → non repris (colonne obligatoire) |
| `message` | `message` | → |
| `campagne` | `campagne` | → |
| `envoye` | `statut` | 1 → `envoye`, 0 → `echec` |
| `date_sms` | `created_at` | dates 1970 listées |
| — | `nombre_sms`, `envoye_par_id` | 1, null |

`rechargement_sms` (vide) → `sms_rechargements` : rien à reprendre.

### 4.17 Pédagogie : non reprise

Décision Q2 : `note` (55 107 lignes) et `moyenne` (51 072) ne sont **pas repris** ; il n'y a que 2019 et le module n'a jamais vraiment servi. La décision de fin d'année et la moyenne annuelle utiles à la réinscription sont portées par l'inscription (`decision_finale`, 4.10).

Restent nécessaires pour la pédagogie à venir en V2 : matières et coefficients par niveau (4.3), affectations des enseignants (4.6), professeurs principaux (4.5), niveaux des éducateurs (4.4).

### 4.18 Tables V1 non reprises

| Table V1 | Raison |
|---|---|
| `note`, `moyenne`, `distinction`, `deliberation` | décision Q2 (et tables vides) |
| `coupe`, `prelevement` | décision Q4 |
| `avance_sur_salaire`, `bulletin_de_paie`, `information_salaire`, `salaire` | vides ; module paie absent de la V2 |
| `confrontation`, `erreur_import`, `code_inscription`, `echeance_versement`, `document`, `absence`, `type_examen`, `rechargement_sms` | vides |
| `role`, `fonction`, `interface`, `menu`, `role_fonction` | droits V2 définis par `config/roles.php` |
| `jour`, `emploi_temps` | données de test (4.7) |
| `cours` | fusionnée dans `matieres` (4.3) |
| `annee`, `trimestre` | fusionnées dans `annees_scolaires` / `periodes` (4.2) |
| `parametre_global` | identifiants SMS → `.env` |
| `photo` (et fichiers des photos) | décision Q10 |

### 4.19 Conversion des téléphones à 10 chiffres (décision Q12)

Depuis le 31/01/2021, la Côte d'Ivoire numérote sur 10 chiffres. On place devant l'ancien numéro à 8 chiffres un préfixe à 2 chiffres, qui dépend de l'opérateur, donc des 2 premiers chiffres de l'ancien numéro.

| Ancien numéro (8 chiffres) commençant par | Opérateur | Préfixe ajouté | Exemple |
|---|---|---|---|
| 07, 08, 09, 47, 48, 49, 57, 58, 59, 67, 68, 69, 77, 78, 79, 87, 88, 89, 97, 98 | Orange (mobile) | **07** | 08 12 34 56 → 07 08 12 34 56 |
| 04, 05, 06, 44, 45, 46, 54, 55, 56, 64, 65, 66, 74, 75, 76, 84, 85, 86, 94, 95, 96 | MTN (mobile) | **05** | 05 12 34 56 → 05 05 12 34 56 |
| 01, 02, 03, 40, 41, 42, 43, 50, 51, 52, 53, 70, 71, 72, 73 | Moov (mobile) | **01** | 01 12 34 56 → 01 01 12 34 56 |
| 2x / 3x (fixe) avec 3e chiffre 0 | MTN (fixe) | **25** | — |
| 2x / 3x (fixe) avec 3e chiffre 8 | Moov (fixe) | **21** | — |
| 2x / 3x (fixe), autres | Orange (fixe) | **27** | — |

**À vérifier avant la reprise** : ce tableau doit être confronté au plan de numérotation officiel publié par l'ARTCI, surtout pour les fixes, qui sont très rares ici.

Règles :
- On ne garde que les chiffres (espaces, points, tirets supprimés).
- Indicatif `225` en tête (11 ou 13 chiffres) : retiré, puis la conversion s'applique.
- Numéro déjà à 10 chiffres commençant par 01, 05, 07, 21, 25 ou 27 : gardé.
- `0` ou vide : vide.
- Autre longueur, ou préfixe inconnu : gardé tel quel et listé (A23).

Résultat sur le dump :

| Numéros | 10 chiffres déjà | 8 chiffres convertibles | Avec indicatif 225 | Non convertibles |
|---|---:|---:|---:|---:|
| Personnel (`num_admin`) | 1 | 99 (70 Orange, 20 MTN, 9 Moov) | 0 | 1 (9 chiffres) |
| Pères (`num_pere`) | 2 574 | 4 259 | 12 | 35 |
| Mères (`num_mere`) | 1 050 | 1 922 | 1 | 11 |
| Tuteurs (`num_tuteur`) | 2 633 | 2 245 | 7 | 40 |
| Élèves (`num_eleve`) | 169 | 47 | 0 | 2 |

Les numéros non convertibles ont 2, 4, 6, 7, 9, 12, 14 ou 16 à 18 chiffres (deux numéros collés, chiffre manquant…) ; ils sont listés pour correction.

L'historique des SMS (`sms.num_sms`) garde le numéro tel qu'il a été envoyé.

---

## 5. Anomalies V1 à traiter (rapport produit par le script)

Les chiffres portent sur le périmètre repris : finances de 2021 et après (décision Q1).

| # | Anomalie | Nombre | Traitement proposé |
|---|---|---:|---|
| A1 | Lignes `dette` vides | 20 231 | ignorées |
| A2 | Inscriptions avec matricule inconnu | 15 | rejet listé |
| A3 | Inscriptions sans année | 13 | année déduite de la date, sinon rejet |
| A4 | Doublon d'inscription élève / année | 1 | fusion (4.10) |
| A5 | Règlements sans date / date aberrante | 58 / 1 | date de l'inscription, listés |
| A6 | Règlements sans caissier | 64 | compte « Reprise V1 » |
| A7 | Règlements sans ligne `frais` | 118 | répartition par l'ordre des frais |
| A8 | `montant_regle` ≠ somme des frais hors dette | 69 | le détail fait foi, écart rapporté |
| A9 | Inscriptions soldées (`inscr_termine = 3`) avec reste > 0 / restes négatifs | 10 / 62 | statut recalculé ; trop-perçus listés |
| A10 | Réduction sans ligne `reduction` | 1 | réduction « Reprise V1 » |
| A11 | Somme des réductions ≠ `inscription.reduction` | 29 | `inscription.reduction` fait foi |
| A12 | Dettes : reste incohérent (non annulées) | 443 (surtout 2021) | `montant` + paiements font foi ; écart rapporté |
| A13 | SMS / réductions avec élève ou inscription inconnus | 23 / 2 | lien vidé ou rejet |
| A14 | Affectations d'enseignants inconnus | 71 | ignorées |
| A15 | Compte `bbb`, affectation `azerty` | 1 / — | comptes de test non repris |
| A16 | Libellés de matière en double (avec espace / avec tiret) | 2 paires | fusion |
| A17 | Double encodage UTF-8 (« Ã¨ ») | noms possibles | correction à l'import |
| A18 | Élèves : dates de naissance vides ou improbables | 74 | listés |
| A19 | Nationalités non normalisées | ~10 000 | liste fermée (4.9) |
| A20 | Niveau `1ereC` absent de la table `niveau` | 1 élève | créer le niveau ou corriger l'élève |
| A21 | Une même personne avec plusieurs comptes (un par poste) | 7 sûres (17 comptes) + 3 à vérifier | regroupement validé par l'école (4.4) |
| A22 | Téléphones à 8 chiffres | 99 personnels, ~8 500 parents et élèves | conversion (§ 4.19) |
| A23 | Téléphones non convertibles (longueur ou préfixe anormal) | 1 personnel, ~90 parents et élèves | gardés tels quels, listés pour correction |
| A24 | Numéros de versement en double sur une inscription / supérieurs à 6 | 68 / 13 | repris tels quels, listés |

---

## 6. Évolutions V2 nécessaires avant la bascule

Ces écarts empêchent de reprendre fidèlement les données ou d'exploiter la V2 comme la V1. Ils sont à faire **dans l'application V2**, avant l'activation.

| # | Écart | Décision / proposition | Ce que cela touche en V2 |
|---|---|---|---|
| **E1** | **Niveau et niveau général** | Décidé : modèle V1. Le niveau porte la série au 2nd cycle (2NDE A, 2NDE C, 1ERE A/C/D, TLE A1/A2/D…). Au 1er cycle, niveau = niveau général. Proposition : garder `niveaux` comme référentiel commun des niveaux généraux, et y rattacher les niveaux avec série ; le référentiel reste commun à toutes les écoles. | Classes, inscriptions (niveau suivi, niveau à suivre), coefficients des matières (`matiere_niveau`), limites d'effectif, résultats et rangs ; listes, filtres et rapports avec les deux regroupements. Les grilles de tarifs, le minimum d'inscription et les niveaux des éducateurs restent au niveau général. La réinscription (niveau à suivre, ex. 2NDE C → 1ERE D) se fait au niveau. |
| **E2** | **Composantes du français** au 1er cycle (orthographe, composition française, expression orale) | Décidé : ce sont des composantes ; leur moyenne (pondérée par leurs coefficients) donne la moyenne de français (4.3). Proposition : une matière peut avoir une « matière parente » ; une matière qui a des composantes dans un niveau ne reçoit pas de notes directes à ce niveau, et sa moyenne est calculée. | Matières et coefficients par niveau (lien composante → français), saisie des notes (composantes au 1er cycle, français au 2nd), calcul des résultats (moyenne de français calculée, composantes hors moyenne générale), arrêt des notes, bulletins (composantes affichées sous le français), matrices et rapports |
| **E3** | **Connexion par téléphone** et comptes regroupés | Décidé : téléphone à 10 chiffres = identifiant principal, unique ; e-mail facultatif mais accepté à la connexion ; un compte, plusieurs postes au choix | Connexion (téléphone **ou** e-mail), unicité du téléphone, fiche utilisateur (téléphone obligatoire, e-mail facultatif, numéro secondaire), mot de passe oublié sans e-mail, comptes de démonstration existants (à doter d'un téléphone) |
| **E4** | Mots de passe | Décidé : un mot de passe provisoire **généré pour chaque utilisateur** (Q14), à changer à la 1re connexion. Le changement forcé existe déjà en V2 (`doit_changer_mot_de_passe`). | Commande de reprise : génération, puis remise à chacun. Proposition : envoi par SMS au numéro de connexion, ou liste imprimable remise par l'administrateur. La liste n'est jamais stockée dans le dépôt. |
| **E5** | **Minimum d'inscription** | Décidé : calculé par défaut, modifiable dans Paramètres | Grilles tarifaires : champ modifiable, indicateur « modifié à la main » pour que les recalculs ne l'écrasent pas, retour possible à la valeur calculée |
| **E6** | **Caisse fictive** | Décidé (comportement V1) : nouveau poste « Caisse fictive », gardé pour l'avenir. Ses règlements sont marqués « fictif » au moment du paiement. Ils comptent dans le reste à payer de l'élève, avec reçu et SMS comme en V1. Ils n'apparaissent ni dans les bilans, ni dans les points de caisse, ni dans la liste des paiements des autres postes, et se retrouvent par la recherche par matricule. **Seul le poste Caisse fictive voit le total de la caisse fictive** (son propre point). | Règlements (marquage), bilans et points de caisse, liste des paiements, rapports financiers et personnalisés, montants de l'accueil, assistant (consultation), dossier de l'élève / recherche par matricule, reçu, SMS, droits (nouveau poste et droits associés) |
| **E7** | Numéros de reçu V1 | Décidé : numéro de reçu au format V2 et numéro de versement V1 gardé ; l'ancien numéro de reçu (`code_regle`, imprimé sur les reçus V1) reste consultable et permet la recherche | Fiche du règlement, recherche des paiements (par ancien numéro) |
| **E8** | Ordre des matières sur le bulletin (`cours.ordre`) | Colonne d'ordre sur `matieres`, ou ordre fixé par le groupe | Bulletins |
| **E9** | Nationalité en liste fermée | Décidé : Ivoirienne, Burkinabè, Guinéenne, Malienne, Ghanéenne, Étrangère | Formulaires élève et personnel (liste au lieu d'un texte libre), lecture du reçu SIGFNE, rapports (effectifs par nationalité) |
| **E10** | Outil de reprise | Commande `php artisan migration:v1` : lecture d'un schéma V1 chargé à part ; mode **essai** (rapport sans écriture), puis mode réel ; rejouable grâce à la table de correspondance. Entrées : dump V1, les trois images de l'école, fichier des regroupements de comptes validé par l'école | — |
| **E11** | **Armoirie et image par défaut des élèves** | Décidé : reprises avec le logo | Paramètres de l'établissement (deux nouvelles images, comme le logo), en-tête des documents PDF officiels (armoirie), affichage d'un élève sans photo (image par défaut à la place des initiales) |
| **E12** | Délai de validité du reçu (`jour_expire_inscr`) | Paramètre de l'établissement (27 jours repris) qui propose la date de validité à l'encaissement ; le caissier peut la modifier | Paramètres › Paiements, encaissement |

---

## 6 bis. Commande de reprise (premier test, 04/10/2026)

`php artisan migration:v1` (`app/Console/Commands/MigrationV1.php`, règles de conversion dans `app/Support/MigrationV1/Conversions.php`).

```
php -d memory_limit=2G artisan migration:v1 --cible=monde_edu_v2_migration --preparer-cible \
    --charger-source="../../monde_edu-v1/bd_principale-v1.sql,../../monde_edu-v1/primaire-v1.sql" \
    --images=../../monde_edu-v1/assets/template/image
```

| Option | Rôle |
|---|---|
| `--cible` | Base V2 qui reçoit la reprise. **Refusée si c'est la base du `.env`** (celle de l'application). |
| `--preparer-cible` | Supprime puis recrée la base cible, la migre, pose les droits et les niveaux. Seulement si son nom contient « migration » ou « test ». |
| `--source` | Base qui contient la copie de la V1 (par défaut `monde_edu_v1_source` ; son nom doit contenir « v1 »). Elle n'est jamais modifiée par la reprise. |
| `--charger-source` | Charge d'abord les dumps V1 dans la base source. Inutile ensuite tant que les dumps ne changent pas. |
| `--images` | Dossier des images de l'école : `logoEcole`, `amoirie`, `photoEleve`. Par défaut `storage/app/public/logos`. Les copies sont nommées par le code de l'école, par exemple `logos/190251-logo.png`, et faites seulement en mode réel. |
| `--finances-depuis` | Première année dont les finances sont reprises (2021, décision Q1). |
| `--regroupements` | CSV des comptes V1 à regrouper (une ligne par personne : mat_admin du compte principal, puis les autres). Sans ce fichier : regroupement automatique par nom et prénoms identiques, listé dans le rapport (P2) pour validation. |
| `--essai` | Tout faire puis annuler : seul le rapport reste. |
| `--copier-v2` | Base V2 à copier dans la cible (nom contenant « migration ») : mysqldump de la V2 (lecture seule, gardé comme sauvegarde), cible recréée à partir de ce dump puis migrée. L'école V1 s'ajoute aux données V2 existantes. |
| `--activer` | Après une reprise réussie, l'application utilise la cible : `DB_DATABASE` du `.env` est changé (ancien `.env` gardé), puis le cache de configuration est vidé. |
| `--mysql-bin` | Dossier de `mysql` et `mysqldump`, si la recherche automatique (PATH, puis Laragon) échoue. |

| `--compte` | Id d'un compte de la base de l'application, recopié dans la cible (même e-mail, même mot de passe) comme Super admin de l'école reprise. Sans lui, personne ne peut se connecter à une base vidée : la V2 se connecte par e-mail et les comptes V1 n'en ont pas (E3). |

**Base qui reçoit la V1 : `migration_monde_edu`, tables vidées (la base et sa structure restent)** (décision du 04/10/2026). Sa structure est mise à jour, ses tables sont vidées (sauf `migrations`), les postes et niveaux posés, puis l'école V1 y est reprise. `monde_edu_v2` reste la base de travail d'origine. `monde_edu_v2_migration` ne servait qu'au premier test et peut être supprimée.

Les dumps V1 sont posés à la **racine de `back/`** (exclus du dépôt par `.gitignore`).

**Depuis l'application** : **Paramètres > Migration V1**, réservé au poste Super admin et au serveur de l'école. **L'application reste sur sa base (`monde_edu_v2`) pendant la reprise.** Cet écran permet de :
- choisir la base qui reçoit la V1 (nom contenant « migration ») ;
- lancer la reprise, réelle ou en essai : la commande tourne en arrière-plan avec `--preparer-cible --compte=<moi>`, sans bascule ;
- suivre son journal en temps réel ;
- voir les volumes, les contrôles et les anomalies ;
- télécharger le rapport et les mots de passe provisoires ;
- **passer sur la base migrée**, avec le bouton affiché en fin de reprise. On peut aussi choisir n'importe quelle base V2 du serveur où son compte existe. Une fois l'action validée, l'utilisateur est **déconnecté immédiatement**, et sa prochaine connexion se fait sur la base choisie. Les dumps V1 récents n'ont plus `classe.limite_effclas` ni `inscription.inscr_ligne` : ces colonnes sont lues si elles existent.

**En ligne de commande** : script `back/scripts/migration-monde-edu.ps1`, depuis `back/` :

```
powershell -ExecutionPolicy Bypass -File scripts\migration-monde-edu.ps1 -Compte 1          # réel : vide la base, reprise, activation
powershell -ExecutionPolicy Bypass -File scripts\migration-monde-edu.ps1 -Compte 1 -Essai   # reprise annulée, sans activation
```

La base cible ne peut pas être celle que l'application utilise. Pour relancer une reprise, il faut donc d'abord rebasculer sur `monde_edu_v2`, depuis l'écran ou dans le `.env`.

- Tout se fait dans **une transaction** : en cas d'erreur, rien n'est enregistré.
- Le journal de synchronisation est coupé pendant la reprise.
- La table `migration_v1_correspondances` garde, pour chaque ligne reprise, sa clé V1 et des informations sans colonne V2 pour l'instant : série (E1), caisse fictive (E6), ancien numéro de reçu (E7).
- **Rapport** et **mots de passe provisoires** (un par personne, Q14) : `storage/app/private/migration-v1/`, hors dépôt. Le fichier des mots de passe est à remettre puis supprimer.

**Limites du premier test** (évolutions V2 pas encore faites) :
- **E1** : les classes portent la série dans leur libellé (`TLE A2 1`), et les inscriptions le niveau général. Le coefficient retenu est celui de la série la plus inscrite (écarts listés).
- **E2** : orthographe, composition française et expression orale sont des matières sans lien avec le français.
- **E3** : les comptes ont leur téléphone, mais la connexion par téléphone n'existe pas encore.
- **E6** : les paiements de la caisse fictive sont repris, mais apparaissent encore dans les bilans.
- **E11** : l'armoirie et l'image par défaut sont copiées, en attendant leurs colonnes. Leur chemin est rangé dans `parametres`.

**Résultat du premier test** (dump analysé, 1 min 15) :
- **Volumes repris** : 10 132 élèves, 25 490 inscriptions, 16 247 tuteurs, 503 classes, 91 comptes (dont 9 comptes V1 regroupés), 3 065 dettes, 23 766 règlements, 611 dépenses et 10 108 SMS.
- **Identiques à la V1** : l'encaissé de chaque année de 2021-2022 à 2025-2026, les dépenses et le nombre d'élèves.
- **Restes à payer** : sur environ 13 000 inscriptions de 2021 et après, 61 écarts restent à analyser. Il reste aussi 3 dettes sur 3 065. Tous les autres écarts sont des restes enregistrés faux dans la V1, que la V2 recalcule correctement.

## 7. Plan de bascule proposé

1. **Développer** **E1 à E12** dans la V2, avec des tests. E1 d'abord : il touche le plus de pages.
2. **Préparer avec l'école** :
   - la liste des regroupements de comptes, avec le numéro de connexion à 10 chiffres de chaque personne ;
   - la façon de remettre les mots de passe provisoires (SMS ou liste) ;
   - la vérification du tableau de conversion des numéros (§ 4.19).
3. **Répétition** : charger un dump V1 récent dans un schéma séparé et lancer la reprise en mode essai. Lire le rapport (anomalies, écarts), corriger les règles, puis recommencer.
4. **Reprise à blanc** dans une copie de la base V2, et vérification par l'école sur des cas réels : élèves, reçus, restes à payer, dettes, caisse fictive.
5. **Vérification métier depuis l'application V2** sur les données reprises, en comparant avec la V1. Saisies réelles à tester :
   - connexion par téléphone et choix du poste ;
   - inscription et réinscription avec série ;
   - encaissement normal et fictif, réduction, dette ;
   - points de caisse, avec et sans le poste Caisse fictive.
6. **Jour J** :
   - gel de la V1 (lecture seule), dump final ;
   - reprise réelle, contrôles du § 8 ;
   - ouverture de la V2, puis `sync:initialiser` pour le serveur en ligne.
7. La V1 reste consultable en lecture pendant une période de recouvrement.

---

## 8. Contrôles après reprise

Ils sont calculés par le script et comparés à la V1. Tout écart est rapporté. Les finances sont contrôlées pour 2021 et après.

| Contrôle | V1 | V2 |
|---|---|---|
| Nombre d'élèves, inscriptions par année et par niveau, classes par année | `count` | `count` |
| Élèves par classe | `inscription.code_classe` | `inscriptions.classe_id` |
| Comptes : chaque `mat_admin` V1 a un compte V2 (direct ou regroupé) ; téléphones uniques | `administration` | table de correspondance, `users.telephone` |
| Total encaissé par année, par jour et par caissier, **caisse normale et caisse fictive séparées** | `sum(frais.montant_frais)` | `sum(reglement_lignes.montant)` |
| Reste à payer par inscription, caisse fictive comprise | `inscription.reste` | dû − réduit − payé + dette restante |
| Dettes : montant, réduit, payé, reste | `dette` | `dettes` |
| Réductions par année et par type | `inscription.reduction`, `is_cas` | `reductions` |
| Dépenses par année | `sum(depense.montant_dep)` | `sum(depenses.montant)` |
| Point de caisse annuel (2022+) | encaissements de la caisse normale − dépenses | point de caisse V2 (sans la caisse fictive) |
| Numéros de versement | `num_reglement` | `numero_versement` |
| Statut d'inscription | `inscr_termine` | `inscriptions.statut` recalculé |

En 2021, le point de caisse V2 diffère de la V1 du montant des coupes et prélèvements, qui ne sont pas repris. Il n'y a pas de point de caisse V2 pour 2018-2020 (finances abandonnées).

---

## 9. Correspondance rapide des clés

| Clé V1 | Clé V2 |
|---|---|
| `code_etab` | `etablissements.code` |
| `annee.code_an` / `an_*` | `annees_scolaires.libelle` (`an`-`an+1`) |
| `niveau.lib_niveau` / `niveau.niveau_gen` | niveau / niveau général (E1) |
| `matiere.lib_mat` / `cours.lib_cour` | `matieres.code` |
| `type_frais.code_type_frais` | `types_frais.code` |
| `role.code_role` | `roles.code` |
| `administration.mat_admin` (un ou plusieurs) | `users.id` (correspondance, comptes regroupés) |
| `eleve.matricule` | `eleves.matricule` |
| `classe.code_classe` | `classes.id` (correspondance) |
| `inscription.id_inscr` | `inscriptions.id` (correspondance) |
| `reglement.code_regle` (n° de reçu V1) | `reglements.id` / `numero_recu` (correspondance, E7) |
| `reglement.num_reglement` | `reglements.numero_versement` |
| `dette.id_dette` | `dettes.id` (correspondance) |
| `depense.code_dep` | `depenses.id` / `numero` (correspondance) |

---

## 10. Questions

### Tranchées

| # | Sujet | Décision |
|---|---|---|
| Q1 | Finances 2018-2020 | Abandonnées (04/10) |
| Q2 | Notes et moyennes | Non reprises (03/10) |
| Q4 | Coupes et prélèvements | Non repris (03/10) |
| Q5 | Caisse fictive | Comportement V1 (03/10), E6 |
| Q6 | Numéros de reçu | Format V2, numéro de versement V1 gardé, ancien numéro consultable (04/10), E7 |
| Q7 | `jour_expire_inscr` | Délai de validité du reçu (04/10), E12 |
| Q8 | Nationalité | Ivoirienne, Burkinabè, Guinéenne, Malienne, Ghanéenne, Étrangère (04/10), E9 |
| Q9 | Mots de passe | Mot de passe par défaut, changé à la 1re connexion (04/10), E4 |
| Q10 / Q11 | Écoles et fichiers | Jean Glao ; logo, armoirie et image par défaut seulement (04/10), E11 |
| Q12 | Téléphones | Conversion à 10 chiffres, règle de 2021 (04/10), § 4.19 |
| Q13 | Avenir de la caisse fictive | Poste gardé ; seul ce poste voit son total (04/10), E6 |
| E2 | Orthographe, composition française, expression orale | Composantes du français : leur moyenne donne la moyenne de français (04/10) |
| Q14 | Mot de passe par défaut | Généré pour chaque utilisateur (04/10), E4 |

Toutes les questions sont tranchées. Seul reste un détail de mise en œuvre : la façon de remettre les mots de passe provisoires (SMS ou liste imprimée, E4).

## Mise à jour du 06/10/2026 (script `php artisan migration:v1`)

- **E1 fait** : table `niveaux` avec `niveau_general_id` ; séries créées par `NiveauSeeder` (libellés : 6eme…3eme, 2nde, 1ere, Tle, 2ndeA, 2ndeC, 1ereA, 1ereC, 1ereD, TleA1, TleA2, TleC, TleD) ; une série V1 absente est créée à la volée. Classes, inscriptions (niveau suivi et à suivre) et coefficients sur la **série** (coefficients V1 exacts par série) ; grilles et niveaux des éducateurs sur le **niveau général**.
- **Noms des classes** : libellé du niveau + numéro en exposant (`6eme¹`, `TleA2³`), lettres : `6eme A` (`App\Support\NomClasse`).
- **Billet d'entrée en classe** : V1 `etablissement.date_expiration` = date d'échéance des reçus (reglementController : date saisie à la caisse, sinon celle-ci) → paramètre `date_validite_billet` (ce n'est pas une date d'abonnement) ; `jour_expire_inscr` → `delai_validite_recu` (jours, utilisé quand le paiement est à moins de 15 jours de l'échéance).
- Éducateur affiché d'une classe : celui qui suit le niveau général (`educateur_niveaux`) ; la V1 avait mis le directeur administratif dans `classe.mat_adminEducateur`.
