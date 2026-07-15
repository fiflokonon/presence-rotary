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
            'positions' => Position::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.positions.create');
    }

    public function store(StorePositionRequest $request): RedirectResponse
    {
        Position::create($request->validated());

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

    public function destroy(Position $position): RedirectResponse
    {
        try {
            $position->delete();
        } catch (QueryException) {
            return redirect()->route('admin.positions.index')
                ->with('error', 'Ce poste est utilisé par des membres ou des présences existantes — désactivez-le plutôt que de le supprimer.');
        }

        return redirect()->route('admin.positions.index');
    }
}
