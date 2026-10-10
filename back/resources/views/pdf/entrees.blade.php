@php($m = fn ($v) => number_format((int) $v, 0, ',', "\u{202F}"))
@php($modes = \App\Models\Entree::MODES)
@php($c = fn ($texte, $mm) => \App\Support\Noms::couper($texte, $mm, 7))
{{-- Entrees : 1. caisse = paiements des eleves, total par caissier ; 2. banque = liste (une ligne par
     entree, la banque tient lieu de beneficiaire) puis bilans par banque, categorie et personne. A4 paysage. --}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $titre }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 10mm 10mm 12mm; }
  h2 { font-size: 10pt; color: #1b2f5e; margin: 5mm 0 1.5mm; page-break-after: avoid; }
  .liste { width: 100%; border-collapse: collapse; }
  .liste th { background: #eef2fa; color: #1b2f5e; font-size: 7pt; padding: 4pt 2pt; border: 0.5pt solid #d9deea; text-align: left; white-space: nowrap; }
  .liste td { font-size: 7pt; padding: 3pt 2pt; border: 0.5pt solid #e3e7f0; white-space: nowrap; }
  .liste .d { text-align: right; }
  .liste .total td { font-weight: bold; background: #d5dcef; }
  .liste tr { page-break-inside: avoid; }
  .bilans { width: 100%; margin-top: 5mm; page-break-inside: avoid; }
  .bilans > tbody > tr > td { width: 33.3%; vertical-align: top; padding: 0 2mm 0 0; }
  .bilan { width: 100%; border-collapse: collapse; }
  .bilan th { background: #eef2fa; color: #1b2f5e; font-size: 7pt; padding: 3pt 2pt; border: 0.5pt solid #d9deea; text-align: left; white-space: nowrap; }
  .bilan td { font-size: 7pt; padding: 3pt 2pt; border: 0.5pt solid #e3e7f0; white-space: nowrap; }
  .bilan .d { text-align: right; }
  .bilan .total td { font-weight: bold; background: #d5dcef; }
  .resume { width: 100%; border-collapse: collapse; margin-top: 4mm; page-break-inside: avoid; }
  .resume td { font-size: 8pt; padding: 3pt 4pt; border: 0.5pt solid #e3e7f0; }
  .resume .d { text-align: right; width: 35mm; }
  .resume .total td { font-weight: bold; background: #d5dcef; }
  .pied-doc { margin-top: 3mm; font-size: 7.5pt; color: #56607a; }
</style>
</head>
<body>
  @include('pdf._entete-caisse')
  <h1>{{ $titre }}</h1>
  <div class="sous-titre">Édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>

  @if ($caisse)
    <h2>1. Entrées caisse · paiements des élèves</h2>
    <table class="liste">
      <thead>
        <tr>
          <th>Caissier</th>
          <th class="d" style="width: 25mm">Paiements</th>
          <th class="d" style="width: 35mm">Frais de l'année</th>
          <th class="d" style="width: 35mm">Dettes</th>
          <th class="d" style="width: 35mm">Total encaissé</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($caisse['par_caissier'] as $l)
          <tr>
            <td>{{ $c($l['nom'], 140) }}</td>
            <td class="d">{{ $m($l['nombre']) }}</td>
            <td class="d">{{ $m($l['frais']) }}</td>
            <td class="d">{{ $m($l['dettes']) }}</td>
            <td class="d">{{ $m($l['montant']) }}</td>
          </tr>
        @empty
          <tr><td colspan="5">Aucun paiement sur la période.</td></tr>
        @endforelse
        <tr class="total"><td>Total caisse</td><td class="d">{{ $m($caisse['nombre']) }}</td><td class="d">{{ $m($caisse['frais']) }}</td><td class="d">{{ $m($caisse['dettes']) }}</td><td class="d">{{ $m($caisse['montant']) }}</td></tr>
      </tbody>
    </table>
  @endif

  <h2>{{ $caisse ? '2. ' : '' }}Entrées banque</h2>
  <table class="liste">
    <thead>
      <tr>
        <th style="width: 14mm">Date</th>
        <th style="width: 38mm">Banque</th>
        <th style="width: 32mm">Catégorie</th>
        <th>Objet</th>
        <th style="width: 34mm">Provenance</th>
        <th style="width: 19mm">Mode</th>
        <th style="width: 32mm">Saisie par</th>
        <th class="d" style="width: 17mm">Montant</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($entrees as $e)
        <tr>
          <td>{{ \Carbon\Carbon::parse($e['date'])->format('d/m/Y') }}</td>
          <td>{{ $c($e['banque'], 38) }}</td>
          <td>{{ $c($e['categorie_libelle'], 32) }}</td>
          <td>{{ $c($e['libelle'], 79) }}</td>
          <td>{{ $c($e['provenance'] ?: ($e['verse_par'] ?? null), 34) }}</td>
          <td>{{ $c($modes[$e['mode']] ?? $e['mode'], 19) }}</td>
          <td>{{ $e['saisi_par'] ? \App\Support\Noms::prenoms($e['saisi_par'], 32, 7) : '—' }}</td>
          <td class="d">{{ $m($e['montant']) }}</td>
        </tr>
      @empty
        <tr><td colspan="8">Aucune entrée banque pour ces critères.</td></tr>
      @endforelse
      <tr class="total"><td colspan="7">Total banque</td><td class="d">{{ $m($total) }}</td></tr>
    </tbody>
  </table>

  @if (count($entrees))
    <table class="bilans">
      <tr>
        @foreach ($bilans as $titreBilan => $lignes)
          <td>
            <table class="bilan">
              <tr><th>{{ $titreBilan }}</th><th class="d" style="width: 11mm">Nombre</th><th class="d" style="width: 17mm">Montant</th></tr>
              @foreach ($lignes as $l)
                <tr><td>{{ $c($l['libelle'], 54) }}</td><td class="d">{{ $l['nombre'] }}</td><td class="d">{{ $m($l['montant']) }}</td></tr>
              @endforeach
              <tr class="total"><td>Total</td><td class="d">{{ count($entrees) }}</td><td class="d">{{ $m($total) }}</td></tr>
            </table>
          </td>
        @endforeach
      </tr>
    </table>
  @endif

  @if ($caisse)
    <h2>3. Récapitulatif</h2>
    <table class="resume">
      <tr><td>Entrées caisse (paiements des élèves)</td><td class="d">{{ $m($caisse['montant']) }}</td></tr>
      <tr><td>Entrées banque</td><td class="d">{{ $m($total) }}</td></tr>
      @if ($versements)
        <tr><td>Dont versements de la caisse à la banque (déjà comptés en caisse)</td><td class="d">− {{ $m($versements) }}</td></tr>
      @endif
      <tr class="total"><td>Total des entrées</td><td class="d">{{ $m($caisse['montant'] + $total - $versements) }}</td></tr>
    </table>
  @endif
  <p class="pied-doc">Montants en F CFA. Le détail des paiements des élèves est dans Finances › Paiements.</p>
</body>
</html>
