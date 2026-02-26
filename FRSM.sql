

CREATE TABLE `api_incidents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `external_id` int(11) NOT NULL COMMENT 'ID from external API',
  `user_id` int(11) DEFAULT NULL,
  `alert_type` varchar(50) DEFAULT NULL,
  `emergency_type` varchar(50) NOT NULL,
  `assistance_needed` varchar(50) DEFAULT NULL,
  `severity` enum('low','medium','high','critical') DEFAULT 'medium',
  `title` varchar(255) DEFAULT NULL,
  `caller_name` varchar(100) NOT NULL,
  `caller_phone` varchar(20) NOT NULL,
  `location` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` enum('pending','processing','responded','closed') DEFAULT 'pending',
  `affected_barangays` text DEFAULT NULL,
  `issued_by` varchar(100) DEFAULT NULL,
  `valid_until` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL COMMENT 'From API',
  `responded_at` datetime DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `sync_status` enum('synced','pending','failed') DEFAULT 'synced',
  `last_sync_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at_local` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_fire_rescue_related` tinyint(1) DEFAULT 0,
  `rescue_category` enum('building_collapse','vehicle_accident','height_rescue','water_rescue','other_rescue') DEFAULT NULL,
  `dispatch_status` enum('for_dispatch','processing','responded','closed') DEFAULT 'for_dispatch',
  `dispatch_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_external_id` (`external_id`),
  KEY `idx_status` (`status`),
  KEY `idx_emergency_type` (`emergency_type`),
  KEY `idx_severity` (`severity`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_sync_status` (`sync_status`),
  KEY `idx_fire_rescue` (`is_fire_rescue_related`),
  KEY `idx_dispatch_status` (`dispatch_status`)
) ENGINE=InnoDB AUTO_INCREMENT=84 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `api_incidents` */

