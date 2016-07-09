<?php
/*
__PocketMine Plugin__
name=Factions
version=1.1.2-Alpha
author=Kevin Wang
class=KVFactions
apiversion=12
*/

/* 

By Kevin Wang
From China

Project Website: http://www.MineConquer.com/
Official Website: http://www.cnkvha.com/
Skype: kvwang98
Twitter: KevinWang_China
Youtube: http://www.youtube.com/VanishedKevin
E-Mail: kevin@cnkvha.com

*/

/*
 * Bookmarks: KVBOOKMARK
 *---------------
 *  TODO List( # is done, X is not done, ! is working on)
 *---------------
 * >>>>>Commands<<<<<
 *  [ /f ] 
 * # join - Directly join a faction. 
 * # quit - Quit a faction. 
 * # create - Create a faction. 
 * # disband - Disband a faction. 
 * # invite - Invite someone to your faction. 
 * # accept - Accept one's invitation. 
 * # deny - Deny one's invitation. 
 * # setperm - Set permission in your faction. 
 * # kick - Kick a player from your faction. 
 * # claim - Claim a 8x8 land for your faction. 
 * # unclaim - Unclaim current land. 
 * # unclaimall - Unclaim all lands. 
 * # setopen - Set player can join with/without being authenticated. 
 * # money - See your current faction's money
 * # map - See the claim map
 *  [ /fadm ]
 * # on - Turn on admin bypass mode. 
 * # off - Turn off admin bypass mode. 
 * # cw - Allow factions to claim lands in the world. 
 * # gm - Get money of a faction
 * # sm - Set money for a faction
 * >>>>>Features<<<<<
 * # Claim Allowed Worlds - Factions can only claim lands in claim-allowed worlds. 
 * # Claim Lands - Only Officer and higher rank can claim lands. 
 * # Admin Bypass Mode - Admins/Ops can build on any claims. 
 * # Build on Claims - Only Builders and higher rank can build on lands. 
 * # No Damage in Claims - Player won't get any damage if he/she is in he/she's faction's OWN claim. 
*/

/*
Faction Money Help: 
1: Initially Money is 1000
2: To buy claims, 500 for a claim(8x8)
*/

/*
[FactionName(Lower-Case)].yml{
	FacName(String) = Faction Name(In-Case)
	IsOpen(Boolean) = Faction Open Status
	Members(Array){
		USERNAME(Lower-Case)(Array){
			Username(String) = Member's Username(In-Case)
		}
	}
	FacMoney(Number) = Faction Money(Initially 1000)
	Claims(Array){
		[WorldName](Array){
			X.Z,
			X.Z,
			X.Z,
			....
			X.Z
		}
	}
}

[Username(Lower-Case)].yml{
	Username(String) = Player's Username(In-Case)
	BelongTo(String) = Player's Faction(Lower-Case)
	Permission(Number) = PermissionID( 0=>Not Authored, 1=>Normal, 2=>Builder, 3=>officer, 4=>Admin)
}

[WorldName(Lower-Case)]/[ClaimX].[ClaimZ].yml{
	OwnerFaction(String) = The faction which own this land
}

*/

/*
Faction Inviting Array: 
facInvs{
	[Username](Lower-Case) = Target Username{
		Player(Player Object), 
		TargetFaction(string)
	}
}
*/



class KVFactions implements Plugin{
	private $api;
	private $dirFacs, $dirClaims, $dirUsers;
	
	public $facInvs = array();
	
	//DO NOT CHANGE FOLLOWING TWO DATA!!!
	private $CLAIMLONG_X = 8; // <<== DO NOT CHANGE
	private $CLAIMLONG_Z = 8; // <<== DO NOT CHANGE
	
	public $adminSessions = array();
	
	private $prevClaim = array();
	
