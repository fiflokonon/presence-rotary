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
            'titles' => Title::withCount('positions')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.titles.create', [
            'positions' => Position::orderBy('name')->get(),
        ]);
    }

    public function store(StoreTitleRequest $request): RedirectResponse
    {
        $title = Title::create($request->safe()->only(['name', 'category']));
        $title->positions()->sync($request->input('position_ids', []));

        return redirect()->route('admin.titles.index');
    }

    public function edit(Title $title): View
    {
        return view('admin.titles.edit', [
            'title' => $title,
            'positions' => Position::orderBy('name')->get(),
            'linkedPositionIds' => $title->positions()->pluck('positions.id')->all(),
        ]);
    }

    public function update(UpdateTitleRequest $request, Title $title): RedirectResponse
    {
        $title->update($request->safe()->only(['name', 'category']));
        $title->positions()->sync($request->input('position_ids', []));

        return redirect()->route('admin.titles.index');
    }

    public function toggleActive(Title $title): RedirectResponse
    {
        $title->update(['is_active' => ! $title->is_active]);

        return redirect()->route('admin.titles.index');
    }

    public function destroy(Title $title): RedirectResponse
    {
        try {
            $title->delete();
        } catch (QueryException) {
            return redirect()->route('admin.titles.index')
                ->with('error', 'Ce titre est utilisé par des membres ou des présences existantes — désactivez-le plutôt que de le supprimer.');
        }

        return redirect()->route('admin.titles.index');
    }
}
