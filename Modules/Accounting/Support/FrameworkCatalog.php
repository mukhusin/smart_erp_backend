<?php

namespace Modules\Accounting\Support;

/** Accounting framework profiles as data: allowed choices and defaults per policy, and statement wording. */
final class FrameworkCatalog
{
    public const PROFILES = [
        'ifrs' => ['label' => 'IFRS (full)', 'description' => 'International Financial Reporting Standards, e.g. banks and large or listed entities.'],
        'ifrs_sme' => ['label' => 'IFRS for SMEs', 'description' => 'Simplified IFRS for private companies, private hospitals and schools, NGOs.'],
        'us_gaap' => ['label' => 'US GAAP', 'description' => 'United States generally accepted accounting principles, e.g. US-funded or US-owned entities.'],
        'ipsas' => ['label' => 'IPSAS', 'description' => 'International Public Sector Accounting Standards, e.g. public hospitals, schools and agencies.'],
        'local' => ['label' => 'Local / regulatory', 'description' => 'A national or regulator framework you name yourself; every choice is open.'],
    ];

    public const POLICIES = [
        'terminology' => ['label' => 'Statement wording', 'options' => [
            'ifrs_business' => 'Company (profit or loss, equity)', 'us_business' => 'US company (income statement, balance sheet)',
            'nonprofit' => 'Non-profit (income and expenditure, fund balances)', 'public' => 'Public sector (financial performance, net assets)']],
        'inventory_costing' => ['label' => 'Stock costing', 'options' => [
            'fifo' => 'First in, first out (FIFO)', 'weighted_average' => 'Weighted average', 'specific' => 'Specific identification (serial items)', 'lifo' => 'Last in, first out (LIFO)']],
        'inventory_writedown_reversal' => ['label' => 'Reverse stock write-downs when value recovers', 'options' => ['yes' => 'Yes', 'no' => 'No']],
        'asset_measurement' => ['label' => 'Measuring property, plant and equipment', 'options' => ['cost' => 'Cost model', 'revaluation' => 'Cost or revaluation model (per category)']],
        'impairment_reversal' => ['label' => 'Reverse impairment losses when value recovers', 'options' => ['yes' => 'Yes', 'no' => 'No']],
        'component_depreciation' => ['label' => 'Depreciate significant parts separately', 'options' => ['required' => 'Required', 'optional' => 'Optional']],
        'cash_flow_method' => ['label' => 'Cash flow statement', 'options' => ['direct' => 'Direct method', 'indirect' => 'Indirect method']],
        'budget_statement' => ['label' => 'Statement comparing budget and actual', 'options' => ['required' => 'Required (published budget)', 'optional' => 'Optional']],
        'grant_recognition' => ['label' => 'Grants and donations', 'options' => [
            'ias20' => 'IAS 20 / IFRS for SMEs grants (as conditions are met)', 'ipsas23' => 'IPSAS 23 non-exchange revenue', 'asc958' => 'ASC 958 contributions']],
    ];

