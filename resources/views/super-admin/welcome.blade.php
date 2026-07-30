<x-layouts.super-admin :title="config('app.name')">
    <div class="mx-auto max-w-[380px] rounded-2xl bg-white p-6 text-center shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">{{ config('app.name') }}</h1>

        <a href="{{ route('super-admin.login') }}"
            class="mt-4 inline-block cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
            Se connecter
        </a>
    </div>
</x-layouts.super-admin>
