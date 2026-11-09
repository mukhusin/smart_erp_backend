<?php

namespace Modules\Platform\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErpModule;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Platform\Http\Resources\ModuleCatalogResource;

/**
 * @tags Platform: Companies
 */
class ModuleCatalogController extends Controller
{
    /**
     * List ERP modules.
     *
     * All modules that can be enabled for a company.
     */
    public function __invoke(): AnonymousResourceCollection
    {
        return ModuleCatalogResource::collection(ErpModule::where('is_active', true)->orderBy('sort_order')->get());
    }
}
