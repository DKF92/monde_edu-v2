{{-- Tableau d'un bilan de caisse : types de frais x affectes / non affectes / total. --}}
@php($m = fn ($v) => \App\Support\RecuPaiement::montant((int) $v))
<table class="bilan">
  <tr><th>Types de frais</th><th>Affectés</th><th>Non affectés</th><th>Total</th></tr>
  @php($groupe = null)
  @foreach ($bilan['lignes'] as $l)
    @php($g = match ($l['nature']) { 'annexe' => 'Frais annexes', 'dette' => 'Dettes', default => 'Frais d\'inscription et scolarité' })
    @if ($g !== $groupe)
      <tr class="groupe"><td colspan="4">{{ $g }}</td></tr>
      @php($groupe = $g)
    @endif
    <tr>
      <td>{{ $l['libelle'] }}</td>
      <td class="{{ $l['applicable_affecte'] ? '' : 'na' }}">{{ $l['applicable_affecte'] || $l['affecte'] ? $m($l['affecte']) : '—' }}</td>
      <td class="{{ $l['applicable_non_affecte'] ? '' : 'na' }}">{{ $l['applicable_non_affecte'] || $l['non_affecte'] ? $m($l['non_affecte']) : '—' }}</td>
      <td class="colonne-total">{{ $m($l['total']) }}</td>
    </tr>
  @endforeach
  <tr class="ligne-total">
    <td>Total{{ isset($bilan['nombre_paiements']) ? ' ('.$bilan['nombre_paiements'].' paiement'.($bilan['nombre_paiements'] > 1 ? 's' : '').')' : '' }}</td>
    <td>{{ $m($bilan['totaux']['affecte']) }}</td>
    <td>{{ $m($bilan['totaux']['non_affecte']) }}</td>
    <td>{{ $m($bilan['totaux']['total']) }}</td>
  </tr>
</table>
