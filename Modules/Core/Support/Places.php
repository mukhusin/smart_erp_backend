<?php

namespace Modules\Core\Support;

/** Regions and districts for choosing where someone will be; Tanzania for now. */
final class Places
{
    public const TANZANIA = [
        'Arusha' => ['Arusha City', 'Arusha', 'Karatu', 'Longido', 'Meru', 'Monduli', 'Ngorongoro'],
        'Dar es Salaam' => ['Ilala', 'Kinondoni', 'Temeke', 'Kigamboni', 'Ubungo'],
        'Dodoma' => ['Dodoma City', 'Bahi', 'Chamwino', 'Chemba', 'Kondoa', 'Kongwa', 'Mpwapwa'],
        'Geita' => ['Geita', 'Bukombe', 'Chato', 'Mbogwe', "Nyang'hwale"],
        'Iringa' => ['Iringa Municipal', 'Iringa', 'Kilolo', 'Mufindi'],
        'Kagera' => ['Bukoba Municipal', 'Bukoba', 'Biharamulo', 'Karagwe', 'Kyerwa', 'Missenyi', 'Muleba', 'Ngara'],
        'Katavi' => ['Mpanda', 'Mlele', 'Tanganyika'],
        'Kigoma' => ['Kigoma-Ujiji', 'Kigoma', 'Buhigwe', 'Kakonko', 'Kasulu', 'Kibondo', 'Uvinza'],
        'Kilimanjaro' => ['Moshi Municipal', 'Moshi', 'Hai', 'Mwanga', 'Rombo', 'Same', 'Siha'],
        'Lindi' => ['Lindi', 'Kilwa', 'Liwale', 'Nachingwea', 'Ruangwa'],
        'Manyara' => ['Babati', 'Hanang', 'Kiteto', 'Mbulu', 'Simanjiro'],
        'Mara' => ['Musoma Municipal', 'Musoma', 'Bunda', 'Butiama', 'Rorya', 'Serengeti', 'Tarime'],
        'Mbeya' => ['Mbeya City', 'Mbeya', 'Busokelo', 'Chunya', 'Kyela', 'Mbarali', 'Rungwe'],
        'Morogoro' => ['Morogoro Municipal', 'Morogoro', 'Gairo', 'Kilombero', 'Kilosa', 'Malinyi', 'Mvomero', 'Ulanga'],
        'Mtwara' => ['Mtwara Municipal', 'Mtwara', 'Masasi', 'Nanyumbu', 'Newala', 'Tandahimba'],
        'Mwanza' => ['Ilemela', 'Nyamagana', 'Buchosa', 'Kwimba', 'Magu', 'Misungwi', 'Sengerema', 'Ukerewe'],
        'Njombe' => ['Njombe', 'Ludewa', 'Makete', "Wanging'ombe"],
        'Pwani' => ['Kibaha', 'Bagamoyo', 'Chalinze', 'Kibiti', 'Kisarawe', 'Mafia', 'Mkuranga', 'Rufiji'],
        'Rukwa' => ['Sumbawanga', 'Kalambo', 'Nkasi'],
        'Ruvuma' => ['Songea Municipal', 'Songea', 'Madaba', 'Mbinga', 'Namtumbo', 'Nyasa', 'Tunduru'],
        'Shinyanga' => ['Shinyanga Municipal', 'Shinyanga', 'Kahama', 'Kishapu'],
        'Simiyu' => ['Bariadi', 'Busega', 'Itilima', 'Maswa', 'Meatu'],
        'Singida' => ['Singida Municipal', 'Singida', 'Ikungi', 'Iramba', 'Manyoni', 'Mkalama'],
        'Songwe' => ['Ileje', 'Mbozi', 'Momba', 'Songwe'],
        'Tabora' => ['Tabora Municipal', 'Igunga', 'Kaliua', 'Nzega', 'Sikonge', 'Urambo', 'Uyui'],
        'Tanga' => ['Tanga City', 'Bumbuli', 'Handeni', 'Kilindi', 'Korogwe', 'Lushoto', 'Mkinga', 'Muheza', 'Pangani'],
        'Kaskazini Pemba' => ['Micheweni', 'Wete'],
        'Kusini Pemba' => ['Chake Chake', 'Mkoani'],
        'Kaskazini Unguja' => ['Kaskazini A', 'Kaskazini B'],
        'Kusini Unguja' => ['Kati', 'Kusini'],
        'Mjini Magharibi' => ['Mjini', 'Magharibi A', 'Magharibi B'],
    ];

    /** @return array<string, list<string>> regions and their districts for a country, empty when not known */
    public static function for(?string $country): array
    {
        return in_array(self::norm((string) $country), ['tanzania', 'tz', 'tza', 'united republic of tanzania'], true) ? self::TANZANIA : [];
    }

    /** The region a city, district or region name belongs to, or null. */
    public static function regionOf(?string $place, array $regions): ?string
    {
        $p = self::norm((string) $place);
        if ($p === '') {
            return null;
        }
        foreach ($regions as $region => $districts) {
            if (self::norm($region) === $p) {
                return $region;
            }
        }
        foreach ($regions as $region => $districts) {
            foreach ($districts as $d) {
                if (self::norm($d) === $p) {
                    return $region;
                }
            }
        }

        return null;
    }

    /** The region of a "District, Region" (or "Region") answer. */
    public static function regionOfAnswer(?string $answer, array $regions): ?string
    {
        $parts = array_map('trim', explode(',', (string) $answer));

        return self::regionOf(end($parts) ?: null, $regions) ?? self::regionOf($parts[0] ?? null, $regions);
    }

    private static function norm(string $s): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($s)));
    }
}
