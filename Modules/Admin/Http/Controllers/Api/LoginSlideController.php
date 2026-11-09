<?php

namespace Modules\Admin\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Http\Requests\SaveLoginSlideRequest;
use Modules\Admin\Http\Resources\LoginSlideResource;
use Modules\Core\Models\LoginSlide;

/**
 * Announcements on the company's login page.
 *
 * @tags Admin: Login announcements
 */
class LoginSlideController extends Controller
{
    /**
     * List announcements.
     */
    public function index(): AnonymousResourceCollection
    {
        return LoginSlideResource::collection(LoginSlide::where('company_id', $this->companyId())->orderBy('sort_order')->orderBy('id')->get());
    }

    /**
     * Create an announcement (multipart/form-data).
     */
    public function store(SaveLoginSlideRequest $request)
    {
        $slide = new LoginSlide(['company_id' => $this->companyId(), 'created_by' => $request->user()->id]);

        return (new LoginSlideResource($this->fill($slide, $request)))->response()->setStatusCode(201);
    }

    /**
     * Update an announcement (multipart/form-data, POST).
     */
    public function update(SaveLoginSlideRequest $request, LoginSlide $loginSlide): LoginSlideResource
    {
        abort_unless($loginSlide->company_id === $this->companyId(), 404);

        return new LoginSlideResource($this->fill($loginSlide, $request));
    }

    /**
     * Delete an announcement.
     */
    public function destroy(LoginSlide $loginSlide): Response
    {
        abort_unless($loginSlide->company_id === $this->companyId(), 404);
        if ($loginSlide->image) {
            Storage::disk('public')->delete($loginSlide->image);
        }
        $loginSlide->delete();

        return response()->noContent();
    }

    private function fill(LoginSlide $slide, SaveLoginSlideRequest $request): LoginSlide
    {
        $data = $request->validated();
        $file = $data['image'] ?? null;
        if ($file instanceof UploadedFile || ! empty($data['remove_image'])) {
            if ($slide->image) {
                Storage::disk('public')->delete($slide->image);
            }
            $slide->image = $file instanceof UploadedFile ? $file->store("branding/{$this->companyId()}/slides", 'public') : null;
        }
        $slide->fill(collect($data)->only(['title', 'content', 'sort_order', 'is_active', 'starts_at', 'ends_at'])->all());
        $slide->sort_order ??= 0;
        $slide->save();

        return $slide;
    }

    private function companyId(): int
    {
        return app(CompanyContext::class)->id();
    }
}
