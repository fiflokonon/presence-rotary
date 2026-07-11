<div
    x-data
    x-show="$store.pageLoading.active"
    x-cloak
    x-transition.opacity
    class="fixed inset-0 z-50 flex items-center justify-center bg-[#12213D]/40"
>
    <x-loader class="h-16 w-16" />
</div>
