<?php
declare(strict_types=1);

namespace One\Housekeeping;

/**
 * The cleaning checklist, ported 1:1 from housekeeping/public/cleaning_form.php + checklist.js:
 * sections, apartment-specific tasks, studios without living room, 3 random photo areas.
 * Stage 4 moves this into "Setări apartamente".
 */
final class Checklist
{
    /** Per check-out: the cleaning + one verification pass. */
    public const MAX_SUBMISSIONS = 2;
    public const PHOTOS = 3;
    public const INTERMEDIATE_RATE = 30; // RON, flat (housekeeping/config/app_config.php)

    /** Studios have no separate living room → the Living section is hidden. */
    public const STUDIOS = ['367', '400', '424', '435', '5', '99', '309'];

    /** section key => [title, [item id => label]] — keys match the legacy e-mail sections. */
    public const SECTIONS = [
        'hol' => ['Hol', [
            'hall-slippers' => 'Pus papuci',
        ]],
        'bucatarie' => ['Bucătărie', [
            'kitchen-table'    => 'Șters masă și blat',
            'kitchen-sink'     => 'Curățat chiuvetă',
            'kitchen-fridge'   => 'Verificat frigider și congelator',
            'kitchen-oven'     => 'Verificat cuptor',
            'kitchen-stove'    => 'Șters plită',
            'kitchen-glasses'  => 'Verificat pahare și căni',
            'kitchen-cutlery'  => 'Verificat tacâmuri',
            'kitchen-trash'    => 'Luat sacul de gunoi',
            'kitchen-napkins'  => 'Verificat șervețele Z',
        ]],
        'dormitor' => ['Dormitor', [
            'bedroom-bed'        => 'Făcut patul',
            'bedroom-towels'     => 'Pus prosoape',
            'bedroom-dresser'    => 'Șters comodă',
            'bedroom-tv'         => 'Șters TV',
            'bedroom-windows'    => 'Verificat geamurile',
            'bedroom-nightstand' => 'Șters noptiere',
        ]],
        'living' => ['Living', [
            'living-couch'   => 'Făcut canapeaua',
            'living-table'   => 'Șters masă',
            'living-tv'      => 'Șters TV',
            'living-dresser' => 'Șters comodă',
            'living-remotes' => 'Telecomenzi TV/AC pe comodă',
        ]],
        'baie' => ['Baie', [
            'bathroom-sink'             => 'Șters chiuvetă',
            'bathroom-mirror'           => 'Șters oglindă',
            'bathroom-wc'               => 'Șters WC',
            'bathroom-washing-machine'  => 'Verificat mașina de spălat și uscător',
            'bathroom-sanitized-bands'  => 'Pus benzi Cleaned & Sanitized',
            'bathroom-shampoo'          => 'Pus sticluțe Șampon și Bețișoare',
            'bathroom-soap'             => 'Verificat Săpun Lichid/Gel de duș',
        ]],
        'balcon' => ['Balcon', [
            'balcony-windows' => 'Verificat geamuri',
            'balcony-ashtray' => 'Golit scrumiera',
        ]],
        'general' => ['General', [
            'general-vacuum' => 'Aspirat podea + plintă',
            'general-mop'    => 'Dat cu mopul',
            'general-lights' => 'Verificat lustre',
        ]],
    ];

    private const DISHWASHER = ['kitchen-dishwasher' => 'Verificat mașina de spălat vase'];
    private const SHOWER = ['bathroom-shower-cabin' => 'Șters cabina de duș'];
    private const BATHTUB = ['bathroom-bathtub' => 'Șters cada'];
    private const HALL_MIRROR = ['hall-mirror' => 'Șters oglindă'];

