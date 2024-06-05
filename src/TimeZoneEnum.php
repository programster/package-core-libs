<?php

/*
 * An enum for all the possible timezones. This values for this can be regenerated from
 * running: "print implode(PHP_EOL, timezone_identifiers_list());"
 */

namespace Programster\CoreLibs;

use Cassandra\Date;

enum TimeZoneEnum : string
{
    case Africa_Abidjan                  = 'Africa/Abidjan';
    case Africa_Accra                    = 'Africa/Accra';
    case Africa_Addis_Ababa              = 'Africa/Addis_Ababa';
    case Africa_Algiers                  = 'Africa/Algiers';
    case Africa_Asmara                   = 'Africa/Asmara';
    case Africa_Bamako                   = 'Africa/Bamako';
    case Africa_Bangui                   = 'Africa/Bangui';
    case Africa_Banjul                   = 'Africa/Banjul';
    case Africa_Bissau                   = 'Africa/Bissau';
    case Africa_Blantyre                 = 'Africa/Blantyre';
    case Africa_Brazzaville              = 'Africa/Brazzaville';
    case Africa_Bujumbura                = 'Africa/Bujumbura';
    case Africa_Cairo                    = 'Africa/Cairo';
    case Africa_Casablanca               = 'Africa/Casablanca';
    case Africa_Ceuta                    = 'Africa/Ceuta';
    case Africa_Conakry                  = 'Africa/Conakry';
    case Africa_Dakar                    = 'Africa/Dakar';
    case Africa_Dar_es_Salaam            = 'Africa/Dar_es_Salaam';
    case Africa_Djibouti                 = 'Africa/Djibouti';
    case Africa_Douala                   = 'Africa/Douala';
    case Africa_El_Aaiun                 = 'Africa/El_Aaiun';
    case Africa_Freetown                 = 'Africa/Freetown';
    case Africa_Gaborone                 = 'Africa/Gaborone';
    case Africa_Harare                   = 'Africa/Harare';
    case Africa_Johannesburg             = 'Africa/Johannesburg';
    case Africa_Juba                     = 'Africa/Juba';
    case Africa_Kampala                  = 'Africa/Kampala';
    case Africa_Khartoum                 = 'Africa/Khartoum';
    case Africa_Kigali                   = 'Africa/Kigali';
    case Africa_Kinshasa                 = 'Africa/Kinshasa';
    case Africa_Lagos                    = 'Africa/Lagos';
    case Africa_Libreville               = 'Africa/Libreville';
    case Africa_Lome                     = 'Africa/Lome';
    case Africa_Luanda                   = 'Africa/Luanda';
    case Africa_Lubumbashi               = 'Africa/Lubumbashi';
    case Africa_Lusaka                   = 'Africa/Lusaka';
    case Africa_Malabo                   = 'Africa/Malabo';
    case Africa_Maputo                   = 'Africa/Maputo';
    case Africa_Maseru                   = 'Africa/Maseru';
    case Africa_Mbabane                  = 'Africa/Mbabane';
    case Africa_Mogadishu                = 'Africa/Mogadishu';
    case Africa_Monrovia                 = 'Africa/Monrovia';
    case Africa_Nairobi                  = 'Africa/Nairobi';
    case Africa_Ndjamena                 = 'Africa/Ndjamena';
    case Africa_Niamey                   = 'Africa/Niamey';
    case Africa_Nouakchott               = 'Africa/Nouakchott';
    case Africa_Ouagadougou              = 'Africa/Ouagadougou';
    case Africa_PortoNovo               = 'Africa/Porto-Novo';
    case Africa_Sao_Tome                 = 'Africa/Sao_Tome';
    case Africa_Tripoli                  = 'Africa/Tripoli';
    case Africa_Tunis                    = 'Africa/Tunis';
    case Africa_Windhoek                 = 'Africa/Windhoek';
    case America_Adak                    = 'America/Adak';
    case America_Anchorage               = 'America/Anchorage';
    case America_Anguilla                = 'America/Anguilla';
    case America_Antigua                 = 'America/Antigua';
    case America_Araguaina               = 'America/Araguaina';
    case America_Argentina_Buenos_Aires  = 'America/Argentina/Buenos_Aires';
    case America_Argentina_Catamarca     = 'America/Argentina/Catamarca';
    case America_Argentina_Cordoba       = 'America/Argentina/Cordoba';
    case America_Argentina_Jujuy         = 'America/Argentina/Jujuy';
    case America_Argentina_La_Rioja      = 'America/Argentina/La_Rioja';
    case America_Argentina_Mendoza       = 'America/Argentina/Mendoza';
    case America_Argentina_Rio_Gallegos  = 'America/Argentina/Rio_Gallegos';
    case America_Argentina_Salta         = 'America/Argentina/Salta';
    case America_Argentina_San_Juan      = 'America/Argentina/San_Juan';
    case America_Argentina_San_Luis      = 'America/Argentina/San_Luis';
    case America_Argentina_Tucuman       = 'America/Argentina/Tucuman';
    case America_Argentina_Ushuaia       = 'America/Argentina/Ushuaia';
    case America_Aruba                   = 'America/Aruba';
    case America_Asuncion                = 'America/Asuncion';
    case America_Atikokan                = 'America/Atikokan';
    case America_Bahia                   = 'America/Bahia';
    case America_Bahia_Banderas          = 'America/Bahia_Banderas';
    case America_Barbados                = 'America/Barbados';
    case America_Belem                   = 'America/Belem';
    case America_Belize                  = 'America/Belize';
    case America_BlancSablon            = 'America/Blanc-Sablon';
    case America_Boa_Vista               = 'America/Boa_Vista';
    case America_Bogota                  = 'America/Bogota';
    case America_Boise                   = 'America/Boise';
    case America_Cambridge_Bay           = 'America/Cambridge_Bay';
    case America_Campo_Grande            = 'America/Campo_Grande';
    case America_Cancun                  = 'America/Cancun';
    case America_Caracas                 = 'America/Caracas';
    case America_Cayenne                 = 'America/Cayenne';
    case America_Cayman                  = 'America/Cayman';
    case America_Chicago                 = 'America/Chicago';
    case America_Chihuahua               = 'America/Chihuahua';
    case America_Ciudad_Juarez           = 'America/Ciudad_Juarez';
    case America_Costa_Rica              = 'America/Costa_Rica';
    case America_Creston                 = 'America/Creston';
    case America_Cuiaba                  = 'America/Cuiaba';
    case America_Curacao                 = 'America/Curacao';
    case America_Danmarkshavn            = 'America/Danmarkshavn';
    case America_Dawson                  = 'America/Dawson';
    case America_Dawson_Creek            = 'America/Dawson_Creek';
    case America_Denver                  = 'America/Denver';
    case America_Detroit                 = 'America/Detroit';
    case America_Dominica                = 'America/Dominica';
    case America_Edmonton                = 'America/Edmonton';
    case America_Eirunepe                = 'America/Eirunepe';
    case America_El_Salvador             = 'America/El_Salvador';
    case America_Fort_Nelson             = 'America/Fort_Nelson';
    case America_Fortaleza               = 'America/Fortaleza';
    case America_Glace_Bay               = 'America/Glace_Bay';
    case America_Goose_Bay               = 'America/Goose_Bay';
    case America_Grand_Turk              = 'America/Grand_Turk';
    case America_Grenada                 = 'America/Grenada';
    case America_Guadeloupe              = 'America/Guadeloupe';
    case America_Guatemala               = 'America/Guatemala';
    case America_Guayaquil               = 'America/Guayaquil';
    case America_Guyana                  = 'America/Guyana';
    case America_Halifax                 = 'America/Halifax';
    case America_Havana                  = 'America/Havana';
    case America_Hermosillo              = 'America/Hermosillo';
    case America_Indiana_Indianapolis    = 'America/Indiana/Indianapolis';
    case America_Indiana_Knox            = 'America/Indiana/Knox';
    case America_Indiana_Marengo         = 'America/Indiana/Marengo';
    case America_Indiana_Petersburg      = 'America/Indiana/Petersburg';
    case America_Indiana_Tell_City       = 'America/Indiana/Tell_City';
    case America_Indiana_Vevay           = 'America/Indiana/Vevay';
    case America_Indiana_Vincennes       = 'America/Indiana/Vincennes';
    case America_Indiana_Winamac         = 'America/Indiana/Winamac';
    case America_Inuvik                  = 'America/Inuvik';
    case America_Iqaluit                 = 'America/Iqaluit';
    case America_Jamaica                 = 'America/Jamaica';
    case America_Juneau                  = 'America/Juneau';
    case America_Kentucky_Louisville     = 'America/Kentucky/Louisville';
    case America_Kentucky_Monticello     = 'America/Kentucky/Monticello';
    case America_Kralendijk              = 'America/Kralendijk';
    case America_La_Paz                  = 'America/La_Paz';
    case America_Lima                    = 'America/Lima';
    case America_Los_Angeles             = 'America/Los_Angeles';
    case America_Lower_Princes           = 'America/Lower_Princes';
    case America_Maceio                  = 'America/Maceio';
    case America_Managua                 = 'America/Managua';
    case America_Manaus                  = 'America/Manaus';
    case America_Marigot                 = 'America/Marigot';
    case America_Martinique              = 'America/Martinique';
    case America_Matamoros               = 'America/Matamoros';
    case America_Mazatlan                = 'America/Mazatlan';
    case America_Menominee               = 'America/Menominee';
    case America_Merida                  = 'America/Merida';
    case America_Metlakatla              = 'America/Metlakatla';
    case America_Mexico_City             = 'America/Mexico_City';
    case America_Miquelon                = 'America/Miquelon';
    case America_Moncton                 = 'America/Moncton';
    case America_Monterrey               = 'America/Monterrey';
    case America_Montevideo              = 'America/Montevideo';
    case America_Montserrat              = 'America/Montserrat';
    case America_Nassau                  = 'America/Nassau';
    case America_New_York                = 'America/New_York';
    case America_Nome                    = 'America/Nome';
    case America_Noronha                 = 'America/Noronha';
    case America_North_Dakota_Beulah     = 'America/North_Dakota/Beulah';
    case America_North_Dakota_Center     = 'America/North_Dakota/Center';
    case America_North_Dakota_New_Salem  = 'America/North_Dakota/New_Salem';
    case America_Nuuk                    = 'America/Nuuk';
    case America_Ojinaga                 = 'America/Ojinaga';
    case America_Panama                  = 'America/Panama';
    case America_Paramaribo              = 'America/Paramaribo';
    case America_Phoenix                 = 'America/Phoenix';
    case America_PortAuPrince          = 'America/Port-au-Prince';
    case America_Port_of_Spain           = 'America/Port_of_Spain';
    case America_Porto_Velho             = 'America/Porto_Velho';
    case America_Puerto_Rico             = 'America/Puerto_Rico';
    case America_Punta_Arenas            = 'America/Punta_Arenas';
    case America_Rankin_Inlet            = 'America/Rankin_Inlet';
    case America_Recife                  = 'America/Recife';
    case America_Regina                  = 'America/Regina';
    case America_Resolute                = 'America/Resolute';
    case America_Rio_Branco              = 'America/Rio_Branco';
    case America_Santarem                = 'America/Santarem';
    case America_Santiago                = 'America/Santiago';
    case America_Santo_Domingo           = 'America/Santo_Domingo';
    case America_Sao_Paulo               = 'America/Sao_Paulo';
    case America_Scoresbysund            = 'America/Scoresbysund';
    case America_Sitka                   = 'America/Sitka';
    case America_St_Barthelemy           = 'America/St_Barthelemy';
    case America_St_Johns                = 'America/St_Johns';
    case America_St_Kitts                = 'America/St_Kitts';
    case America_St_Lucia                = 'America/St_Lucia';
    case America_St_Thomas               = 'America/St_Thomas';
    case America_St_Vincent              = 'America/St_Vincent';
    case America_Swift_Current           = 'America/Swift_Current';
    case America_Tegucigalpa             = 'America/Tegucigalpa';
    case America_Thule                   = 'America/Thule';
    case America_Tijuana                 = 'America/Tijuana';
    case America_Toronto                 = 'America/Toronto';
    case America_Tortola                 = 'America/Tortola';
    case America_Vancouver               = 'America/Vancouver';
    case America_Whitehorse              = 'America/Whitehorse';
    case America_Winnipeg                = 'America/Winnipeg';
    case America_Yakutat                 = 'America/Yakutat';
    case Antarctica_Casey                = 'Antarctica/Casey';
    case Antarctica_Davis                = 'Antarctica/Davis';
    case Antarctica_DumontDUrville       = 'Antarctica/DumontDUrville';
    case Antarctica_Macquarie            = 'Antarctica/Macquarie';
    case Antarctica_Mawson               = 'Antarctica/Mawson';
    case Antarctica_McMurdo              = 'Antarctica/McMurdo';
    case Antarctica_Palmer               = 'Antarctica/Palmer';
    case Antarctica_Rothera              = 'Antarctica/Rothera';
    case Antarctica_Syowa                = 'Antarctica/Syowa';
    case Antarctica_Troll                = 'Antarctica/Troll';
    case Antarctica_Vostok               = 'Antarctica/Vostok';
    case Arctic_Longyearbyen             = 'Arctic/Longyearbyen';
    case Asia_Aden                       = 'Asia/Aden';
    case Asia_Almaty                     = 'Asia/Almaty';
    case Asia_Amman                      = 'Asia/Amman';
    case Asia_Anadyr                     = 'Asia/Anadyr';
    case Asia_Aqtau                      = 'Asia/Aqtau';
    case Asia_Aqtobe                     = 'Asia/Aqtobe';
    case Asia_Ashgabat                   = 'Asia/Ashgabat';
    case Asia_Atyrau                     = 'Asia/Atyrau';
    case Asia_Baghdad                    = 'Asia/Baghdad';
    case Asia_Bahrain                    = 'Asia/Bahrain';
    case Asia_Baku                       = 'Asia/Baku';
    case Asia_Bangkok                    = 'Asia/Bangkok';
    case Asia_Barnaul                    = 'Asia/Barnaul';
    case Asia_Beirut                     = 'Asia/Beirut';
    case Asia_Bishkek                    = 'Asia/Bishkek';
    case Asia_Brunei                     = 'Asia/Brunei';
    case Asia_Chita                      = 'Asia/Chita';
    case Asia_Choibalsan                 = 'Asia/Choibalsan';
    case Asia_Colombo                    = 'Asia/Colombo';
    case Asia_Damascus                   = 'Asia/Damascus';
    case Asia_Dhaka                      = 'Asia/Dhaka';
    case Asia_Dili                       = 'Asia/Dili';
    case Asia_Dubai                      = 'Asia/Dubai';
    case Asia_Dushanbe                   = 'Asia/Dushanbe';
    case Asia_Famagusta                  = 'Asia/Famagusta';
    case Asia_Gaza                       = 'Asia/Gaza';
    case Asia_Hebron                     = 'Asia/Hebron';
    case Asia_Ho_Chi_Minh                = 'Asia/Ho_Chi_Minh';
    case Asia_Hong_Kong                  = 'Asia/Hong_Kong';
    case Asia_Hovd                       = 'Asia/Hovd';
    case Asia_Irkutsk                    = 'Asia/Irkutsk';
    case Asia_Jakarta                    = 'Asia/Jakarta';
    case Asia_Jayapura                   = 'Asia/Jayapura';
    case Asia_Jerusalem                  = 'Asia/Jerusalem';
    case Asia_Kabul                      = 'Asia/Kabul';
    case Asia_Kamchatka                  = 'Asia/Kamchatka';
    case Asia_Karachi                    = 'Asia/Karachi';
    case Asia_Kathmandu                  = 'Asia/Kathmandu';
    case Asia_Khandyga                   = 'Asia/Khandyga';
    case Asia_Kolkata                    = 'Asia/Kolkata';
    case Asia_Krasnoyarsk                = 'Asia/Krasnoyarsk';
    case Asia_Kuala_Lumpur               = 'Asia/Kuala_Lumpur';
    case Asia_Kuching                    = 'Asia/Kuching';
    case Asia_Kuwait                     = 'Asia/Kuwait';
    case Asia_Macau                      = 'Asia/Macau';
    case Asia_Magadan                    = 'Asia/Magadan';
    case Asia_Makassar                   = 'Asia/Makassar';
    case Asia_Manila                     = 'Asia/Manila';
    case Asia_Muscat                     = 'Asia/Muscat';
    case Asia_Nicosia                    = 'Asia/Nicosia';
    case Asia_Novokuznetsk               = 'Asia/Novokuznetsk';
    case Asia_Novosibirsk                = 'Asia/Novosibirsk';
    case Asia_Omsk                       = 'Asia/Omsk';
    case Asia_Oral                       = 'Asia/Oral';
    case Asia_Phnom_Penh                 = 'Asia/Phnom_Penh';
    case Asia_Pontianak                  = 'Asia/Pontianak';
    case Asia_Pyongyang                  = 'Asia/Pyongyang';
    case Asia_Qatar                      = 'Asia/Qatar';
    case Asia_Qostanay                   = 'Asia/Qostanay';
    case Asia_Qyzylorda                  = 'Asia/Qyzylorda';
    case Asia_Riyadh                     = 'Asia/Riyadh';
    case Asia_Sakhalin                   = 'Asia/Sakhalin';
    case Asia_Samarkand                  = 'Asia/Samarkand';
    case Asia_Seoul                      = 'Asia/Seoul';
    case Asia_Shanghai                   = 'Asia/Shanghai';
    case Asia_Singapore                  = 'Asia/Singapore';
    case Asia_Srednekolymsk              = 'Asia/Srednekolymsk';
    case Asia_Taipei                     = 'Asia/Taipei';
    case Asia_Tashkent                   = 'Asia/Tashkent';
    case Asia_Tbilisi                    = 'Asia/Tbilisi';
    case Asia_Tehran                     = 'Asia/Tehran';
    case Asia_Thimphu                    = 'Asia/Thimphu';
    case Asia_Tokyo                      = 'Asia/Tokyo';
    case Asia_Tomsk                      = 'Asia/Tomsk';
    case Asia_Ulaanbaatar                = 'Asia/Ulaanbaatar';
    case Asia_Urumqi                     = 'Asia/Urumqi';
    case Asia_UstNera                    = 'Asia/Ust-Nera';
    case Asia_Vientiane                  = 'Asia/Vientiane';
    case Asia_Vladivostok                = 'Asia/Vladivostok';
    case Asia_Yakutsk                    = 'Asia/Yakutsk';
    case Asia_Yangon                     = 'Asia/Yangon';
    case Asia_Yekaterinburg              = 'Asia/Yekaterinburg';
    case Asia_Yerevan                    = 'Asia/Yerevan';
    case Atlantic_Azores                 = 'Atlantic/Azores';
    case Atlantic_Bermuda                = 'Atlantic/Bermuda';
    case Atlantic_Canary                 = 'Atlantic/Canary';
    case Atlantic_Cape_Verde             = 'Atlantic/Cape_Verde';
    case Atlantic_Faroe                  = 'Atlantic/Faroe';
    case Atlantic_Madeira                = 'Atlantic/Madeira';
    case Atlantic_Reykjavik              = 'Atlantic/Reykjavik';
    case Atlantic_South_Georgia          = 'Atlantic/South_Georgia';
    case Atlantic_St_Helena              = 'Atlantic/St_Helena';
    case Atlantic_Stanley                = 'Atlantic/Stanley';
    case Australia_Adelaide              = 'Australia/Adelaide';
    case Australia_Brisbane              = 'Australia/Brisbane';
    case Australia_Broken_Hill           = 'Australia/Broken_Hill';
    case Australia_Darwin                = 'Australia/Darwin';
    case Australia_Eucla                 = 'Australia/Eucla';
    case Australia_Hobart                = 'Australia/Hobart';
    case Australia_Lindeman              = 'Australia/Lindeman';
    case Australia_Lord_Howe             = 'Australia/Lord_Howe';
    case Australia_Melbourne             = 'Australia/Melbourne';
    case Australia_Perth                 = 'Australia/Perth';
    case Australia_Sydney                = 'Australia/Sydney';
    case Europe_Amsterdam                = 'Europe/Amsterdam';
    case Europe_Andorra                  = 'Europe/Andorra';
    case Europe_Astrakhan                = 'Europe/Astrakhan';
    case Europe_Athens                   = 'Europe/Athens';
    case Europe_Belgrade                 = 'Europe/Belgrade';
    case Europe_Berlin                   = 'Europe/Berlin';
    case Europe_Bratislava               = 'Europe/Bratislava';
    case Europe_Brussels                 = 'Europe/Brussels';
    case Europe_Bucharest                = 'Europe/Bucharest';
    case Europe_Budapest                 = 'Europe/Budapest';
    case Europe_Busingen                 = 'Europe/Busingen';
    case Europe_Chisinau                 = 'Europe/Chisinau';
    case Europe_Copenhagen               = 'Europe/Copenhagen';
    case Europe_Dublin                   = 'Europe/Dublin';
    case Europe_Gibraltar                = 'Europe/Gibraltar';
    case Europe_Guernsey                 = 'Europe/Guernsey';
    case Europe_Helsinki                 = 'Europe/Helsinki';
    case Europe_Isle_of_Man              = 'Europe/Isle_of_Man';
    case Europe_Istanbul                 = 'Europe/Istanbul';
    case Europe_Jersey                   = 'Europe/Jersey';
    case Europe_Kaliningrad              = 'Europe/Kaliningrad';
    case Europe_Kirov                    = 'Europe/Kirov';
    case Europe_Kyiv                     = 'Europe/Kyiv';
    case Europe_Lisbon                   = 'Europe/Lisbon';
    case Europe_Ljubljana                = 'Europe/Ljubljana';
    case Europe_London                   = 'Europe/London';
    case Europe_Luxembourg               = 'Europe/Luxembourg';
    case Europe_Madrid                   = 'Europe/Madrid';
    case Europe_Malta                    = 'Europe/Malta';
    case Europe_Mariehamn                = 'Europe/Mariehamn';
    case Europe_Minsk                    = 'Europe/Minsk';
    case Europe_Monaco                   = 'Europe/Monaco';
    case Europe_Moscow                   = 'Europe/Moscow';
    case Europe_Oslo                     = 'Europe/Oslo';
    case Europe_Paris                    = 'Europe/Paris';
    case Europe_Podgorica                = 'Europe/Podgorica';
    case Europe_Prague                   = 'Europe/Prague';
    case Europe_Riga                     = 'Europe/Riga';
    case Europe_Rome                     = 'Europe/Rome';
    case Europe_Samara                   = 'Europe/Samara';
    case Europe_San_Marino               = 'Europe/San_Marino';
    case Europe_Sarajevo                 = 'Europe/Sarajevo';
    case Europe_Saratov                  = 'Europe/Saratov';
    case Europe_Simferopol               = 'Europe/Simferopol';
    case Europe_Skopje                   = 'Europe/Skopje';
    case Europe_Sofia                    = 'Europe/Sofia';
    case Europe_Stockholm                = 'Europe/Stockholm';
    case Europe_Tallinn                  = 'Europe/Tallinn';
    case Europe_Tirane                   = 'Europe/Tirane';
    case Europe_Ulyanovsk                = 'Europe/Ulyanovsk';
    case Europe_Vaduz                    = 'Europe/Vaduz';
    case Europe_Vatican                  = 'Europe/Vatican';
    case Europe_Vienna                   = 'Europe/Vienna';
    case Europe_Vilnius                  = 'Europe/Vilnius';
    case Europe_Volgograd                = 'Europe/Volgograd';
    case Europe_Warsaw                   = 'Europe/Warsaw';
    case Europe_Zagreb                   = 'Europe/Zagreb';
    case Europe_Zurich                   = 'Europe/Zurich';
    case Indian_Antananarivo             = 'Indian/Antananarivo';
    case Indian_Chagos                   = 'Indian/Chagos';
    case Indian_Christmas                = 'Indian/Christmas';
    case Indian_Cocos                    = 'Indian/Cocos';
    case Indian_Comoro                   = 'Indian/Comoro';
    case Indian_Kerguelen                = 'Indian/Kerguelen';
    case Indian_Mahe                     = 'Indian/Mahe';
    case Indian_Maldives                 = 'Indian/Maldives';
    case Indian_Mauritius                = 'Indian/Mauritius';
    case Indian_Mayotte                  = 'Indian/Mayotte';
    case Indian_Reunion                  = 'Indian/Reunion';
    case Pacific_Apia                    = 'Pacific/Apia';
    case Pacific_Auckland                = 'Pacific/Auckland';
    case Pacific_Bougainville            = 'Pacific/Bougainville';
    case Pacific_Chatham                 = 'Pacific/Chatham';
    case Pacific_Chuuk                   = 'Pacific/Chuuk';
    case Pacific_Easter                  = 'Pacific/Easter';
    case Pacific_Efate                   = 'Pacific/Efate';
    case Pacific_Fakaofo                 = 'Pacific/Fakaofo';
    case Pacific_Fiji                    = 'Pacific/Fiji';
    case Pacific_Funafuti                = 'Pacific/Funafuti';
    case Pacific_Galapagos               = 'Pacific/Galapagos';
    case Pacific_Gambier                 = 'Pacific/Gambier';
    case Pacific_Guadalcanal             = 'Pacific/Guadalcanal';
    case Pacific_Guam                    = 'Pacific/Guam';
    case Pacific_Honolulu                = 'Pacific/Honolulu';
    case Pacific_Kanton                  = 'Pacific/Kanton';
    case Pacific_Kiritimati              = 'Pacific/Kiritimati';
    case Pacific_Kosrae                  = 'Pacific/Kosrae';
    case Pacific_Kwajalein               = 'Pacific/Kwajalein';
    case Pacific_Majuro                  = 'Pacific/Majuro';
    case Pacific_Marquesas               = 'Pacific/Marquesas';
    case Pacific_Midway                  = 'Pacific/Midway';
    case Pacific_Nauru                   = 'Pacific/Nauru';
    case Pacific_Niue                    = 'Pacific/Niue';
    case Pacific_Norfolk                 = 'Pacific/Norfolk';
    case Pacific_Noumea                  = 'Pacific/Noumea';
    case Pacific_Pago_Pago               = 'Pacific/Pago_Pago';
    case Pacific_Palau                   = 'Pacific/Palau';
    case Pacific_Pitcairn                = 'Pacific/Pitcairn';
    case Pacific_Pohnpei                 = 'Pacific/Pohnpei';
    case Pacific_Port_Moresby            = 'Pacific/Port_Moresby';
    case Pacific_Rarotonga               = 'Pacific/Rarotonga';
    case Pacific_Saipan                  = 'Pacific/Saipan';
    case Pacific_Tahiti                  = 'Pacific/Tahiti';
    case Pacific_Tarawa                  = 'Pacific/Tarawa';
    case Pacific_Tongatapu               = 'Pacific/Tongatapu';
    case Pacific_Wake                    = 'Pacific/Wake';
    case Pacific_Wallis                  = 'Pacific/Wallis';
    case UTC                             = 'UTC';


    /**
     * Create a native DateTimeZone object from this enum.
     * @return \DateTimeZone
     * @throws \Exception
     */
    public function toDateTimeZone() : \DateTimeZone
    {
        return new \DateTimeZone($this->value);
    }
}