	private $FAC_INIT_MONEY = 1000;
	private $CLAIM_PRICE = 500;
	private $PERM_NONE = 0;
	private $PERM_NORMAL = 1;
	private $PERM_BUILDER = 2;
	private $PERM_OFFICER = 3;
	private $PERM_ADMIN = 4;
	
	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
	}
	
	public function init(){
		//Initialize the folders
		$this->init_Folders();
		//Register commands
		$this->api->console->register("f", "Factions commands. ", array($this, "cmdSender"));
		$this->api->console->register("fadm", "Factions admin commands. ", array($this, "cmdSender"));
		//Whitelist the public faction command
		$this->api->ban->cmdWhitelist("f");
		//Register event(s)
		$this->api->addHandler("player.block.touch", array($this, "hdlEvents"), 8);
		$this->api->addHandler("entity.health.change", array($this, "hdlEvents"), 1);
		$this->api->addHandler("player.move", array($this, "hdlEvents"), 1);
		$this->api->addHandler("player.quit", array($this, "hdlEvents"), 1);
	}
	
	public function init_Folders(){
		$this->dirFacs = $this->api->plugin->configPath($this)."/Factions";
		$this->dirClaims = $this->api->plugin->configPath($this)."/Claims";
		$this->dirUsers = $this->api->plugin->configPath($this)."/Users";
		$this->SafeCreateFolder($this->dirClaims);
		$this->SafeCreateFolder($this->dirFacs);
		$this->SafeCreateFolder($this->dirUsers);
	}
	
	
	/***********************************************************************
	                      FACTIONS PLUGIN CORE FUNCTION
	***********************************************************************/
	
	public function hdlCommand($cmd, $arg, $issuer){
		switch(strtolower($cmd)){
			case "help":
			case "h":
			case "?":
				if(count($arg) != 1){
					return($this->getHelp("1"));
				}
				return($this->getHelp($arg[0]));
				break;
			case "create":
				if(count($arg)!=1){
					return("Usage: \n/f create [Faction Name]");
				}
				$ret = $this->facCreate($arg[0], $issuer->iusername);
				if($ret == 0){
					$this->api->chat->broadcast($issuer->iusername . " created faction: " . $arg[0]);
					return("You have created the faction. ");
				}elseif($ret == 1){
					return("Faction already exists! ");
				}elseif($ret == 2){
					return("You are already in a faction. ");
				}else{
					return("Error! ");
				}
				break;
			case "disband":
				$ret = $this->facDisband($issuer->iusername);
				if($ret == 0){
					return("You disbanded your faction. ");
				}elseif($ret == 1 or $ret == 2){
					return("No permission or faction doesn't exist. ");
				}else{
					return("Error! ");
				}
				break;
			case "quit":
				$ret = $this->facQuit($issuer->iusername);
				if($ret == 0){
					return("You have left your faction. ");
				}elseif($ret == 1){
					return("You are not in a faction. ");
				}else{
					return("Error! ");
				}
				break;
			case "join":
				if(count($arg) != 1){
					return("Join a faction: \n /f join [NAME]");
				}
				if($this->getUserFaction($issuer->iusername) != false){
					return("You are already in a faction. ");
				}
				if($this->chkFacExists($arg[0]) == false){return("Faction doesn't exists! ");}
				if($this->getFacOpenStatus($arg[0]) == true){
					//Join directly
					$this->facJoin($issuer->iusername, $arg[0], $this->PERM_NORMAL);
					return("You successfully joined the faction: \n" . $arg[0] . "\nand your permission is normal user. ");
				}else{
					//Send a request
					return("Target faction is non-open, \nyou need to be invited! ");
				}
			case "setperm": 
				if(count($arg) != 2){
					return("Usage: \n/f setperm [Username] [Permission]\nPermissions: \nnormal, builder, officer, admin");
				}
				if($this->getUserFaction($issuer->iusername) == false){
					return("You are not in a faction. ");
				}
				if($this->getUserPerm($issuer->iusername) == $this->PERM_ADMIN){
					return("You need to be an admin of the faction. ");
				}
				$permstr = strtolower($arg[1]);
				if($permstr != "normal" and $permstr != "builder" and $permstr != "officer" and $permstr != "admin"){
					return("Permissions: \nnormal, builder, officer, admin");
				}
				if($this->getUserFaction($arg[0]) == false or $this->getUserFaction($arg[0]) != $this->getUserFaction($issuer->iusername)){
					return("Target is not in your faction. ");
				}
				if($permstr == "normal"){
					$perm = $this->PERM_NORMAL;
				}elseif($permstr == "builder"){
					$perm = $this->PERM_BUILDER;
				}elseif($permstr == "officer"){
					$perm = $this->PERM_OFFICER;
				}elseif($permstr == "admin"){
					$perm = $this->PERM_ADMIN;
				}
				if($this->setUserPerm($arg[0], $perm) == true){
					return("Permission set! ");
				}else{
					return("Error! ");
				}
				break;
			case "kick":
				if(count($arg) != 1){return("Usage: \n/f kick [Username]");}
				if($this->getUserFaction($issuer->iusername) == false){
					return("You are not in a faction. ");
				}
				$perm = $this->getUserPerm($issuer->iusername);
				if($perm != $this->PERM_OFFICER and $perm != $this->PERM_ADMIN){
					return("Only officers and admins can do this. ");
				}
				if($this->getUserFaction($arg[0]) == false){
					return("Target is not in your faction. ");
				}
				$ret = $this->facQuit($arg[0]);
				if($ret == 0){
					return("Player kicked from your faction. ");
				}else{
					return("Error! ");
				}
				break;
			case "setopen":
				if(count($arg) != 1){
					return("Usage: \n/f setopen [on/off]");
				}
				if(strtolower($arg[0]) != "on" and strtolower($arg[0]) != "off"){
					return("State muse be: \n\"on\" or \"off\"");
				}
				$fac = $this->getUserFaction($issuer->iusername);
				if($fac == false){return("You don't have a faction! ");}
				$perm = $this->getUserPerm($issuer->iusername);
				if($perm != $this->PERM_OFFICER and $perm != $this->PERM_ADMIN){
					return("You need to be your faction's \nofficer or admin to do this. ");
				}
				$newStat = strtolower($arg[0]) == "on" ? true : false;
				if($this->setFacOpenStatus($fac, $newStat) == true){
					return("Faction open state changed. ");
				}else{
					return("Error when changing faction\nopen state. ");
				}
				break;
			case "claim":
				if(count($arg) != 0){return("Usage: \n/f claim");}
				$fac = $this->getUserFaction($issuer->iusername);
				if($fac == false){
					return("You are not in a faction. ");
				}
				$perm = $this->getUserPerm($issuer->iusername);
				if($perm != $this->PERM_OFFICER and $perm != $this->PERM_ADMIN){
					return("Only officers and admins can do this. ");
				}
				$facMoney = $this->getFacMoney($fac);
				if($facMoney < $this->CLAIM_PRICE){
					return("No enough money! \nOne land costs 500. ");
				}
				$cords = $this->calcClaimCords(intval($issuer->entity->x), intval($issuer->entity->z));
				if(count($cords) != 2){
					return("Claim faild, \nwrong position data. ");
				}
				$cx = $cords[0];
				$cz = $cords[1];
				$owner = $this->getClaimOwner($issuer->level->getName(), $cx, $cz);
				if($owner != false){
					return("Claim already owned by: \n" . $owner);
				}
				//Minus money
				$facMoney -= $this->CLAIM_PRICE;
				$this->setFacMoney($fac, $facMoney);
				$ret = $this->claimLand($fac, $issuer->level->getName(), $cx, $cz);
				if($ret == 0){
					return("You have claimed an 8x8 land\nfor your birthday. ");
				}else{
					return("Target world has claiming\ndisbled. ");
				}
				break;
			case "unclaim":
				if(count($arg) != 0){return("Usage: \n/f unclaim");}
				$fac = $this->getUserFaction($issuer->iusername);
				if($fac == false){
					return("You are not in a faction. ");
				}
				$perm = $this->getUserPerm($issuer->iusername);
				if($perm != $this->PERM_OFFICER and $perm != $this->PERM_ADMIN){
					return("Only officers and admins can do this. ");
				}
				$cords = $this->calcClaimCords(intval($issuer->entity->x), intval($issuer->entity->z));
				if(count($cords) != 2){
					return("Claim faild, \nwrong position data. ");
				}
				$cx = $cords[0];
				$cz = $cords[1];
				$owner = $this->getClaimOwner($issuer->level->getName(), $cx, $cz);
				if(strtolower($owner) != strtolower($fac)){
					return("Claim is not owned by your faction. \nIt is owned by: " . $fac);
				}
				$ret = $this->unclaimLand($issuer->level->getName(), $cx, $cz);
				if($ret == 0){
					return("You unclaimed this land. ");
				}else{
					return("Faild to unclaim. ");
				}
				break;
			case "unclaimall":
				if(count($arg) != 0){return("Usage: \n/f unclaim");}
				$fac = $this->getUserFaction($issuer->iusername);
				if($fac == false){
					return("You are not in a faction. ");
				}
				$perm = $this->getUserPerm($issuer->iusername);
				if($perm != $this->PERM_ADMIN){
					return("Only admins can do this. ");
				}
				//First, get all claims
				$cfgFac = new Config($this->dirFacs."/" . strtolower($fac) . ".yml", CONFIG_YAML, array());
				$claimAllWorlds = $cfgFac->get("Claims");
				foreach($claimAllWorlds as $claimWorld){
					foreach($claimWorlds as $claimCords){
						$cord = explode($claimCords, ".");
						if(count($cord) == 2){
							$this->unclaimLand($claimWorld, $cord[0], $cord[1]);
						}
					}
				}
				return("All claims removed! ");
				break;
			case "money":
				if(count($arg) != 0){return("Usage: \n/f money");}
				$fac = $this->getUserFaction($issuer->iusername);
				if($fac == false){
					return("You are not in a faction. ");
				}
				return("Your faction's money: \n" . $this->getFacMoney($fac));
				break;
			case "map":
				$level = $issuer->level->getName();
				if($this->isClaimOn($level) == false){return("Current world is claiming disabled. ");} //If claim is not on in this world, skip. 
				$x = intval($issuer->entity->x);
				$z = intval($issuer->entity->z);
				$claimCords = $this->calcClaimCords($x, $z);
				$cx = $claimCords[0];
				$cz = $claimCords[1];
				$startX = $cx - 16;
				$startZ = $cz - 2;
				$endX = $cx + 16;
				$endZ = $cz + 2;
				if($startX < 0){$startX = 0;}
				if($startZ < 0){$startZ = 0;}
				if($endX > 256/$this->CLAIMLONG_X){$endX = 256/$this->CLAIMLONG_X;}
				if($endZ > 256/$this->CLAIMLONG_Z){$endX = 256/$this->CLAIMLONG_Z;}
				$output = "< Horizontal = X, Vertical = Z >\n";
				for($loopZ = $startZ; $loopZ <= $endZ; $loopZ++){
					for($loopX = $startX; $loopX <= $endX; $loopX++){
						if($loopX == $cx and $loopZ == $cz){
							$output .= "+";
						}else{
							$output .= $this->getClaimOwner($issuer->level->getName(), $loopX, $loopZ) == false ? "-" : "*";
						}
					}
					$output .= "\n";
				}
				return($output);
				break;
			case "invite":
				if(count($arg) != 1){return("Usage: \n/f invite [Username]");}
				if($this->getUserFaction($issuer->iusername) == false){return("You are not in a faction. ");}
				if($this->getUserPerm($issuer->iusername) != $this->PERM_OFFICER and $this->getUserPerm($issuer->iusername) != $this->PERM_ADMIN){
					return("You need to be an officer \nor admin to do this. ");
				}
				$targetUser = $this->api->player->get($arg[0]);
				if($targetUser == false){
					return("Target user doesn't exists. ");
				}
				//Start sending invitation
				if(isset($this->facInvs[$targetUser->iusername])){
					$targetUser->sendChat("Your previous invitation\nhas been removed. ");
				}
				$targetFaction = $this->getUserFaction($issuer->iusername);
				$this->facInvs[$targetUser->iusername] = array("TargetFaction" => $targetFaction);
				$targetUser->sendChat(" \n \nYou have received an invitation \nfrom the faction: \n" . $targetFaction . "\nType '/f accept' to join it. ");
				return("You have invited " . $targetUser->username . " to\n your faction. ");
				break;
			case "accept":
				if(count($arg) != 0){return("Usage: \n/f accept");}
				if(isset($this->facInvs[$issuer->iusername]) == false){return("You don't have any invitations. ");}
				if($this->getUserFaction($issuer->iusername) != false){return("You are already in a faction. \nType '/f leave' to leave it. \nThen you can join other \nfactions. ");}
				$targetFaction = $this->facInvs[$issuer->iusername]["TargetFaction"];
				unset($this->facInvs[$issuer->iusername]);
				if($this->chkFacExists($targetFaction) == false){return("Target faction doesn't\nexist. Maybe disbanded. ");}
				$ret = $this->facJoin($issuer->username, $targetFaction, $this->PERM_NORMAL);
				if($ret == 0){
					return("You successfully joined: \n" . $targetFaction);
				}else{
					return("Error! You need to be \ninvited again. ");
				}
				break;
			case "deny":
				if(count($arg) != 0){return("Usage: \n/f deny");}
				if(isset($this->facInvs[$issuer->iusername]) == false){return("You don't have any invitations. ");}
				unset($this->facInvs[$issuer->iusername]);
				return("You have denied the\ninvitation. ");
				break;
		}
	}
	
	public function hdlAdminCommand($cmd, $arg, $issuer){
		switch(strtolower($cmd)){
			case "on":
				if(!($issuer instanceof Player)){return("This is an in-game command. ");}
				//Turn on admin bypass mode
				if(class_exists("PMEssGM")){
					//Has GroupManager, check permission node. 
					if($this->api->perm->checkPerm($issuer->iusername, "factions.adminbypass") == false){
						return("Factions Plugin\nGroupManager Error\nYou don't have PermissionNode: \nfactions.adminbypass");
					}
				}else{
					//Doesn't have GroupManager, check OP. 
					if($this->api->ban->isOp($issuer->iusername) == false){
						return("Factions Plugin\nPermission Error\nYou are not an OP. ");
					}
				}
				$this->adminSessions["CID" . $issuer->CID] = true;
				return("You have entered admin bypass mode. ");
				break;
			case "off":
				if(!($issuer instanceof Player)){return("This is an in-game command. ");}
				//Turn off admin bypass mode
				if(class_exists("PMEssGM")){
					//Has GroupManager, check permission node. 
					if($this->api->perm->checkPerm($issuer->iusername, "factions.adminbypass") == false){
						return("Factions Plugin\nGroupManager Error\nYou don't have PermissionNode: \nfactions.adminbypass");
					}
				}else{
					//Doesn't have GroupManager, check OP. 
					if($this->api->ban->isOp($issuer->iusername) == false){
						return("Factions Plugin\nPermission Error\nYou are not an OP. ");
					}
				}
				if(isset($this->adminSessions["CID" . $issuer->CID])){
					unset($this->adminSessions["CID" . $issuer->CID]);
				}
				return("You have disabled admin bypass mode. ");
				break;
			case "cw":
				if(count($arg) != 2){
					return("Usage: /fadm cw [World] [on/off]");
				}
				if($issuer instanceof Player){
					//Turn off admin bypass mode
					if(class_exists("PMEssGM")){
						//Has GroupManager, check permission node. 
						if($this->api->perm->checkPerm($issuer->iusername, "factions.manageclaiming") == false){
							return("Factions Plugin\nGroupManager Error\nYou don't have PermissionNode: \nfactions.manageclaiming");
						}
					}else{
						//Doesn't have GroupManager, check OP. 
						if($this->api->ban->isOp($issuer->iusername) == false){
							return("Factions Plugin\nPermission Error\nYou are not an OP. ");
						}
					}
				}
				if($this->api->level->levelExists($arg[0]) == false){return("Target world can not be found! ");}
				if(strtolower($arg[1]) != "on" and strtolower($arg[1] != "off")){
					return("State must be: \n  on / off");
				}
				$newStat = strtolower($arg[1]) == "on" ? true : false;
				if($this->setClaimState($arg[0], $newStat) == true){
					return("Claim state changed \nto " . ($newStat == true ? "true" : "false") . " in world: \n" . $arg[0]);
				}else{
					return("No change needed or error. ");
				}
				break;
			case "gm":
				if(count($arg) != 1){
					return("Description: Get money of a faction. \nUsage: \n/fadm gm [FactionName]");
				}
				if(!($this->chkFacExists($arg[0]))){
					return("Target faction doesn't exist. ");
				}
				$money = $this->getFacMoney($arg[0]);
				return("Faction Name: " . $this->getFacNameInCase($arg[0]) . "\nMoney: " . $money);
				break;
			case "sm":
				if(count($arg) != 2){
					return("Description: Set money of a faction. \nUsage: \n/fadm gm [FactionName] [Money]");
				}
				if(!($this->chkFacExists($arg[0]))){
					return("Target faction doesn't exist. ");
				}
				$this->setFacMoney($arg[0], (int)$arg[1]);
				$money = $this->getFacMoney($arg[0]);
				return("Faction Name: " . $this->getFacNameInCase($arg[0]) . "\nNew Money: " . $money);
				break;
		}
	}
	
	/**********************************************************************/
	
	public function hdlEvents(&$data, $event){
		switch($event){
			case "entity.health.change":
				if($data["entity"]->class != ENTITY_PLAYER){return;}
				$level = $data["entity"]->level->getName();
				if($this->isClaimOn($level) == false){return;} //If claim is not on in this world, skip. 
				$player = $data["entity"]->player;
				$x = intval($data["entity"]->x);
				$z = intval($data["entity"]->z);
				$claimCords = $this->calcClaimCords($x, $z);
				$cx = $claimCords[0];
				$cz = $claimCords[1];
				$cOwner = $this->getClaimOwner($level, $cx, $cz);
				$fac = $this->getUserFaction($player->iusername);
				if(strtolower($fac) == strtolower($cOwner)){
					return(false);
				}
				return;
				break;
			case "player.move":
				$level = $data->level->getName();
				if($this->isClaimOn($level) == false){return;} //If claim is not on in this world, skip. 
				$player = $data->player;
				$x = intval($data->x);
				$z = intval($data->z);
				$claimCords = $this->calcClaimCords($x, $z);
				$cx = $claimCords[0];
				$cz = $claimCords[1];
				$cOwner = $this->getClaimOwner($level, $cx, $cz);
				if(isset($this->prevClaim[$player->CID]) == false){
					$this->prevClaim[$player->CID] = false;
				}
				if($cOwner == false and $this->prevClaim[$player->CID] != false){
					//From claim to wild
					$this->prevClaim[$player->CID] = false;
					$player->sendChat("You are now in the wild area. ");
					return;
				}elseif($cOwner != $this->prevClaim[$player->CID]){
					$this->prevClaim[$player->CID] = $cOwner;
					$player->sendChat("You entered a claim owned by: \n" . $cOwner);
					return;
				}
				return;
				break;
			case "player.quit":
				if(isset($this->prevClaim[$data->CID])){
					unset($this->prevClaim[$data->CID]);
				}
				if(isset($this->facInvs[$data->iusername])){
					unset($this->facInvs[$data->iusername]);
				}
				return;
				break;
			case "player.block.touch":
				$x = intval($data["target"]->x);
				$y = intval($data["target"]->y);
				$z = intval($data["target"]->z);
				$level = $data["target"]->level->getName();
				$player = $data["player"];
				if($this->isClaimOn($level) == false){return;} //If claim is not on in this world, skip. 
				$claimCords = $this->calcClaimCords($x, $z);
				$cx = $claimCords[0];
				$cz = $claimCords[1];
				$cOwner = $this->getClaimOwner($player->level->getName(), $cx, $cz);
				if($cOwner != false){
					if($cOwner != $this->getUserFaction($player->iusername)){
						//Admin bypass mode
						if(isset($this->adminSessions["CID" . $player->CID]) and $this->adminSessions["CID" . $player->CID] == true){
							return;
						}
						$player->sendChat("Factions Claims\nLand is claimed by faction: \n" . $cOwner);
						return(false);
					}else{
						$uPerm = $this->getUserPerm($player->iusername);
						if($uPerm != $this->PERM_ADMIN and $uPerm != $this->PERM_BUILDER and $uPerm != $this->PERM_OFFICER){
							$player->sendChat("Factions Claims\nYou need to be builder, \nofficer or admin to build\nin your faction's claims. ");
							return(false);
						}else{
							return;
						}
					}
				}else{
					return;
				}
				break;
		}
	}
	
	
	
	
	public function __destruct(){
	}
	
	public function cmdSender($cmd, $arg, $issuer, $alias){
		if(strtolower($cmd) == "f"){
			if(count($arg)==0){
				return($this->getHelp("1"));
			}
			$nCmd = array_shift($arg);
			return($this->hdlCommand($nCmd, $arg, $issuer));
		}else{
			if(count($arg)==0){
				return($this->getAdminHelp("1"));
			}
			$nCmd = array_shift($arg);
			return($this->hdlAdminCommand($nCmd, $arg, $issuer));
		}
	}
	
	public function getHelp($page){
		switch($page){
			case "1":
			default:
				return(" \n \n
Factions Commands( Page 1/3 )\n
* join - Request/Directly join a faction. \n
* quit - Quit a faction. \n
* create - Create a faction. \n
* disband - Disband a faction. \n
					");
			case "2":
				return(" \n \n
Factions Commands( Page 2/3 )\n
* setperm - Set permission in your faction. \n
* kick - Kick a player from your faction. \n
* claim - Claim a 8x8 land for your faction. \n
* unclaimall - Unclaim all lands. \n
					");
			case "3":
				return(" \n \n
Factions Commands( Page 3/3 )\n
* setopen - Request Join or directly join. \n
* money - See your faction's money. \n
					");
		}
	}
	
	public function getAdminHelp($page){
		switch($page){
			case "1":
			default:
				return(" \n \n
Factions Admin Commands( Page 1/1 )\n
* on - Turn on admin bypass mode. \n
* quit - Turn off admin bypass mode. \n
* cw - Allow to claim lands in a world. \n
					");
		}
	}
	
	
	public function chkFacExists($facName){
		return(file_exists($this->dirFacs . "/" . strtolower($facName) . ".yml"));
	}
	
	
/*
Create Faction
Returns: 
0 = Successfully
1 = Group Already exists
2 = User already in a faction
3 = FileSystem Error
*/
	public function facCreate($facName, $owner){
		if($this->chkFacExists($facName)){return(1);}
		if($this->getUserFaction($owner) != false){return(2);}
		//START - Init new config
		$cfgFaction = new Config($this->dirFacs."/" . strtolower($facName) . ".yml", CONFIG_YAML, array());
		$cfgFaction->set("FacName", $facName);
		$cfgFaction->set("IsOpen", false);
		$cfgFaction->set("FacMoney", $this->FAC_INIT_MONEY);
		$cfgFaction->set("Members", array()); //Init to blank array
		$cfgFaction->set("Claims", array()); //Init to blank array
		$cfgFaction->save();
		unset($cfgFaction);
		//END - Init new config
		//START - Init Admin(Owner)
		$joinRet = $this->facJoin($owner, $facName, $this->PERM_ADMIN);
		if($joinRet == 0){
			//Success
			return(0);
		}else{
			//Faild, Roll back actions
			@unlink($this->dirFacs."/" . strtolower($facName));
			//Returns are the 99% same as "facJoin()" so we directly return it. :P
			return($joinRet);
		}
		//END -Init Admin(Owner)
	}

/*
Disband Faction
Returns: 
0 = Successfully
1 = Group Doesn't exists
2 = No Enough Permission
3 = FileSystem Error
*/
	public function facDisband($username){
		$facName = $this->getUserFaction($username);
		if($facName == false){return(1);}
		if(!($this->chkFacExists($facName))){return(1);}
		if($this->getUserPerm($username) != $this->PERM_ADMIN){return(2);}
		//Remove all users in the group
		$cfgFaction = new Config($this->dirFacs."/" . strtolower($facName) . ".yml", CONFIG_YAML, array());
		$members = $cfgFaction->get("Members");
		unset($cfgFaction);
		foreach($members as $uName => $uData){
			@unlink($this->dirUsers."/" . strtolower($uName) . ".yml");
		}
		@unlink($this->dirFacs."/" . strtolower($facName) . ".yml");
		return(0);
	}
	
/*
Let user join a faction
Returns: 
0 = Successfully
1 = Group Doesn't Exist
2 = User already in a faction
3 = FileSystem Error
*/

	public function facJoin($username, $facName, $perm){
		if($this->chkFacExists($facName) == false){return(1);}
		if($this->getUserFaction($owner) != false){return(2);}
		if($perm != $this->PERM_NONE and $perm != $this->PERM_NORMAL and $perm != $this->PERM_BUILDER and $perm != $this->PERM_OFFICER and $perm != $this->PERM_ADMIN){$perm = 0;}
		//START - Add to faction config
		$cfgFaction = new Config($this->dirFacs."/" . strtolower($facName) . ".yml", CONFIG_YAML, array());
		$members = $cfgFaction->get("Members");
		if(array_key_exists(strtolower($username), $members) == false){
			$toAdd = array(strtolower($username) => array("Username"=>$username));
			$newMembers = array_merge($members, $toAdd);
			$cfgFaction->set("Members", $newMembers);
			$cfgFaction->save();
		}
		unset($cfgFaction);
		//END - Add to faction config
		$cfgUser= new Config($this->dirUsers."/" . strtolower($username) . ".yml", CONFIG_YAML, array());
		$cfgUser->set("Username", $username);
		$cfgUser->set("BelongTo", $this->getFacNameInCase($facName));
		$cfgUser->set("Permission", $perm);
		$cfgUser->save();
		unset($cfgUser);
		$this->api->chat->broadcast($username . " joined the faction \n" . $facName . " as a " . $this->perm2name($perm) . ". ");
		return(0);
	}

/*
Get a Factions Open Status
Returns: 
false = Faction is not open or faction doesn't exist
*/
	public function getFacOpenStatus($facName){
		if($this->chkFacExists($facName) == false){
			return(false);
		}
		$facCfg = new Config($this->dirFacs."/" . strtolower($facName) . ".yml", CONFIG_YAML, array());
		$ret = $facCfg->get("IsOpen");
		unset($facCfg);
		return($ret);
	}
	
/*
Set a Factions Open Status
Returns: 
true = Success
false = Wrong argument or faction doesn't exist. 
*/
	public function setFacOpenStatus($facName, $newStat){
		if(is_bool($newStat) == false){return(false);}
		if($this->chkFacExists($facName) == false){
			return(false);
		}
		$facCfg = new Config($this->dirFacs."/" . strtolower($facName) . ".yml", CONFIG_YAML, array());
		$facCfg->set("IsOpen", $newStat);
		$facCfg->save();
		unset($facCfg);
		return(true);
	}
	
	
/*
User Quit A Faction
Returns: 
0 = Successfully
1 = User Not in A Group or Group Doesn't Exist
2 = FileSystem Error
*/
	public function facQuit($username){
		$facName = $this->getUserFaction($username);
		if($facName == false){return(1);}
		//Remove from Faction Config
		$cfgFaction = new Config($this->dirFacs."/" . strtolower($facName) . ".yml", CONFIG_YAML, array());
		$members = $cfgFaction->get("Members");
		if(array_key_exists(strtolower($username), $members)){
			unset($members[strtolower($username)]);
			$cfgFaction->set("Members", $members);
			$cfgFaction->save();
		}
		unset($members);
		//Remove from User Config
		@unlink($this->dirUsers."/" . strtolower($username) . ".yml");
		if($this->getMemberCount($facName) == 0){
			$this->facDisband($facName);
		}
		return(0);
	}
	
	/*
	Get an User's Permission in a Faction
	Returns: 
	Permission Values, 
	-1 = User is not in a faction
	*/
	public function getUserPerm($uName)	{
		if($this->getUserFaction($uName)==false){return(-1);}
		$userCfg = new Config($this->dirUsers."/" . strtolower($uName) . ".yml", CONFIG_YAML, array());
		$ret = $userCfg->get("Permission");
		unset($userCfg);
		return($ret);
	}


	/*
	Set an User's Permission in a Faction
	Returns: 
	Permission Values, 
	true = Successful
	false = User is not in a faction or permission data error
	*/
	public function setUserPerm($uName, $perm)	{
		if($this->getUserFaction($uName)==false){return(false);}
		if($perm != $this->PERM_NONE and $perm != $this->PERM_NORMAL and $perm != $this->PERM_BUILDER and $perm != ADMIN ){return(false);}
		$userCfg = new Config($this->dirUsers."/" . strtolower($uName) . ".yml", CONFIG_YAML, array());
		$userCfg->set("Permission", $perm);
		$userCfg->save();
		unset($userCfg);
		return(true);
	}
	
	/*
	Get the member count of a faction
	Returns: 
	-1 = Faction doesn't exist
	*/
	public function getMemberCount($facName){
		if($this->chkFacExists($facName) == false){return(-1);}
		$cfgFaction = new Config($this->dirFacs."/" . strtolower($facName) . ".yml", CONFIG_YAML, array());
		$members = $cfgFaction->get("Members");
		$cntMembers = count($members);
		unset($members);
		return($cntMembers);
	}
	
	/*
	Get the member money of a faction
	Returns: 
	-1 = Faction doesn't exist
	*/
	public function getFacMoney($facName){
		if($this->chkFacExists($facName) == false){return(-1);}
		$cfg = new Config($this->dirFacs."/" . strtolower($facName) . ".yml", CONFIG_YAML, array());
		$money = $cfg->get("FacMoney");
		unset($cfg);
		return($money);
	}
	
	/*
	Set the member money of a faction
	Returns: 
	true = Successful
	false = Faction doesn't exist or argument error
	*/
	public function setFacMoney($facName, $money){
		if($this->chkFacExists($facName) == false){return(false);}
		$cfg = new Config($this->dirFacs."/" . strtolower($facName) . ".yml", CONFIG_YAML, array());
		$cfg->set("FacMoney", $money);
		$cfg->save();
		unset($cfg);
		return(true);
	}
	
	
	public function getUserFaction($uName){
		if(file_exists($this->dirUsers . "/" . strtolower($uName) . ".yml") == false){
			return(false);
		}
		$userCfg = new Config($this->dirUsers."/" . strtolower($uName) . ".yml", CONFIG_YAML, array());
		if($userCfg->exists("BelongTo") == true){
			$ret = $userCfg->get("BelongTo");
			if($this->chkFacExists($ret) == false){
				$ret = false;
				@unlink($this->dirUsers."/" . strtolower($uName) . ".yml");
			}
		}else{
			$ret = false;
		}
		unset($userCfg);
		return($ret);
	}
	
	public function SafeCreateFolder($path){
		if (!file_exists($path) and !is_dir($path)){
			mkdir($path);
		} 
	}
	
	public function removeClaimWorldDir($wName) {
		$dirPath = $this->dirClaims . "/" . strtolower($wName);
		if (! is_dir($dirPath)) {
			return(false);
		}
		if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
			$dirPath .= '/';
		}
		$files = glob($dirPath . '*', GLOB_MARK);
		foreach ($files as $file) {
			if (is_dir($file)) {
				self::deleteDir($file);
			} else {
				//We need to clear the data in factions config also!
				$claimCords = explode(basename($file, ".yml"), ".");
				if(count($claimCords) == 2){
					if(unclaimLand($wName, $claimCords[0], $claimCords[1]) != 0){
						@unlink($file);
					}
				}else{
					@unlink($file);
				}
			}
		}
		rmdir($dirPath);
		return(true);
	}
	
	/*
	=================================================================
	                     Functions About Claims
	=================================================================
	*/
	
	
	public function isClaimOn($wName){
		//This line will be removed because LevelAPI doesn't have getAll() function. 
		//REMOVED: if($this->api->infworld->checkLoadedLevelExist($wName) == false){return(false);}
		//Instead, use this: 
		if($this->api->level->levelExists($wName) == false){return(false);}
		return(file_exists($this->dirClaims . "/" . strtolower($wName)) and is_dir($this->dirClaims . "/" . strtolower($wName)));
	}
	
	public function setClaimState($wName, $newStat){
		if($newStat == true){
			if (!file_exists($this->dirClaims . "/" . strtolower($wName)) and !is_dir($this->dirClaims . "/" . strtolower($wName))){
				mkdir($this->dirClaims . "/" . strtolower($wName));
				return(true);
			}else{
				return(false);
			} 
		}else{
			if (file_exists($this->dirClaims . "/" . strtolower($wName)) and is_dir($this->dirClaims . "/" . strtolower($wName))){
				$this->removeClaimWorldDir($wName);
				return(true);
			}else{
				return(false);
			} 
		}
	}
	
	//Caculate the claim cords
	public function calcClaimCords($x, $z){
		if(is_array($x)){
			if(count($x) != 2){return(false);}
			return($this->calcClaimCords($x[0], $x[1]));
		}
		$claimX = intval($x/$this->CLAIMLONG_X);
		$claimZ = intval($z/$this->CLAIMLONG_Z);
		$modX = $x % $this->CLAIMLONG_X;
		$modZ = $z % $this->CLAIMLONG_Z;
		if($modX == 0){$claimX--;}
		if($modZ == 0){$claimZ--;}
		return(array($claimX, $claimZ));
	}
	
	//Get the owner of a claim. It retuens false if error. 
	public function getClaimOwner($wName, $cx, $cz){
		if($this->isClaimOn($wName) == false){return(false);}
		if(file_exists($this->dirClaims . "/" . strtolower($wName) . "/" . intval($cx) . "." . intval($cz) . "yml") == false){
			return(false);
		}
		$claimCfg = new Config($this->dirClaims . "/" . strtolower($wName) . "/" . intval($cx) . "." . intval($cz) . "yml", CONFIG_YAML, array());
		$ret = $claimCfg->get("OwnerFaction");
		unset($claimCfg);
		if($ret != false){
			if($this->chkFacExists($ret) == false){
				//Owner Faction doesn't exist
				$ret = false;
				@unlink($this->dirClaims . "/" . strtolower($wName) . "/" . intval($cx) . "." . intval($cz) . "yml");
			}
		}
		return($ret);
	}
	
	/*
	Claim a land for a faction
	Returns: 
	0 = Success
	1 = Target world is claiming disabled
	2 = Faction doesn't exist
	3 = Land is already owned by this/other faction. 
	*/
	public function claimLand($facName, $wName, $cx, $cz){
		if($this->isClaimOn($wName) == false){return(1);}
		if($this->chkFacExists($facName) == false){return(2);}
		if($this->getClaimOwner($wName, $cx, $cz) != false){return(3);}
		//Set the claim config
		$claimCfg = new Config($this->dirClaims . "/" . strtolower($wName) . "/" . intval($cx) . "." . intval($cz) . "yml", CONFIG_YAML, array());
		$claimCfg->set("OwnerFaction", $facName);
		$claimCfg->save();
		unset($claimCfg);
		//Set the faction config
		$cfgFaction = new Config($this->dirFacs."/" . strtolower($facName) . ".yml", CONFIG_YAML, array());
		$claimData = $cfgFaction->get("Claims");
		if(isset($claimData[strtolower($wName)]) == false){
			$claimData[strtolower($wName)] = array();
		}
		if(in_array(intval($cx) . "." . intval($cz), $claimData[strtolower($wName)], true) == false){
			array_push($claimData[strtolower($wName)], intval($cx) . "." . intval($cz));
		}
		$cfgFaction->set("Claims", $claimData);
		$cfgFaction->save();
		unset($cfgFaction);
		return(0);
	}

	/*
	Claim a land for a faction
	Returns: 
	0 = Success
	1 = Target world is claiming disabled
	2 = Land doesn't have a owner. 
	3 = Faction doesn't exist
	*/
	public function unclaimLand($wName, $cx, $cz){
		if($this->isClaimOn($wName) == false){return(1);}
		$facName = $this->getClaimOwner($wName, $cx, $cz);
		if($facName == false){return(2);}
		//Set the claim config
		@unlink($this->dirClaims . "/" . strtolower($wName) . "/" . intval($cx) . "." . intval($cz) . "yml");
		//Check faction if it exists
		if($this->chkFacExists($facName) == false){return(3);}
		//Set the faction config
		$cfgFaction = new Config($this->dirFacs."/" . strtolower($facName) . ".yml", CONFIG_YAML, array());
		$claimData = $cfgFaction->get("Claims");
		if(isset($claimData[strtolower($wName)]) == true){
			if(($key = array_search(intval($cx) . "." . intval($cz), $claimData[strtolower($wName)])) !== false) {
				unset($claimData[strtolower($wName)][$key]);
			}
		}
		$cfgFaction->set("Claims", $claimData);
		$cfgFaction->save();
		unset($cfgFaction);
		return(0);
	}
	
	public function getFacNameInCase($facName){
		if($this->chkFacExists($facName) == false){return(false);}
		$cfgFac = new Config($this->dirFacs."/" . strtolower($facName) . ".yml", CONFIG_YAML, array());
		$facNameInCase = $cfgFac->get("FacName");
		unset($cfgFac);
		return($facNameInCase);
	}
	
	public function perm2name($permID){
		switch($permID){
			case $this->PERM_NONE:
				return("None");
				break;
			case $this->PERM_NORMAL:
				return("Normal");
				break;
			case $this->PERM_BUILDER:
				return("Builder");
				break;
			case $this->PERM_OFFICER:
				return("Officer");
				break;
			case $this->PERM_ADMIN:
				return("Admin");
				break;
			default:
				return("Unknown");
				break;
		}
	}
	
}
?>

