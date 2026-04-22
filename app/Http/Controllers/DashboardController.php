<?php
namespace App\Http\Controllers;

use App\Services\ModuleAccessService;

class DashboardController extends Controller
{
    public function __construct(protected ModuleAccessService $moduleAccess) {}

    public function index()
    {
        $modules = $this->moduleAccess->allForDashboard();
        return view('dashboard.index', compact('modules'));
    }
}
