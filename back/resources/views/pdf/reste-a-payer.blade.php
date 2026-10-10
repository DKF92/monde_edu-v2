@php($m = fn ($v) => number_format((int) $v, 0, ',', "\u{202F}"))
@php($z = fn ($v) => (int) $v ? $m($v) : '—')
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $titre }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 10mm 8mm 12mm; }
  h1 { margin: 4mm 0 1mm; }
  .liste { width: 100%; border-collapse: collapse; }
  .liste th { background: #eef2fa; color: #1b2f5e; font-size: 6.4pt; text-transform: uppercase; padding: 3pt 2.5pt; text-align: right; border: 0.5pt solid #d9deea; }
  .liste th.g, .liste td.g { text-align: left; }
  .liste th.c, .liste td.c { text-align: center; }
  .liste td { font-size: 7pt; padding: 2.5pt; border: 0.5pt solid #e3e7f0; text-align: right; }
  .liste .groupe-entete th { background: #d5dcef; font-size: 6.4pt; }
  .liste .classe td { background: #1b2f5e; color: #fff; font-weight: bold; font-size: 8pt; text-align: left; padding: 3.5pt 5pt; }
  .liste .sous-total td { background: #f2f5fb; font-weight: bold; }
  .liste .total-general td { background: #d5dcef; font-weight: bold; font-size: 7.5pt; }
  .liste .fort { font-weight: bold; }
  .liste .reste { color: #b35c00; font-weight: bold; }
  .liste .solde { color: #16603a; }
  .liste tr { page-break-inside: avoid; }
  .saut { page-break-before: always; }
  .pied-doc { margin-top: 4mm; font-size: 7.5pt; color: #56607a; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => 'RESTE À PAYER'])

  <h1>{{ $titre }}</h1>
  <div class="sous-titre">
    {{ $nombre }} élève{{ $nombre > 1 ? 's' : '' }}{{ $sous_titre ? ' · '.$sous_titre : '' }} · édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}
  </div>

  @if ($statistiques)
    @include('pdf._statistiques-reste')
  @endif

  <table class="liste">
    <thead>
      <tr class="groupe-entete">
        <th class="g" colspan="7">Élève</th>
        <th class="c" colspan="5">Inscription {{ $annee }}</th>
        <th class="c" colspan="5">Dettes des années précédentes</th>
        <th rowspan="2">Reste total</th>
      </tr>
      <tr>
        <th class="c">N°</th>
        <th class="g">Matricule</th>
        <th class="g" style="width: 50mm">Nom et prénoms</th>
        <th class="c">Sexe</th>
        <th class="c">Statut</th>
        <th class="c">Red.</th>
        <th class="c">Assistance</th>
        <th>Avant réduction</th>
        <th>Réduction</th>
        <th>À payer</th>
        <th>Payé</th>
        <th>Reste</th>
        <th>Dette</th>
        <th>Réduction</th>
        <th>Après réduction</th>
        <th>Payé</th>
        <th>Reste</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($classes as $groupe)
        <tr class="classe"><td colspan="18">{{ $groupe['classe'] }} · {{ count($groupe['lignes']) }} élève{{ count($groupe['lignes']) > 1 ? 's' : '' }}</td></tr>
        @foreach ($groupe['lignes'] as $i => $l)
          <tr>
            <td class="c">{{ $i + 1 }}</td>
            <td class="g">{{ $l['matricule'] }}</td>
            <td class="g fort" style="white-space: nowrap">{{ \App\Support\Noms::complet($l['nom'], $l['prenoms'], 48.2, 7, true) }}</td>
            <td class="c">{{ $l['sexe'] }}</td>
            <td class="c">{{ $l['affecte'] ? 'Aff.' : 'Non aff.' }}</td>
            <td class="c">{{ $l['redoublant'] ? 'Oui' : 'Non' }}</td>
            <td class="c">{{ ['cas' => 'Cas', 'reduction' => 'Réduction'][$l['assistance']] ?? '' }}</td>
            <td>{{ $m($l['montant_du']) }}</td>
            <td>{{ $z($l['reduction']) }}</td>
            <td class="fort">{{ $m($l['a_payer']) }}</td>
            <td>{{ $m($l['paye']) }}</td>
            <td class="{{ $l['reste_inscription'] ? 'reste' : 'solde' }}">{{ $l['reste_inscription'] ? $m($l['reste_inscription']) : ($l['a_payer'] ? 'Soldé' : '—') }}</td>
            <td>{{ $z($l['dette']) }}</td>
            <td>{{ $z($l['dette_reduite']) }}</td>
            <td>{{ $z($l['dette_apres']) }}</td>
            <td>{{ $z($l['dette_payee']) }}</td>
            <td class="{{ $l['reste_dette'] ? 'reste' : '' }}">{{ $z($l['reste_dette']) }}</td>
            <td class="{{ $l['reste'] ? 'reste' : 'solde' }}">{{ $l['reste'] ? $m($l['reste']) : ($l['a_payer'] || $l['dette_apres'] ? 'Soldé' : '—') }}</td>
          </tr>
        @endforeach
        @php($t = $groupe['totaux'])
        <tr class="sous-total">
          <td class="g" colspan="7">Total {{ $groupe['classe'] }}</td>
          <td>{{ $m($t['montant_du']) }}</td><td>{{ $m($t['reduction']) }}</td><td>{{ $m($t['a_payer']) }}</td><td>{{ $m($t['paye']) }}</td><td>{{ $m($t['reste_inscription']) }}</td>
          <td>{{ $m($t['dette']) }}</td><td>{{ $m($t['dette_reduite']) }}</td><td>{{ $m($t['dette_apres']) }}</td><td>{{ $m($t['dette_payee']) }}</td><td>{{ $m($t['reste_dette']) }}</td>
          <td>{{ $m($t['reste']) }}</td>
        </tr>
      @empty
        <tr><td class="c" colspan="18">Aucun élève pour ces critères.</td></tr>
      @endforelse
      @if (count($classes) > 1)
        <tr class="total-general">
          <td class="g" colspan="7">Total général · {{ $nombre }} élève{{ $nombre > 1 ? 's' : '' }}</td>
          <td>{{ $m($totaux['montant_du']) }}</td><td>{{ $m($totaux['reduction']) }}</td><td>{{ $m($totaux['a_payer']) }}</td><td>{{ $m($totaux['paye']) }}</td><td>{{ $m($totaux['reste_inscription']) }}</td>
          <td>{{ $m($totaux['dette']) }}</td><td>{{ $m($totaux['dette_reduite']) }}</td><td>{{ $m($totaux['dette_apres']) }}</td><td>{{ $m($totaux['dette_payee']) }}</td><td>{{ $m($totaux['reste_dette']) }}</td>
          <td>{{ $m($totaux['reste']) }}</td>
        </tr>
      @endif
    </tbody>
  </table>
  <p class="pied-doc">Montants en F CFA. Red. = redoublant · Assistance : réduction ou cas social.</p>

</body>
</html>
