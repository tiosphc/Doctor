@if (session('success'))
    <x-alert tone="success" class="mb-5">{{ session('success') }}</x-alert>
@endif
@if (session('error'))
    <x-alert tone="error" class="mb-5">{{ session('error') }}</x-alert>
@endif
@if ($errors->any())
    <x-alert tone="error" class="mb-5">Vui lòng kiểm tra các trường được đánh dấu bên dưới.</x-alert>
@endif
