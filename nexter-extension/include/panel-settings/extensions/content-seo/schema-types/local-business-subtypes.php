<?php
/**
 * Grouped JSON-LD @type values for LocalBusiness (schema.org subtypes).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nexter_content_seo_schema_local_business_subtype_groups' ) ) {
	/**
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_local_business_subtype_groups() {
		return array(
			'general'    => array(
				'label'   => __( 'General', 'nexter-extension' ),
				'options' => array(
					'LocalBusiness'            => __( 'Local business', 'nexter-extension' ),
					'AnimalShelter'            => __( 'Animal shelter', 'nexter-extension' ),
					'ArchiveOrganization'      => __( 'Archive organization', 'nexter-extension' ),
					'ChildCare'                => __( 'Child care', 'nexter-extension' ),
					'DryCleaningOrLaundry'     => __( 'Dry cleaning or laundry', 'nexter-extension' ),
					'EmploymentAgency'         => __( 'Employment agency', 'nexter-extension' ),
					'InternetCafe'             => __( 'Internet cafe', 'nexter-extension' ),
					'Library'                  => __( 'Library', 'nexter-extension' ),
					'ProfessionalService'      => __( 'Professional service', 'nexter-extension' ),
					'RadioStation'             => __( 'Radio station', 'nexter-extension' ),
					'RealEstateAgent'          => __( 'Real-estate agent', 'nexter-extension' ),
					'RecyclingCenter'          => __( 'Recycling center', 'nexter-extension' ),
					'SelfStorage'              => __( 'Self storage', 'nexter-extension' ),
					'ShoppingCenter'           => __( 'Shopping center', 'nexter-extension' ),
					'TelevisionStation'        => __( 'Television station', 'nexter-extension' ),
					'TouristInformationCenter' => __( 'Tourist information center', 'nexter-extension' ),
					'TravelAgency'             => __( 'Travel agency', 'nexter-extension' ),
				),
			),
			'automotive' => array(
				'label'   => __( 'Automotive business', 'nexter-extension' ),
				'options' => array(
					'AutoBodyShop'       => __( 'Auto body shop', 'nexter-extension' ),
					'AutoDealer'         => __( 'Auto dealer', 'nexter-extension' ),
					'AutoPartsStore'     => __( 'Auto parts store', 'nexter-extension' ),
					'AutoRental'         => __( 'Auto rental', 'nexter-extension' ),
					'AutoRepair'         => __( 'Auto repair', 'nexter-extension' ),
					'AutoWash'           => __( 'Auto wash', 'nexter-extension' ),
					'GasStation'         => __( 'Gas station', 'nexter-extension' ),
					'MotorcycleDealer'   => __( 'Motorcycle dealer', 'nexter-extension' ),
					'MotorcycleRepair'   => __( 'Motorcycle repair', 'nexter-extension' ),
					'AutomotiveBusiness' => __( 'Other automotive', 'nexter-extension' ),
				),
			),
			'emergency'  => array(
				'label'   => __( 'Emergency service', 'nexter-extension' ),
				'options' => array(
					'FireStation'      => __( 'Fire station', 'nexter-extension' ),
					'Hospital'         => __( 'Hospital', 'nexter-extension' ),
					'PoliceStation'    => __( 'Police station', 'nexter-extension' ),
					'EmergencyService' => __( 'Other emergency', 'nexter-extension' ),
				),
			),
			'entertain'  => array(
				'label'   => __( 'Entertainment business', 'nexter-extension' ),
				'options' => array(
					'AdultEntertainment'    => __( 'Adult entertainment', 'nexter-extension' ),
					'AmusementPark'         => __( 'Amusement park', 'nexter-extension' ),
					'ArtGallery'            => __( 'Art gallery', 'nexter-extension' ),
					'Casino'                => __( 'Casino', 'nexter-extension' ),
					'ComedyClub'            => __( 'Comedy club', 'nexter-extension' ),
					'MovieTheater'          => __( 'Movie theater', 'nexter-extension' ),
					'NightClub'             => __( 'Night club', 'nexter-extension' ),
					'EntertainmentBusiness' => __( 'Other entertainment', 'nexter-extension' ),
				),
			),
			'financial'  => array(
				'label'   => __( 'Financial service', 'nexter-extension' ),
				'options' => array(
					'AccountingService' => __( 'Accounting service', 'nexter-extension' ),
					'AutomatedTeller'   => __( 'Automated teller', 'nexter-extension' ),
					'BankOrCreditUnion' => __( 'Bank or credit union', 'nexter-extension' ),
					'InsuranceAgency'   => __( 'Insurance agency', 'nexter-extension' ),
					'FinancialService'  => __( 'Other financial', 'nexter-extension' ),
				),
			),
			'food'       => array(
				'label'   => __( 'Food establishment', 'nexter-extension' ),
				'options' => array(
					'Bakery'             => __( 'Bakery', 'nexter-extension' ),
					'BarOrPub'           => __( 'Bar or pub', 'nexter-extension' ),
					'Brewery'            => __( 'Brewery', 'nexter-extension' ),
					'CafeOrCoffeeShop'   => __( 'Cafe or coffee shop', 'nexter-extension' ),
					'Distillery'         => __( 'Distillery', 'nexter-extension' ),
					'FastFoodRestaurant' => __( 'Fast-food restaurant', 'nexter-extension' ),
					'IceCreamShop'       => __( 'Ice-cream shop', 'nexter-extension' ),
					'Restaurant'         => __( 'Restaurant', 'nexter-extension' ),
					'Winery'             => __( 'Winery', 'nexter-extension' ),
					'FoodEstablishment'  => __( 'Other food', 'nexter-extension' ),
				),
			),
			'government' => array(
				'label'   => __( 'Government office', 'nexter-extension' ),
				'options' => array(
					'PostOffice'       => __( 'Post office', 'nexter-extension' ),
					'GovernmentOffice' => __( 'Other government', 'nexter-extension' ),
				),
			),
			'healthbeat' => array(
				'label'   => __( 'Health and beauty business', 'nexter-extension' ),
				'options' => array(
					'BeautySalon'             => __( 'Beauty salon', 'nexter-extension' ),
					'DaySpa'                  => __( 'Day spa', 'nexter-extension' ),
					'HairSalon'               => __( 'Hair salon', 'nexter-extension' ),
					'HealthClub'              => __( 'Health club', 'nexter-extension' ),
					'NailSalon'               => __( 'Nail salon', 'nexter-extension' ),
					'TattooParlor'            => __( 'Tattoo parlor', 'nexter-extension' ),
					'HealthAndBeautyBusiness' => __( 'Other health & beauty', 'nexter-extension' ),
				),
			),
			'homeconst'  => array(
				'label'   => __( 'Home and construction business', 'nexter-extension' ),
				'options' => array(
					'Electrician'                 => __( 'Electrician', 'nexter-extension' ),
					'GeneralContractor'           => __( 'General contractor', 'nexter-extension' ),
					'HVACBusiness'                => __( 'HVAC business', 'nexter-extension' ),
					'HousePainter'                => __( 'House painter', 'nexter-extension' ),
					'Locksmith'                   => __( 'Locksmith', 'nexter-extension' ),
					'MovingCompany'               => __( 'Moving company', 'nexter-extension' ),
					'Plumber'                     => __( 'Plumber', 'nexter-extension' ),
					'RoofingContractor'           => __( 'Roofing contractor', 'nexter-extension' ),
					'HomeAndConstructionBusiness' => __( 'Other home & construction', 'nexter-extension' ),
				),
			),
			'legal'      => array(
				'label'   => __( 'Legal service', 'nexter-extension' ),
				'options' => array(
					'Attorney'     => __( 'Attorney', 'nexter-extension' ),
					'Notary'       => __( 'Notary', 'nexter-extension' ),
					'LegalService' => __( 'Other legal', 'nexter-extension' ),
				),
			),
			'lodging'    => array(
				'label'   => __( 'Lodging business', 'nexter-extension' ),
				'options' => array(
					'BedAndBreakfast' => __( 'Bed and breakfast', 'nexter-extension' ),
					'Campground'      => __( 'Campground', 'nexter-extension' ),
					'Hostel'          => __( 'Hostel', 'nexter-extension' ),
					'Hotel'           => __( 'Hotel', 'nexter-extension' ),
					'Motel'           => __( 'Motel', 'nexter-extension' ),
					'Resort'          => __( 'Resort', 'nexter-extension' ),
					'LodgingBusiness' => __( 'Other lodging', 'nexter-extension' ),
				),
			),
			'medicalbiz' => array(
				'label'   => __( 'Medical business', 'nexter-extension' ),
				'options' => array(
					'CommunityHealth' => __( 'Community health', 'nexter-extension' ),
					'Dentist'         => __( 'Dentist', 'nexter-extension' ),
					'Dermatology'     => __( 'Dermatology', 'nexter-extension' ),
					'DietNutrition'   => __( 'Diet nutrition', 'nexter-extension' ),
					'Emergency'       => __( 'Emergency', 'nexter-extension' ),
					'Geriatric'       => __( 'Geriatric', 'nexter-extension' ),
					'Gynecologic'     => __( 'Gynecologic', 'nexter-extension' ),
					'MedicalClinic'   => __( 'Medical clinic', 'nexter-extension' ),
					'Midwifery'       => __( 'Midwifery', 'nexter-extension' ),
					'Nursing'         => __( 'Nursing', 'nexter-extension' ),
					'Obstetric'       => __( 'Obstetric', 'nexter-extension' ),
					'Oncologic'       => __( 'Oncologic', 'nexter-extension' ),
					'Optician'        => __( 'Optician', 'nexter-extension' ),
					'Optometric'      => __( 'Optometric', 'nexter-extension' ),
					'Otolaryngologic' => __( 'Otolaryngologic', 'nexter-extension' ),
					'Pediatric'       => __( 'Pediatric', 'nexter-extension' ),
					'Pharmacy'        => __( 'Pharmacy', 'nexter-extension' ),
					'Physician'       => __( 'Physician', 'nexter-extension' ),
					'Physiotherapy'   => __( 'Physiotherapy', 'nexter-extension' ),
					'PlasticSurgery'  => __( 'Plastic surgery', 'nexter-extension' ),
					'Podiatric'       => __( 'Podiatric', 'nexter-extension' ),
					'PrimaryCare'     => __( 'Primary care', 'nexter-extension' ),
					'Psychiatric'     => __( 'Psychiatric', 'nexter-extension' ),
					'PublicHealth'    => __( 'Public health', 'nexter-extension' ),
					'MedicalBusiness' => __( 'Other medical', 'nexter-extension' ),
				),
			),
			'sportsact'  => array(
				'label'   => __( 'Sports activity location', 'nexter-extension' ),
				'options' => array(
					'BowlingAlley'           => __( 'Bowling alley', 'nexter-extension' ),
					'ExerciseGym'            => __( 'Exercise gym', 'nexter-extension' ),
					'GolfCourse'             => __( 'Golf course', 'nexter-extension' ),
					'PublicSwimmingPool'     => __( 'Public swimming pool', 'nexter-extension' ),
					'SkiResort'              => __( 'Ski resort', 'nexter-extension' ),
					'SportsClub'             => __( 'Sports club', 'nexter-extension' ),
					'StadiumOrArena'         => __( 'Stadium or arena', 'nexter-extension' ),
					'TennisComplex'          => __( 'Tennis complex', 'nexter-extension' ),
					'SportsActivityLocation' => __( 'Other sports activity', 'nexter-extension' ),
				),
			),
			'store'      => array(
				'label'   => __( 'Store', 'nexter-extension' ),
				'options' => array(
					'BikeStore'            => __( 'Bike store', 'nexter-extension' ),
					'BookStore'            => __( 'Book store', 'nexter-extension' ),
					'ClothingStore'        => __( 'Clothing store', 'nexter-extension' ),
					'ComputerStore'        => __( 'Computer store', 'nexter-extension' ),
					'ConvenienceStore'     => __( 'Convenience store', 'nexter-extension' ),
					'DepartmentStore'      => __( 'Department store', 'nexter-extension' ),
					'ElectronicsStore'     => __( 'Electronics store', 'nexter-extension' ),
					'Florist'              => __( 'Florist', 'nexter-extension' ),
					'FurnitureStore'       => __( 'Furniture store', 'nexter-extension' ),
					'GardenStore'          => __( 'Garden store', 'nexter-extension' ),
					'GroceryStore'         => __( 'Grocery store', 'nexter-extension' ),
					'HardwareStore'        => __( 'Hardware store', 'nexter-extension' ),
					'HobbyShop'            => __( 'Hobby shop', 'nexter-extension' ),
					'HomeGoodsStore'       => __( 'Home goods store', 'nexter-extension' ),
					'JewelryStore'         => __( 'Jewelry store', 'nexter-extension' ),
					'LiquorStore'          => __( 'Liquor store', 'nexter-extension' ),
					'MensClothingStore'    => __( "Men's clothing store", 'nexter-extension' ),
					'MobilePhoneStore'     => __( 'Mobile phone store', 'nexter-extension' ),
					'MovieRentalStore'     => __( 'Movie rental store', 'nexter-extension' ),
					'MusicStore'           => __( 'Music store', 'nexter-extension' ),
					'OfficeEquipmentStore' => __( 'Office equipment store', 'nexter-extension' ),
					'OutletStore'          => __( 'Outlet store', 'nexter-extension' ),
					'PawnShop'             => __( 'Pawn shop', 'nexter-extension' ),
					'PetStore'             => __( 'Pet store', 'nexter-extension' ),
					'ShoeStore'            => __( 'Shoe store', 'nexter-extension' ),
					'SportingGoodsStore'   => __( 'Sporting goods store', 'nexter-extension' ),
					'TireShop'             => __( 'Tire shop', 'nexter-extension' ),
					'ToyStore'             => __( 'Toy store', 'nexter-extension' ),
					'WholesaleStore'       => __( 'Wholesale store', 'nexter-extension' ),
					'Store'                => __( 'Other store', 'nexter-extension' ),
				),
			),
		);
	}
}
