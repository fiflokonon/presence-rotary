<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTitleRequest;
use App\Http\Requests\UpdateTitleRequest;
use App\Models\Position;
use App\Models\Title;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TitleController extends Controller
{
    public function index(): View
    {
        return view('admin.titles.index', [
            'titles' => Title::withCount('positions')
                ->where('name', '!=', Title::GUEST_NAME)
                ->orderBy('order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.titles.create', [
            'positions' => Position::active()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreTitleRequest $request): RedirectResponse
    {
        // Compute next order value (append at the end)
        $maxOrder = Title::where('name', '!=', Title::GUEST_NAME)->max('order');
        $nextOrder = $maxOrder === null ? 0 : $maxOrder + 1;

        $title = Title::create(array_merge($request->safe()->only(['name', 'category']), ['order' => $nextOrder]));
        $title->positions()->sync($request->input('position_ids', []));

        return redirect()->route('admin.titles.index');
    }

    public function edit(Title $title): View
    {
        abort_if($title->name === Title::GUEST_NAME, 404);

        $linkedPositionIds = $title->positions()->pluck('positions.id')->all();

        return view('admin.titles.edit', [
            'title' => $title,
            'positions' => Position::query()
                ->where('is_active', true)
                ->orWhereIn('id', $linkedPositionIds)
                ->orderBy('name')
                ->get(),
            'linkedPositionIds' => $linkedPositionIds,
        ]);
    }

    public function update(UpdateTitleRequest $request, Title $title): RedirectResponse
    {
        abort_if($title->name === Title::GUEST_NAME, 404);

        $title->update($request->safe()->only(['name', 'category']));
        $title->positions()->sync($request->input('position_ids', []));

        return redirect()->route('admin.titles.index');
    }

    public function toggleActive(Title $title): RedirectResponse
    {
        abort_if($title->name === Title::GUEST_NAME, 404);

        $title->update(['is_active' => ! $title->is_active]);

        return redirect()->route('admin.titles.index');
    }

    public function moveOrder(Title $title, string $direction): RedirectResponse
    {
        abort_if($title->name === Title::GUEST_NAME, 404);

        // Ensure the title has a defined order
        if ($title->order === null) {
            $maxOrder = Title::where('name', '!=', Title::GUEST_NAME)->max('order');
            $title->update(['order' => ($maxOrder === null ? 0 : $maxOrder + 1)]);
            $title->refresh();
        }

        $direction = strtolower($direction);
        abort_if(! in_array($direction, ['up', 'down']), 404);

        if ($direction === 'up') {
            // Find the title with the highest order less than current order
            $swapWith = Title::where('name', '!=', Title::GUEST_NAME)
                ->where('order', '<', $title->order)
                ->orderByDesc('order')
                ->first();

            if ($swapWith !== null) {
                $tempOrder = $title->order;
                $title->update(['order' => $swapWith->order]);
                $swapWith->update(['order' => $tempOrder]);
            }
        } else {
            // Find the title with the lowest order greater than current order
            $swapWith = Title::where('name', '!=', Title::GUEST_NAME)
                ->where('order', '>', $title->order)
                ->orderBy('order')
                ->first();

            if ($swapWith !== null) {
                $tempOrder = $title->order;
                $title->update(['order' => $swapWith->order]);
                $swapWith->update(['order' => $tempOrder]);
            }
        }

        return redirect()->route('admin.titles.index');
    }

    public function destroy(Title $title): RedirectResponse
    {
        abort_if($title->name === Title::GUEST_NAME, 404);

        try {
            $title->delete();
        } catch (QueryException) {
            return redirect()->route('admin.titles.index')
                ->with('error', 'Cette organisation est utilisée par des membres ou des présences existantes — désactivez-la plutôt que de la supprimer.');
        }

        return redirect()->route('admin.titles.index');
    }
}