    /** apartment => [section => [id => label]] */
    private const EXTRA_TASKS = [
        '367' => ['bucatarie' => ['kitchen-mirror' => 'Șters oglindă'] + self::DISHWASHER, 'baie' => self::SHOWER],
        '400' => ['bucatarie' => self::DISHWASHER, 'baie' => self::BATHTUB],
        '424' => ['baie' => self::SHOWER, 'bucatarie' => self::DISHWASHER, 'hol' => self::HALL_MIRROR],
        '435' => ['baie' => self::SHOWER, 'hol' => self::HALL_MIRROR],
        '5'   => ['baie' => self::SHOWER, 'bucatarie' => self::DISHWASHER, 'hol' => self::HALL_MIRROR],
        '99'  => ['baie' => self::SHOWER, 'hol' => self::HALL_MIRROR],
        '177' => ['baie' => self::BATHTUB, 'bucatarie' => self::DISHWASHER, 'living' => ['living-glass-table' => 'Șters masa de sticlă']],
        '187' => ['baie' => self::BATHTUB, 'bucatarie' => self::DISHWASHER],
        '347' => ['baie' => self::SHOWER, 'bucatarie' => self::DISHWASHER],
        '594' => ['baie' => self::SHOWER],
    ];

    private const PHOTO_AREAS = [
        'Chiuvetă bucătărie', 'Chiuvetă baie', 'Frigider', 'Oglindă baie', 'Toaletă',
        'Pat', 'Balcon', 'Geamuri cameră', 'Tocul de la Termopan cameră', 'Blat bucătărie',
    ];

    private const PHOTO_AREAS_APARTMENT = [
        '367' => ['Oglindă bucătărie', 'Canapea', 'Mașina de spălat vase', 'Cabina de duș', 'Scurgerea de la duș (interior)'],
        '400' => ['Microunde', 'Spate comodă TV', 'Cada'],
        '424' => ['Cabina de duș', 'Mașina de spălat vase', 'Spate comodă TV', 'Oglinda hol', 'Scurgerea de la duș (interior)'],
        '435' => ['Cabina de duș', 'Sertar pat', 'Spate comodă TV', 'Oglinda hol', 'Scurgerea de la duș (interior)'],
        '5'   => ['Cabina de duș', 'Mașina de spălat vase', 'Oglinda hol', 'Scurgerea de la duș (interior)'],
        '99'  => ['Cabina de duș', 'Oglinda hol', 'Scurgerea de la duș (interior)'],
        '309' => ['Cabina de duș', 'Scaunul gri', 'Spate comodă TV', 'Oglinda hol', 'Scurgerea de la duș (interior)'],
        '177' => ['Cada', 'Mașina de spălat vase', 'Masa din living', 'Bancă hol'],
        '187' => ['Cada', 'Mașina de spălat vase', 'Canapea', 'Fotoliu galben', 'Bancă hol'],
        '347' => ['Cabina de duș', 'Mașina de spălat vase', 'Bancă hol', 'Scurgerea de la duș (interior)'],
        '594' => ['Cabina de duș', 'Sub comoda living', 'Scurgerea de la duș (interior)'],
    ];

    /** Submissions allowed on a day with $rounds check-outs in the apartment (2 per check-out). */
    public static function maxFor(int $rounds): int
    {
        return self::MAX_SUBMISSIONS * max(1, $rounds);
    }

    /** @return array<string, array{0:string, 1:array<string,string>}> sections for this apartment */
    public static function sectionsFor(string $apartment): array
    {
        $sections = self::SECTIONS;
        if (in_array($apartment, self::STUDIOS, true)) {
            unset($sections['living']);
        }
        foreach (self::EXTRA_TASKS[$apartment] ?? [] as $section => $items) {
            if (isset($sections[$section])) {
                $sections[$section][1] += $items;
            }
        }
        return $sections;
    }

    /** 3 random areas; when the apartment has its own areas, one of them is always included. @return list<string> */
    public static function photoAreas(string $apartment): array
    {
        $specific = self::PHOTO_AREAS_APARTMENT[$apartment] ?? [];
        $pool = array_values(array_unique([...self::PHOTO_AREAS, ...$specific]));
        $picked = [];
        if ($specific) {
            $picked[] = $specific[array_rand($specific)];
            $pool = array_values(array_diff($pool, $picked));
        }
        shuffle($pool);
        while (count($picked) < self::PHOTOS && $pool) {
            $picked[] = array_shift($pool);
        }
        return $picked;
    }
}
