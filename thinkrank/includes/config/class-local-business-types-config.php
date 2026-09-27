<?php

/**
 * schema.org LocalBusiness subtypes.
 *
 * The Local SEO business-type control offered five of these. A customer asking
 * for `HealthAndBeautyBusiness` had no way to select it, and competing plugins
 * expose the whole vocabulary, so anything outside those five was simply
 * unreachable (#623).
 *
 * Kept as a flat map of type => English label, with the hierarchy expressed by
 * `PARENTS` rather than by nesting. A flat list is what both consumers actually
 * want: the control renders one searchable list, and the MCP ability needs a
 * flat `enum`. Nesting would have to be flattened again at both call sites.
 *
 * Labels are the schema.org type names in readable form, deliberately NOT
 * translated. The value written to settings is the schema.org type, the label
 * differs from it only by spacing, and a translated label would leave a
 * German-language site searching this list in English for a term it was shown
 * in German. Where a type name is genuinely opaque the label stays identical to
 * the type rather than inventing a gloss.
 *
 * @package ThinkRank\Config
 * @since 2.10.0
 */

declare(strict_types=1);

namespace ThinkRank\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Local Business Types Config
 *
 * @since 2.10.0
 */
class Local_Business_Types_Config {

    /**
     * The root type. Always valid, and the default.
     */
    public const ROOT = 'LocalBusiness';

    /**
     * Direct parent of each subtype, for grouping in the UI.
     *
     * Types absent from this map hang directly off LocalBusiness.
     *
     * @var array<string,string>
     */
    private const PARENTS = [
        // AutomotiveBusiness
        'AutoBodyShop' => 'AutomotiveBusiness',
        'AutoDealer' => 'AutomotiveBusiness',
        'AutoPartsStore' => 'AutomotiveBusiness',
        'AutoRental' => 'AutomotiveBusiness',
        'AutoRepair' => 'AutomotiveBusiness',
        'AutoWash' => 'AutomotiveBusiness',
        'GasStation' => 'AutomotiveBusiness',
        'MotorcycleDealer' => 'AutomotiveBusiness',
        'MotorcycleRepair' => 'AutomotiveBusiness',

        // EmergencyService
        'FireStation' => 'EmergencyService',
        'Hospital' => 'EmergencyService',
        'PoliceStation' => 'EmergencyService',

        // EntertainmentBusiness
        'AdultEntertainment' => 'EntertainmentBusiness',
        'AmusementPark' => 'EntertainmentBusiness',
        'ArtGallery' => 'EntertainmentBusiness',
        'Casino' => 'EntertainmentBusiness',
        'ComedyClub' => 'EntertainmentBusiness',
        'MovieTheater' => 'EntertainmentBusiness',
        'NightClub' => 'EntertainmentBusiness',

        // FinancialService
        'AccountingService' => 'FinancialService',
        'AutomatedTeller' => 'FinancialService',
        'BankOrCreditUnion' => 'FinancialService',
        'InsuranceAgency' => 'FinancialService',

        // FoodEstablishment
        'Bakery' => 'FoodEstablishment',
        'BarOrPub' => 'FoodEstablishment',
        'Brewery' => 'FoodEstablishment',
        'CafeOrCoffeeShop' => 'FoodEstablishment',
        'Distillery' => 'FoodEstablishment',
        'FastFoodRestaurant' => 'FoodEstablishment',
        'IceCreamShop' => 'FoodEstablishment',
        'Restaurant' => 'FoodEstablishment',
        'Winery' => 'FoodEstablishment',

        // GovernmentOffice
        'PostOffice' => 'GovernmentOffice',

        // HealthAndBeautyBusiness — the type the report was about.
        'BeautySalon' => 'HealthAndBeautyBusiness',
        'DaySpa' => 'HealthAndBeautyBusiness',
        'HairSalon' => 'HealthAndBeautyBusiness',
        'HealthClub' => 'HealthAndBeautyBusiness',
        'NailSalon' => 'HealthAndBeautyBusiness',
        'TattooParlor' => 'HealthAndBeautyBusiness',

        // HomeAndConstructionBusiness
        'Electrician' => 'HomeAndConstructionBusiness',
        'GeneralContractor' => 'HomeAndConstructionBusiness',
        'HVACBusiness' => 'HomeAndConstructionBusiness',
        'HousePainter' => 'HomeAndConstructionBusiness',
        'Locksmith' => 'HomeAndConstructionBusiness',
        'MovingCompany' => 'HomeAndConstructionBusiness',
        'Plumber' => 'HomeAndConstructionBusiness',
        'RoofingContractor' => 'HomeAndConstructionBusiness',

        // LegalService
        'Attorney' => 'LegalService',
        'Notary' => 'LegalService',

        // LodgingBusiness
        'BedAndBreakfast' => 'LodgingBusiness',
        'Campground' => 'LodgingBusiness',
        'Hostel' => 'LodgingBusiness',
        'Hotel' => 'LodgingBusiness',
        'Motel' => 'LodgingBusiness',
        'Resort' => 'LodgingBusiness',
        'VacationRental' => 'LodgingBusiness',

        // MedicalBusiness
        'CommunityHealth' => 'MedicalBusiness',
        'Dentist' => 'MedicalBusiness',
        'Dermatology' => 'MedicalBusiness',
        'DietNutrition' => 'MedicalBusiness',
        'Emergency' => 'MedicalBusiness',
        'Geriatric' => 'MedicalBusiness',
        'Gynecologic' => 'MedicalBusiness',
        'MedicalClinic' => 'MedicalBusiness',
        'Midwifery' => 'MedicalBusiness',
        'Nursing' => 'MedicalBusiness',
        'Obstetric' => 'MedicalBusiness',
        'Oncologic' => 'MedicalBusiness',
        'Optician' => 'MedicalBusiness',
        'Optometric' => 'MedicalBusiness',
        'Otolaryngologic' => 'MedicalBusiness',
        'Pediatric' => 'MedicalBusiness',
        'Pharmacy' => 'MedicalBusiness',
        'Physician' => 'MedicalBusiness',
        'Physiotherapy' => 'MedicalBusiness',
        'PlasticSurgery' => 'MedicalBusiness',
        'Podiatric' => 'MedicalBusiness',
        'PrimaryCare' => 'MedicalBusiness',
        'Psychiatric' => 'MedicalBusiness',
        'PublicHealth' => 'MedicalBusiness',

        // SportsActivityLocation
        'BowlingAlley' => 'SportsActivityLocation',
        'ExerciseGym' => 'SportsActivityLocation',
        'GolfCourse' => 'SportsActivityLocation',
        'PublicSwimmingPool' => 'SportsActivityLocation',
        'SkiResort' => 'SportsActivityLocation',
        'SportsClub' => 'SportsActivityLocation',
        'StadiumOrArena' => 'SportsActivityLocation',
        'TennisComplex' => 'SportsActivityLocation',

        // Store
        'BikeStore' => 'Store',
        'BookStore' => 'Store',
        'ClothingStore' => 'Store',
        'ComputerStore' => 'Store',
        'ConvenienceStore' => 'Store',
        'DepartmentStore' => 'Store',
        'ElectronicsStore' => 'Store',
        'Florist' => 'Store',
        'FurnitureStore' => 'Store',
        'GardenStore' => 'Store',
        'GroceryStore' => 'Store',
        'HardwareStore' => 'Store',
        'HobbyShop' => 'Store',
        'HomeGoodsStore' => 'Store',
        'JewelryStore' => 'Store',
        'LiquorStore' => 'Store',
        'MensClothingStore' => 'Store',
        'MobilePhoneStore' => 'Store',
        'MovieRentalStore' => 'Store',
        'MusicStore' => 'Store',
        'OfficeEquipmentStore' => 'Store',
        'OutletStore' => 'Store',
        'PawnShop' => 'Store',
        'PetStore' => 'Store',
        'ShoeStore' => 'Store',
        'SportingGoodsStore' => 'Store',
        'TireShop' => 'Store',
        'ToyStore' => 'Store',
        'WholesaleStore' => 'Store',
    ];

