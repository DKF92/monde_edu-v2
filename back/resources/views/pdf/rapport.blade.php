@php($nb = count($rapport['colonnes']))
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $rapport['titre'] }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 12mm 10mm 14mm; }
  .tab { width: 100%; border-collapse: collapse; margin-top: 2mm; }
  .tab th { background: #eef2fa; color: #1b2f5e; font-size: {{ $nb > 10 ? '6.4' : '7.4' }}pt; text-transform: uppercase; padding: 4pt 3pt; border: 0.5pt solid #d9deea; }
  .tab td { font-size: {{ $nb > 10 ? '7.4' : '8.6' }}pt; padding: 3pt; border: 0.5pt solid #e3e7f0; }
  .tab .g { text-align: left; } .tab .c { text-align: center; } .tab .d { text-align: right; }
  .tab .fort { font-weight: bold; }
  .tab .groupe td { background: #1b2f5e; color: #fff; font-weight: bold; font-size: 8pt; }
  .tab .sous-total td { background: #f2f5fb; font-weight: bold; }
  .tab .total td { background: #d5dcef; font-weight: bold; }
  .tab tr { page-break-inside: avoid; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => mb_strtoupper($rapport['titre'])])
  <h1>{{ $rapport['titre'] }}{{ $rapport['periode'] ? ' · '.$rapport['periode'] : '' }}</h1>
  <div class="sous-titre">{{ $rapport['sous_titre'] ?? '' }}{{ !empty($rapport['sous_titre']) ? ' · ' : '' }}Édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>

  @include('pdf._tableau-rapport', ['rapport' => $rapport])
</body>
</html>
