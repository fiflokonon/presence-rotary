<x-layouts.app :title="'Liste de présence' . ($meetingSession ? ' — ' . $meetingSession->title : '')">
    <div class="mx-auto flex min-h-screen max-w-[420px] items-center px-4 py-10">
        <div class="w-full overflow-hidden rounded-xl bg-white shadow-[0_2px_10px_rgba(20,30,50,.06)]">
            <div class="bg-[#12213D] px-6 pb-[18px] pt-[22px]">
                <p class="font-display text-lg font-extrabold text-white">RC Cotonou Nexus</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-wide text-[#F2B94D]">District 9103</p>
                <p class="font-display text-[15px] font-bold text-white">RC Cotonou Nexus</p>
            </div>

            @if (session('attendanceSubmitted'))
                <div class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                    <div class="flex h-14 w-14 items-center justify-center rounded-full bg-[#E7F5F1] text-2xl text-[#0E7C66]">✓</div>
                    <p class="font-display text-lg font-extrabold text-[#12213D]">Présence enregistrée</p>
                    <p class="text-sm text-[#8A8474]">
                        @if (session('attendanceWasLate'))
                            Votre présence en retard a bien été enregistrée.
                        @else
                            Merci, votre présence a bien été enregistrée.
                        @endif
                    </p>
                    <a href="{{ route('attendance.show') }}" class="text-sm font-semibold text-[#12213D] underline">
                        Envoyer une autre réponse
                    </a>
                </div>
            @elseif (! $meetingSession)
                <div class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                    <p class="font-display text-lg font-extrabold text-[#12213D]">Aucune séance en cours</p>
                    <p class="text-sm text-[#8A8474]">Revenez lors de la prochaine réunion du club.</p>
                </div>
            @else
                <div class="px-6 pb-2 pt-[18px]">
                    <p class="font-display text-xl font-extrabold text-[#12213D]">Liste de présence</p>
                    <p class="text-[13.5px] text-[#8A8474]">{{ $meetingSession->title }} — {{ $meetingSession->date->translatedFormat('d F Y') }}</p>
                </div>

                @if ($meetingSession->is_open)
                    <x-attendance-form :late="false" />
                @else
                    <div x-data="{ lateMode: false }">
                        <div x-show="! lateMode" class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-[#F1EFEA] text-2xl">⏱</div>
                            <p class="font-display text-lg font-extrabold text-[#12213D]">La séance est clôturée</p>
                            <p class="text-sm text-[#8A8474]">Le pointage a été clôturé par l'administrateur.</p>
                            <button type="button" @click="lateMode = true"
                                class="rounded-lg bg-[#12213D] px-4 py-2.5 text-sm font-bold text-white">
                                Marquer ma présence en retard
                            </button>
                        </div>
                        <div x-show="lateMode" x-cloak>
                            <x-attendance-form :late="true" />
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
</x-layouts.app>