    /**
     * Subtypes that hang directly off LocalBusiness.
     *
     * @var string[]
     */
    private const TOP_LEVEL = [
        'AnimalShelter',
        'ArchiveOrganization',
        'AutomotiveBusiness',
        'ChildCare',
        'Dentist',
        'DryCleaningOrLaundry',
        'EmergencyService',
        'EmploymentAgency',
        'EntertainmentBusiness',
        'FinancialService',
        'FoodEstablishment',
        'GovernmentOffice',
        'HealthAndBeautyBusiness',
        'HomeAndConstructionBusiness',
        'InternetCafe',
        'LegalService',
        'Library',
        'LodgingBusiness',
        'MedicalBusiness',
        'ProfessionalService',
        'RadioStation',
        'RealEstateAgent',
        'RecyclingCenter',
        'SelfStorage',
        'ShoppingCenter',
        'SportsActivityLocation',
        'Store',
        'TelevisionStation',
        'TouristInformationCenter',
        'TravelAgency',
    ];

    /**
     * Types ThinkRank offered before this list existed that are NOT
     * LocalBusiness subtypes.
     *
     * `MedicalOrganization` sits under Organization, not LocalBusiness — but it
     * was one of the five options the control used to offer, so sites are
     * storing it. Dropping it would have an upgrade silently rewrite what those
     * sites publish, which is worse than carrying a type that is merely in the
     * wrong branch of the vocabulary: it is still valid schema.org, and
     * MedicalBusiness (the LocalBusiness-side equivalent) is offered alongside
     * it for anyone choosing afresh.
     *
     * @var string[]
     */
    private const LEGACY = [
        'MedicalOrganization',
    ];

