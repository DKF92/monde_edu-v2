{{-- Fiche d'inscription (V1 recu_inscr) : identite, scolarite, parents, frais, signatures ; en-tete officiel. --}}
@php
  $e = $d['eleve'];
  $m = fn ($v) => number_format((int) $v, 0, ',', "\u{202F}").' F';
  $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
  $liens = ['pere' => 'Père', 'mere' => 'Mère', 'tuteur_legal' => 'Tuteur'];
  $decisions = ['ADMIS' => 'Admis(e)', 'REDOUBLE' => 'Redouble', 'EXCLU' => 'Exclu(e)'];
  $statuts = [0 => 'En attente de choix de classe', 1 => 'En attente du 1er versement', 2 => 'En attente de solder', 3 => 'Soldé'];
  $dettes = collect($d['dettes']);
  $orphelin = collect([$e['orphelin_pere'] ? 'de père' : null, $e['orphelin_mere'] ? 'de mère' : null])->filter()->implode(' et ') ?: 'Non';
  $identite = [
      ['Matricule', $e['matricule']], ['Sexe', $e['sexe'] === 'F' ? 'Féminin' : ($e['sexe'] === 'M' ? 'Masculin' : '—')],
      ['Nom', $e['nom']], ['Prénoms', $e['prenoms']],
      ['Né(e) le', $date($e['date_naissance'])], ['À', $e['lieu_naissance']],
      ['Nationalité', $e['nationalite']], ['Téléphone', $e['telephone']],
      ['Quartier', $e['quartier']], ['Orphelin(e)', $orphelin],
  ];
  $p = $d['precedente'];
  $scolarite = [
      ['Niveau', $d['niveau']['libelle'] ?? null], ['Classe', $d['classe']['libelle'] ?? 'Non attribuée'],
      ['Statut', $d['affecte'] ? 'Affecté(e)' : 'Non affecté(e)'], ['Redoublant(e)', $d['redoublant'] ? 'Oui' : 'Non'],
      ['Boursier(ère)', $d['boursier'] ? 'Oui' : 'Non'], ['LV2', $d['langue_vivante_2'] ?: 'Aucune'],
  ];
  if ($p) {
      $scolarite[] = ['Décision '.$p['annee'], $p['decision_finale'] ? ($decisions[$p['decision_finale']] ?? $p['decision_finale']) : null];
      $scolarite[] = ['Classe '.$p['annee'], $p['classe']];
  } else {
      $scolarite[] = ['Provenance', collect([$d['etablissement_origine'], $d['classe_origine']])->filter()->implode(' · ')];
      $scolarite[] = ['Décision précédente', $d['decision_origine'] ? ($decisions[$d['decision_origine']] ?? $d['decision_origine']) : null];
  }
  $enLigne = $d['inscription_en_ligne']['numero_recu'] ?? null;
  $scolarite[] = ['Inscription en ligne', $d['inscrit_en_ligne'] ? 'Oui'.($enLigne ? ' (reçu '.$enLigne.')' : '') : 'Non'];
  $scolarite[] = ['État', $statuts[$d['statut']] ?? ''];
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Fiche d'inscription {{ $e['matricule'] }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 10mm 12mm 12mm; }
  h2 { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #1b2f5e; margin: 4mm 0 1.5mm; padding-bottom: 1pt; border-bottom: 0.8pt solid #d9deea; }
  .identite td { vertical-align: top; }
  .photo { width: 28mm; height: 35mm; border: 0.6pt solid #c9d0de; text-align: center; color: #9aa3b5; font-size: 8pt; }
  .photo img { width: 28mm; height: 35mm; }
  .champs td { font-size: 9pt; padding: 2.5pt 4pt; border-bottom: 0.5pt dotted #c9d0de; }
  .champs .l { color: #56607a; width: 22%; }
  .champs .v { font-weight: bold; width: 28%; }
  .frais th { background: #eef2fa; color: #1b2f5e; font-size: 7.5pt; text-transform: uppercase; padding: 4pt; border: 0.5pt solid #d9deea; text-align: right; }
  .frais td { font-size: 9pt; padding: 3.5pt 4pt; border: 0.5pt solid #e3e7f0; text-align: right; }
  .frais th:first-child, .frais td:first-child { text-align: left; }
  .frais .dette td { color: #a35200; }
  .frais .total td { font-weight: bold; background: #eef2fa; }
  .note { font-size: 8.5pt; color: #56607a; margin: 1.5mm 0; }
  .signatures { margin-top: 6mm; }
  .signatures td { width: 50%; height: 24mm; vertical-align: top; border: 0.6pt solid #c9d0de; padding: 4pt; font-size: 8.5pt; color: #56607a; }
  .signatures td + td { border-left: none; }
  .trace { margin-top: 3mm; font-size: 7.5pt; color: #8a93a6; text-align: center; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => 'FICHE D\'INSCRIPTION '.$d['annee']])

  <h2>Identité de l'élève</h2>
  <table class="identite">
    <tr>
      <td style="width: 31mm"><div class="photo">
        @if ($photo)
          <img src="{{ $photo }}" alt="">
        @else
          <br><br><br>Photo
        @endif
      </div></td>
      <td>
        <table class="champs">
          @foreach (array_chunk($identite, 2) as $paire)
            <tr>@foreach ($paire as [$libelle, $valeur])<td class="l">{{ $libelle }}</td><td class="v">{{ $valeur ?: '—' }}</td>@endforeach</tr>
          @endforeach
        </table>
      </td>
    </tr>
  </table>

  <h2>Scolarité {{ $d['annee'] }}</h2>
  <table class="champs">
    @foreach (array_chunk($scolarite, 2) as $paire)
      <tr>@foreach ($paire as [$libelle, $valeur])<td class="l">{{ $libelle }}</td><td class="v">{{ $valeur ?: '—' }}</td>@endforeach</tr>
    @endforeach
  </table>

  <h2>Parents et tuteur</h2>
  @if (count($e['parents']))
    <table class="champs">
      @foreach ($e['parents'] as $pa)
        <tr>
          <td class="l">{{ $liens[$pa['lien_parente']] ?? 'Parent' }}{{ $pa['is_contact_principal'] ? ' (contact)' : '' }}{{ $pa['is_payeur'] ? ' (payeur)' : '' }}</td>
          <td class="v" colspan="3">{{ collect([$pa['nom_complet'], $pa['telephone'], $pa['profession']])->filter()->implode(' · ') }}</td>
        </tr>
      @endforeach
    </table>
  @else
    <p class="note">Aucun parent renseigné.</p>
  @endif

  <h2>Frais d'inscription</h2>
  @if (count($d['frais']) || $dettes->isNotEmpty())
    <table class="frais">
      <tr><th>Frais</th><th>Montant</th><th>Réduction</th><th>Payé</th><th>Reste</th></tr>
      @foreach ($d['frais'] as $f)
        <tr><td>{{ $f['libelle'] }}</td><td>{{ $m($f['montant_du']) }}</td><td>{{ $f['montant_reduit'] ? $m($f['montant_reduit']) : '—' }}</td><td>{{ $m($f['montant_paye']) }}</td><td>{{ $m($f['reste']) }}</td></tr>
      @endforeach
      @foreach ($dettes as $x)
        <tr class="dette"><td>Dette {{ $x['annee'] }}</td><td>{{ $m($x['montant']) }}</td><td>—</td><td>{{ $m($x['montant'] - $x['reste']) }}</td><td>{{ $m($x['reste']) }}</td></tr>
      @endforeach
      <tr class="total">
        <td>Total</td>
        <td>{{ $m($d['total_du'] + $dettes->sum('montant')) }}</td>
        <td>{{ $m($d['total_reduit']) }}</td>
        <td>{{ $m($d['total_paye']) }}</td>
        <td>{{ $m($d['reste'] + $dettes->sum('reste')) }}</td>
      </tr>
    </table>
    @if ($d['montant_minimum'] !== null)
      <p class="note">Minimum à verser pour valider l'inscription : <strong>{{ $m($d['montant_minimum']) }}</strong></p>
    @endif
  @else
    <p class="note">{{ $d['classe'] ? 'Aucun frais défini pour ce niveau.' : 'Les frais seront fixés au choix de la classe.' }}</p>
  @endif

  @if (count($d['fournitures']))
    <h2>Fournitures à apporter</h2>
    <p class="note">{{ collect($d['fournitures'])->map(fn ($f) => $f['libelle'].' : '.$f['quantite_remise'].' / '.$f['quantite_due'])->implode('  ·  ') }}</p>
  @endif

  <table class="signatures">
    <tr><td>Signature du parent / tuteur</td><td>Cachet et signature de l'établissement</td></tr>
  </table>
  <p class="trace">Inscrit(e) le {{ $date($d['date_inscription']) }}{{ $d['enregistre_par'] ? ' par '.$d['enregistre_par'] : '' }} · imprimé le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</p>
</body>
</html>
