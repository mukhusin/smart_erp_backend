<?php

namespace Modules\Hr\Support;

use App\Models\Employee;
use App\Models\RenewalLetterTemplate;
use Modules\Core\Support\RichText;

/**
 * The renewal request letter template: a fixed subject line, an optional fixed salutation, and the closing and
 * signature (from the employee record) in Kiswahili or English, around the body the employee writes.
 * HR can change the wording (HR settings → Renewal letter); until then the defaults below are used.
 */
final class RenewalLetter
{
    public const LANGUAGES = ['sw' => 'Kiswahili', 'en' => 'English'];

    public const FIELDS = ['subject', 'salutation', 'closing', 'number_label', 'department_label', 'section_label', 'position_label', 'show_section', 'show_position'];

    public const DEFAULTS = [
        'sw' => [
            'subject' => 'YAH: OMBI LA KUONGEZEWA MKATABA WA AJIRA', 'salutation' => null, 'closing' => 'Wako mtiifu,',
            'number_label' => 'Namba ya mfanyakazi', 'department_label' => 'Idara', 'section_label' => 'Kitengo', 'position_label' => 'Cheo',
            'show_section' => true, 'show_position' => false,
        ],
        'en' => [
            'subject' => 'RE: REQUEST FOR RENEWAL OF EMPLOYMENT CONTRACT', 'salutation' => null, 'closing' => 'Yours faithfully,',
            'number_label' => 'Employee number', 'department_label' => 'Department', 'section_label' => 'Section', 'position_label' => 'Position',
            'show_section' => true, 'show_position' => false,
        ],
    ];

    /** The company's wording for a language (saved or default). */
    public static function settings(string $language): array
    {
        $saved = RenewalLetterTemplate::where('language', $language)->first();

        return ['language' => $language, 'name' => self::LANGUAGES[$language] ?? $language, 'customized' => (bool) $saved]
            + ($saved ? $saved->only(self::FIELDS) : self::DEFAULTS[$language] ?? self::DEFAULTS['sw']);
    }

    /** @return array{language: string, name: string, subject: string, salutation: string|null, closing: string, signature: list<string>} */
    public static function template(Employee $employee, string $language): array
    {
        $t = self::settings($language);
        $employee->loadMissing(['department:id,name', 'section:id,name', 'jobPosition:id,title']);

        return [
            'language' => $language, 'name' => $t['name'], 'subject' => $t['subject'], 'salutation' => $t['salutation'] ?: null, 'closing' => $t['closing'],
            'signature' => array_values(array_filter([
                $employee->full_name,
                $t['show_position'] && $employee->jobPosition ? "{$t['position_label']}: {$employee->jobPosition->title}" : null,
                $employee->employee_no ? "{$t['number_label']}: {$employee->employee_no}" : null,
                $employee->department ? "{$t['department_label']}: {$employee->department->name}" : null,
                $t['show_section'] && $employee->section ? "{$t['section_label']}: {$employee->section->name}" : null,
            ])),
        ];
    }

    /** @return array<string, array{language: string, name: string, subject: string, salutation: string|null, closing: string, signature: list<string>}> */
    public static function templates(Employee $employee): array
    {
        return collect(array_keys(self::LANGUAGES))->mapWithKeys(fn ($l) => [$l => self::template($employee, $l)])->all();
    }

    /** The full letter: subject, salutation, the employee's cleaned body, closing and signature. */
    public static function compose(Employee $employee, string $language, string $body): string
    {
        $t = self::template($employee, $language);
        $signature = implode('<br>', array_map(fn ($line, $i) => $i === 0 ? '<strong>'.e($line).'</strong>' : e($line), $t['signature'], array_keys($t['signature'])));

        return '<p><strong>'.e($t['subject']).'</strong></p>'.($t['salutation'] ? '<p>'.e($t['salutation']).'</p>' : '')
            .RichText::clean($body).'<p>'.e($t['closing']).'</p><p>'.$signature.'</p>';
    }
}
