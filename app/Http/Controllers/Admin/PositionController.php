<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePositionRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Models\Position;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PositionController extends Controller
{
    public function index(): View
    {
        return view('admin.positions.index', [
            'positions' => Position::orderBy('order')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.positions.create');
    }

    public function store(StorePositionRequest $request): RedirectResponse
    {
        $maxOrder = Position::max('order');
        $nextOrder = $maxOrder === null ? 0 : $maxOrder + 1;

        Position::create([...$request->validated(), 'order' => $nextOrder]);

        return redirect()->route('admin.positions.index');
    }

    public function edit(Position $position): View
    {
        return view('admin.positions.edit', ['position' => $position]);
    }

    public function update(UpdatePositionRequest $request, Position $position): RedirectResponse
    {
        $position->update($request->validated());

        return redirect()->route('admin.positions.index');
    }

    public function toggleActive(Position $position): RedirectResponse
    {
        $position->update(['is_active' => ! $position->is_active]);

        return redirect()->route('admin.positions.index');
    }

    public function moveOrder(Position $position, string $direction): RedirectResponse
    {
        if ($position->order === null) {
            $maxOrder = Position::max('order');
            $position->update(['order' => ($maxOrder === null ? 0 : $maxOrder + 1)]);
            $position->refresh();
        }

        $direction = strtolower($direction);
        abort_if(! in_array($direction, ['up', 'down']), 404);

        if ($direction === 'up') {
            $swapWith = Position::where('order', '<', $position->order)->orderByDesc('order')->first();
        } else {
            $swapWith = Position::where('order', '>', $position->order)->orderBy('order')->first();
        }

        if ($swapWith !== null) {
            $tempOrder = $position->order;
            $position->update(['order' => $swapWith->order]);
            $swapWith->update(['order' => $tempOrder]);
        }

        return redirect()->route('admin.positions.index');
    }

    public function destroy(Position $position): RedirectResponse
    {
        try {
            $position->delete();
        } catch (QueryException) {
            return redirect()->route('admin.positions.index')
                ->with('error', 'Ce titre/qualité est utilisé par des membres ou des présences existantes — désactivez-le plutôt que de le supprimer.');
        }

        return redirect()->route('admin.positions.index');
    }
}
