<div class="flex flex-col gap-2 border-b border-divider py-2.5 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-3">
        <div class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full bg-divider text-xs font-bold" x-text="initials(record.name)"></div>
        <div>
            <p class="text-[14.5px] font-semibold text-navy" x-text="record.name"></p>
            <p class="text-[12.5px] text-muted-strong">
                <span x-text="record.title + (record.position ? ' — ' + record.position : '') + ' · ' + record.club"></span>
                <span x-show="record.isLate" class="font-bold text-gold"> · marqué en retard</span>
                <span x-show="record.hasMisc" class="font-bold text-navy"> · divers</span>
            </p>
            <p class="mt-0.5 font-mono text-xs text-muted-strong sm:hidden" x-text="record.phone"></p>
        </div>
    </div>
    <div class="flex items-center justify-between gap-3 sm:justify-end">
        <span class="hidden font-mono text-sm text-muted-strong sm:inline" x-text="record.phone"></span>
        <form method="POST" :action="'/admin/attendances/' + record.id + '/toggle-present'">
            @csrf
            @method('PATCH')
            <button type="submit"
                :class="record.present ? 'bg-success-bg text-success' : 'border border-border text-muted'"
                class="cursor-pointer rounded-lg px-3 py-1.5 text-xs font-semibold">
                <span x-text="record.present ? 'Présent' : 'Marquer présent'"></span>
            </button>
        </form>
    </div>
</div>