    /** Allowed values per profile and policy; the first value is the default. */
    private const RULES = [
        'ifrs' => ['terminology' => ['ifrs_business', 'nonprofit'], 'inventory_costing' => ['weighted_average', 'fifo', 'specific'], 'inventory_writedown_reversal' => ['yes'],
            'asset_measurement' => ['cost', 'revaluation'], 'impairment_reversal' => ['yes'], 'component_depreciation' => ['required'], 'cash_flow_method' => ['indirect', 'direct'],
            'budget_statement' => ['optional', 'required'], 'grant_recognition' => ['ias20']],
        'ifrs_sme' => ['terminology' => ['nonprofit', 'ifrs_business'], 'inventory_costing' => ['weighted_average', 'fifo', 'specific'], 'inventory_writedown_reversal' => ['yes'],
            'asset_measurement' => ['cost', 'revaluation'], 'impairment_reversal' => ['yes'], 'component_depreciation' => ['optional', 'required'], 'cash_flow_method' => ['indirect', 'direct'],
            'budget_statement' => ['optional', 'required'], 'grant_recognition' => ['ias20']],
        'us_gaap' => ['terminology' => ['us_business', 'nonprofit'], 'inventory_costing' => ['fifo', 'weighted_average', 'specific', 'lifo'], 'inventory_writedown_reversal' => ['no'],
            'asset_measurement' => ['cost'], 'impairment_reversal' => ['no'], 'component_depreciation' => ['optional', 'required'], 'cash_flow_method' => ['indirect', 'direct'],
            'budget_statement' => ['optional', 'required'], 'grant_recognition' => ['asc958']],
        'ipsas' => ['terminology' => ['public'], 'inventory_costing' => ['weighted_average', 'fifo', 'specific'], 'inventory_writedown_reversal' => ['yes'],
            'asset_measurement' => ['cost', 'revaluation'], 'impairment_reversal' => ['yes'], 'component_depreciation' => ['required'], 'cash_flow_method' => ['direct', 'indirect'],
            'budget_statement' => ['required', 'optional'], 'grant_recognition' => ['ipsas23']],
        'local' => ['terminology' => ['nonprofit', 'ifrs_business', 'us_business', 'public'], 'inventory_costing' => ['weighted_average', 'fifo', 'specific', 'lifo'],
            'inventory_writedown_reversal' => ['yes', 'no'], 'asset_measurement' => ['cost', 'revaluation'], 'impairment_reversal' => ['yes', 'no'], 'component_depreciation' => ['optional', 'required'],
            'cash_flow_method' => ['indirect', 'direct'], 'budget_statement' => ['optional', 'required'], 'grant_recognition' => ['ias20', 'ipsas23', 'asc958']],
    ];

    public const WORDING = [
        'ifrs_business' => ['performance' => 'Statement of profit or loss', 'position' => 'Statement of financial position', 'revenue' => 'Revenue', 'expense' => 'Expenses',
            'equity' => 'Equity', 'result' => 'Profit / (loss) for the period', 'accumulated' => 'Retained earnings', 'total_claims' => 'Total equity and liabilities'],
        'us_business' => ['performance' => 'Income statement', 'position' => 'Balance sheet', 'revenue' => 'Revenues', 'expense' => 'Expenses',
            'equity' => "Stockholders' equity", 'result' => 'Net income / (loss)', 'accumulated' => 'Retained earnings', 'total_claims' => "Total liabilities and stockholders' equity"],
        'nonprofit' => ['performance' => 'Statement of income and expenditure', 'position' => 'Statement of financial position', 'revenue' => 'Income', 'expense' => 'Expenditure',
            'equity' => 'Fund balances and reserves', 'result' => 'Surplus / (deficit) for the period', 'accumulated' => 'Accumulated surplus / (deficit)', 'total_claims' => 'Total liabilities and fund balances'],
        'public' => ['performance' => 'Statement of financial performance', 'position' => 'Statement of financial position', 'revenue' => 'Revenue', 'expense' => 'Expenses',
            'equity' => 'Net assets / equity', 'result' => 'Surplus / (deficit) for the period', 'accumulated' => 'Accumulated surplus / (deficit)', 'total_claims' => 'Total liabilities and net assets'],
    ];

    /** @return array<string, list<string>> */
    public static function allowed(string $profile): array
    {
        return self::RULES[$profile] ?? self::RULES['ifrs_sme'];
    }

    /** @return array<string, string> */
    public static function defaults(string $profile): array
    {
        return array_map(fn ($values) => $values[0], self::allowed($profile));
    }

    /** Overrides kept only where the profile allows them; everything else falls back to the default. */
    public static function resolve(string $profile, ?array $overrides): array
    {
        $allowed = self::allowed($profile);
        $out = self::defaults($profile);
        foreach ($overrides ?? [] as $key => $value) {
            if (isset($allowed[$key]) && in_array($value, $allowed[$key], true)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