    /**
     * Labels that a space-separated type name would get wrong.
     *
     * Only where splitting on capitals produces something misleading. Anything
     * not listed is derived, so the map stays short enough to read.
     *
     * @var array<string,string>
     */
    private const LABEL_OVERRIDES = [
        'BarOrPub' => 'Bar or Pub',
        'BankOrCreditUnion' => 'Bank or Credit Union',
        'CafeOrCoffeeShop' => 'Cafe or Coffee Shop',
        'StadiumOrArena' => 'Stadium or Arena',
        'DryCleaningOrLaundry' => 'Dry Cleaning or Laundry',
        'BedAndBreakfast' => 'Bed and Breakfast',
        'HealthAndBeautyBusiness' => 'Health and Beauty Business',
        'HomeAndConstructionBusiness' => 'Home and Construction Business',
        'HVACBusiness' => 'HVAC Business',
        'AutomatedTeller' => 'Automated Teller (ATM)',
        'DietNutrition' => 'Diet and Nutrition',
        'MensClothingStore' => "Men's Clothing Store",
    ];

    /**
     * Every selectable business type, root first.
     *
     * @return string[]
     */
    public static function get_types(): array {
        $types = array_merge(
            [self::ROOT],
            self::TOP_LEVEL,
            array_keys(self::PARENTS),
            self::LEGACY
        );

        // A type can appear in both lists — Dentist is a LocalBusiness subtype
        // in its own right and a MedicalBusiness one — and must be offered once.
        return array_values(array_unique($types));
    }

    /**
     * Whether a value is a business type ThinkRank will accept.
     *
     * @param string $type Candidate type.
     * @return bool
     */
    public static function is_valid(string $type): bool {
        return in_array($type, self::get_types(), true);
    }

    /**
     * Whether a type is a LocalBusiness in schema.org's own hierarchy.
     *
     * Narrower than is_valid(): the LEGACY entries are accepted as stored
     * values but are not LocalBusiness subtypes. Anything that decides what a
     * node IS (its `@type`, its site-level `@id`, which validator spec applies)
     * asks this, not is_valid().
     *
     * @since 2.10.0
     *
     * @param mixed $type Candidate type. Anything but a string is not a type.
     * @return bool
     */
    public static function is_local_business($type): bool {
        if (!is_string($type) || '' === $type) {
            return false;
        }

        return self::ROOT === $type
            || in_array($type, self::TOP_LEVEL, true)
            || isset(self::PARENTS[$type]);
    }

    /**
     * The `@type` to publish for a stored business type.
     *
     * The stored type when it is a LocalBusiness subtype, the root otherwise.
     * "Otherwise" covers an unset value, anything an older importer stored
     * verbatim (Rank Math's `Organization`), and the LEGACY
     * `MedicalOrganization`: every one of those has always been published as
     * `LocalBusiness`, and keeping it that way means an upgrade changes the
     * JSON-LD only of sites that actually chose a subtype. A LEGACY type is
     * also an Organization, and the node carries LocalBusiness-only properties
     * (openingHoursSpecification, priceRange) that would not be valid on one.
     *
     * @since 2.10.0
     *
     * @param mixed $stored Stored business_type value.
     * @return string schema.org type.
     */
    public static function schema_type($stored): string {
        return self::is_local_business($stored) ? (string) $stored : self::ROOT;
    }

    /**
     * Readable label for a type.
     *
     * @param string $type schema.org type name.
     * @return string
     */
    public static function label(string $type): string {
        if (isset(self::LABEL_OVERRIDES[$type])) {
            return self::LABEL_OVERRIDES[$type];
        }

        // Split on the capitals that start a word, keeping runs of capitals
        // (HVAC, ATM) together.
        $label = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $type);

        return trim($label);
    }

    /**
     * The direct parent of a type, or '' for a top-level one.
     *
     * @param string $type schema.org type name.
     * @return string
     */
    public static function parent(string $type): string {
        return self::PARENTS[$type] ?? '';
    }

    /**
     * Every type as value/label pairs, for a select control.
     *
     * Sorted by label so a ~180-entry searchable list reads alphabetically,
     * with the root pinned first because it is the default and the safe answer.
     *
     * @return array<int,array{value:string,label:string,parent:string}>
     */
    public static function get_options(): array {
        $types = self::get_types();
        $root  = array_shift($types);

        $options = array_map(
            static fn(string $type): array => [
                'value'  => $type,
                'label'  => self::label($type),
                'parent' => self::parent($type),
            ],
            $types
        );

        usort($options, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

        array_unshift($options, [
            'value'  => $root,
            'label'  => self::label($root),
            'parent' => '',
        ]);

        return $options;
    }
}
