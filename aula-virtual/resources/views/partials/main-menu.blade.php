{{-- Menu principal de navegacion --}}
<nav class="nav-links" aria-label="Menu principal">
  @foreach(\App\Support\Navigation::mainMenu() as $item)
    @php
      $isActive = !empty($item['active']) && request()->routeIs($item['active']);
      $href = '#';
      if (!empty($item['route']) && Route::has($item['route'])) {
          $href = route($item['route'], $item['params'] ?? []);
      } elseif (!empty($item['url'])) {
          $href = $item['url'];
      }
    @endphp
    <a href="{{ $href }}"
       class="{{ $isActive ? 'active' : '' }}"
       @if(!empty($item['aria_label'])) aria-label="{{ $item['aria_label'] }}" @endif>{{ $item['label'] }}</a>
  @endforeach
</nav>