insert  into `api_incidents`(`id`,`external_id`,`user_id`,`alert_type`,`emergency_type`,`assistance_needed`,`severity`,`title`,`caller_name`,`caller_phone`,`location`,`description`,`status`,`affected_barangays`,`issued_by`,`valid_until`,`created_at`,`responded_at`,`responded_by`,`notes`,`sync_status`,`last_sync_at`,`created_at_local`,`updated_at`,`is_fire_rescue_related`,`rescue_category`,`dispatch_status`,`dispatch_id`) values 
(1,3,NULL,'Typhoon','fire','fire','medium','Fire Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Taga jan lang po','May sunog po sami dito sa Commonwealth','pending','Holy Spirit','Anonymous',NULL,'2026-01-11 15:05:41',NULL,NULL,NULL,'synced','2026-01-11 01:48:46','2026-01-11 01:48:46','2026-01-12 20:27:12',1,NULL,'for_dispatch',NULL),
(2,1,NULL,'Typhoon','fire','fire','high','Fire Emergency Report','John Doe','09171234567','123 Main St, Holy Spirit, QC','Test emergency','responded','Holy Spirit','Test User',NULL,'2026-01-11 14:57:49','2026-01-25 15:51:11',9,NULL,'synced','2026-01-11 01:48:46','2026-01-11 01:48:46','2026-01-25 07:51:11',1,NULL,'responded',19),
(3,2,NULL,'Typhoon','medical','medical','critical','Medical Emergency Report','Jane Smith','09187654321','456 Elm St, Batasan Hills, QC','Medical emergency','pending','Batasan Hills','Test User',NULL,'2026-01-11 14:57:49',NULL,NULL,NULL,'synced','2026-01-11 01:48:46','2026-01-11 01:48:46','2026-01-12 20:28:49',0,NULL,'for_dispatch',NULL),
(4,15,NULL,'Typhoon','fire','fire','medium','Other Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Testing','Need Rescue collapsing building Barangay Holy Spirit','pending','Holy Spirit','Anonymous',NULL,'2026-01-11 17:42:02',NULL,NULL,NULL,'synced','2026-01-11 01:48:46','2026-01-11 01:48:46','2026-01-14 02:17:27',1,NULL,'for_dispatch',7),
(5,14,NULL,'','fire','medical','high','Severe Injury Assistance Needed','Maria Santos','09171230011','Block 12, Brgy. Bagong Silangan, QC','Person sustained a severe cut on the leg after falling','closed','Bagong Silangan','Neighbor',NULL,'2026-01-11 15:30:00','2026-01-23 19:50:04',9,NULL,'synced','2026-01-11 01:48:46','2026-01-11 01:48:46','2026-01-23 14:20:34',1,NULL,'closed',17),
(6,13,NULL,'Earthquake','','monitoring','low','Aftershock Felt','Joshua Tan','09171230010','Block 10, Brgy. Batasan, QC','Light aftershock felt, no visible damage','pending','','Resident',NULL,'2026-01-11 15:05:00',NULL,NULL,NULL,'synced','2026-01-11 01:48:46','2026-01-11 01:48:46','2026-01-11 01:48:46',0,NULL,'for_dispatch',NULL),
(7,12,NULL,'Typhoon','','utility','','Power Outage Report','Cynthia Ramos','09171230009','Sitio Masagana, Brgy. Payatas, QC','No electricity since early morning','pending','Payatas','Resident',NULL,'2026-01-11 14:40:12',NULL,NULL,NULL,'synced','2026-01-11 01:48:46','2026-01-11 01:48:46','2026-01-11 01:48:46',0,NULL,'for_dispatch',NULL),
(8,9,NULL,'Flood','','monitoring','low','Minor Flooding Observed','Leo Navarro','09171230006','Zone 1, Brgy. Commonwealth, QC','Ankle-deep water on side streets','pending','Commonwealth','Traffic Aide',NULL,'2026-01-11 13:00:00',NULL,NULL,NULL,'synced','2026-01-11 01:48:46','2026-01-11 01:48:46','2026-01-11 01:48:46',0,NULL,'for_dispatch',NULL),
(9,7,NULL,'Earthquake','','inspection','','Post-Earthquake Inspection Request','Dennis Uy','09171230004','Street 5, Brgy. Holy Spirit, QC','Visible cracks on residential wall','pending','Holy Spirit','Homeowner',NULL,'2026-01-11 11:40:00',NULL,NULL,NULL,'synced','2026-01-11 01:48:46','2026-01-11 01:48:46','2026-01-11 01:48:46',0,NULL,'for_dispatch',NULL),
(10,6,NULL,'','','medical','high','Medical Assistance Needed','Ramon Dela Cruz','09171230003','Block 8, Brgy. Bagong Silangan, QC','Patient experiencing severe chest pain','pending','Bagong Silangan','Family Member',NULL,'2026-01-11 11:05:22',NULL,NULL,NULL,'synced','2026-01-11 01:48:46','2026-01-11 01:48:46','2026-01-11 01:48:46',0,NULL,'for_dispatch',NULL),
(11,4,NULL,'Flood','','evacuation','high','Flood Alert in Commonwealth','Alvin Reyes','09171230001','Zone 3, Brgy. Commonwealth, QC','Flood water rising rapidly after heavy rainfall','pending','Commonwealth','Barangay Officer',NULL,'2026-01-11 10:15:00',NULL,NULL,NULL,'synced','2026-01-11 01:48:46','2026-01-11 01:48:46','2026-01-11 01:48:46',0,NULL,'for_dispatch',NULL),
(14,17,NULL,'Typhoon','fire','fire','medium','Fire Emergency Report','Katrina Decepida','09383741627','8-4C HACIENDA BALAI, BRGY. KALIGAYAHAN, QUEZON CITY','bzjakamxhwiwjsjsjsidnnxbxbxnsiwks','pending','Holy Spirit','Anonymous',NULL,'2026-01-12 04:11:44',NULL,NULL,NULL,'synced','2026-01-11 23:32:37','2026-01-11 23:32:37','2026-01-12 20:27:12',1,NULL,'for_dispatch',NULL),
(17,18,NULL,'Typhoon','fire','fire','medium','Fire Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Brgy. Holy Spirit','Nasusunog po yung bangketa dito banda sa talipapa sa Holy Drive','pending','Holy Spirit','Anonymous',NULL,'2026-01-12 16:15:18',NULL,NULL,NULL,'synced','2026-01-12 01:30:25','2026-01-12 01:30:25','2026-01-12 20:27:12',1,NULL,'for_dispatch',NULL),
(18,19,NULL,'Typhoon','fire','fire','medium','Fire Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Brgy. Holy Spirit','Nasusunog po yung bangketa dito banda sa talipapa sa Holy Drive','pending','Holy Spirit','Anonymous',NULL,'2026-01-12 17:36:19',NULL,NULL,NULL,'synced','2026-01-12 01:36:21','2026-01-12 01:36:21','2026-01-12 20:27:12',1,NULL,'for_dispatch',NULL),
(19,20,NULL,'Typhoon','fire','fire','medium','Fire Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Brgy. Commonwealth','Nasusunog po yung building malapit sa Puregold','pending','Commonwealth','Anonymous',NULL,'2026-01-12 17:37:01',NULL,NULL,NULL,'synced','2026-01-12 01:37:16','2026-01-12 01:37:16','2026-01-13 00:49:47',1,NULL,'for_dispatch',NULL),
(20,21,NULL,'Typhoon','fire','fire','medium','Fire Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Bagong Silangan','Nasusunog po dito banda sa school malapit sa baranggay hall','pending','Bagong Silangan','Anonymous',NULL,'2026-01-12 18:24:44',NULL,NULL,NULL,'synced','2026-01-12 02:24:52','2026-01-12 02:24:52','2026-01-13 00:49:47',1,NULL,'for_dispatch',NULL),
(22,23,NULL,'Security','rescue','rescue','medium','Rescue Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Bagong Silangan','Nalaglag po ng 3rd floor yung kaibigan namin please send po ng help dito sa blk 22 lt 21','processing','Bagong Silangan','Anonymous',NULL,'2026-01-14 17:52:55',NULL,NULL,NULL,'synced','2026-01-14 01:53:10','2026-01-14 01:53:10','2026-01-14 01:53:10',0,NULL,'for_dispatch',NULL),
(23,22,NULL,'Typhoon','other','rescue','medium','Rescue Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Bagong Silangan','Nahulog po sa 3rd floor kasama namin nabalian po','pending','Bagong Silangan','Anonymous',NULL,'2026-01-14 17:28:22',NULL,NULL,NULL,'synced','2026-01-14 01:55:39','2026-01-14 01:55:39','2026-01-14 01:55:39',0,NULL,'for_dispatch',NULL),
(24,24,NULL,'Security','rescue','rescue','medium','Rescue Emergency Report','yukki','09984319585','mary rose strore sanchez street','na ipit po yung ulo ng kaibigan namin sa bintana','processing','Holy Spirit','Anonymous',NULL,'2026-01-14 18:13:17',NULL,NULL,NULL,'synced','2026-01-14 02:13:29','2026-01-14 02:13:29','2026-01-14 02:13:29',0,NULL,'for_dispatch',NULL),
(25,25,NULL,'Security','rescue','rescue','medium','Rescue Emergency Report','yukki','09984319585','mary rose strore sanchez street','testtasdasdadasdddddddddddddddddddddd','processing','Holy Spirit','Anonymous',NULL,'2026-01-14 18:31:51',NULL,NULL,NULL,'synced','2026-01-14 02:31:51','2026-01-14 02:31:51','2026-01-14 02:31:51',0,NULL,'for_dispatch',NULL),
(26,26,NULL,'Security','rescue','rescue','medium','Rescue Emergency Report','Marcus Pelaez','09263969662','Barangay Holy Spirit, Quezon City','Yung tropa ko nasa bangin kelangan namen ng tulong dito banda sa payatas rd','processing','Holy Spirit','User 9',NULL,'2026-01-14 19:14:59',NULL,NULL,NULL,'synced','2026-01-14 03:15:07','2026-01-14 03:15:07','2026-01-20 22:56:50',0,NULL,'for_dispatch',16),
(27,16,NULL,'Typhoon','other','fire','medium','Other Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Testing','Need Rescue collapsing building Barangay Holy Spirit','pending','Holy Spirit','Anonymous',NULL,'2026-01-11 17:50:49',NULL,NULL,NULL,'synced','2026-01-14 05:00:53','2026-01-14 05:00:53','2026-01-14 05:00:53',1,'building_collapse','for_dispatch',NULL),
(28,30,NULL,'Fire','fire','fire','medium','Fire Emergency Report','bing','09358322191','Quezon city','sunoooggg 4t83tieguegeggregg3egiegegeg','pending','Holy Spirit','Anonymous',NULL,'2026-01-15 08:41:51',NULL,NULL,NULL,'synced','2026-01-15 01:30:42','2026-01-15 01:30:42','2026-01-15 01:30:42',1,NULL,'for_dispatch',NULL),
(29,29,NULL,'Fire','fire','fire','medium','Fire Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Brgy. Commonwealth','Nasusunog po yung building malapit sa Puregold','pending','Commonwealth','Anonymous',NULL,'2026-01-15 02:26:47',NULL,NULL,NULL,'synced','2026-01-15 01:30:42','2026-01-15 01:30:42','2026-01-15 01:30:42',1,NULL,'for_dispatch',NULL),
(30,27,NULL,'Security','rescue','rescue','medium','Rescue Emergency Report','Marcus Pelaez','09263969662','Barangay Holy Spirit, Quezon City','Yung tropa ko nasa bangin kelangan namen ng tulong dito banda sa payatas rd','pending','Holy Spirit','Anonymous',NULL,'2026-01-14 21:36:37',NULL,NULL,NULL,'synced','2026-01-15 01:30:42','2026-01-15 01:30:42','2026-01-15 01:30:42',0,NULL,'for_dispatch',NULL),
(31,28,NULL,'Other','other','rescue','medium','Rain Emergency Report','Von Dulfo','09458252517','Barangay Holy Spirit, Quezon City','Tulong guys 12345678nadjandk jnakdnakdakjsnaw','pending','Holy Spirit','User 10',NULL,'2026-01-14 22:36:15',NULL,NULL,NULL,'synced','2026-01-15 01:30:42','2026-01-15 01:30:42','2026-01-15 01:30:42',0,NULL,'for_dispatch',NULL),
(32,34,NULL,'fire','fire','fire','medium','Fire Emergency Report','Maria Santos','09569733114','Bagong Silangan','rfeegasdfghyjukloikjtgewddfghmjhngfdsfgnhn','responded','Bagong Silangan','Anonymous',NULL,'2026-01-16 13:19:43','2026-01-31 14:46:12',9,NULL,'synced','2026-01-20 17:59:15','2026-01-20 17:59:15','2026-01-31 06:46:12',1,NULL,'responded',18),
(33,32,NULL,'fire','fire','fire','medium','Fire Emergency Report','Danielle Marsh','09984319585','asddddddddddd','sadddddddddyiguiouasydgouasyhgduyasgduahosybdas','pending','Holy Spirit','Anonymous',NULL,'2026-01-16 12:29:52',NULL,NULL,NULL,'synced','2026-01-20 17:59:15','2026-01-20 17:59:15','2026-01-20 17:59:15',1,NULL,'for_dispatch',NULL),
(34,33,NULL,'security','rescue','police','medium','Rescue Emergency Report','Danielle Marsh','09984319585','asddddddddddd','1111111111111111111111111111111111111','pending','Holy Spirit','Anonymous',NULL,'2026-01-16 12:31:54',NULL,NULL,NULL,'synced','2026-01-20 17:59:15','2026-01-20 17:59:15','2026-01-20 17:59:15',0,NULL,'for_dispatch',NULL),
(35,31,NULL,'security','rescue','rescue','medium','Rescue Emergency Report','Trisha May Tudillo','09938137366','Barangay Batasan Hills, Quezon City','Nalaglag sa bangin tropa ko boss','pending','Batasan Hills','User 15',NULL,'2026-01-16 03:40:18',NULL,NULL,NULL,'synced','2026-01-20 17:59:15','2026-01-20 17:59:15','2026-01-20 17:59:15',0,NULL,'for_dispatch',NULL),
(36,36,NULL,'fire','fire','fire','medium','Fire Emergency Report','alliana barrete','09984371654','jashdkjahdkjahdkahsdkhakdhaskjdhkjasa','sadtyguhijokajsdoiajdioasjoijasdiojasoidjaodjaoisjdioajdoiajodi','pending','Holy Spirit','Anonymous',NULL,'2026-01-23 11:49:19',NULL,NULL,NULL,'synced','2026-01-22 19:52:17','2026-01-22 19:52:17','2026-01-22 19:52:17',1,NULL,'for_dispatch',NULL),
(37,35,NULL,'fire','fire','fire','medium','Fire Emergency Report','akilron','09984319585','adasdasdasdadsadasdad','testtttttttttttttadiuyasiudyadhiuasdhasd','pending','Holy Spirit','Anonymous',NULL,'2026-01-23 11:33:18',NULL,NULL,NULL,'synced','2026-01-22 19:52:17','2026-01-22 19:52:17','2026-01-22 19:52:17',1,NULL,'for_dispatch',NULL),
(38,37,NULL,'fire','fire','fire','medium','Fire Emergency Report','andy doza','09984571655','57 sanchez street','sunogggggggggggggggggggggggggggggggggggg','pending','Holy Spirit','Anonymous',NULL,'2026-01-23 12:35:21',NULL,NULL,NULL,'synced','2026-01-22 20:35:28','2026-01-22 20:35:28','2026-01-22 20:35:28',1,NULL,'for_dispatch',NULL),
(39,38,NULL,'fire','fire','fire','medium','Fire Emergency Report','dadang smiltzer','09981231231','taga jan lang po','qwertyuiolkjhgfazxcvbnm,kiytfcvbnjytresx','pending','Holy Spirit','Anonymous',NULL,'2026-01-24 04:46:29',NULL,NULL,NULL,'synced','2026-01-23 20:46:37','2026-01-23 20:46:37','2026-01-23 20:46:37',1,NULL,'for_dispatch',NULL),
(40,39,NULL,'fire','fire','fire','medium','Fire Emergency Report','christine','09536841503','03 Ivory Street, Payatas A. Quezon City','may sunog dito sa payatas A.','pending','Payatas','Anonymous',NULL,'2026-01-24 04:51:22',NULL,NULL,NULL,'synced','2026-01-23 20:57:32','2026-01-23 20:57:32','2026-01-23 20:57:32',1,NULL,'for_dispatch',NULL),
(41,40,NULL,'fire','fire','fire','medium','Fire Emergency Report','asdasdasd','09984319585','asdadasdasdasdasd','asdasdasdasdasdasdasdasdasdasdasdasd','pending','Holy Spirit','Anonymous',NULL,'2026-01-24 14:05:53',NULL,NULL,NULL,'synced','2026-01-24 15:12:54','2026-01-24 15:12:54','2026-01-24 15:12:54',1,NULL,'for_dispatch',NULL),
(42,41,NULL,'fire','fire','fire','medium','Fire Emergency Report','asdasdasd','09984319585','asdasdasdasd','asdddddddddddddddddddddd','pending','Holy Spirit','Anonymous',NULL,'2026-01-24 15:13:13',NULL,NULL,NULL,'synced','2026-01-24 15:13:24','2026-01-24 15:13:24','2026-01-24 15:13:24',1,NULL,'for_dispatch',NULL),
(43,42,NULL,'security','security','shelter','medium','Security Emergency Report','Josh','09393912434','Barangay Holy Spirit, Quezon City','Tulong guys my sunod na dito sa bahay malapit dito sa gilid','pending','Holy Spirit','Anonymous',NULL,'2026-01-25 16:23:35',NULL,NULL,NULL,'synced','2026-01-26 23:20:50','2026-01-26 23:20:50','2026-01-26 23:20:50',0,NULL,'for_dispatch',NULL),
(44,46,NULL,'fire','fire','fire','medium','Fire Emergency Report','stephen kyle viray','09984319585','baliwaffffffffffffffffffffffffffffffffffffffffff','000000000000000000000000000000000000000000000','pending','Holy Spirit','Anonymous',NULL,'2026-01-30 20:08:11',NULL,NULL,NULL,'synced','2026-02-01 12:11:08','2026-02-01 12:11:08','2026-02-01 12:11:08',1,NULL,'for_dispatch',NULL),
(45,45,NULL,'fire','fire','fire','medium','Fire Emergency Report','kyutie','09984319585','1231231231231','jjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjj','pending','Holy Spirit','Anonymous',NULL,'2026-01-30 20:05:22',NULL,NULL,NULL,'synced','2026-02-01 12:11:08','2026-02-01 12:11:08','2026-02-01 12:11:08',1,NULL,'for_dispatch',NULL),
(46,44,NULL,'fire','fire','fire','medium','Fire Emergency Report','yukki','09984319585','hgcftgctfuvgyuivgyuivguo','jgckhyhjfdrtddrtedhvhfgcyvhgjcrtudcr5es463s34w344654','pending','Holy Spirit','Anonymous',NULL,'2026-01-30 20:03:20',NULL,NULL,NULL,'synced','2026-02-01 12:11:08','2026-02-01 12:11:08','2026-02-01 12:11:08',1,NULL,'for_dispatch',NULL),
(47,43,NULL,'fire','fire','fire','medium','Fire Emergency Report','yukki','09984319585','hgcftgctfuvgyuivgyuivguo','jgckhyhjfdrtddrtedhvhfgcyvhgjcrtudcr5es463s34w344654','pending','Holy Spirit','Anonymous',NULL,'2026-01-30 20:02:12',NULL,NULL,NULL,'synced','2026-02-01 12:11:08','2026-02-01 12:11:08','2026-02-01 12:11:08',1,NULL,'for_dispatch',NULL),
(48,64,NULL,'fire','fire','fire','medium','Fire Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Commonwealth','Nasusunog po dito saamin kuya patulong po','pending','Commonwealth','Anonymous',NULL,'2026-02-02 05:40:29',NULL,NULL,NULL,'synced','2026-02-01 22:34:40','2026-02-01 22:34:40','2026-02-01 22:34:40',1,NULL,'for_dispatch',NULL),
(49,54,NULL,'other','traffic_accident','traffic','medium','Traffic accident Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Commonwealth','Nagkaron po ng bungguan dito samin','pending','Commonwealth','Anonymous',NULL,'2026-02-01 11:35:30',NULL,NULL,NULL,'synced','2026-02-01 12:11:08','2026-02-01 12:11:08','2026-02-01 12:11:08',0,NULL,'for_dispatch',NULL),
(50,53,NULL,'other','traffic_accident','traffic','medium','Traffic accident Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Commonwealth','Nagkaron po ng bungguan dito samin','pending','Commonwealth','Anonymous',NULL,'2026-02-01 11:35:24',NULL,NULL,NULL,'synced','2026-02-01 12:11:08','2026-02-01 12:11:08','2026-02-01 12:11:08',0,NULL,'for_dispatch',NULL),
(51,52,NULL,'other','traffic_accident','traffic','medium','Traffic accident Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Commonwealth','Nagkaron po ng banggaan dito sa may baranggay commonwealth','pending','Commonwealth','Anonymous',NULL,'2026-02-01 11:08:53',NULL,NULL,NULL,'synced','2026-02-01 12:11:08','2026-02-01 12:11:08','2026-02-01 12:11:08',0,NULL,'for_dispatch',NULL),
(52,51,NULL,'other','traffic_accident','traffic','medium','Traffic accident Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Commonwealth','Nagkaron po ng banggaan dito sa may baranggay commonwealth','pending','Commonwealth','Anonymous',NULL,'2026-02-01 11:08:48',NULL,NULL,NULL,'synced','2026-02-01 12:11:08','2026-02-01 12:11:08','2026-02-01 12:11:08',0,NULL,'for_dispatch',NULL),
(53,47,NULL,'flood','flood','supplies','medium','Flood Emergency Report','Rhuary Dela Cruz','09052346153','Bistekville16 Pasacola rd. Brgy. Nagkaisang','Need po ng Help sa BAHA','pending','Holy Spirit','Anonymous',NULL,'2026-01-31 14:37:49',NULL,NULL,NULL,'synced','2026-02-01 22:34:40','2026-02-01 22:34:40','2026-02-01 22:34:40',0,NULL,'for_dispatch',NULL),
(54,49,NULL,'','traffic_accident','traffic','medium','Traffic accident Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Commonwealth','Nagkaron po ng banggaan dito sa may baranggay commonwealth','pending','Commonwealth','Anonymous',NULL,'2026-02-01 10:52:25',NULL,NULL,NULL,'synced','2026-02-01 12:11:08','2026-02-01 12:11:08','2026-02-01 12:11:08',0,NULL,'for_dispatch',NULL),
(55,48,NULL,'','traffic_accident','traffic','medium','Traffic accident Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Commonwealth','Nagkaron po ng banggaan dito sa may baranggay commonwealth','pending','Commonwealth','Anonymous',NULL,'2026-02-01 10:52:07',NULL,NULL,NULL,'synced','2026-02-01 12:11:08','2026-02-01 12:11:08','2026-02-01 12:11:08',0,NULL,'for_dispatch',NULL),
(56,56,NULL,'traffic','vehicle_breakdown','tow','medium','Vehicle breakdown Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Commonwealth','Nasiraan kami ng kotse along Litex','pending','Commonwealth','Anonymous',NULL,'2026-02-01 12:46:23',NULL,NULL,NULL,'synced','2026-02-01 12:46:16','2026-02-01 12:46:16','2026-02-01 12:46:16',0,NULL,'for_dispatch',NULL),
(57,55,NULL,'traffic','vehicle_breakdown','tow','medium','Vehicle breakdown Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Commonwealth','Nasiraan kami ng kotse along Litex','pending','Commonwealth','Anonymous',NULL,'2026-02-01 12:46:11',NULL,NULL,NULL,'synced','2026-02-01 12:46:16','2026-02-01 12:46:16','2026-02-01 12:46:16',0,NULL,'for_dispatch',NULL),
(58,57,NULL,'traffic','traffic_accident','traffic','medium','Traffic accident Emergency Report','Maria Santos','09569733114','Bagong Silangan','SARADOOSARADOOSARADOOSARADOOSARADOO','pending','Bagong Silangan','Anonymous',NULL,'2026-02-01 12:47:08',NULL,NULL,NULL,'synced','2026-02-01 12:47:01','2026-02-01 12:47:01','2026-02-01 12:47:01',0,NULL,'for_dispatch',NULL),
(59,58,NULL,'fire','fire','fire','medium','Fire Emergency Report','11111','09984319585','123111111111111','1233333333333333333333333333333333333333333333333333333333333','pending','Holy Spirit','Anonymous',NULL,'2026-02-01 12:47:10',NULL,NULL,NULL,'synced','2026-02-01 12:47:04','2026-02-01 12:47:04','2026-02-01 12:47:04',1,NULL,'for_dispatch',NULL),
(60,60,NULL,'fire','fire','fire','medium','Fire Emergency Report','asddassadasdads','09984319585','1223123123','asddddddddddddddddddddddddddddddddddddddd','pending','Holy Spirit','Anonymous',NULL,'2026-02-01 13:01:05',NULL,NULL,NULL,'synced','2026-02-01 13:01:13','2026-02-01 13:01:13','2026-02-01 13:01:13',1,NULL,'for_dispatch',NULL),
(61,59,NULL,'traffic','road_hazard','traffic','medium','Road hazard Emergency Report','stephen kyle viray','09984319585','57 sanchez street','pleasee may butas dito sa sanchez street and marami ng na aksidente please make this road okay','pending','Holy Spirit','Anonymous',NULL,'2026-02-01 12:59:56',NULL,NULL,NULL,'synced','2026-02-01 13:01:13','2026-02-01 13:01:13','2026-02-01 13:01:13',0,NULL,'for_dispatch',NULL),
(62,61,NULL,'fire','fire','fire','medium','Fire Emergency Report','asddassadasdads','09984319585','1223123123','asddddddddddddddddddddddddddddddddddddddd','pending','Holy Spirit','Anonymous',NULL,'2026-02-01 13:01:37',NULL,NULL,NULL,'synced','2026-02-01 13:01:34','2026-02-01 13:01:34','2026-02-01 13:01:34',1,NULL,'for_dispatch',NULL),
(63,50,NULL,'other','traffic_accident','traffic','medium','Traffic accident Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Commonwealth','Nagkaron po ng banggaan dito sa may baranggay commonwealth','pending','Commonwealth','Anonymous',NULL,'2026-02-01 11:08:38',NULL,NULL,NULL,'synced','2026-02-01 22:34:40','2026-02-01 22:34:40','2026-02-01 22:34:40',0,NULL,'for_dispatch',NULL),
(64,62,NULL,'traffic','traffic_accident','traffic','medium','Traffic accident Emergency Report','Maria Santos','09569733114','Bagong Silangan','SARADOOSARADOOSARADOOSARADOOSARADOO','pending','Bagong Silangan','Anonymous',NULL,'2026-02-01 14:20:31',NULL,NULL,NULL,'synced','2026-02-02 05:39:17','2026-02-02 05:39:17','2026-02-02 05:39:17',0,NULL,'for_dispatch',NULL),
(66,65,NULL,'fire','fire','fire','medium','Fire Emergency Report','Marcus Pelaez','09263969662','Barangay Batasan Hills, Quezon City','aoksdjaksjdaou[hsflkajdo[asjdhajfaojakl hf89adjaosjdhajshdak;laskdl','pending','Batasan Hills','User 6',NULL,'2026-02-02 06:37:07',NULL,NULL,NULL,'synced','2026-02-01 22:37:27','2026-02-01 22:37:27','2026-02-01 22:37:27',1,NULL,'for_dispatch',NULL),
(67,76,NULL,'fire','fire','fire','medium','Fire Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Brgy. Commonwealth','May nasusunog po na bahay malapit dito sa Baranggay Hall','pending','Commonwealth','Anonymous',NULL,'2026-02-04 13:53:22',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',1,NULL,'for_dispatch',NULL),
(68,63,NULL,'fire','fire','fire','medium','Fire Emergency Report','Marcus Geremie D.R. Pelaez','09263969662','Barangay Commonwealth','Nasusunog po dito saamin kuya patulong po','pending','Commonwealth','Anonymous',NULL,'2026-02-02 05:38:02',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',1,NULL,'for_dispatch',NULL),
(69,81,NULL,'traffic','traffic_accident','traffic','medium','Traffic accident Emergency Report','ma\'am rich','09090909090','bestlink','whats happennnnnnnnnnnnnnnnnnnnnn','pending','Holy Spirit','Anonymous',NULL,'2026-02-11 16:23:48',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(70,80,NULL,'traffic','traffic_accident','rescue','medium','Traffic accident Emergency Report','Kebs','09094144681','Commonwealth','May malaking path hole','pending','Commonwealth','Anonymous',NULL,'2026-02-10 10:55:47',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(71,79,NULL,'traffic','road_hazard','traffic','medium','Road hazard Emergency Report','Kebs','09094144681','Commonwealth','May malaking path hole','pending','Commonwealth','Anonymous',NULL,'2026-02-10 10:51:23',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(72,78,NULL,'traffic','traffic_violation','police','medium','Traffic violation Emergency Report','joseph','09090909090','batasan','bestlink collage of the philippines','pending','Holy Spirit','Anonymous',NULL,'2026-02-08 14:49:10',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(73,77,NULL,'traffic','traffic_accident','rescue','medium','Traffic accident Emergency Report','joseph','09090909090','batasan','bestlink collage of the philippines','pending','Holy Spirit','Anonymous',NULL,'2026-02-08 14:48:17',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(74,75,NULL,'traffic','traffic_accident','rescue','medium','Traffic accident Emergency Report','jakman','09454545455','MILITAREEEEEEEEEEEEEEEEE','DFGDSWDQsddfbgfewedfbgffwfb f','pending','Holy Spirit','Anonymous',NULL,'2026-02-03 18:22:25',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(75,74,NULL,'traffic','traffic_accident','rescue','medium','Traffic accident Emergency Report','Barako Mundido','09094144681','Batasaaaaan','Jsjsjsjsjsjusjajajjajajajajajaj','pending','Holy Spirit','Anonymous',NULL,'2026-02-03 17:49:38',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(76,73,NULL,'traffic','traffic_accident','rescue','medium','Traffic accident Emergency Report','jakman','09454545455','MILITAR','dfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhji','pending','Holy Spirit','Anonymous',NULL,'2026-02-02 13:40:30',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(77,72,NULL,'traffic','traffic_accident','rescue','medium','Traffic accident Emergency Report','jakman','09454545455','payatas','dfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhji','pending','Payatas','Anonymous',NULL,'2026-02-02 13:37:13',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(78,71,NULL,'traffic','traffic_accident','rescue','medium','Traffic accident Emergency Report','jakman','09454545455','payatas','dfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhji','pending','Payatas','Anonymous',NULL,'2026-02-02 13:33:53',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(79,70,NULL,'traffic','traffic_accident','rescue','medium','Traffic accident Emergency Report','jakman','09454545455','payatas','dfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhji','pending','Payatas','Anonymous',NULL,'2026-02-02 10:37:36',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(80,69,NULL,'traffic','traffic_accident','rescue','medium','Traffic accident Emergency Report','jakman','09454545455','palawan','dfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhjidfghjlk;jhgfdghjkl;jhgfhjopoijhhji','pending','Holy Spirit','Anonymous',NULL,'2026-02-02 10:32:33',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(81,68,NULL,'traffic','traffic_accident','rescue','medium','Traffic accident Emergency Report','Barako Mundido','09094144681','Sitio militar','Hende q na alam hwhahahahahhahaha','pending','Holy Spirit','Anonymous',NULL,'2026-02-02 07:09:03',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(82,67,NULL,'traffic','traffic_accident','rescue','medium','Traffic accident Emergency Report','Barako Mundido','09094144681','Sitio militar','Hende q na alam hwhahahahahhahaha','pending','Holy Spirit','Anonymous',NULL,'2026-02-02 07:08:13',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL),
(83,66,NULL,'traffic','road_hazard','traffic','medium','Road hazard Emergency Report','Barako Mundido','09094144681','Sitio militar','Hende q na alam hwhahahahahhahaha','pending','Holy Spirit','Anonymous',NULL,'2026-02-02 07:06:33',NULL,NULL,NULL,'synced','2026-02-21 10:56:27','2026-02-21 10:56:27','2026-02-21 10:56:27',0,NULL,'for_dispatch',NULL);

/*Table structure for table `attendance_logs` */

DROP TABLE IF EXISTS `attendance_logs`;

CREATE TABLE `attendance_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `shift_date` date NOT NULL,
  `user_id` int(11) NOT NULL,
  `check_in` datetime DEFAULT NULL,
  `check_out` datetime DEFAULT NULL,
  `attendance_status` enum('present','late','absent','excused','on_leave') DEFAULT NULL,
  `total_hours` decimal(5,2) DEFAULT NULL,
  `overtime_hours` decimal(5,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_check_in_date` (`check_in`),
  KEY `fk_attendance_verified_by` (`verified_by`),
  KEY `idx_attendance_date` (`shift_date`,`user_id`),
  CONSTRAINT `fk_attendance_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `attendance_logs` */

insert  into `attendance_logs`(`id`,`shift_id`,`volunteer_id`,`shift_date`,`user_id`,`check_in`,`check_out`,`attendance_status`,`total_hours`,`overtime_hours`,`notes`,`verified_by`,`verified_at`,`created_at`,`updated_at`) values 
(2,131,13,'2026-01-19',10,'2026-01-16 21:10:04','2026-01-16 21:10:10','present',0.00,0.00,'test Checked out: test',NULL,NULL,'2026-01-16 12:10:04','2026-01-16 12:10:10');

/*Table structure for table `change_requests` */

DROP TABLE IF EXISTS `change_requests`;

CREATE TABLE `change_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `field` varchar(50) NOT NULL,
  `current_value` text DEFAULT NULL,
  `requested_value` text NOT NULL,
  `reason` text NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  CONSTRAINT `change_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `change_requests` */

/*Table structure for table `dispatch_incidents` */

DROP TABLE IF EXISTS `dispatch_incidents`;

CREATE TABLE `dispatch_incidents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `incident_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `vehicles_json` text DEFAULT NULL COMMENT 'JSON array of vehicles',
  `dispatched_by` int(11) DEFAULT NULL,
  `suggested_by` int(11) DEFAULT NULL,
  `dispatched_at` datetime DEFAULT current_timestamp(),
  `status` enum('pending','dispatched','en_route','arrived','completed','cancelled') DEFAULT 'pending',
  `status_updated_at` datetime DEFAULT NULL,
  `er_notes` text DEFAULT NULL COMMENT 'Notes from Emergency Response',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_incident` (`incident_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_dispatch_incident` FOREIGN KEY (`incident_id`) REFERENCES `api_incidents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dispatch_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `dispatch_incidents` */

insert  into `dispatch_incidents`(`id`,`incident_id`,`unit_id`,`vehicles_json`,`dispatched_by`,`suggested_by`,`dispatched_at`,`status`,`status_updated_at`,`er_notes`,`created_at`) values 
(13,5,1,'[{\"id\":1,\"vehicle_name\":\"Fire Truck 1\",\"type\":\"Fire\"}]',8,NULL,'2026-01-14 00:54:24','completed','2026-01-24 06:20:34',NULL,'2026-01-13 08:54:24'),
(15,2,6,'[{\"id\":5,\"vehicle_name\":\"Fire Truck 5\",\"type\":\"Fire\",\"available\":1,\"status\":\"Available\"},{\"id\":4,\"vehicle_name\":\"Fire Truck 4\",\"type\":\"Fire\",\"available\":1,\"status\":\"Available\"}]',8,NULL,'2026-01-15 00:58:19','cancelled',NULL,NULL,'2026-01-14 08:58:19'),
(16,26,2,'[{\"id\":8,\"vehicle_name\":\"Ambulance 3\",\"type\":\"Rescue\",\"available\":1,\"status\":\"Available\"}]',8,NULL,'2026-01-15 03:17:41','cancelled',NULL,NULL,'2026-01-14 11:17:41'),
(17,5,1,'[]',NULL,8,'2026-01-21 23:52:12','completed','2026-01-24 06:20:34',NULL,'2026-01-21 07:52:12'),
(18,32,2,'[{\"id\":7,\"vehicle_name\":\"Ambulance 2\",\"type\":\"Rescue\",\"available\":1,\"status\":\"Available\"}]',NULL,8,'2026-01-23 20:24:43','pending',NULL,NULL,'2026-01-23 12:24:43'),
(19,2,1,'[]',NULL,8,'2026-01-24 08:34:40','dispatched','2026-01-25 15:51:11',NULL,'2026-01-24 00:34:40');

/*Table structure for table `duty_assignments` */

DROP TABLE IF EXISTS `duty_assignments`;

CREATE TABLE `duty_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_id` int(11) NOT NULL,
  `duty_type` varchar(100) NOT NULL COMMENT 'Type of duty (e.g., Fire Suppression, Rescue, Medical, Logistics)',
  `duty_description` text NOT NULL COMMENT 'Specific duties and responsibilities',
  `priority` enum('primary','secondary','support') DEFAULT 'primary',
  `required_equipment` text DEFAULT NULL COMMENT 'Required equipment for this duty',
  `required_training` text DEFAULT NULL COMMENT 'Required training/certifications',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shift_id` (`shift_id`),
  CONSTRAINT `fk_duty_assignment_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `duty_assignments` */

insert  into `duty_assignments`(`id`,`shift_id`,`duty_type`,`duty_description`,`priority`,`required_equipment`,`required_training`,`notes`,`created_at`,`updated_at`) values 
(1,131,'logistics_support','Manage and distribute equipment, supplies, and resources to support ongoing operations.','support','gears','Inventory Management, Supply Chain Operations','','2026-01-15 14:55:50','2026-01-15 14:55:50'),
(5,135,'salvage_overhaul','Perform salvage operations to protect property and overhaul to ensure complete extinguishment.','primary','test','Salvage Operations, Overhaul Techniques, Property Conservation','tetst','2026-01-16 09:14:57','2026-01-16 09:14:57'),
(16,146,'command_post','Assist with incident command system operations including communications, resource tracking, and documentation.','support','testtt','ICS Training, Resource Management, Communications Protocols','testt','2026-01-24 06:04:35','2026-01-24 06:04:35'),
(17,147,'command_post','Assist with incident command system operations including communications, resource tracking, and documentation.','support','testtt','ICS Training, Resource Management, Communications Protocols','testt','2026-01-24 06:12:24','2026-01-24 06:12:24'),
(18,148,'command_post','Assist with incident command system operations including communications, resource tracking, and documentation.','support','testtt','ICS Training, Resource Management, Communications Protocols','testt','2026-01-24 06:14:58','2026-01-24 06:14:58'),
(19,149,'command_post','Assist with incident command system operations including communications, resource tracking, and documentation.','support','testtt','ICS Training, Resource Management, Communications Protocols','testt','2026-01-24 06:24:38','2026-01-24 06:24:38'),
(20,150,'command_post','Assist with incident command system operations including communications, resource tracking, and documentation.','support','testtt','ICS Training, Resource Management, Communications Protocols','testt','2026-01-24 06:27:57','2026-01-24 06:27:57'),
(21,151,'command_post','Assist with incident command system operations including communications, resource tracking, and documentation.','support','testtt','ICS Training, Resource Management, Communications Protocols','testt','2026-01-24 06:30:39','2026-01-24 06:30:39'),
(22,152,'first_aid_station','Operate rehabilitation station providing medical monitoring, hydration, and rest for personnel.','primary','asdasdasdasdasdasdasd','First Aid/CPR, Vital Signs Monitoring, Medical Documentation','asdasdasdasd','2026-02-01 20:41:43','2026-02-01 20:41:43'),
(23,153,'rehabilitation','Monitor personnel for signs of exhaustion, provide hydration and nutrition, and ensure crew readiness.','primary','asdasdasdasdasdasdasd','Rehab Operations, Medical Monitoring, Crew Resource Management','asdasdasdasd','2026-02-01 20:43:16','2026-02-01 20:43:16');

/*Table structure for table `duty_templates` */

DROP TABLE IF EXISTS `duty_templates`;

CREATE TABLE `duty_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_name` varchar(100) NOT NULL,
  `duty_type` varchar(100) NOT NULL,
  `duty_description` text NOT NULL,
  `priority` enum('primary','secondary','support') DEFAULT 'primary',
  `required_equipment` text DEFAULT NULL,
  `required_training` text DEFAULT NULL,
  `applicable_units` text DEFAULT NULL COMMENT 'Comma-separated list of unit types this template applies to',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `duty_templates` */

insert  into `duty_templates`(`id`,`template_name`,`duty_type`,`duty_description`,`priority`,`required_equipment`,`required_training`,`applicable_units`,`created_at`,`updated_at`) values 
(1,'Standard Fire Suppression','fire_suppression','Primary firefighting duties including hose line operations, water supply, ventilation, and search & rescue in fire conditions.','primary','Turnout gear, SCBA, helmet, gloves, boots, radio','Basic Firefighter Training, SCBA Certification, Hose & Ladder Operations','Fire','2026-01-15 08:00:00','2026-01-15 08:00:00'),
(2,'Emergency Medical Response','emergency_medical','Provide emergency medical care including patient assessment, basic life support, and stabilization until EMS arrival.','primary','First aid kit, AED, oxygen, trauma bag, gloves','First Aid/CPR Certification, Emergency Medical Responder, Bloodborne Pathogens','EMS,Rescue','2026-01-15 08:00:00','2026-01-15 08:00:00'),
(3,'Rescue Operations','rescue_operations','Search and rescue operations including victim location, extrication, and technical rescue scenarios.','primary','Rescue tools, ropes, harnesses, helmets, gloves','Technical Rescue Training, Rope Rescue Certification, Confined Space Awareness','Rescue','2026-01-15 08:00:00','2026-01-15 08:00:00'),
(4,'Command Post Support','command_post','Assist with incident command system operations including communications, resource tracking, and documentation.','support','Radio, clipboard, forms, maps, computer','ICS Training, Resource Management, Communications Protocols','Command','2026-01-15 08:00:00','2026-01-15 08:00:00'),
(5,'Logistics Support','logistics_support','Manage and distribute equipment, supplies, and resources to support ongoing operations.','support','Inventory lists, supplies, equipment tracking forms','Inventory Management, Supply Chain Operations','Logistics','2026-01-15 08:00:00','2026-01-15 08:00:00');

/*Table structure for table `email_logs` */

DROP TABLE IF EXISTS `email_logs`;

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `sent_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `email_logs` */

insert  into `email_logs`(`id`,`recipient`,`subject`,`body`,`status`,`sent_at`) values 
(1,'stephenviray12123123@gmail.com','Volunteer Application Approved - Account Created','Dear party,\n\nYour volunteer application has been approved!\n\nYour login credentials:\nUsername: stephenviray12123123\nPassword: #PST0000\n\nPlease login at: [Your Login URL]\n\nNote: This is your default password. Please change it after your first login.\n\nBest regards,\nFire & Rescue Team','sent','2026-01-14 23:15:06'),
(2,'stephenviray121111@gmail.com','Volunteer Application Approved - Account Created','Dear zaldy,\n\nYour volunteer application has been approved!\n\nYour login credentials:\nUsername: stephenviray121111\nPassword: #Z0000\n\nPlease login at: [Your Login URL]\n\nNote: This is your default password. Please change it after your first login.\n\nBest regards,\nFire & Rescue Team','sent','2026-01-14 23:41:08'),
(3,'stephenvisssray12@gmail.com','Emergency Dispatch Notification - Commonwealth Fire Unit 1','Dear stephen kyle viray,\n\nYour unit Commonwealth Fire Unit 1 has been dispatched to an emergency incident:\n\nIncident: Severe Injury Assistance Needed\nLocation: Block 12, Brgy. Bagong Silangan, QC\nStatus: DISPATCHED\n\nPlease report to your unit immediately for deployment.\n\nYou can also check your volunteer dashboard for updates.\n\nThis is an automated dispatch notification.\n','sent','2026-01-23 19:50:04'),
(4,'stephensssviray12@gmail.com','Emergency Dispatch Notification - Commonwealth Fire Unit 1','Dear Danielle Marsh,\n\nYour unit Commonwealth Fire Unit 1 has been dispatched to an emergency incident:\n\nIncident: Severe Injury Assistance Needed\nLocation: Block 12, Brgy. Bagong Silangan, QC\nStatus: DISPATCHED\n\nPlease report to your unit immediately for deployment.\n\nYou can also check your volunteer dashboard for updates.\n\nThis is an automated dispatch notification.\n','sent','2026-01-23 19:50:04'),
(5,'jasmine.lopez@example.com','Emergency Dispatch Notification - Commonwealth Fire Unit 1','Dear Jasmine Lopez,\n\nYour unit Commonwealth Fire Unit 1 has been dispatched to an emergency incident:\n\nIncident: Severe Injury Assistance Needed\nLocation: Block 12, Brgy. Bagong Silangan, QC\nStatus: DISPATCHED\n\nPlease report to your unit immediately for deployment.\n\nYou can also check your volunteer dashboard for updates.\n\nThis is an automated dispatch notification.\n','sent','2026-01-23 19:50:04'),
(6,'stephenviray121111@gmail.com','Emergency Dispatch Notification - Commonwealth Fire Unit 1','Dear ,\n\nYour unit Commonwealth Fire Unit 1 has been dispatched to an emergency incident:\n\nIncident: Severe Injury Assistance Needed\nLocation: Block 12, Brgy. Bagong Silangan, QC\nStatus: DISPATCHED\n\nPlease report to your unit immediately for deployment.\n\nYou can also check your volunteer dashboard for updates.\n\nThis is an automated dispatch notification.\n','sent','2026-01-23 19:50:04'),
(7,'sahdjsjahsjd@gmail.com','Volunteer Application Approved - Account Created','Dear Mariefe,\n\nYour volunteer application has been approved!\n\nYour login credentials:\nUsername: sahdjsjahsjd\nPassword: #M0000\n\nPlease login at: [Your Login URL]\n\nNote: This is your default password. Please change it after your first login.\n\nBest regards,\nFire & Rescue Team','sent','2026-01-24 08:57:47'),
(8,'stephenvisssray12@gmail.com','Emergency Dispatch Notification - Commonwealth Fire Unit 1','Dear stephen kyle viray,\n\nYour unit Commonwealth Fire Unit 1 has been dispatched to an emergency incident:\n\nIncident: Fire Emergency Report\nLocation: 123 Main St, Holy Spirit, QC\nStatus: DISPATCHED\n\nPlease report to your unit immediately for deployment.\n\nYou can also check your volunteer dashboard for updates.\n\nThis is an automated dispatch notification.\n','sent','2026-01-25 15:51:11'),
(9,'stephensssviray12@gmail.com','Emergency Dispatch Notification - Commonwealth Fire Unit 1','Dear Danielle Marsh,\n\nYour unit Commonwealth Fire Unit 1 has been dispatched to an emergency incident:\n\nIncident: Fire Emergency Report\nLocation: 123 Main St, Holy Spirit, QC\nStatus: DISPATCHED\n\nPlease report to your unit immediately for deployment.\n\nYou can also check your volunteer dashboard for updates.\n\nThis is an automated dispatch notification.\n','sent','2026-01-25 15:51:11'),
(10,'jasmine.lopez@example.com','Emergency Dispatch Notification - Commonwealth Fire Unit 1','Dear Jasmine Lopez,\n\nYour unit Commonwealth Fire Unit 1 has been dispatched to an emergency incident:\n\nIncident: Fire Emergency Report\nLocation: 123 Main St, Holy Spirit, QC\nStatus: DISPATCHED\n\nPlease report to your unit immediately for deployment.\n\nYou can also check your volunteer dashboard for updates.\n\nThis is an automated dispatch notification.\n','sent','2026-01-25 15:51:11'),
(11,'stephenviray121111@gmail.com','Emergency Dispatch Notification - Commonwealth Fire Unit 1','Dear ,\n\nYour unit Commonwealth Fire Unit 1 has been dispatched to an emergency incident:\n\nIncident: Fire Emergency Report\nLocation: 123 Main St, Holy Spirit, QC\nStatus: DISPATCHED\n\nPlease report to your unit immediately for deployment.\n\nYou can also check your volunteer dashboard for updates.\n\nThis is an automated dispatch notification.\n','sent','2026-01-25 15:51:11'),
(12,'sahdjsjahsjd@gmail.com','Emergency Dispatch Notification - Commonwealth Fire Unit 1','Dear ,\n\nYour unit Commonwealth Fire Unit 1 has been dispatched to an emergency incident:\n\nIncident: Fire Emergency Report\nLocation: 123 Main St, Holy Spirit, QC\nStatus: DISPATCHED\n\nPlease report to your unit immediately for deployment.\n\nYou can also check your volunteer dashboard for updates.\n\nThis is an automated dispatch notification.\n','sent','2026-01-25 15:51:11');

/*Table structure for table `experience_proofs` */

DROP TABLE IF EXISTS `experience_proofs`;

CREATE TABLE `experience_proofs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `proof_type` enum('certificate','employment_record','recommendation','other') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_request_id` (`request_id`),
  CONSTRAINT `fk_proof_request` FOREIGN KEY (`request_id`) REFERENCES `experienced_volunteer_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `experience_proofs` */

insert  into `experience_proofs`(`id`,`request_id`,`proof_type`,`file_path`,`description`,`uploaded_at`) values 
(1,1,'other','exp_proof_3_1771677267_6999a6534922c.jpg','','2026-02-21 12:34:27');

/*Table structure for table `experienced_volunteer_requests` */

DROP TABLE IF EXISTS `experienced_volunteer_requests`;

CREATE TABLE `experienced_volunteer_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `volunteer_id` int(11) NOT NULL,
  `experience_years` int(11) NOT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `review_notes` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_volunteer_id` (`volunteer_id`),
  KEY `idx_status` (`status`),
  KEY `fk_experienced_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_experienced_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_experienced_volunteer` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `experienced_volunteer_requests` */

insert  into `experienced_volunteer_requests`(`id`,`volunteer_id`,`experience_years`,`proof_path`,`status`,`review_notes`,`reviewed_by`,`reviewed_at`,`created_at`,`updated_at`) values 
(1,3,10,'exp_proof_3_1771677267_6999a6534922c.jpg','approved',NULL,11,'2026-02-21 13:10:40','2026-02-21 12:34:27','2026-02-21 13:10:40');

/*Table structure for table `feedbacks` */

DROP TABLE IF EXISTS `feedbacks`;

CREATE TABLE `feedbacks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `rating` int(11) NOT NULL DEFAULT 5,
  `message` text NOT NULL,
  `is_anonymous` tinyint(1) DEFAULT 0,
  `is_approved` tinyint(1) DEFAULT 0,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `is_approved` (`is_approved`),
  CONSTRAINT `feedbacks_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `feedbacks` */

insert  into `feedbacks`(`id`,`name`,`email`,`rating`,`message`,`is_anonymous`,`is_approved`,`user_id`,`created_at`,`updated_at`) values 
(1,'yuuki','maria@example.com',5,'The quick response from Barangay Commonwealth Fire & Rescue saved our home during the recent fire incident. Their professionalism and dedication are truly commendable.',0,1,NULL,'2025-12-03 04:29:53','2025-12-03 04:30:14'),
(2,'Carlos Reyes','carlos@example.com',5,'Volunteering with the fire and rescue team has been one of the most rewarding experiences of my life. The training is excellent and the team feels like family.',0,1,NULL,'2025-12-03 04:29:53','2025-12-03 04:29:53'),
(3,'Anna Santos','anna@example.com',4,'The fire safety seminar organized by the team was incredibly informative. I now feel much more prepared to handle emergency situations at home and work.',0,1,NULL,'2025-12-03 04:29:53','2025-12-03 04:29:53'),
(4,NULL,NULL,5,'Excellent service! The team responded quickly to our emergency call and handled the situation professionally. Thank you for keeping our community safe.',1,1,NULL,'2025-12-03 04:29:53','2025-12-03 04:29:53'),
(5,NULL,NULL,5,'The volunteer training program is outstanding. I learned valuable skills that I can use in everyday emergencies. Highly recommended!',1,1,NULL,'2025-12-03 04:29:53','2025-12-03 04:29:53'),
(6,NULL,NULL,4,'This is trash. jk its tesing feedback tho',1,1,NULL,'2025-12-03 04:31:58','2025-12-03 04:34:14'),
(7,'Haerin Kang','stephenviray12@gmail.com',5,'WOWWWWW',0,1,NULL,'2025-12-03 04:33:43','2025-12-03 04:34:16'),
(8,NULL,NULL,2,'panget ng gawa, pero pogi gumawa! <3',1,1,NULL,'2025-12-03 05:28:16','2025-12-03 05:50:32');

/*Table structure for table `incident_reports` */

DROP TABLE IF EXISTS `incident_reports`;

CREATE TABLE `incident_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `external_id` int(11) DEFAULT NULL COMMENT 'ID from external API',
  `location` varchar(255) NOT NULL,
  `affected_barangays` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `incident_type` varchar(50) NOT NULL,
  `assistance_needed` varchar(50) DEFAULT NULL,
  `alert_type` varchar(50) DEFAULT NULL,
  `emergency_level` enum('low','medium','high','critical') DEFAULT 'medium',
  `status` enum('pending','processing','responded','closed','reported','dispatched','in_progress','resolved') DEFAULT 'pending',
  `date_reported` datetime DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reported_by` varchar(100) DEFAULT NULL,
  `issued_by` varchar(100) DEFAULT NULL,
  `caller_name` varchar(100) DEFAULT NULL,
  `caller_phone` varchar(20) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `valid_until` datetime DEFAULT NULL,
  `incident_proof` varchar(255) DEFAULT NULL COMMENT 'Optional photo/video proof',
  PRIMARY KEY (`id`),
  KEY `idx_external_id` (`external_id`),
  KEY `idx_status` (`status`),
  KEY `idx_incident_type` (`incident_type`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `incident_reports` */

/*Table structure for table `incident_status_logs` */

DROP TABLE IF EXISTS `incident_status_logs`;

CREATE TABLE `incident_status_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `incident_id` int(11) NOT NULL,
  `old_status` varchar(50) NOT NULL,
  `new_status` varchar(50) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `change_notes` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_incident_id` (`incident_id`),
  KEY `idx_changed_at` (`changed_at`),
  KEY `idx_changed_by` (`changed_by`),
  CONSTRAINT `incident_status_logs_ibfk_1` FOREIGN KEY (`incident_id`) REFERENCES `api_incidents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `incident_status_logs_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `incident_status_logs` */

/*Table structure for table `inspection_certificates` */

DROP TABLE IF EXISTS `inspection_certificates`;

CREATE TABLE `inspection_certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inspection_id` int(11) NOT NULL,
  `establishment_id` int(11) NOT NULL,
  `establishment_address` text DEFAULT NULL,
  `establishment_owner` varchar(255) DEFAULT NULL,
  `inspection_date` date DEFAULT NULL,
  `certificate_number` varchar(50) NOT NULL,
  `certificate_type` enum('fsic','compliance','exemption','provisional') DEFAULT 'fsic',
  `certificate_type_full` varchar(100) DEFAULT NULL,
  `issue_date` date NOT NULL,
  `valid_until` date NOT NULL,
  `issued_by` int(11) NOT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `certificate_file` varchar(255) DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `qr_code_data` text DEFAULT NULL,
  `revoked` tinyint(1) DEFAULT 0,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_reason` text DEFAULT NULL,
  `revoked_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `certificate_number` (`certificate_number`),
  KEY `inspection_id` (`inspection_id`),
  KEY `establishment_id` (`establishment_id`),
  KEY `issued_by` (`issued_by`),
  KEY `idx_certificate_number` (`certificate_number`),
  KEY `idx_establishment` (`establishment_id`),
  KEY `idx_validity` (`valid_until`,`revoked`),
  CONSTRAINT `inspection_certificates_ibfk_1` FOREIGN KEY (`inspection_id`) REFERENCES `inspection_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inspection_certificates_ibfk_2` FOREIGN KEY (`establishment_id`) REFERENCES `inspection_establishments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inspection_certificates_ibfk_3` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `inspection_certificates` */

/*Table structure for table `inspection_checklist_items` */

DROP TABLE IF EXISTS `inspection_checklist_items`;

CREATE TABLE `inspection_checklist_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category` varchar(100) NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `item_description` text NOT NULL,
  `compliance_standard` text DEFAULT NULL,
  `weight` int(11) DEFAULT 1 COMMENT 'Weight for scoring',
  `is_mandatory` tinyint(1) DEFAULT 1,
  `applicable_establishment_types` text DEFAULT NULL COMMENT 'JSON array of applicable types',
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_code` (`item_code`),
  KEY `idx_category` (`category`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `inspection_checklist_items` */

insert  into `inspection_checklist_items`(`id`,`category`,`item_code`,`item_description`,`compliance_standard`,`weight`,`is_mandatory`,`applicable_establishment_types`,`active`,`created_at`,`updated_at`) values 
(1,'Fire Safety Equipment','FS-001','Fire extinguishers properly placed and accessible','NFPA 10',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\",\"Residential\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(2,'Fire Safety Equipment','FS-002','Fire extinguishers properly maintained and charged','NFPA 10',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\",\"Residential\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(3,'Fire Safety Equipment','FS-003','Fire alarms operational and tested regularly','NFPA 72',3,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\",\"Government\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(4,'Fire Safety Equipment','FS-004','Smoke detectors installed in designated areas','NFPA 72',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\",\"Residential\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(5,'Fire Safety Equipment','FS-005','Emergency lighting functional','NFPA 101',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(6,'Fire Safety Equipment','FS-006','Exit signs illuminated and visible','NFPA 101',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\",\"Government\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(7,'Emergency Exits','EE-001','Exit doors unlocked and operational','NFPA 101',3,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\",\"Government\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(8,'Emergency Exits','EE-002','Exit pathways clear and unobstructed','NFPA 101',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\",\"Government\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(9,'Emergency Exits','EE-003','Emergency exit maps posted','NFPA 101',1,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(10,'Emergency Exits','EE-004','Assembly area designated and marked','NFPA 101',1,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(11,'Electrical Safety','ES-001','Electrical panels accessible and labeled','Philippine Electrical Code',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\",\"Residential\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(12,'Electrical Safety','ES-002','No exposed wiring or damaged cables','Philippine Electrical Code',3,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\",\"Residential\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(13,'Electrical Safety','ES-003','Proper grounding and bonding','Philippine Electrical Code',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(14,'Electrical Safety','ES-004','Overload protection devices functional','Philippine Electrical Code',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(15,'Storage and Housekeeping','SH-001','Flammable materials properly stored','NFPA 30',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(16,'Storage and Housekeeping','SH-002','No accumulation of combustible waste','NFPA 1',1,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(17,'Storage and Housekeeping','SH-003','Aisles and passageways clear','OSHA 1910.22',1,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(18,'Storage and Housekeeping','SH-004','Storage not blocking fire equipment','NFPA 1',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(19,'Fire Protection Systems','FP-001','Sprinkler system operational (if applicable)','NFPA 13',3,0,'[\"Commercial\",\"Industrial\",\"Healthcare\",\"Government\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(20,'Fire Protection Systems','FP-002','Standpipes accessible and functional','NFPA 14',2,0,'[\"Commercial\",\"Industrial\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(21,'Fire Protection Systems','FP-003','Fire hose cabinets complete','NFPA 1962',2,0,'[\"Commercial\",\"Industrial\",\"Healthcare\",\"Government\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(22,'Fire Protection Systems','FP-004','Automatic fire suppression system','NFPA 17',3,0,'[\"Commercial\",\"Industrial\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(23,'Training and Preparedness','TP-001','Fire safety officer designated','RA 9514',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(24,'Training and Preparedness','TP-002','Employees trained in fire safety','RA 9514',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(25,'Training and Preparedness','TP-003','Fire drills conducted regularly','RA 9514',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(26,'Training and Preparedness','TP-004','First aid kits available and stocked','OSHA 1910.151',1,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\",\"Government\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(27,'Documentation and Permits','DP-001','Fire Safety Inspection Certificate (FSIC) valid','RA 9514',3,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(28,'Documentation and Permits','DP-002','Business permit current','Local Ordinance',2,1,'[\"Commercial\",\"Industrial\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(29,'Documentation and Permits','DP-003','Fire safety plans updated','RA 9514',2,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(30,'Documentation and Permits','DP-004','Records of fire safety training','RA 9514',1,1,'[\"Commercial\",\"Industrial\",\"Educational\",\"Healthcare\"]',1,'2026-01-23 12:12:55','2026-01-23 12:12:55');

/*Table structure for table `inspection_checklist_responses` */

DROP TABLE IF EXISTS `inspection_checklist_responses`;

CREATE TABLE `inspection_checklist_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inspection_id` int(11) NOT NULL,
  `checklist_item_id` int(11) NOT NULL,
  `compliance_status` enum('compliant','non_compliant','not_applicable','partial') DEFAULT 'compliant',
  `score` int(11) DEFAULT 0 COMMENT '0-100 based on compliance',
  `notes` text DEFAULT NULL,
  `evidence_photo` varchar(255) DEFAULT NULL,
  `corrective_action_required` text DEFAULT NULL,
  `corrective_action_deadline` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_inspection_item` (`inspection_id`,`checklist_item_id`),
  KEY `inspection_id` (`inspection_id`),
  KEY `checklist_item_id` (`checklist_item_id`),
  CONSTRAINT `inspection_checklist_responses_ibfk_1` FOREIGN KEY (`inspection_id`) REFERENCES `inspection_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inspection_checklist_responses_ibfk_2` FOREIGN KEY (`checklist_item_id`) REFERENCES `inspection_checklist_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=121 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `inspection_checklist_responses` */

insert  into `inspection_checklist_responses`(`id`,`inspection_id`,`checklist_item_id`,`compliance_status`,`score`,`notes`,`evidence_photo`,`corrective_action_required`,`corrective_action_deadline`,`created_at`,`updated_at`) values 
(1,1,27,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(2,1,28,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(3,1,29,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(4,1,30,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(5,1,11,'partial',50,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(6,1,12,'partial',50,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(7,1,13,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(8,1,14,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(9,1,7,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(10,1,8,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(11,1,9,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(12,1,10,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(13,1,19,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(14,1,20,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(15,1,21,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(16,1,22,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(17,1,1,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(18,1,2,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(19,1,3,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(20,1,4,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(21,1,5,'partial',50,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(22,1,6,'partial',50,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(23,1,15,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(24,1,16,'partial',50,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(25,1,17,'partial',50,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(26,1,18,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(27,1,23,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(28,1,24,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(29,1,25,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(30,1,26,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(31,2,27,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(32,2,28,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(33,2,29,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(34,2,30,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(35,2,11,'partial',50,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(36,2,12,'partial',50,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(37,2,13,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(38,2,14,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(39,2,7,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(40,2,8,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(41,2,9,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(42,2,10,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(43,2,19,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(44,2,20,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(45,2,21,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(46,2,22,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(47,2,1,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(48,2,2,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(49,2,3,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(50,2,4,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(51,2,5,'partial',50,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(52,2,6,'partial',50,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(53,2,15,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(54,2,16,'partial',50,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(55,2,17,'partial',50,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(56,2,18,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(57,2,23,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(58,2,24,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(59,2,25,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(60,2,26,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(61,3,27,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(62,3,28,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(63,3,29,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(64,3,30,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(65,3,11,'partial',50,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(66,3,12,'partial',50,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(67,3,13,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(68,3,14,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(69,3,7,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(70,3,8,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(71,3,9,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(72,3,10,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(73,3,19,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(74,3,20,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(75,3,21,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(76,3,22,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(77,3,1,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(78,3,2,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(79,3,3,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(80,3,4,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(81,3,5,'partial',50,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(82,3,6,'partial',50,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(83,3,15,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(84,3,16,'partial',50,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(85,3,17,'partial',50,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(86,3,18,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(87,3,23,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(88,3,24,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(89,3,25,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(90,3,26,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:35'),
(91,4,27,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(92,4,28,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(93,4,29,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(94,4,30,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(95,4,11,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(96,4,12,'partial',50,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(97,4,13,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(98,4,14,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(99,4,7,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(100,4,8,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(101,4,9,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(102,4,10,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(103,4,19,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(104,4,20,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(105,4,21,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(106,4,22,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(107,4,1,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(108,4,2,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(109,4,3,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(110,4,4,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(111,4,5,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(112,4,6,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(113,4,15,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(114,4,16,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(115,4,17,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(116,4,18,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(117,4,23,'non_compliant',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(118,4,24,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(119,4,25,'compliant',100,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50'),
(120,4,26,'not_applicable',0,'',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50');

/*Table structure for table `inspection_establishments` */

DROP TABLE IF EXISTS `inspection_establishments`;

CREATE TABLE `inspection_establishments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `establishment_name` varchar(255) NOT NULL,
  `establishment_type` enum('Commercial','Residential','Industrial','Educational','Healthcare','Government','Other') NOT NULL,
  `owner_name` varchar(255) NOT NULL,
  `owner_contact` varchar(20) NOT NULL,
  `owner_email` varchar(100) DEFAULT NULL,
  `address` text NOT NULL,
  `barangay` varchar(100) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `business_permit_number` varchar(100) DEFAULT NULL,
  `business_permit_expiry` date DEFAULT NULL,
  `occupancy_type` varchar(100) DEFAULT NULL,
  `occupancy_count` int(11) DEFAULT NULL,
  `floor_area` decimal(10,2) DEFAULT NULL,
  `number_of_floors` int(11) DEFAULT NULL,
  `fire_safety_officer` varchar(255) DEFAULT NULL,
  `fso_contact` varchar(20) DEFAULT NULL,
  `last_inspection_date` date DEFAULT NULL,
  `next_scheduled_inspection` date DEFAULT NULL,
  `inspection_frequency` enum('monthly','quarterly','semi-annual','annual','biannual') DEFAULT 'annual',
  `compliance_rating` int(11) DEFAULT 0 COMMENT 'Percentage rating 0-100',
  `overall_risk_level` enum('low','medium','high','critical') DEFAULT 'medium',
  `status` enum('active','inactive','suspended','closed') DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_permit_number` (`business_permit_number`),
  KEY `idx_barangay` (`barangay`),
  KEY `idx_status` (`status`),
  KEY `idx_next_inspection` (`next_scheduled_inspection`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `inspection_establishments` */

insert  into `inspection_establishments`(`id`,`establishment_name`,`establishment_type`,`owner_name`,`owner_contact`,`owner_email`,`address`,`barangay`,`latitude`,`longitude`,`business_permit_number`,`business_permit_expiry`,`occupancy_type`,`occupancy_count`,`floor_area`,`number_of_floors`,`fire_safety_officer`,`fso_contact`,`last_inspection_date`,`next_scheduled_inspection`,`inspection_frequency`,`compliance_rating`,`overall_risk_level`,`status`,`notes`,`created_by`,`updated_by`,`created_at`,`updated_at`) values 
(1,'Commonwealth Market','Commercial','Juan Dela Cruz','09123456789','juan@market.com','Commonwealth Ave, Brgy. Commonwealth','Commonwealth',NULL,NULL,'BP-CM-2025-001','2025-12-31','Public Market',500,2000.00,2,'Pedro Santos','09187654321','2026-01-23','2026-04-23','quarterly',85,'low','active','Main public market in Commonwealth area',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 12:55:14'),
(2,'Puregold Commonwealth','Commercial','Maria Santos','09228887766','manager@puregold.com','Commonwealth Ave cor Tandang Sora','Commonwealth',NULL,NULL,'BP-PG-2025-002','2025-11-30','Supermarket',300,5000.00,3,'Carlos Reyes','09334445566','2025-07-20','2026-02-15','semi-annual',90,'low','active','Large supermarket chain',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(3,'7-Eleven Commonwealth','Commercial','SM Investments','09175554433','admin@7eleven.com','Commonwealth Ave','Commonwealth',NULL,NULL,'BP-7E-2025-003','2025-10-31','Convenience Store',50,200.00,1,'Anna Lopez','09229988776','2025-08-10','2026-02-28','monthly',75,'medium','active','24/7 convenience store',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(4,'Commonwealth Elementary School','Educational','DepEd Quezon City','09112223344','principal@commonwealth.edu.ph','Commonwealth Ave, Brgy. Commonwealth','Commonwealth',NULL,NULL,'BP-ES-2025-004','2025-12-31','School',2000,8000.00,4,'Principal Juan','09113334455','2025-05-25','2026-01-30','annual',88,'medium','active','Public elementary school',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(5,'Holy Spirit Academy','Educational','Private Corp','09226667788','info@holyspiritacademy.edu.ph','Holy Spirit Drive, Brgy. Holy Spirit','Holy Spirit',NULL,NULL,'BP-HS-2025-005','2025-11-30','Private School',1500,6000.00,3,'Sister Maria','09337778899','2025-09-15','2026-03-15','annual',92,'low','active','Private Catholic school',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(6,'Commonwealth Health Center','Healthcare','Quezon City LGU','09114445566','admin@commonwealthhealth.gov.ph','Commonwealth Ave, Brgy. Commonwealth','Commonwealth',NULL,NULL,'BP-CH-2025-006','2025-12-31','Health Center',200,1500.00,2,'Dr. Santos','09225556677','2025-07-30','2026-02-10','quarterly',95,'low','active','Public health center',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(7,'St. Luke\'s Commonwealth','Healthcare','St. Luke\'s Medical Center','09116667788','admin@stlukescommonwealth.com','Commonwealth Ave','Commonwealth',NULL,NULL,'BP-SL-2025-007','2025-12-31','Hospital',500,15000.00,5,'Safety Officer Cruz','09338889900','2026-01-23','2026-02-23','monthly',34,'high','active','Private hospital branch',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 13:24:50'),
(8,'Commonwealth Woodworks','Industrial','Carlo Mendoza','09117778899','carlo@woodworks.com','Industrial St, Brgy. Commonwealth','Commonwealth',NULL,NULL,'BP-CW-2025-008','2025-09-30','Factory',100,3000.00,2,'Mario Lopez','09228889911','2025-08-05','2026-02-20','monthly',70,'high','active','Wood furniture factory',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(9,'Metro Plastic Corp','Industrial','Metro Industries','09118889900','safety@metroplastic.com','Industrial Area, Brgy. Payatas','Payatas',NULL,NULL,'BP-MP-2025-009','2025-10-31','Plastic Factory',150,5000.00,3,'Safety Manager Tan','09339990011','2025-07-15','2026-01-30','monthly',65,'high','active','Plastic manufacturing',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(10,'Commonwealth Heights Condo','Residential','DMCI Homes','09119990011','property@dmci-commonwealth.com','Commonwealth Ave, Brgy. Commonwealth','Commonwealth',NULL,NULL,'BP-CHC-2025-010','2025-12-31','Condominium',1000,20000.00,15,'Building Admin Reyes','09221112233','2025-09-10','2026-03-10','semi-annual',82,'medium','active','High-rise condominium',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(11,'Holy Spirit Village','Residential','Villar Group','09223334455','admin@holyspiritvillage.com','Holy Spirit Drive, Brgy. Holy Spirit','Holy Spirit',NULL,NULL,'BP-HSV-2025-011','2025-11-30','Subdivision',800,12000.00,3,'Village Safety Officer','09334445566','2025-08-20','2026-02-25','annual',87,'medium','active','Gated subdivision',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(12,'Commonwealth Barangay Hall','Government','Barangay Commonwealth','09124445566','brgy.commonwealth@qc.gov.ph','Commonwealth Ave, Brgy. Commonwealth','Commonwealth',NULL,NULL,'BP-BH-2025-012','2025-12-31','Government Office',50,800.00,2,'Kagawad Cruz','09225556677','2025-10-05','2026-04-05','annual',93,'low','active','Barangay government office',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 12:12:55'),
(13,'QC Fire Station Commonwealth','Government','Bureau of Fire Protection','09126667788','commonwealth@bfp.gov.ph','Commonwealth Ave, Brgy. Commonwealth','Commonwealth',NULL,NULL,'BP-FS-2025-013','2025-12-31','Fire Station',30,1000.00,2,'FO2 Santos','09337778899','2025-11-15','2026-05-15','semi-annual',99,'low','active','Fire and rescue station',NULL,NULL,'2026-01-23 12:12:55','2026-01-23 12:12:55');

/*Table structure for table `inspection_follow_ups` */

DROP TABLE IF EXISTS `inspection_follow_ups`;

CREATE TABLE `inspection_follow_ups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inspection_id` int(11) NOT NULL,
  `establishment_id` int(11) NOT NULL,
  `follow_up_type` enum('compliance_check','violation_rectification','training','re_inspection','other') NOT NULL,
  `scheduled_date` date NOT NULL,
  `actual_date` date DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `status` enum('pending','scheduled','in_progress','completed','cancelled','overdue') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `outcome` text DEFAULT NULL,
  `compliance_verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `inspection_id` (`inspection_id`),
  KEY `establishment_id` (`establishment_id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `idx_status` (`status`),
  KEY `idx_scheduled_date` (`scheduled_date`),
  CONSTRAINT `inspection_follow_ups_ibfk_1` FOREIGN KEY (`inspection_id`) REFERENCES `inspection_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inspection_follow_ups_ibfk_2` FOREIGN KEY (`establishment_id`) REFERENCES `inspection_establishments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inspection_follow_ups_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `inspection_follow_ups` */

/*Table structure for table `inspection_reports` */

DROP TABLE IF EXISTS `inspection_reports`;

CREATE TABLE `inspection_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `establishment_id` int(11) NOT NULL,
  `inspection_date` date NOT NULL,
  `inspected_by` int(11) NOT NULL COMMENT 'User ID of inspector',
  `inspection_type` enum('routine','follow_up','complaint','random','pre-license','renewal') DEFAULT 'routine',
  `report_number` varchar(50) NOT NULL,
  `status` enum('draft','submitted','under_review','approved','rejected','revision_requested','completed') DEFAULT 'draft',
  `overall_compliance_score` int(11) DEFAULT 0 COMMENT 'Percentage 0-100',
  `risk_assessment` enum('low','medium','high','critical') DEFAULT 'medium',
  `fire_hazard_level` enum('low','medium','high','extreme') DEFAULT 'medium',
  `recommendations` text DEFAULT NULL,
  `corrective_actions_required` text DEFAULT NULL,
  `compliance_deadline` date DEFAULT NULL,
  `certificate_issued` tinyint(1) DEFAULT 0,
  `certificate_number` varchar(50) DEFAULT NULL,
  `certificate_valid_until` date DEFAULT NULL,
  `admin_reviewed_by` int(11) DEFAULT NULL,
  `admin_reviewed_at` datetime DEFAULT NULL,
  `admin_review_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `report_number` (`report_number`),
  KEY `establishment_id` (`establishment_id`),
  KEY `inspected_by` (`inspected_by`),
  KEY `admin_reviewed_by` (`admin_reviewed_by`),
  KEY `idx_status` (`status`),
  KEY `idx_inspection_date` (`inspection_date`),
  CONSTRAINT `inspection_reports_ibfk_1` FOREIGN KEY (`establishment_id`) REFERENCES `inspection_establishments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inspection_reports_ibfk_2` FOREIGN KEY (`inspected_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inspection_reports_ibfk_3` FOREIGN KEY (`admin_reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `inspection_reports` */

insert  into `inspection_reports`(`id`,`establishment_id`,`inspection_date`,`inspected_by`,`inspection_type`,`report_number`,`status`,`overall_compliance_score`,`risk_assessment`,`fire_hazard_level`,`recommendations`,`corrective_actions_required`,`compliance_deadline`,`certificate_issued`,`certificate_number`,`certificate_valid_until`,`admin_reviewed_by`,`admin_reviewed_at`,`admin_review_notes`,`created_at`,`updated_at`) values 
(1,1,'2026-01-23',8,'renewal','INSP-20260123-3181','draft',85,'low','low','testt','testt','2026-01-30',0,NULL,NULL,NULL,NULL,NULL,'2026-01-23 12:55:14','2026-01-23 12:55:14'),
(2,1,'2026-01-23',8,'renewal','INSP-20260123-1169','draft',85,'low','low','testt','testt','2026-01-30',0,NULL,NULL,NULL,NULL,NULL,'2026-01-23 12:55:19','2026-01-23 12:55:19'),
(3,1,'2026-01-23',8,'renewal','INSP-20260123-8069','submitted',85,'low','low','testt','testt','2026-01-30',0,NULL,NULL,NULL,NULL,NULL,'2026-01-23 13:09:35','2026-01-23 13:09:48'),
(4,7,'2026-01-23',8,'complaint','INSP-20260123-9546','draft',34,'high','high','testt','testt','2026-02-07',0,NULL,NULL,NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50');

/*Table structure for table `inspection_violations` */

DROP TABLE IF EXISTS `inspection_violations`;

CREATE TABLE `inspection_violations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inspection_id` int(11) NOT NULL,
  `violation_code` varchar(50) NOT NULL,
  `violation_description` text NOT NULL,
  `severity` enum('minor','major','critical') DEFAULT 'minor',
  `section_violated` varchar(100) DEFAULT NULL,
  `fine_amount` decimal(10,2) DEFAULT NULL,
  `compliance_deadline` date DEFAULT NULL,
  `status` enum('pending','rectified','overdue','escalated','waived') DEFAULT 'pending',
  `rectified_at` datetime DEFAULT NULL,
  `rectified_evidence` varchar(255) DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `inspection_id` (`inspection_id`),
  KEY `idx_status` (`status`),
  KEY `idx_severity` (`severity`),
  CONSTRAINT `inspection_violations_ibfk_1` FOREIGN KEY (`inspection_id`) REFERENCES `inspection_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `inspection_violations` */

insert  into `inspection_violations`(`id`,`inspection_id`,`violation_code`,`violation_description`,`severity`,`section_violated`,`fine_amount`,`compliance_deadline`,`status`,`rectified_at`,`rectified_evidence`,`admin_notes`,`created_at`,`updated_at`) values 
(1,4,'fs1121','testttt','major','nfpa 101',500000.00,'2026-02-07','pending',NULL,NULL,NULL,'2026-01-23 13:24:50','2026-01-23 13:24:50');

/*Table structure for table `login_attempts` */

DROP TABLE IF EXISTS `login_attempts`;

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `attempt_time` datetime NOT NULL,
  `successful` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`,`attempt_time`),
  KEY `idx_time` (`attempt_time`)
) ENGINE=InnoDB AUTO_INCREMENT=191 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `login_attempts` */

insert  into `login_attempts`(`id`,`ip_address`,`email`,`attempt_time`,`successful`) values 
(106,'::1','stephenviray12@gmail.com','2026-01-16 23:54:00',1),
(108,'::1','stephenviray121111@gmail.com','2026-01-16 23:54:30',1),
(109,'::1','stephenviray121111@gmail.com','2026-01-17 04:11:12',1),
(110,'::1','stephenviray12@gmail.com','2026-01-18 02:56:53',1),
(111,'::1','stephenviray12@gmail.com','2026-01-19 01:13:56',1),
(112,'::1','stephenviray121111@gmail.com','2026-01-19 01:15:03',1),
(113,'::1','stephenviray12@gmail.com','2026-01-19 01:19:11',1),
(114,'::1','stephenviray121111@gmail.com','2026-01-19 01:19:50',1),
(116,'::1','stephenviray121111@gmail.com','2026-01-19 14:42:14',1),
(117,'::1','stephenviray12@gmail.com','2026-01-19 18:15:56',1),
(118,'::1','stephenviray121111@gmail.com','2026-01-19 18:18:54',1),
(119,'::1','stephenviray121111@gmail.com','2026-01-20 00:49:14',1),
(122,'::1','yenajigumina12@gmail.com','2026-01-20 14:55:08',1),
(123,'::1','stephenviray12@gmail.com','2026-01-20 14:55:16',1),
(125,'::1','stephenviray121111@gmail.com','2026-01-20 14:55:30',1),
(126,'::1','yenajigumina12@gmail.com','2026-01-27 16:18:25',1),
(127,'::1','stephenviray121111@gmail.com','2026-01-20 18:27:51',1),
(128,'::1','stephenviray121111@gmail.com','2026-01-21 13:28:36',1),
(129,'::1','stephenviray12@gmail.com','2026-01-21 13:28:56',1),
(130,'::1','yenajigumina12@gmail.com','2026-01-21 13:29:04',1),
(131,'::1','yenajigumina12@gmail.com','2026-01-22 00:38:33',1),
(132,'::1','yenajigumina12@gmail.com','2026-01-22 22:47:11',1),
(133,'::1','stephenviray12@gmail.com','2026-01-22 22:50:58',1),
(134,'::1','stephenviray121111@gmail.com','2026-01-22 23:48:18',1),
(135,'::1','yenajigumina12@gmail.com','2026-01-22 23:50:11',1),
(136,'::1','stephenviray121111@gmail.com','2026-01-22 23:50:57',1),
(137,'::1','yenajigumina12@gmail.com','2026-01-23 00:09:11',1),
(138,'::1','stephenviray121111@gmail.com','2026-01-23 00:18:15',1),
(140,'::1','stephenviray121111@gmail.com','2026-01-23 08:59:03',1),
(141,'::1','stephenviray12@gmail.com','2026-01-23 19:52:12',1),
(142,'152.32.100.115','stephenviray121111@gmail.com','2026-01-23 15:16:34',1),
(143,'152.32.100.115','stephenviray12@gmail.com','2026-01-23 19:49:52',1),
(144,'152.32.100.115','stephenviray12@gmail.com','2026-01-23 20:19:42',1),
(145,'152.32.100.115','stephenviray12@gmail.com','2026-01-23 21:48:49',1),
(146,'::1','yenajigumina12@gmail.com','2026-01-24 07:04:48',1),
(147,'::1','stephenviray12@gmail.com','2026-01-24 09:48:57',1),
(148,'::1','yenajigumina12@gmail.com','2026-01-24 10:37:34',1),
(151,'110.54.151.60','stephenviray12@gmail.com','2026-01-24 03:22:22',1),
(152,'2405:8d40:4042:7bec:85ed:b6bb:76df:d01d','yenajigumina12@gmail.com','2026-01-24 04:22:50',1),
(153,'2405:8d40:4079:b0a6:b835:c32a:f0b1:277c','stephenviray12@gmail.com','2026-01-24 04:45:07',1),
(155,'110.54.149.122','yenajigumina12@gmail.com','2026-01-24 04:58:07',1),
(156,'175.158.203.140','yenajigumina12@gmail.com','2026-01-24 05:43:39',1),
(157,'175.158.203.140','yenajigumina12@gmail.com','2026-01-24 05:59:58',1),
(158,'110.54.134.221','stephenviray12@gmail.com','2026-01-24 06:32:22',1),
(159,'110.54.134.221','yenajigumina12@gmail.com','2026-01-24 06:33:24',1),
(160,'110.54.134.221','stephenviray121111@gmail.com','2026-01-24 06:34:25',1),
(162,'110.54.134.221','stephenviray12@gmail.com','2026-01-24 08:30:18',1),
(163,'2001:fd8:17c5:6c79:b052:a7da:5a5:fe29','sahdjsjahsjd@gmail.com','2026-01-24 08:58:31',1),
(164,'2001:fd8:17c5:6c79:b052:a7da:5a5:fe29','sahdjsjahsjd@gmail.com','2026-01-24 09:03:02',1),
(166,'2001:fd8:17c5:6c79:ec15:9146:54b5:551d','yenajigumina12@gmail.com','2026-01-24 09:10:40',1),
(167,'2001:fd8:17c5:6c79:ec15:9146:54b5:551d','sahdjsjahsjd@gmail.com','2026-01-24 09:13:13',1),
(168,'152.32.100.115','stephenviray12@gmail.com','2026-01-24 13:36:25',1),
(169,'136.158.37.143','stephenviray12@gmail.com','2026-01-27 07:20:35',1),
(170,'152.32.100.115','stephenviray12@gmail.com','2026-01-30 19:59:49',1),
(171,'152.32.100.115','stephenviray12@gmail.com','2026-02-02 04:40:00',1),
(172,'152.32.100.115','stephenviray12@gmail.com','2026-02-02 06:34:31',1),
(174,'::1','stephenviray12@gmail.com','2026-02-01 20:11:03',1),
(175,'::1','stephenviray12@gmail.com','2026-02-02 13:38:35',1),
(176,'::1','stephenviray12@gmail.com','2026-02-21 18:56:21',1),
(179,'::1','yenajigumina12@gmail.com','2026-02-21 19:03:00',1),
(180,'::1','stephenviray12@gmail.com','2026-02-21 19:49:30',1),
(181,'::1','yenajigumina12@gmail.com','2026-02-21 19:49:52',1),
(183,'152.32.100.115','yenajigumina12@gmail.com','2026-02-21 12:45:06',1),
(187,'152.32.100.115','stephenviray121111@gmail.com','2026-02-21 13:32:57',1),
(188,'136.158.39.56','stephenviray12@gmail.com','2026-02-21 13:33:30',1),
(189,'136.158.39.56','yenajigumina12@gmail.com','2026-02-21 13:35:10',1),
(190,'152.32.100.115','yenajigumina12@gmail.com','2026-02-21 15:29:58',1);

/*Table structure for table `maintenance_requests` */

DROP TABLE IF EXISTS `maintenance_requests`;

CREATE TABLE `maintenance_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `request_type` enum('routine_maintenance','repair','inspection','calibration','disposal') NOT NULL,
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `description` text NOT NULL,
  `requested_date` datetime DEFAULT current_timestamp(),
  `scheduled_date` date DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','approved','in_progress','completed','rejected','cancelled') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `completed_date` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `resource_id` (`resource_id`),
  KEY `requested_by` (`requested_by`),
  KEY `approved_by` (`approved_by`),
  KEY `completed_by` (`completed_by`),
  CONSTRAINT `maintenance_requests_ibfk_1` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `maintenance_requests_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `maintenance_requests_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `maintenance_requests_ibfk_4` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `maintenance_requests` */

insert  into `maintenance_requests`(`id`,`resource_id`,`requested_by`,`request_type`,`priority`,`description`,`requested_date`,`scheduled_date`,`estimated_cost`,`status`,`approved_by`,`approved_date`,`completed_by`,`completed_date`,`notes`,`created_at`,`updated_at`) values 
(1,11,11,'repair','medium','Status changed to: Under Maintenance. Notes: tets','2026-01-22 04:44:32',NULL,NULL,'completed',NULL,NULL,NULL,NULL,NULL,'2026-01-21 12:44:32','2026-01-21 12:44:32'),
(2,11,8,'repair','low','Resource usage logged: 6 units used for routine_check','2026-01-22 22:51:39',NULL,NULL,'completed',NULL,NULL,NULL,NULL,NULL,'2026-01-22 06:51:39','2026-01-22 06:51:39'),
(3,11,8,'repair','low','Resource usage logged: 6 units used for routine_check','2026-01-22 22:51:41',NULL,NULL,'completed',NULL,NULL,NULL,NULL,NULL,'2026-01-22 06:51:41','2026-01-22 06:51:41'),
(4,10,8,'repair','medium','Damage Report - mechanical_failure\nSeverity: moderate\nDescription: test\nAffected Quantity: 3\nPrevious Available: 12\nNew Available: 9\nUnit ID: 9\nIncident ID: 19\nUrgency Level: medium\nNotes: test','2026-01-22 23:14:06',NULL,6000.00,'pending',NULL,NULL,NULL,NULL,NULL,'2026-01-22 07:14:06','2026-01-22 07:14:06'),
(5,10,8,'repair','medium','Damage Report - mechanical_failure\nSeverity: moderate\nDescription: test\nAffected Quantity: 3\nPrevious Available: 9\nNew Available: 6\nUnit ID: 9\nIncident ID: 19\nUrgency Level: medium\nNotes: test','2026-01-22 23:31:19',NULL,6000.00,'pending',NULL,NULL,NULL,NULL,NULL,'2026-01-22 07:31:19','2026-01-22 07:31:19'),
(6,10,8,'repair','medium','Damage Report - mechanical_failure\nSeverity: moderate\nDescription: test\nAffected Quantity: 3\nPrevious Available: 6\nNew Available: 3\nUnit ID: 9\nIncident ID: 19\nUrgency Level: medium\nNotes: test','2026-01-22 23:31:47','2026-02-22',200.00,'approved',11,'2026-02-21 19:07:17',NULL,NULL,'\n\n[Approval] asd','2026-01-22 07:31:47','2026-02-21 11:07:17'),
(7,11,10,'repair','low','yey','2026-01-23 00:08:36',NULL,NULL,'pending',NULL,NULL,NULL,NULL,'tsy','2026-01-22 08:08:36','2026-01-22 08:08:36'),
(8,11,10,'repair','low','yey','2026-01-23 00:08:42',NULL,NULL,'pending',NULL,NULL,NULL,NULL,'tsy','2026-01-22 08:08:42','2026-01-22 08:08:42'),
(9,11,10,'repair','low','yey','2026-01-23 00:08:59',NULL,NULL,'pending',NULL,NULL,NULL,NULL,'tsy','2026-01-22 08:08:59','2026-01-22 08:08:59');

/*Table structure for table `notifications` */

DROP TABLE IF EXISTS `notifications`;

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `is_read` (`is_read`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=155 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `notifications` */

insert  into `notifications`(`id`,`user_id`,`type`,`title`,`message`,`is_read`,`created_at`) values 
(4,10,'shift_declined','Shift Declined','You have declined your shift scheduled on 2026-01-18',0,'2026-01-16 01:27:44'),
(5,10,'shift_confirmation','Shift Confirmed','You have confirmed your shift scheduled on 2026-01-17',0,'2026-01-16 01:27:50'),
(6,10,'shift_change_request','Shift Change Requested','You have requested a change for your shift scheduled on 2026-01-24',0,'2026-01-16 01:49:34'),
(7,10,'shift_declined','Shift Declined','You have declined your shift scheduled on 2026-01-31',0,'2026-01-16 03:09:44'),
(8,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 18, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 03:30:49'),
(9,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Jan 19, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:31:06'),
(10,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Jan 21, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:31:06'),
(11,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Jan 23, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:31:06'),
(12,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Jan 26, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:31:06'),
(13,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Jan 28, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:31:06'),
(14,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Jan 30, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:31:06'),
(15,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 02, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:31:06'),
(16,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 04, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:31:06'),
(17,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 06, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:31:06'),
(18,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 09, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:31:06'),
(19,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 11, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:31:06'),
(20,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 13, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:31:06'),
(21,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 18, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 03:35:54'),
(22,10,'shift_change_request','Shift Change Requested','You have requested a change for your shift scheduled on 2026-01-18',0,'2026-01-16 03:36:29'),
(23,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 25, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 03:37:35'),
(24,10,'shift_confirmation','Shift Confirmed','You have confirmed your shift scheduled on 2026-01-25',0,'2026-01-16 03:37:44'),
(25,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 18, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 03:52:39'),
(26,10,'shift_change_request','Shift Change Requested','You have requested a change for your shift scheduled on 2026-01-18',0,'2026-01-16 03:53:00'),
(27,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 18, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 03:53:05'),
(28,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 26, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 03:56:55'),
(29,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 02, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(30,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 04, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(31,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 06, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(32,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 09, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(33,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 11, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(34,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 13, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(35,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 16, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(36,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 18, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(37,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 20, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(38,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 23, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(39,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 25, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(40,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 27, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(41,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Mar 02, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(42,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Mar 04, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(43,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Mar 06, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:17'),
(44,10,'shift_confirmation','Shift Confirmed','You have confirmed your shift scheduled on 2026-01-26',0,'2026-01-16 03:57:32'),
(45,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 02, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(46,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 04, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(47,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 06, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(48,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 09, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(49,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 11, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(50,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 13, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(51,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 16, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(52,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 18, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(53,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 20, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(54,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 23, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(55,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 25, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(56,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Feb 27, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(57,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Mar 02, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(58,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Mar 04, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(59,10,'new_shift','New Shift Assigned','You have been assigned a new recurring shift on Mar 06, 2026 from 8:00 AM to 4:00 PM. Please confirm your availability.',0,'2026-01-16 03:57:38'),
(60,10,'shift_change_approved','Shift Change Approved','Your shift change request has been approved. New schedule: January 25, 2026 from 06:52 AM to 12:52 PM',0,'2026-01-16 04:49:11'),
(61,10,'shift_change_status','Shift Change Request ','Your shift change request has been approved: oki dokie',0,'2026-01-16 04:49:11'),
(62,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 15, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 04:49:57'),
(63,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 18, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 04:50:42'),
(64,10,'shift_change_request','Shift Change Requested','You have requested a change for your shift scheduled on 2026-01-18',0,'2026-01-16 04:51:31'),
(65,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 18, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 04:51:35'),
(66,10,'shift_change_approved','Shift Change Approved','Your shift change request has been approved. New schedule: January 21, 2026 from 06:00 AM to 12:00 PM',0,'2026-01-16 04:52:05'),
(67,10,'shift_change_status','Shift Change Request ','Your shift change request has been approved: okay make sure you gonna take that sched',0,'2026-01-16 04:52:05'),
(68,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Feb 01, 2026 from 10:00 PM to 6:00 AM. Please confirm your availability.',0,'2026-01-16 04:54:30'),
(69,10,'shift_change_request','Shift Change Requested','You have requested a change for your shift scheduled on 2026-02-01',0,'2026-01-16 04:55:05'),
(70,10,'shift_change_status','Shift Change Request ','Your shift change request has been approved: test',0,'2026-01-16 04:55:19'),
(71,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 31, 2026 from 8:00 AM to 5:00 PM. Please confirm your availability.',0,'2026-01-16 04:56:18'),
(72,10,'shift_change_request','Shift Change Requested','You have requested a change for your shift scheduled on 2026-01-31',0,'2026-01-16 04:56:48'),
(73,10,'shift_change_approved','Shift Change Approved','Your shift change request has been approved. New schedule: February 01, 2026 from 06:00 AM to 12:00 PM',0,'2026-01-16 04:56:58'),
(74,10,'shift_change_status','Shift Change Request ','Your shift change request has been approved: okii dokie',0,'2026-01-16 04:56:58'),
(75,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 31, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 05:03:55'),
(76,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Feb 02, 2026 from 8:00 AM to 5:00 PM. Please confirm your availability.',0,'2026-01-16 05:04:11'),
(77,10,'shift_change_request','Shift Change Requested','You have requested a change for your shift scheduled on 2026-01-31',0,'2026-01-16 05:04:50'),
(78,10,'shift_change_request','Shift Change Requested','You have requested a change for your shift scheduled on 2026-02-02',0,'2026-01-16 05:04:57'),
(79,10,'shift_change_approved','Shift Change Approved','Your shift change request has been approved. New schedule: February 04, 2026 from 12:00 PM to 07:00 PM',0,'2026-01-16 05:13:46'),
(80,10,'shift_change_status','Shift Change Request Updated','Your shift change request has been approved: okiii',0,'2026-01-16 05:13:46'),
(81,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 25, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 05:16:54'),
(82,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Mar 01, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 05:17:04'),
(83,10,'shift_change_request','Shift Change Requested','You have requested a change for your shift scheduled on 2026-01-25',0,'2026-01-16 05:17:46'),
(84,10,'shift_change_request','Shift Change Requested','You have requested a change for your shift scheduled on 2026-03-01',0,'2026-01-16 05:17:56'),
(85,10,'shift_change_approved','Shift Change Approved','Your shift change request has been approved. New schedule: January 28, 2026 from 07:00 PM to 02:00 PM',0,'2026-01-16 05:27:37'),
(86,10,'shift_change_status','Shift Change Request Updated','Your shift change request has been approved: 12312312',0,'2026-01-16 05:27:37'),
(87,10,'shift_change_approved','Shift Change Approved','Your shift change request has been approved. New schedule: January 28, 2026 from 07:00 PM to 02:00 PM',0,'2026-01-16 05:28:00'),
(88,10,'shift_change_status','Shift Change Request Updated','Your shift change request has been approved: 12312312',0,'2026-01-16 05:28:00'),
(89,10,'shift_change_approved','Shift Change Request Approved','Your shift change request has been approved. Notes: asdasddddd',0,'2026-01-16 06:30:31'),
(90,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 19, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-16 06:55:50'),
(91,10,'shift_confirmation','Shift Confirmed','You have confirmed your shift scheduled on 2026-01-19',0,'2026-01-17 00:54:44'),
(92,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 16, 2026 from 2:00 PM to 10:00 PM. Please confirm your availability.',0,'2026-01-17 00:55:16'),
(93,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 25, 2026 from 10:00 PM to 6:00 AM. Please confirm your availability.',0,'2026-01-17 00:55:37'),
(94,10,'shift_change_request','Shift Change Requested','You have requested a change for your shift scheduled on 2026-01-25',0,'2026-01-17 00:56:06'),
(95,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 25, 2026 from 10:00 PM to 6:00 AM. Please confirm your availability.',0,'2026-01-17 00:56:08'),
(96,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Jan 25, 2026 from 6:00 AM to 2:00 PM. Please confirm your availability.',0,'2026-01-17 01:14:57'),
(97,10,'shift_change_request','Shift Change Requested','You have requested a change for your shift scheduled on 2026-01-25',0,'2026-01-17 01:16:11'),
(98,10,'shift_change_approved','Shift Change Request Approved','Your shift change request has been approved. Notes: test',0,'2026-01-17 01:17:00'),
(99,10,'shift_change_approved','Shift Change Request Approved','Your shift change request has been approved. Notes: test',0,'2026-01-17 01:17:01'),
(100,10,'attendance_checkin','Checked In Successfully','You have been checked in for your shift starting at 6:00 AM.',0,'2026-01-17 04:10:04'),
(101,10,'attendance_checkout','Checked Out Successfully','You have been checked out from your shift. Total hours: 0.',0,'2026-01-17 04:10:10'),
(102,8,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 01:39:09'),
(103,8,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Incident Command System',0,'2026-01-27 18:15:34'),
(104,11,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Incident Command System',0,'2026-01-27 18:15:34'),
(106,8,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 18:29:46'),
(107,11,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 18:29:46'),
(109,8,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 18:36:43'),
(110,11,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 18:36:43'),
(112,8,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Wildland Firefighting',0,'2026-01-27 18:42:37'),
(113,11,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Wildland Firefighting',0,'2026-01-27 18:42:37'),
(115,8,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 18:48:51'),
(116,11,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 18:48:51'),
(118,8,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Wildland Firefighting',0,'2026-01-27 18:52:28'),
(119,11,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Wildland Firefighting',0,'2026-01-27 18:52:28'),
(121,8,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 18:54:02'),
(122,11,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 18:54:02'),
(124,8,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 18:56:29'),
(125,11,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 18:56:29'),
(127,8,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Incident Command System',0,'2026-01-20 20:32:29'),
(128,11,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Incident Command System',0,'2026-01-20 20:32:29'),
(130,8,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 20:32:41'),
(131,11,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 20:32:41'),
(133,8,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 21:22:51'),
(134,11,'training_registration','New Training Registration','Volunteer zaldy solis has registered for training: Advanced Rescue Techniques',0,'2026-01-20 21:22:51'),
(136,10,'training_assigned','Training Assigned','You have been assigned to training: Wildland Firefighting. Training starts on: January 28, 2026',1,'2026-01-20 23:25:24'),
(153,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Feb 02, 2026 from 2:00 PM to 10:00 PM. Please confirm your availability.',0,'2026-02-02 04:41:43'),
(154,10,'new_shift','New Shift Assigned','You have been assigned a new shift on Feb 02, 2026 from 2:00 PM to 10:00 PM. Please confirm your availability.',0,'2026-02-02 04:43:16');

/*Table structure for table `password_change_logs` */

DROP TABLE IF EXISTS `password_change_logs`;

CREATE TABLE `password_change_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `changed_by` int(11) DEFAULT NULL COMMENT 'Admin who initiated the change',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_changed_at` (`changed_at`),
  KEY `fk_password_log_changed_by` (`changed_by`),
  CONSTRAINT `fk_password_log_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_password_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `password_change_logs` */

/*Table structure for table `post_incident_reports` */

DROP TABLE IF EXISTS `post_incident_reports`;

CREATE TABLE `post_incident_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `incident_id` int(11) NOT NULL,
  `submitted_by` int(11) NOT NULL,
  `field_reports` text DEFAULT NULL COMMENT 'JSON array of file paths',
  `equipment_used_json` text DEFAULT NULL COMMENT 'JSON array of equipment used',
  `debrief_notes` text NOT NULL,
  `completion_status` enum('draft','completed','reviewed','archived') DEFAULT 'draft',
  `submitted_at` datetime DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `incident_id` (`incident_id`),
  KEY `submitted_by` (`submitted_by`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `post_incident_reports_ibfk_1` FOREIGN KEY (`incident_id`) REFERENCES `api_incidents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `post_incident_reports_ibfk_2` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `post_incident_reports_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `post_incident_reports` */

insert  into `post_incident_reports`(`id`,`incident_id`,`submitted_by`,`field_reports`,`equipment_used_json`,`debrief_notes`,`completion_status`,`submitted_at`,`reviewed_by`,`reviewed_at`,`review_notes`,`created_at`,`updated_at`) values 
(1,5,8,'[\"post_incident_reports\\/field_report_5_1769206807_0.jpg\"]','[]','testtttttt','draft','2026-01-24 06:20:07',NULL,NULL,NULL,'2026-01-23 14:20:07','2026-01-23 14:20:07'),
(2,5,8,'[\"post_incident_reports\\/field_report_5_1769206834_0.jpg\"]','[]','testtttttt','completed','2026-01-24 06:20:34',NULL,NULL,NULL,'2026-01-23 14:20:34','2026-01-23 14:20:34');

/*Table structure for table `registration_attempts` */

DROP TABLE IF EXISTS `registration_attempts`;

CREATE TABLE `registration_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `attempt_time` datetime NOT NULL,
  `successful` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`,`attempt_time`),
  KEY `idx_time` (`attempt_time`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `registration_attempts` */

insert  into `registration_attempts`(`id`,`ip_address`,`email`,`attempt_time`,`successful`) values 
(9,'::1','stephenviray12@gmail.com','2025-11-03 20:26:02',1),
(10,'::1','yenajigumina12@gmail.com','2026-01-20 14:54:34',1);

/*Table structure for table `resources` */

DROP TABLE IF EXISTS `resources`;

CREATE TABLE `resources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `external_id` int(11) DEFAULT NULL COMMENT 'ID from ER API',
  `resource_name` varchar(255) NOT NULL,
  `resource_type` enum('Vehicle','Tool','Equipment','Supply','PPE','Other') NOT NULL,
  `category` enum('Firefighting','Medical','Rescue','PPE','Communication','Other') DEFAULT 'Other',
  `vehicle_type` varchar(100) DEFAULT NULL,
  `emergency_type` varchar(100) DEFAULT NULL,
  `equipment_list` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `available_quantity` int(11) DEFAULT NULL,
  `unit_of_measure` varchar(50) DEFAULT NULL COMMENT 'pcs, units, liters, etc.',
  `condition_status` enum('Serviceable','Under Maintenance','Condemned','Out of Service') DEFAULT 'Serviceable',
  `location` varchar(255) DEFAULT NULL,
  `storage_area` varchar(100) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL COMMENT 'Assigned to which unit',
  `last_inspection` date DEFAULT NULL,
  `next_inspection` date DEFAULT NULL,
  `maintenance_notes` text DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_price` decimal(10,2) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `model_number` varchar(100) DEFAULT NULL,
  `manufacturer` varchar(100) DEFAULT NULL,
  `supplier` varchar(100) DEFAULT NULL,
  `minimum_stock_level` int(11) DEFAULT 0,
  `reorder_quantity` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sync_status` enum('synced','pending','failed') DEFAULT 'pending',
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_external_id` (`external_id`),
  KEY `idx_resource_type` (`resource_type`),
  KEY `idx_category` (`category`),
  KEY `idx_condition_status` (`condition_status`),
  KEY `idx_sync_status` (`sync_status`),
  KEY `fk_resources_unit` (`unit_id`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `fk_resources_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `resources` */

insert  into `resources`(`id`,`external_id`,`resource_name`,`resource_type`,`category`,`vehicle_type`,`emergency_type`,`equipment_list`,`description`,`quantity`,`available_quantity`,`unit_of_measure`,`condition_status`,`location`,`storage_area`,`unit_id`,`last_inspection`,`next_inspection`,`maintenance_notes`,`purchase_date`,`purchase_price`,`serial_number`,`model_number`,`manufacturer`,`supplier`,`minimum_stock_level`,`reorder_quantity`,`is_active`,`sync_status`,`last_sync_at`,`created_at`,`updated_at`) values 
(10,2,'Medical Emergency','Vehicle','Medical',NULL,NULL,NULL,'{\"equipment_list\":[{\"name\":\"Medical kit (complete)\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Stretcher\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Oxygen tanks\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Defibrillator\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Splints and bandages\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Extrication tools\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"First aid supplies\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Communication equipment\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"IV fluids\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Medications\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Blood pressure monitor\",\"is_mandatory\":false,\"is_recommended\":false},{\"name\":\"Pulse oximeter\",\"is_mandatory\":false,\"is_recommended\":false}],\"mandatory_items\":[\"Medical kit (complete)\",\"Stretcher\",\"Oxygen tanks\",\"Defibrillator\",\"Communication equipment\"],\"recommended_items\":[\"Splints and bandages\",\"Extrication tools\",\"First aid supplies\",\"IV fluids\",\"Medications\"],\"stats\":{\"total_items\":12,\"mandatory_count\":5,\"recommended_count\":5,\"completeness_percentage\":100,\"categories_count\":0}}',12,3,NULL,'Serviceable',NULL,NULL,NULL,NULL,NULL,'\n2026-01-22 16:14:06 - DAMAGE REPORTED:\nType: mechanical_failure\nSeverity: moderate\nDescription: test\nAffected Quantity: 3\nPrevious Available: 12\nNew Available: 9\nReported By: Employee ID 8 (Stephen Kyle Viray)\nIncident ID: 19\nUrgency: medium\nAdditional Notes: test\n\n2026-01-22 16:31:19 - DAMAGE REPORTED:\nType: mechanical_failure\nSeverity: moderate\nDescription: test\nAffected Quantity: 3\nPrevious Available: 9\nNew Available: 6\nReported By: Employee ID 8 (Stephen Kyle Viray)\nIncident ID: 19\nUrgency: medium\nAdditional Notes: test\n\n2026-01-22 16:31:47 - DAMAGE REPORTED:\nType: mechanical_failure\nSeverity: moderate\nDescription: test\nAffected Quantity: 3\nPrevious Available: 6\nNew Available: 3\nReported By: Employee ID 8 (Stephen Kyle Viray)\nIncident ID: 19\nUrgency: medium\nAdditional Notes: test\n',NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,1,'synced','2026-02-21 11:07:59','2026-01-21 10:13:32','2026-02-21 11:07:59'),
(11,1,'Fire Response Full','Vehicle','Firefighting',NULL,NULL,NULL,'{\"equipment_list\":[{\"name\":\"Fire hoses (all lengths)\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Water tank full\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Pump operational\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Ladders secured\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Breathing apparatus\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Firefighting foam\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Emergency lights\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Communication radios\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Turnout gear\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Hydrant wrenches\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Heat sensors\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Gas detectors\",\"is_mandatory\":false,\"is_recommended\":false}],\"mandatory_items\":[\"Fire hoses (all lengths)\",\"Water tank full\",\"Pump operational\",\"Ladders secured\",\"Breathing apparatus\",\"Communication radios\"],\"recommended_items\":[\"Firefighting foam\",\"Emergency lights\",\"Turnout gear\",\"Hydrant wrenches\",\"Heat sensors\"],\"stats\":{\"total_items\":12,\"mandatory_count\":6,\"recommended_count\":5,\"completeness_percentage\":100,\"categories_count\":0}}',12,0,NULL,'Serviceable',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,1,'synced','2026-02-21 11:07:59','2026-01-21 10:13:32','2026-02-21 11:07:59'),
(12,4,'Law Enforcement','Other','Other',NULL,NULL,NULL,'{\"equipment_list\":[{\"name\":\"Firearms\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Ammunition\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Body armor\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Handcuffs\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Communication radios\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"First aid kit\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Flashlights\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Evidence kits\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Taser\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Pepper spray\",\"is_mandatory\":false,\"is_recommended\":false},{\"name\":\"Dash camera\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Computer terminal\",\"is_mandatory\":false,\"is_recommended\":false}],\"mandatory_items\":[\"Firearms\",\"Body armor\",\"Handcuffs\",\"Communication radios\",\"First aid kit\"],\"recommended_items\":[\"Ammunition\",\"Flashlights\",\"Evidence kits\",\"Taser\",\"Dash camera\"],\"stats\":{\"total_items\":12,\"mandatory_count\":5,\"recommended_count\":5,\"completeness_percentage\":100,\"categories_count\":0}}',12,12,NULL,'Serviceable',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,1,'synced','2026-02-21 11:07:59','2026-01-21 10:13:32','2026-02-21 11:07:59'),
(13,3,'Search and Rescue','Vehicle','Rescue',NULL,NULL,NULL,'{\"equipment_list\":[{\"name\":\"Extrication tools\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Ropes and harnesses\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Stokes basket\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Medical kit\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Communication gear\",\"is_mandatory\":true,\"is_recommended\":false},{\"name\":\"Lighting equipment\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Power tools\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Generators\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Tents\",\"is_mandatory\":false,\"is_recommended\":true},{\"name\":\"Water supplies\",\"is_mandatory\":false,\"is_recommended\":false},{\"name\":\"Food rations\",\"is_mandatory\":false,\"is_recommended\":false},{\"name\":\"Navigation equipment\",\"is_mandatory\":false,\"is_recommended\":true}],\"mandatory_items\":[\"Extrication tools\",\"Ropes and harnesses\",\"Stokes basket\",\"Communication gear\",\"Medical kit\"],\"recommended_items\":[\"Lighting equipment\",\"Power tools\",\"Generators\",\"Tents\",\"Navigation equipment\"],\"stats\":{\"total_items\":12,\"mandatory_count\":5,\"recommended_count\":5,\"completeness_percentage\":100,\"categories_count\":0}}',12,12,NULL,'Serviceable',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,1,'synced','2026-02-21 11:07:59','2026-01-21 10:13:32','2026-02-21 11:07:59');

/*Table structure for table `service_history` */

DROP TABLE IF EXISTS `service_history`;

CREATE TABLE `service_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_id` int(11) NOT NULL,
  `maintenance_id` int(11) DEFAULT NULL,
  `service_type` varchar(100) NOT NULL,
  `service_date` date NOT NULL,
  `next_service_date` date DEFAULT NULL,
  `performed_by` varchar(100) DEFAULT NULL,
  `performed_by_id` int(11) DEFAULT NULL,
  `service_provider` varchar(100) DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `parts_replaced` text DEFAULT NULL,
  `labor_hours` decimal(5,2) DEFAULT NULL,
  `service_notes` text DEFAULT NULL,
  `status_after_service` enum('Serviceable','Under Maintenance','Condemned') DEFAULT 'Serviceable',
  `documentation` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `resource_id` (`resource_id`),
  KEY `maintenance_id` (`maintenance_id`),
  KEY `performed_by_id` (`performed_by_id`),
  CONSTRAINT `service_history_ibfk_1` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_history_ibfk_2` FOREIGN KEY (`maintenance_id`) REFERENCES `maintenance_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_history_ibfk_3` FOREIGN KEY (`performed_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `service_history` */

insert  into `service_history`(`id`,`resource_id`,`maintenance_id`,`service_type`,`service_date`,`next_service_date`,`performed_by`,`performed_by_id`,`service_provider`,`cost`,`parts_replaced`,`labor_hours`,`service_notes`,`status_after_service`,`documentation`,`created_at`,`updated_at`) values 
(1,11,2,'resource_usage','2026-01-22',NULL,NULL,8,NULL,NULL,NULL,NULL,'Usage Type: routine_check\nQuantity Used: 6\nNotes: test','Serviceable',NULL,'2026-01-22 06:51:39','2026-01-22 06:51:39'),
(2,11,3,'resource_usage','2026-01-22',NULL,NULL,8,NULL,NULL,NULL,NULL,'Usage Type: routine_check\nQuantity Used: 6\nNotes: test','Serviceable',NULL,'2026-01-22 06:51:41','2026-01-22 06:51:41'),
(3,10,4,'damage_report','2026-01-22',NULL,NULL,8,NULL,6000.00,'Damage reported - requires repair/replacement',5.00,'DAMAGE REPORTED\n===============\nDamage Type: mechanical_failure\nSeverity: moderate\nDescription: test\nAffected Quantity: 3\nPrevious Available Quantity: 12\nNew Available Quantity: 9\nUrgency Level: medium\nUnit ID: 9\nIncident ID: 19\nEstimated Repair Cost: ₱6,000.00\nEstimated Repair Time: 5 days\nAdditional Notes: test\n','Under Maintenance',NULL,'2026-01-22 07:14:06','2026-01-22 07:14:06');

/*Table structure for table `shift_change_requests` */

DROP TABLE IF EXISTS `shift_change_requests`;

CREATE TABLE `shift_change_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `request_type` enum('time_change','date_change','swap','other') NOT NULL,
  `request_details` text NOT NULL,
  `proposed_date` date DEFAULT NULL,
  `proposed_start_time` time DEFAULT NULL,
  `proposed_end_time` time DEFAULT NULL,
  `swap_with_volunteer_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shift_id` (`shift_id`),
  KEY `volunteer_id` (`volunteer_id`),
  KEY `swap_with_volunteer_id` (`swap_with_volunteer_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `shift_change_requests_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shift_change_requests_ibfk_2` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shift_change_requests_ibfk_3` FOREIGN KEY (`swap_with_volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `shift_change_requests_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `shift_change_requests` */

insert  into `shift_change_requests`(`id`,`shift_id`,`volunteer_id`,`request_type`,`request_details`,`proposed_date`,`proposed_start_time`,`proposed_end_time`,`swap_with_volunteer_id`,`status`,`admin_notes`,`requested_at`,`reviewed_at`,`reviewed_by`) values 
(12,135,13,'time_change','421','2026-02-10','07:00:00','19:00:00',NULL,'approved','test','2026-01-16 09:16:11','2026-01-17 01:17:01',8);

/*Table structure for table `shift_confirmations` */

DROP TABLE IF EXISTS `shift_confirmations`;

CREATE TABLE `shift_confirmations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `status` enum('pending','confirmed','declined') DEFAULT 'pending',
  `response_notes` text DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_shift_volunteer` (`shift_id`,`volunteer_id`),
  KEY `volunteer_id` (`volunteer_id`),
  CONSTRAINT `shift_confirmations_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shift_confirmations_ibfk_2` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `shift_confirmations` */

insert  into `shift_confirmations`(`id`,`shift_id`,`volunteer_id`,`status`,`response_notes`,`responded_at`,`created_at`) values 
(9,131,13,'confirmed',NULL,'2026-01-17 00:54:44','2026-01-16 08:54:44'),
(10,135,13,'confirmed','Time/Date change approved Time/Date change approved','2026-01-17 01:17:01','2026-01-16 09:17:00');

/*Table structure for table `shifts` */

DROP TABLE IF EXISTS `shifts`;

CREATE TABLE `shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'Employee ID (NULL for volunteers)',
  `volunteer_id` int(11) DEFAULT NULL,
  `shift_for` enum('user','volunteer') DEFAULT 'user' COMMENT 'user = employee shift, volunteer = volunteer shift',
  `unit_id` int(11) DEFAULT NULL COMMENT 'Assigned unit',
  `duty_assignment_id` int(11) DEFAULT NULL,
  `shift_date` date NOT NULL COMMENT 'Date of shift',
  `shift_type` enum('morning','afternoon','evening','night','full_day') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('scheduled','confirmed','in_progress','completed','cancelled','absent') DEFAULT 'scheduled',
  `location` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'Who scheduled this shift',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `confirmation_status` enum('pending','confirmed','declined','change_requested') DEFAULT 'pending',
  `confirmed_at` datetime DEFAULT NULL,
  `declined_reason` text DEFAULT NULL,
  `change_request_notes` text DEFAULT NULL,
  `late_threshold` int(11) DEFAULT 15 COMMENT 'Minutes allowed before marked as late',
  `attendance_marked_by` int(11) DEFAULT NULL,
  `attendance_marked_at` datetime DEFAULT NULL,
  `attendance_status` enum('pending','checked_in','checked_out','absent','excused') DEFAULT 'pending',
  `check_in_time` datetime DEFAULT NULL,
  `check_out_time` datetime DEFAULT NULL,
  `attendance_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_shift_date` (`shift_date`),
  KEY `idx_status` (`status`),
  KEY `fk_shifts_unit` (`unit_id`),
  KEY `fk_shifts_created_by` (`created_by`),
  KEY `idx_volunteer_id` (`volunteer_id`),
  KEY `idx_shift_for` (`shift_for`),
  KEY `idx_duty_assignment` (`duty_assignment_id`),
  KEY `idx_shifts_today` (`shift_date`,`status`,`confirmation_status`),
  KEY `idx_shifts_volunteer` (`volunteer_id`,`shift_date`),
  KEY `idx_shifts_attendance` (`shift_date`,`attendance_status`,`volunteer_id`),
  CONSTRAINT `fk_shifts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shifts_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shifts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shifts_volunteer` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=154 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `shifts` */

insert  into `shifts`(`id`,`user_id`,`volunteer_id`,`shift_for`,`unit_id`,`duty_assignment_id`,`shift_date`,`shift_type`,`start_time`,`end_time`,`status`,`location`,`notes`,`created_by`,`created_at`,`updated_at`,`confirmation_status`,`confirmed_at`,`declined_reason`,`change_request_notes`,`late_threshold`,`attendance_marked_by`,`attendance_marked_at`,`attendance_status`,`check_in_time`,`check_out_time`,`attendance_notes`) values 
(131,10,13,'volunteer',3,1,'2026-01-19','morning','06:00:00','14:00:00','completed','Main Station','testtt',8,'2026-01-15 14:55:50','2026-01-16 12:10:10','confirmed','2026-01-17 00:54:44',NULL,NULL,15,NULL,NULL,'checked_out','2026-01-16 21:10:04','2026-01-16 21:10:10',NULL),
(135,10,13,'volunteer',8,5,'2026-02-10','morning','07:00:00','19:00:00','confirmed','Main Station','testt',8,'2026-01-16 09:14:57','2026-01-16 09:17:01','confirmed','2026-01-17 01:17:01',NULL,'421',15,NULL,NULL,'pending',NULL,NULL,NULL),
(136,10,13,'volunteer',5,6,'2026-01-23','morning','06:00:00','14:00:00','scheduled','Main Station','aliannaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',8,'2026-01-23 04:45:24','2026-01-23 04:45:24','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(137,10,13,'volunteer',5,7,'2026-01-23','morning','06:00:00','14:00:00','scheduled','Main Station','aliannaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',8,'2026-01-23 05:14:47','2026-01-23 05:14:47','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(138,10,13,'volunteer',1,8,'2026-01-23','evening','18:00:00','02:00:00','scheduled','Main Station','marcussssssssssssssssssssssssssssss',8,'2026-01-23 05:15:03','2026-01-23 05:15:03','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(139,10,13,'volunteer',10,9,'2026-01-23','morning','06:00:00','14:00:00','scheduled','Main Station','dadangggggggggggggggggggggggggggggg',8,'2026-01-23 05:28:23','2026-01-23 05:28:23','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(140,10,13,'volunteer',10,10,'2026-01-23','morning','06:00:00','14:00:00','scheduled','Main Station','andyyyyyyyyyyyyyyyyyyyyyyyyyyyyy',8,'2026-01-23 05:29:36','2026-01-23 05:29:36','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(141,10,13,'volunteer',2,11,'2026-01-26','evening','18:00:00','02:00:00','scheduled','Main Station','marcos gabutero',8,'2026-01-23 13:50:56','2026-01-23 13:50:56','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(146,10,13,'volunteer',8,16,'2026-01-24','afternoon','14:00:00','22:00:00','scheduled','Main Station','clarisaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',8,'2026-01-24 06:04:35','2026-01-24 06:04:35','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(147,10,13,'volunteer',8,17,'2026-01-24','afternoon','14:00:00','22:00:00','scheduled','Main Station','eopooooooooooooooo',8,'2026-01-24 06:12:24','2026-01-24 06:12:24','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(148,10,13,'volunteer',8,18,'2026-01-24','afternoon','14:00:00','22:00:00','scheduled','Main Station','481741741741',8,'2026-01-24 06:14:58','2026-01-24 06:14:58','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(149,10,13,'volunteer',8,19,'2026-01-24','afternoon','14:00:00','22:00:00','scheduled','Main Station','jeffffffffffffffffffffffffffffffffffffff',8,'2026-01-24 06:24:38','2026-01-24 06:24:38','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(150,10,13,'volunteer',8,20,'2026-01-24','afternoon','14:00:00','22:00:00','scheduled','Main Station','dozaaaaaaaaaaaaa',8,'2026-01-24 06:27:57','2026-01-24 06:27:57','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(151,10,13,'volunteer',8,21,'2026-01-24','full_day','08:00:00','17:00:00','scheduled','Main Station','789789789789',8,'2026-01-24 06:30:39','2026-01-24 06:30:39','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(152,10,13,'volunteer',9,22,'2026-02-02','afternoon','14:00:00','22:00:00','scheduled','Main Station','11111111111111111111111111111111111111111111',8,'2026-02-01 20:41:43','2026-02-01 20:41:43','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL),
(153,10,13,'volunteer',9,23,'2026-02-02','afternoon','14:00:00','22:00:00','scheduled','Main Station','marcosssssss',8,'2026-02-01 20:43:16','2026-02-01 20:43:16','pending',NULL,NULL,NULL,15,NULL,NULL,'pending',NULL,NULL,NULL);

/*Table structure for table `sms_logs` */

DROP TABLE IF EXISTS `sms_logs`;

CREATE TABLE `sms_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `sent_at` datetime DEFAULT current_timestamp(),
  `response` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `sms_logs` */

insert  into `sms_logs`(`id`,`recipient`,`message`,`status`,`sent_at`,`response`) values 
(1,'09984319585','Reminder: You have a shift on 2026-01-18 at 06:00:00 - Main Station. Please confirm your availability.','sent','2026-01-16 03:32:24',NULL);

/*Table structure for table `sync_queue` */

DROP TABLE IF EXISTS `sync_queue`;

CREATE TABLE `sync_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `operation` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `table_name` varchar(50) NOT NULL,
  `record_id` int(11) NOT NULL,
  `data_json` longtext NOT NULL,
  `source` enum('cloud','local') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed` tinyint(1) DEFAULT 0,
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pending` (`processed`,`table_name`),
  KEY `idx_source_table` (`source`,`table_name`,`record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `sync_queue` */

/*Table structure for table `sync_status` */

DROP TABLE IF EXISTS `sync_status`;

CREATE TABLE `sync_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `table_name` varchar(50) NOT NULL,
  `last_sync_id` int(11) DEFAULT 0,
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `direction` enum('cloud_to_local','local_to_cloud','bidirectional') DEFAULT 'bidirectional',
  `sync_enabled` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_table` (`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `sync_status` */

/*Table structure for table `training_certificates` */

DROP TABLE IF EXISTS `training_certificates`;

CREATE TABLE `training_certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `registration_id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `training_id` int(11) NOT NULL,
  `certificate_number` varchar(50) NOT NULL,
  `issue_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `certificate_file` varchar(255) DEFAULT NULL,
  `certificate_data` text DEFAULT NULL,
  `issued_by` int(11) DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_certificate_number` (`certificate_number`),
  KEY `idx_registration_id` (`registration_id`),
  KEY `idx_volunteer_id` (`volunteer_id`),
  KEY `idx_training_id` (`training_id`),
  CONSTRAINT `fk_certificate_registration` FOREIGN KEY (`registration_id`) REFERENCES `training_registrations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_certificate_training` FOREIGN KEY (`training_id`) REFERENCES `trainings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_certificate_volunteer` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `training_certificates` */

insert  into `training_certificates`(`id`,`registration_id`,`volunteer_id`,`training_id`,`certificate_number`,`issue_date`,`expiry_date`,`certificate_file`,`certificate_data`,`issued_by`,`issued_at`,`verified`,`verified_by`,`verified_at`) values 
(10,12,13,8,'CERT-20260127-7698','2026-01-27','2027-01-27','uploads/certificates/certificate_12_1769520212.pdf',NULL,11,'2026-01-27 05:23:32',1,NULL,NULL);

/*Table structure for table `training_registrations` */

DROP TABLE IF EXISTS `training_registrations`;

CREATE TABLE `training_registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `training_id` int(11) NOT NULL,
  `volunteer_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('registered','attending','completed','cancelled','no_show') DEFAULT 'registered',
  `completion_status` enum('not_started','in_progress','completed','failed') DEFAULT 'not_started',
  `completion_date` date DEFAULT NULL,
  `certificate_issued` tinyint(1) DEFAULT 0,
  `certificate_path` varchar(255) DEFAULT NULL,
  `certificate_issued_at` datetime DEFAULT NULL,
  `admin_approved` tinyint(1) DEFAULT 0,
  `admin_approved_by` int(11) DEFAULT NULL,
  `admin_approved_at` datetime DEFAULT NULL,
  `employee_submitted` tinyint(1) DEFAULT 0,
  `employee_submitted_by` int(11) DEFAULT NULL,
  `employee_submitted_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `completion_verified` tinyint(1) DEFAULT 0,
  `completion_verified_by` int(11) DEFAULT NULL,
  `completion_verified_at` datetime DEFAULT NULL,
  `completion_proof` varchar(255) DEFAULT NULL,
  `completion_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_training_volunteer` (`training_id`,`volunteer_id`),
  KEY `idx_training_id` (`training_id`),
  KEY `idx_volunteer_id` (`volunteer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_completion` (`completion_status`),
  KEY `completion_verified_by` (`completion_verified_by`),
  KEY `employee_submitted_by` (`employee_submitted_by`),
  CONSTRAINT `fk_training_reg_training` FOREIGN KEY (`training_id`) REFERENCES `trainings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_training_reg_volunteer` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `training_registrations_ibfk_1` FOREIGN KEY (`completion_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `training_registrations_ibfk_2` FOREIGN KEY (`employee_submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `training_registrations` */

insert  into `training_registrations`(`id`,`training_id`,`volunteer_id`,`user_id`,`registration_date`,`status`,`completion_status`,`completion_date`,`certificate_issued`,`certificate_path`,`certificate_issued_at`,`admin_approved`,`admin_approved_by`,`admin_approved_at`,`employee_submitted`,`employee_submitted_by`,`employee_submitted_at`,`notes`,`completion_verified`,`completion_verified_by`,`completion_verified_at`,`completion_proof`,`completion_notes`) values 
(12,8,13,10,'2026-01-20 05:22:51','completed','completed',NULL,1,NULL,'2026-01-27 21:23:32',0,NULL,NULL,0,NULL,NULL,NULL,1,8,'2026-01-27 21:40:13','proof_1769521213_6978c03ddc2a6.jpg','\nEmployee Verification: test\nEmployee Verification: test\nEmployee Verification: test'),
(13,7,13,NULL,'2026-01-20 07:25:24','registered','not_started',NULL,0,NULL,NULL,1,11,'2026-01-20 23:25:24',0,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL);

/*Table structure for table `trainings` */

DROP TABLE IF EXISTS `trainings`;

CREATE TABLE `trainings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `external_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `training_date` date NOT NULL,
  `training_end_date` date DEFAULT NULL,
  `duration_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `instructor` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `max_participants` int(11) DEFAULT 0,
  `current_participants` int(11) DEFAULT 0,
  `status` enum('scheduled','ongoing','completed','cancelled') DEFAULT 'scheduled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_sync_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_training_date` (`training_date`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `trainings` */

insert  into `trainings`(`id`,`external_id`,`title`,`description`,`training_date`,`training_end_date`,`duration_hours`,`instructor`,`location`,`max_participants`,`current_participants`,`status`,`created_at`,`updated_at`,`last_sync_at`) values 
(1,8,'Incident Command System','ICS structure and procedures for managing emergency incidents','2026-02-27','2026-02-28',6.00,'Battalion Chief James Miller','Command Center',30,0,'scheduled','2026-01-19 08:21:18','2026-01-20 07:11:01','2026-01-19 09:40:46'),
(2,5,'Vehicle Extrication','Techniques for extricating victims from vehicle accidents','2026-02-14','2026-02-16',5.00,'Sgt. Michael Brown','Extrication Training Grounds',15,0,'scheduled','2026-01-19 08:21:18','2026-01-20 02:29:24','2026-01-19 09:40:46'),
(3,7,'SCBA Maintenance & Use','Self-Contained Breathing Apparatus maintenance, inspection, and proper usage','2026-02-13','2026-02-14',3.50,'Engineer Lisa Thompson','Equipment Training Room',20,0,'scheduled','2026-01-19 08:21:18','2026-01-20 02:29:26','2026-01-19 09:40:46'),
(4,1,'Fire Safety Basics','Introduction to fire safety protocols and basic firefighting techniques','2026-02-08','2026-02-09',4.50,'Capt. John Smith','Main Fire Station',30,0,'scheduled','2026-01-19 08:21:18','2026-01-20 02:29:29','2026-01-19 09:40:46'),
(5,4,'Emergency Medical Response','First responder medical training and trauma care','2026-02-01','2026-02-03',7.50,'Dr. Sarah Johnson','Medical Training Center',35,0,'scheduled','2026-01-19 08:21:18','2026-01-20 02:29:32','2026-01-19 09:40:46'),
(6,10,'Building Collapse Search','Search and rescue operations in structural collapse scenarios','2026-01-29','2026-01-30',9.00,'Special Ops Captain Thomas Reed','Collapse Training Structure',15,1,'scheduled','2026-01-19 00:21:18','2026-01-24 01:04:30','2026-01-19 01:40:46'),
(7,6,'Wildland Firefighting','Techniques for combating wildfires in forest and grassland environments','2026-01-28','2026-01-29',10.00,'Captain David Wilson','Forest Training Area',25,1,'scheduled','2026-01-19 08:21:18','2026-01-20 07:25:24','2026-01-19 09:40:46'),
(8,2,'Advanced Rescue Techniques','Advanced rope rescue and confined space rescue training','2026-01-25','2026-01-26',8.00,'Lt. Maria Garcia','Training Center A',20,0,'completed','2026-01-19 08:21:18','2026-01-20 07:10:22','2026-01-19 09:40:46');

/*Table structure for table `units` */

DROP TABLE IF EXISTS `units`;

CREATE TABLE `units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unit_name` varchar(100) NOT NULL,
  `unit_code` varchar(20) NOT NULL,
  `unit_type` enum('Fire','Rescue','EMS','Logistics','Command') NOT NULL,
  `location` varchar(100) NOT NULL,
  `status` enum('Active','Inactive','Maintenance') DEFAULT 'Active',
  `capacity` int(11) DEFAULT 0,
  `current_count` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `current_status` enum('available','dispatched','unavailable','maintenance') DEFAULT 'available',
  `current_dispatch_id` int(11) DEFAULT NULL,
  `last_status_change` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unit_code` (`unit_code`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `units` */

insert  into `units`(`id`,`unit_name`,`unit_code`,`unit_type`,`location`,`status`,`capacity`,`current_count`,`description`,`created_at`,`updated_at`,`current_status`,`current_dispatch_id`,`last_status_change`) values 
(1,'Commonwealth Fire Unit 1','CFU-001','Fire','Brgy. Commonwealth, Near Market','Active',15,6,'Primary fire response unit for Commonwealth area','2025-11-18 16:28:29','2026-02-21 11:13:02','dispatched',19,'2026-01-25 07:51:11'),
(2,'Commonwealth Rescue Team A','CRT-A','Rescue','Brgy. Commonwealth, Main Road','Active',10,0,'Search and rescue operations team','2025-11-18 16:28:29','2026-01-31 06:46:12','dispatched',18,'2026-01-31 06:46:12'),
(3,'Commonwealth EMS Unit','CEMS-01','EMS','Brgy. Commonwealth Health Center','Active',8,1,'Emergency medical services unit','2025-11-19 00:28:29','2026-01-13 08:52:39','available',NULL,'2026-01-13 08:52:39'),
(4,'Commonwealth Logistics Support','CLS-01','Logistics','Brgy. Commonwealth HQ','Active',12,2,'Equipment and logistics support team','2025-11-19 00:28:29','2026-02-21 11:12:56','available',NULL,'2026-01-13 08:52:39'),
(5,'Commonwealth Command Center','CCC-01','Command','Brgy. Commonwealth Hall','Active',5,0,'Incident command and coordination','2025-11-19 00:28:29','2026-01-13 08:52:39','available',NULL,'2026-01-13 08:52:39'),
(6,'Commonwealth Fire Unit 2','CFU-002','Fire','Brgy. Commonwealth, Batasan Area','Active',12,0,'Secondary fire response unit','2025-11-19 00:28:29','2026-01-14 08:58:19','available',15,'2026-01-14 08:58:19'),
(7,'Commonwealth Rescue Team B','CRT-B','Rescue','Brgy. Commonwealth, Payatas Area','Active',8,0,'Secondary rescue operations team','2025-11-19 00:28:29','2026-01-14 07:52:32','available',14,'2026-01-14 07:52:32'),
(8,'Commonwealth Community Response','CCR-01','EMS','Brgy. Commonwealth, Various Locations','Active',15,0,'Community emergency response team','2025-11-19 00:28:29','2026-01-13 08:52:39','available',NULL,'2026-01-13 08:52:39'),
(9,'Commonwealth Equipment Unit','CEQ-01','Logistics','Brgy. Commonwealth Storage','Active',6,0,'Equipment maintenance and management','2025-11-19 00:28:29','2026-01-13 08:52:39','available',NULL,'2026-01-13 08:52:39'),
(10,'Commonwealth Communications','CCOM-01','Command','Brgy. Commonwealth HQ','Active',4,0,'Communications and dispatch support','2025-11-19 00:28:29','2026-01-13 08:52:39','available',NULL,'2026-01-13 08:52:39');

/*Table structure for table `users` */

DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `username` varchar(50) NOT NULL,
  `contact` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `date_of_birth` date NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('ADMIN','EMPLOYEE','USER') DEFAULT 'USER',
  `is_verified` tinyint(1) DEFAULT 0,
  `verification_code` varchar(10) DEFAULT NULL,
  `code_expiry` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reset_token` varchar(64) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_reset_token` (`reset_token`),
  KEY `idx_token_expiry` (`token_expiry`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `users` */

insert  into `users`(`id`,`first_name`,`middle_name`,`last_name`,`username`,`contact`,`address`,`date_of_birth`,`email`,`password`,`role`,`is_verified`,`verification_code`,`code_expiry`,`created_at`,`updated_at`,`reset_token`,`avatar`,`token_expiry`) values 
(8,'Stephen','Kyle','Viray','Yukki','09984319585','054 gold extention\r\nbaranggay commonwelth qc','2004-02-10','stephenviray12@gmail.com','$2y$12$2a9p/WXFMFFzVjydxkjuYOumacEXvfZfSf2uhAf7d7lIe8YJcVuO6','EMPLOYEE',1,NULL,NULL,'2025-11-03 04:26:02','2025-11-26 07:17:43',NULL,'avatar_8_1763866448.jpg',NULL),
(10,'zaldy','g','solis','yukki1','09984319585','054 gold extention\r\nbaranggay commonwelth qc','2003-02-10','stephenviray121111@gmail.com','$2y$12$JmfpASpwVdSAa/d7uZ9og.FYVnA66Y2sX4cczKiU06m46ODfDwgzq','USER',1,NULL,NULL,'2026-01-14 07:41:08','2026-01-18 11:18:41',NULL,'avatar_10_1768763921.jpg',NULL),
(11,'Mariefee','S','Baturi','riri','09984319585','054 gold extention','2004-02-29','yenajigumina12@gmail.com','$2y$12$hs2Wez9y2UgIE68VxrDQNup9PcDEPOWY02GHKzl2L6VqIPYu.fd4m','ADMIN',1,NULL,NULL,'2026-01-19 22:54:34','2026-01-20 23:44:17',NULL,NULL,NULL),
(12,'Mariefe','s','baturi','sahdjsjahsjd','09984319585','54 vgold extention barnaggay cmonnwelath qckjhaskdjhakjhdkjadsaa','2004-02-10','sahdjsjahsjd@gmail.com','$2y$12$3iShBezAg8ad7g3U2p05KuQGpjtmi1Xqomcdbmz1pd1fx6.haqzH6','USER',1,NULL,NULL,'2026-01-24 00:57:47','2026-01-24 00:58:31',NULL,NULL,NULL);

/*Table structure for table `vehicle_status` */

DROP TABLE IF EXISTS `vehicle_status`;

CREATE TABLE `vehicle_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(11) NOT NULL,
  `vehicle_name` varchar(100) NOT NULL,
  `vehicle_type` varchar(50) NOT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `dispatch_id` int(11) DEFAULT NULL,
  `suggestion_id` int(11) DEFAULT NULL,
  `status` enum('available','suggested','dispatched','maintenance','out_of_service') DEFAULT 'available',
  `current_location` varchar(255) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vehicle_status` (`status`),
  KEY `idx_vehicle_unit` (`unit_id`),
  KEY `idx_vehicle_dispatch` (`dispatch_id`),
  KEY `idx_vehicle_suggestion` (`suggestion_id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `vehicle_status` */

insert  into `vehicle_status`(`id`,`vehicle_id`,`vehicle_name`,`vehicle_type`,`unit_id`,`dispatch_id`,`suggestion_id`,`status`,`current_location`,`last_updated`) values 
(11,1,'Fire Truck 1','Fire',1,13,13,'dispatched',NULL,'2026-01-21 06:56:50'),
(12,5,'Fire Truck 5','Fire',1,NULL,NULL,'available',NULL,'2026-01-14 04:02:14'),
(13,6,'Ambulance 1','Rescue',7,14,14,'dispatched',NULL,'2026-01-21 06:56:50'),
(14,5,'Fire Truck 5','Fire',6,15,15,'dispatched',NULL,'2026-01-21 06:56:50'),
(15,4,'Fire Truck 4','Fire',6,15,15,'dispatched',NULL,'2026-01-21 06:56:50'),
(16,8,'Ambulance 3','Rescue',2,16,16,'dispatched',NULL,'2026-01-21 06:56:50'),
(17,7,'Ambulance 2','Rescue',2,NULL,18,'suggested',NULL,'2026-01-23 12:24:43');

/*Table structure for table `verification_codes` */

DROP TABLE IF EXISTS `verification_codes`;

CREATE TABLE `verification_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `code` varchar(10) NOT NULL,
  `expiry` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `verification_codes` */

insert  into `verification_codes`(`id`,`email`,`code`,`expiry`,`created_at`) values 
(8,'stephenviray12@gmail.com','713642','2025-11-03 13:41:01','2025-11-03 04:26:02'),
(9,'stephenviray12@gmail.com','491175','2025-11-03 13:56:17','2025-11-03 04:26:17'),
(10,'stephenviray12@gmail.com','589667','2025-11-03 14:13:52','2025-11-03 04:43:52'),
(11,'stephenviray12@gmail.com','787000','2025-11-03 14:14:35','2025-11-03 04:44:35'),
(13,'stephenviray12@gmail.com','073181','2025-11-03 14:24:16','2025-11-03 04:54:16'),
(14,'stephenviray12@gmail.com','481594','2025-11-03 14:24:42','2025-11-03 04:54:42'),
(15,'stephenviray12@gmail.com','311995','2025-11-03 14:25:50','2025-11-03 04:55:50'),
(16,'stephenviray12@gmail.com','536095','2025-11-03 14:26:24','2025-11-03 04:56:24'),
(18,'stephenviray12@gmail.com','194171','2025-11-03 15:49:25','2025-11-03 06:19:25'),
(19,'stephenviray12@gmail.com','335715','2025-11-03 16:41:34','2025-11-03 07:11:34'),
(20,'stephenviray12@gmail.com','801337','2025-11-03 16:41:53','2025-11-03 07:11:53');

/*Table structure for table `volunteer_assignments` */

DROP TABLE IF EXISTS `volunteer_assignments`;

CREATE TABLE `volunteer_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `volunteer_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assignment_date` date NOT NULL,
  `status` enum('Active','Inactive','Transferred') DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `volunteer_id` (`volunteer_id`),
  KEY `unit_id` (`unit_id`),
  KEY `assigned_by` (`assigned_by`),
  CONSTRAINT `volunteer_assignments_ibfk_1` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `volunteer_assignments_ibfk_2` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `volunteer_assignments_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `volunteer_assignments` */

insert  into `volunteer_assignments`(`id`,`volunteer_id`,`unit_id`,`assigned_by`,`assignment_date`,`status`,`notes`,`created_at`,`updated_at`) values 
(6,7,4,8,'2025-11-20','Active',NULL,'2025-11-19 13:27:22','2025-11-19 13:27:22'),
(7,2,1,8,'2025-11-20','Active',NULL,'2025-11-19 13:28:48','2025-11-19 13:28:48'),
(9,10,1,8,'2026-01-13','Active',NULL,'2026-01-12 09:53:42','2026-01-12 09:53:42'),
(10,8,1,8,'2026-01-13','Active',NULL,'2026-01-12 09:53:48','2026-01-12 09:53:48'),
(11,4,3,8,'2026-01-13','Active',NULL,'2026-01-12 09:53:56','2026-01-12 09:53:56'),
(12,1,4,8,'2026-01-13','Active',NULL,'2026-01-12 09:54:04','2026-01-12 09:54:04'),
(13,13,1,8,'2026-01-15','Active',NULL,'2026-01-14 09:52:33','2026-01-14 09:52:33'),
(15,5,1,11,'2026-02-21','Active',NULL,'2026-02-21 11:13:02','2026-02-21 11:13:02');

/*Table structure for table `volunteer_registration_status` */

DROP TABLE IF EXISTS `volunteer_registration_status`;

CREATE TABLE `volunteer_registration_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status` enum('open','closed') NOT NULL DEFAULT 'closed',
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `volunteer_registration_status` */

insert  into `volunteer_registration_status`(`id`,`status`,`updated_by`,`updated_at`) values 
(1,'open',8,'2026-01-08 03:15:18');

/*Table structure for table `volunteer_shifts` */

DROP TABLE IF EXISTS `volunteer_shifts`;

CREATE TABLE `volunteer_shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `volunteer_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime DEFAULT current_timestamp(),
  `status` enum('assigned','confirmed','declined','completed','absent') DEFAULT 'assigned',
  `attendance_marked` tinyint(1) DEFAULT 0,
  `attendance_marked_at` datetime DEFAULT NULL,
  `attendance_marked_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_volunteer_shift` (`volunteer_id`,`shift_id`),
  KEY `idx_volunteer_id` (`volunteer_id`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_volunteer_shifts_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_volunteer_shifts_volunteer` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `volunteer_shifts` */

/*Table structure for table `volunteers` */

DROP TABLE IF EXISTS `volunteers`;

CREATE TABLE `volunteers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `date_of_birth` date NOT NULL,
  `gender` enum('Male','Female','Other') NOT NULL,
  `civil_status` enum('Single','Married','Divorced','Widowed') NOT NULL,
  `address` text NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `social_media` varchar(100) DEFAULT NULL,
  `valid_id_type` varchar(50) NOT NULL,
  `valid_id_number` varchar(50) NOT NULL,
  `id_front_photo` varchar(255) DEFAULT NULL,
  `id_back_photo` varchar(255) DEFAULT NULL,
  `id_front_verified` tinyint(1) DEFAULT 0,
  `id_back_verified` tinyint(1) DEFAULT 0,
  `emergency_contact_name` varchar(100) NOT NULL,
  `emergency_contact_relationship` varchar(50) NOT NULL,
  `emergency_contact_number` varchar(20) NOT NULL,
  `emergency_contact_address` text NOT NULL,
  `volunteered_before` enum('Yes','No') NOT NULL,
  `previous_volunteer_experience` text DEFAULT NULL,
  `volunteer_motivation` text NOT NULL,
  `currently_employed` enum('Yes','No') NOT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `company` varchar(100) DEFAULT NULL,
  `education` varchar(100) NOT NULL,
  `specialized_training` text DEFAULT NULL,
  `physical_fitness` enum('Excellent','Good','Fair') NOT NULL,
  `languages_spoken` varchar(200) NOT NULL,
  `skills_basic_firefighting` tinyint(1) DEFAULT 0,
  `skills_first_aid_cpr` tinyint(1) DEFAULT 0,
  `skills_search_rescue` tinyint(1) DEFAULT 0,
  `skills_driving` tinyint(1) DEFAULT 0,
  `driving_license_no` varchar(50) DEFAULT NULL,
  `skills_communication` tinyint(1) DEFAULT 0,
  `skills_mechanical` tinyint(1) DEFAULT 0,
  `skills_logistics` tinyint(1) DEFAULT 0,
  `available_days` varchar(100) NOT NULL,
  `available_hours` varchar(100) NOT NULL,
  `emergency_response` enum('Yes','No') NOT NULL,
  `area_interest_fire_suppression` tinyint(1) DEFAULT 0,
  `area_interest_rescue_operations` tinyint(1) DEFAULT 0,
  `area_interest_ems` tinyint(1) DEFAULT 0,
  `area_interest_disaster_response` tinyint(1) DEFAULT 0,
  `area_interest_admin_logistics` tinyint(1) DEFAULT 0,
  `declaration_agreed` tinyint(1) NOT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `application_date` date NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `volunteer_status` enum('New Volunteer','Active','Inactive','On Leave') DEFAULT 'New Volunteer',
  `training_completion_status` enum('none','in_progress','completed','certified') DEFAULT 'none',
  `first_training_completed_at` date DEFAULT NULL,
  `active_since` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_email` (`email`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `idx_email` (`email`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_volunteers_status` (`status`),
  KEY `idx_volunteers_created_at` (`created_at`),
  KEY `idx_volunteers_active` (`status`,`volunteer_status`),
  CONSTRAINT `fk_volunteers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `volunteers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `volunteers` */

insert  into `volunteers`(`id`,`user_id`,`first_name`,`middle_name`,`last_name`,`full_name`,`date_of_birth`,`gender`,`civil_status`,`address`,`contact_number`,`email`,`social_media`,`valid_id_type`,`valid_id_number`,`id_front_photo`,`id_back_photo`,`id_front_verified`,`id_back_verified`,`emergency_contact_name`,`emergency_contact_relationship`,`emergency_contact_number`,`emergency_contact_address`,`volunteered_before`,`previous_volunteer_experience`,`volunteer_motivation`,`currently_employed`,`occupation`,`company`,`education`,`specialized_training`,`physical_fitness`,`languages_spoken`,`skills_basic_firefighting`,`skills_first_aid_cpr`,`skills_search_rescue`,`skills_driving`,`driving_license_no`,`skills_communication`,`skills_mechanical`,`skills_logistics`,`available_days`,`available_hours`,`emergency_response`,`area_interest_fire_suppression`,`area_interest_rescue_operations`,`area_interest_ems`,`area_interest_disaster_response`,`area_interest_admin_logistics`,`declaration_agreed`,`signature`,`application_date`,`status`,`volunteer_status`,`training_completion_status`,`first_training_completed_at`,`active_since`,`created_at`,`updated_at`,`notes`) values 
(1,NULL,'stephen','kyle','viray','stephen kyle viray','2004-02-10','Male','Married','054 gold extention\r\nbaranggay commonwelth qc','09984319585','stephenviray12@gmail.com','Spyke Kyle','Passport','asd123123123123',NULL,NULL,0,0,'Mariefe S Baturi','Wife','09984319585','054 gold extention\r\nbaranggay commonwelth qc','No','','asdasd','No','','','College Undergraduate','123asd','Excellent','bisaya',1,1,0,0,'',0,0,0,'Monday,Tuesday,Wednesday','Morning,Afternoon','Yes',1,1,1,0,1,1,'stephen kyle 12','2025-11-13','approved','New Volunteer','none',NULL,NULL,'2025-11-12 15:17:10','2026-01-14 06:22:19',NULL),
(2,NULL,'stephen','kyle','viray','stephen kyle viray','2004-02-10','Male','Married','054 gold extention\r\nbaranggay commonwelth qc','09984319585','stephenvisssray12@gmail.com','asdas','Voter&#039;s ID','123123','uploads/volunteer_id_photos/id_front_1763126462_c4fa8b15f901a947.jpg','uploads/volunteer_id_photos/id_back_1763126462_44b4886dc45049b0.jpg',0,0,'stephen kyle viray','Husband','09984319585','054 gold extention\r\nbaranggay commonwelth qc','No','','asdasdasd','No','','','Vocational','asdasd','Excellent','bisaya',1,0,0,0,'',0,0,0,'Wednesday','Afternoon','No',1,0,0,1,0,1,'stephen kyle viray','2025-11-14','approved','New Volunteer','none',NULL,NULL,'2025-11-14 05:21:02','2026-01-14 06:22:19',NULL),
(3,NULL,'Juan','Dela','Cruz','Juan Dela Cruz','1998-07-21','Male','Single','Brgy Commonwealth, QC','09123456781','juan.cruz@example.com','JuanCruzFB','PhilHealth','PH123456',NULL,NULL,1,1,'Maria Dela Cruz','Mother','09181234567','Same address','No','','To serve the community','Yes','Construction Worker','BuildWell Co.','High School Graduate','','Good','Tagalog,English',1,1,0,0,'',1,0,0,'Monday,Wednesday,Friday','Morning','Yes',1,0,1,0,1,1,'juan sig','2025-11-14','rejected','Active','certified',NULL,'2026-02-21','2025-11-14 06:48:38','2026-02-21 13:10:40','\nApproved as experienced volunteer with 10 years of experience on 2026-02-21'),
(4,NULL,'Maria','','Santos','Maria Santos','2000-03-11','Female','Single','Brgy Holy Spirit, QC','09955667788','maria.santos@example.com','MariaInsta','Driver License','DL987654',NULL,NULL,0,0,'Ana Santos','Sister','09229988776','Pasig City','Yes','School event volunteer','Wants to help during emergencies','No','','','College Undergraduate','Basic First Aid','Excellent','Tagalog',0,1,1,1,'N1234567',1,0,1,'Tuesday,Thursday','Afternoon','Yes',1,1,0,1,0,1,'maria sig','2025-11-14','approved','Active','certified',NULL,'2026-01-20','2025-11-14 06:48:38','2026-01-19 08:57:56',NULL),
(5,NULL,'Mark','','Villanueva','Mark Villanueva','1995-01-05','Male','Married','Brgy Batasan, QC','09187776655','mark.villa@example.com','MarkV','Passport','PS1223344',NULL,NULL,0,0,'Jen Villanueva','Wife','09175554433','Batasan Hills, QC','No','','Wants to volunteer','Yes','Mechanic','AutoFix Shop','Vocational','Automotive Training','Good','Tagalog,English',1,0,1,1,'D9988776',1,1,1,'Saturday,Sunday','Evening','No',1,0,0,0,1,1,'mark sig','2025-11-14','approved','Active','certified',NULL,'2026-01-20','2025-11-14 06:48:38','2026-01-19 08:57:56',NULL),
(7,NULL,'Carlos','','Mendoza','Carlos Mendoza','1990-12-02','Male','Married','Brgy Commonwealth, QC','09219988776','carlos.mendoza@example.com','CarlM','SSS','SSS998877',NULL,NULL,1,1,'Grace Mendoza','Wife','09198877665','Same address','Yes','Barangay volunteer','Wants to support barangay programs','Yes','Driver','Transport Co.','High School','Defensive Driving','Fair','Tagalog',1,0,0,1,'D5566778',1,1,1,'Wednesday,Friday,Sunday','Morning','Yes',1,0,0,1,1,1,'carlos sig','2025-11-14','approved','Active','certified',NULL,'2026-01-20','2025-11-14 06:48:38','2026-01-19 08:57:56',NULL),
(8,NULL,'Jasmine','','Lopez','Jasmine Lopez','2001-06-14','Female','Single','Brgy Holy Spirit, QC','09334445566','jasmine.lopez@example.com','JasLopez','Postal ID','POST12345',NULL,NULL,0,0,'Jose Lopez','Father','09123334455','QC','No','','To help disaster victims','No','','','High School','','Good','Tagalog,English',0,1,0,0,'',1,0,0,'Thursday,Saturday','Afternoon,Evening','Yes',0,1,0,1,0,1,'jasmine sig','2025-11-14','approved','Active','certified',NULL,'2026-01-20','2025-11-14 06:48:38','2026-01-19 08:57:56',NULL),
(9,NULL,'hann','','pham','hann pham','0000-00-00','Female','Married','asdasd','123123123123','asdasdd@asdasd.com','asdasdasd','Postal ID','123123','uploads/volunteer_id_photos/id_front_1763227831_7ad79fc34b75c6e9.jpg','uploads/volunteer_id_photos/id_back_1763227831_b60b8c328fd4acf7.jpg',1,1,'stephen kyle viray','Husband','09984319585','054 gold extention\r\nbaranggay commonwelth qc','No','','asdasd','No','','','College Undergraduate','asdasdasdasd','Excellent','asdasdasd',1,0,0,0,'',1,0,0,'Sunday','Morning','Yes',1,0,0,0,1,1,'Hanni Pham','2025-11-15','rejected','Active','certified',NULL,'2026-01-20','2025-11-15 09:30:31','2026-01-19 08:57:56',NULL),
(10,NULL,'Danielle','','Marsh','Danielle Marsh','2004-02-10','Female','Married','054 gold extention\r\nbaranggay commonwelth qc','09984319585','stephensssviray12@gmail.com','asdasd','PhilHealth ID','asdas123123','uploads/volunteer_id_photos/id_front_1763229100_bdf900ffd3113e4e.jpg','uploads/volunteer_id_photos/id_back_1763229100_bea3dbffa3df8f1a.jpg',0,0,'stephen kyle viray','Husband','09984319585','054 gold extention\r\nbaranggay commonwelth qc','No','','asdasd','No','','','High School','asdasd123123','Excellent','asdasdasd',1,0,0,0,'',0,0,0,'Monday','Morning','Yes',1,1,1,1,0,1,'Danielle Marsh','2025-11-15','approved','Inactive','none',NULL,NULL,'2025-11-15 09:51:40','2026-01-14 06:22:19',NULL),
(13,10,'zaldy','g','solis','','2003-02-10','Male','Single','054 gold extention\r\nbaranggay commonwelth qc','09984319585','stephenviray121111@gmail.com','Spyke Kyle','Voter&#039;s ID','123123','uploads/volunteer_id_photos/id_front_1768404277_79552e438be7c909.jpg','uploads/volunteer_id_photos/id_back_1768404277_ca2f8ff1735c4054.jpg',0,0,'stephen kyle viray','brother','09984319585','054 gold extention\r\nbaranggay commonwelth qc','No','','asddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd','No','','','High School','rescue','Good','tagalog',0,0,1,0,'',0,1,0,'Monday,Wednesday,Friday','Afternoon,Night','Yes',0,0,1,0,0,1,'zaldy g solis','2026-01-14','approved','Active','certified','2026-01-27','2026-01-27','2026-01-14 07:24:37','2026-01-27 02:10:40',NULL);

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
