<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StorePlanRequest;
use App\Http\Requests\SuperAdmin\UpdatePlanRequest;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        return view('super-admin.plans.index', [
            'plans' => Plan::orderBy('duration_months')->get(),
        ]);
    }

    public function create(): View
    {
        return view('super-admin.plans.create');
    }

    public function store(StorePlanRequest $request): RedirectResponse
    {
        Plan::create($request->validated());

        return redirect()->route('super-admin.plans.index')->with('status', 'Plan créé.');
    }

    public function edit(Plan $plan): View
    {
        return view('super-admin.plans.edit', ['plan' => $plan]);
    }

    public function update(UpdatePlanRequest $request, Plan $plan): RedirectResponse
    {
        $plan->update($request->validated());

        return redirect()->route('super-admin.plans.index')->with('status', 'Plan mis à jour.');
    }

    public function toggleActive(Plan $plan): RedirectResponse
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return redirect()->route('super-admin.plans.index');
    }
}
