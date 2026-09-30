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
  .stats-titre { font-size: 12pt; color: #1b2f5e; margin: 0 0 3mm; }
  .stats { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
  .stats th { background: #eef2fa; color: #1b2f5e; font-size: 7.5pt; text-transform: uppercase; padding: 4pt; border: 0.5pt solid #d9deea; text-align: center; }
  .stats th:first-child, .stats td:first-child { text-align: left; width: 26%; }
  .stats th { width: 14.8%; }
  .stats td { font-size: 8.5pt; padding: 4pt; border: 0.5pt solid #e3e7f0; text-align: center; }
  .stats tr:last-child td { font-weight: bold; background: #f2f5fb; }
  .bloc-stats { page-break-inside: avoid; }
  .grille-stats td.moitie { width: 50%; vertical-align: top; padding: 0 3mm 0 0; border: none; }
  .saut { page-break-before: always; }
  .pied-doc { margin-top: 4mm; font-size: 7.5pt; color: #56607a; }
  /* 12 colonnes : plus lisible que le reste a payer (18 colonnes). */
  .liste th { font-size: 7.2pt; padding: 4pt 3pt; }
  .liste td { font-size: 8.2pt; padding: 3.5pt 3pt; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => 'LISTE DES DETTES'])

  <h1>{{ $titre }}</h1>
  <div class="sous-titre">
    {{ $nombre }} élève{{ $nombre > 1 ? 's' : '' }}{{ $sous_titre ? ' · '.$sous_titre : '' }} · édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}
  </div>

  <table class="liste">
    <thead>
      <tr>
        <th class="c">N°</th>
        <th class="g">Matricule</th>
        <th class="g">Nom et prénoms</th>
        <th class="c">Sexe</th>
        <th class="c">Statut</th>
        <th class="c">Red.</th>
        <th class="c">Année(s) de la dette</th>
        <th>Montant dette</th>
        <th>Réduction</th>
        <th>Après réduction</th>
        <th>Payé</th>
        <th>Reste dette</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($classes as $groupe)
        <tr class="classe"><td colspan="12">{{ $groupe['classe'] }} · {{ count($groupe['lignes']) }} élève{{ count($groupe['lignes']) > 1 ? 's' : '' }}</td></tr>
        @foreach ($groupe['lignes'] as $i => $l)
          <tr>
            <td class="c">{{ $i + 1 }}</td>
            <td class="g">{{ $l['matricule'] }}</td>
            <td class="g fort">{{ $l['nom'] }} {{ $l['prenoms'] }}</td>
            <td class="c">{{ $l['sexe'] }}</td>
            <td class="c">{{ $l['affecte'] ? 'Aff.' : 'Non aff.' }}</td>
            <td class="c">{{ $l['redoublant'] ? 'Oui' : 'Non' }}</td>
            <td class="c">{{ $l['dette_annees'] }}</td>
            <td>{{ $m($l['dette']) }}</td>
            <td>{{ $z($l['dette_reduite']) }}</td>
            <td class="fort">{{ $m($l['dette_apres']) }}</td>
            <td>{{ $z($l['dette_payee']) }}</td>
            <td class="{{ $l['reste_dette'] ? 'reste' : 'solde' }}">{{ $l['reste_dette'] ? $m($l['reste_dette']) : 'Soldée' }}</td>
          </tr>
        @endforeach
        @php($t = $groupe['totaux'])
        <tr class="sous-total">
          <td class="g" colspan="7">Total {{ $groupe['classe'] }}</td>
          <td>{{ $m($t['dette']) }}</td><td>{{ $m($t['dette_reduite']) }}</td><td>{{ $m($t['dette_apres']) }}</td><td>{{ $m($t['dette_payee']) }}</td><td>{{ $m($t['reste_dette']) }}</td>
        </tr>
      @empty
        <tr><td class="c" colspan="12">Aucune dette pour ces critères.</td></tr>
      @endforelse
      @if (count($classes) > 1)
        <tr class="total-general">
          <td class="g" colspan="7">Total général · {{ $nombre }} élève{{ $nombre > 1 ? 's' : '' }}</td>
          <td>{{ $m($totaux['dette']) }}</td><td>{{ $m($totaux['dette_reduite']) }}</td><td>{{ $m($totaux['dette_apres']) }}</td><td>{{ $m($totaux['dette_payee']) }}</td><td>{{ $m($totaux['reste_dette']) }}</td>
        </tr>
      @endif
    </tbody>
  </table>
  <p class="pied-doc">Montants en F CFA. Dettes des années précédentes non annulées · Red. = redoublant.</p>

  @if ($statistiques)
    <div class="saut"></div>
    <h2 class="stats-titre">Statistiques · {{ $nombre }} élève{{ $nombre > 1 ? 's' : '' }}</h2>
    <table class="grille-stats">
      @foreach (array_chunk($statistiques, 2) as $paire)
        <tr>
          @foreach ($paire as $s)
            <td class="moitie">
              <div class="bloc-stats">
                <table class="stats">
                  <tr>
                    <th>{{ $s['titre'] }}</th>
                    @foreach ($s['colonnes'] as $libelle)<th>{{ $libelle }}</th>@endforeach
                    <th>Total</th>
                  </tr>
                  @foreach ($s['lignes'] as $r)
                    <tr>
                      <td>{{ $r['libelle'] }}</td>
                      @foreach ($r['valeurs'] as $v)<td>{{ $v }}</td>@endforeach
                      <td>{{ $r['total'] }}</td>
                    </tr>
                  @endforeach
                </table>
              </div>
            </td>
          @endforeach
        </tr>
      @endforeach
    </table>
  @endif
</body>
</html>
