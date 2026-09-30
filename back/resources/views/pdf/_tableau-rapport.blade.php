{{-- Tableau d'un rapport ({colonnes, groupes: [{titre, lignes, total}], total}) : rapports simples et rapports compiles. --}}
@php
  $valeurRapport = function ($ligne, $c) {
      $v = $ligne[$c['cle']] ?? null;
      return match ($c['type'] ?? 'texte') {
          'moyenne' => $v === null ? '—' : number_format((float) $v, 2, ',', ' '),
          'pourcent' => $v === null ? '—' : number_format((float) $v, 2, ',', ' ').' %',
          'montant' => $v === null ? '' : number_format((float) $v, 0, ',', ' ').' F',
          'nombre' => $v === null ? '' : (fmod((float) $v, 1) ? number_format((float) $v, 1, ',', ' ') : number_format((float) $v, 0, ',', ' ')),
          default => $v === null || $v === '' ? '' : $v,
      };
  };
  $classeRapport = fn ($c) => in_array($c['type'] ?? 'texte', ['moyenne', 'pourcent', 'nombre', 'montant'], true) ? 'd' : (($c['type'] ?? '') === 'centre' ? 'c' : 'g');
  $nbColonnes = count($rapport['colonnes']);
@endphp
<table class="tab {{ $nbColonnes > 10 ? 'serre' : '' }}">
  <thead>
    <tr>@foreach ($rapport['colonnes'] as $c)<th class="{{ $classeRapport($c) }}">{{ $c['libelle'] }}</th>@endforeach</tr>
  </thead>
  <tbody>
    @forelse ($rapport['groupes'] as $g)
      @if ($g['titre'])
        <tr class="groupe"><td colspan="{{ $nbColonnes }}">{{ $g['titre'] }}</td></tr>
      @endif
      @foreach ($g['lignes'] as $l)
        <tr>@foreach ($rapport['colonnes'] as $c)<td class="{{ $classeRapport($c) }} {{ !empty($c['fort']) ? 'fort' : '' }}">{{ $valeurRapport($l, $c) }}</td>@endforeach</tr>
      @endforeach
      @if ($g['total'])
        <tr class="sous-total">@foreach ($rapport['colonnes'] as $i => $c)<td class="{{ $classeRapport($c) }}">{{ $i === 0 ? 'Total '.$g['titre'] : $valeurRapport($g['total'], $c) }}</td>@endforeach</tr>
      @endif
    @empty
      <tr><td colspan="{{ $nbColonnes }}" class="c">Aucune donnée.</td></tr>
    @endforelse
    @if ($rapport['total'])
      <tr class="total">@foreach ($rapport['colonnes'] as $i => $c)<td class="{{ $classeRapport($c) }}">{{ $i === 0 ? 'Total général' : $valeurRapport($rapport['total'], $c) }}</td>@endforeach</tr>
    @endif
  </tbody>
</table>
