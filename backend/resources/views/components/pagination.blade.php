@props(['paginator'])

@if ($paginator->hasPages())
    <nav aria-label="Phân trang" {{ $attributes->class(['mt-6']) }}>
        {{ $paginator->withQueryString()->links() }}
    </nav>
@endif
