<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RenewalLetterTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Hr\Support\RenewalLetter;

/**
 * The wording of the renewal request letter template (HR settings → Renewal letter), per language.
 *
 * @tags HR: Renewal letter
 */
class RenewalLetterController extends Controller
{
    /**
     * The template in each language (saved wording, or the defaults).
     *
     * @response array{data: list<array{language: string, name: string, customized: bool, subject: string, salutation: string|null, closing: string, number_label: string, department_label: string, section_label: string, position_label: string, show_section: bool, show_position: bool}>}
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => collect(array_keys(RenewalLetter::LANGUAGES))->map(fn ($l) => RenewalLetter::settings($l))->values()]);
    }

    /**
     * Save the template of a language. Requests already sent keep their letter.
     *
     * @response array{data: array{language: string, name: string, customized: bool, subject: string, salutation: string|null, closing: string, number_label: string, department_label: string, section_label: string, position_label: string, show_section: bool, show_position: bool}}
     */
    public function update(Request $request, string $language): JsonResponse
    {
        abort_unless(array_key_exists($language, RenewalLetter::LANGUAGES), 404);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            /** Fixed salutation, e.g. "Kwa Meneja wa Rasilimali Watu,". Empty: the employee writes it. */
            'salutation' => ['nullable', 'string', 'max:200'],
            'closing' => ['required', 'string', 'max:100'],
            'number_label' => ['required', 'string', 'max:60'],
            'department_label' => ['required', 'string', 'max:60'],
            'section_label' => ['required', 'string', 'max:60'],
            'position_label' => ['required', 'string', 'max:60'],
            'show_section' => ['boolean'],
            'show_position' => ['boolean'],
        ]);
        RenewalLetterTemplate::updateOrCreate(['language' => $language], $data + ['updated_by' => $request->user()->id]);

        return response()->json(['data' => RenewalLetter::settings($language)]);
    }

    /** Go back to the default wording of a language. */
    public function reset(string $language): JsonResponse
    {
        abort_unless(array_key_exists($language, RenewalLetter::LANGUAGES), 404);
        RenewalLetterTemplate::where('language', $language)->delete();

        return response()->json(['data' => RenewalLetter::settings($language)]);
    }
}
