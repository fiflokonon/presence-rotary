<x-layouts.admin title="Tableau de bord — Administration">
    <div class="flex flex-col gap-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                <p class="text-xs font-semibold uppercase text-muted-strong">Membres actifs</p>
                <p class="mt-2 font-display text-3xl font-extrabold text-navy">{{ $activeMembersCount }}</p>
            </div>
            <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                <p class="text-xs font-semibold uppercase text-muted-strong">Séances organisées</p>
                <p class="mt-2 font-display text-3xl font-extrabold text-navy">{{ $totalSessionsCount }}</p>
            </div>
            <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                <p class="text-xs font-semibold uppercase text-muted-strong">Taux de présence moyen</p>
                <p class="mt-2 font-display text-3xl font-extrabold text-navy">
                    {{ $averageAttendanceRate !== null ? $averageAttendanceRate.' %' : '—' }}
                </p>
            </div>
            <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                <p class="text-xs font-semibold uppercase text-muted-strong">Dernière séance</p>
                @if ($lastSession)
                    <p class="mt-2 font-display text-3xl font-extrabold text-navy">{{ $lastSession->present_count }}/{{ $lastSession->attendances_count }}</p>
                    <p class="mt-1 text-xs text-muted">{{ $lastSession->date->translatedFormat('d F Y') }}</p>
                @else
                    <p class="mt-2 text-sm text-muted">Aucune séance</p>
                @endif
            </div>
        </div>

        @if ($recentSessions->isEmpty())
            <div class="rounded-2xl bg-white p-6 text-center shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
                <p class="text-sm text-muted">Pas encore de séance. Créez votre première séance depuis « Séances ».</p>
            </div>
        @else
            <div
                x-data="dashboardCharts(
                    @js($attendanceTrend->map(fn ($s) => $s->date->translatedFormat('d/m'))),
                    @js($attendanceTrend->map(fn ($s) => $s->attendances_count > 0 ? round($s->present_count / $s->attendances_count * 100) : 0)),
                    @js($lastSessionBreakdown->keys()),
                    @js($lastSessionBreakdown->values())
                )"
                class="grid grid-cols-1 gap-4 lg:grid-cols-2"
            >
                <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                    <h2 class="font-display text-sm font-extrabold uppercase text-muted-strong">Évolution du taux de présence</h2>
                    <canvas x-ref="trendCanvas" class="mt-4"></canvas>
                </div>
                <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                    <h2 class="font-display text-sm font-extrabold uppercase text-muted-strong">Répartition (dernière séance)</h2>
                    <canvas x-ref="breakdownCanvas" class="mt-4"></canvas>
                </div>
            </div>

            <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
                <h2 class="font-display text-lg font-extrabold text-navy">Activité récente</h2>
                <ul class="mt-4 divide-y divide-divider">
                    @foreach ($recentSessions as $session)
                        <li>
                            <a href="{{ route('admin.sessions.show', $session) }}"
                                class="flex cursor-pointer items-center justify-between gap-3 rounded-lg py-3 pl-2 pr-2 hover:bg-cream">
                                <span class="min-w-0 truncate text-sm font-semibold text-navy">
                                    {{ $session->title }} — {{ $session->date->translatedFormat('d F Y') }}
                                </span>
                                <span class="shrink-0 rounded-full bg-success-bg px-2 py-0.5 text-[11px] font-semibold uppercase text-success">
                                    {{ $session->present_count }}/{{ $session->attendances_count }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-layouts.admin>
