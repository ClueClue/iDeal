<?php
/* 
	iDeal - 거래플러그인 [안전거래|아이템클라우드|아이템판매기|아이템상점|아이템경매]
	본플러그인의 수정은 허용하나, 재배포 및 무단배포는 금지합니다.

	Copyright 2015-2016. 인플레터(egmzkdhtm@naver.com) in HONEY Server All Rights Reserved.
*/
namespace inflater\iDeal;

use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\Player;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\item\Item;
use pocketmine\utils\TextFormat;
use pocketmine\block\Block;
use pocketmine\network\protocol\AddItemEntityPacket;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\network\protocol\RemoveEntityPacket;
use pocketmine\entity\Item as ItemEntity;
use pocketmine\entity\Entity;
use pocketmine\level\Level;
use pocketmine\level\Position;
use inflater\iDeal\event\EventListener;
use inflater\iDeal\event\DealEvent;
use inflater\iDeal\event\ShopEvent;
use inflater\iDeal\event\CloudEvent;
use inflater\iDeal\event\VmachineEvent;
use inflater\iDeal\event\AuctionEvent;
use inflater\iDeal\event\CraftEvent;

class iDeal extends PluginBase implements Listener {

	private static $instance = null;
	public $db, $deal, $EconomyAPI, $AreaAPI, $itemName, $message, $setting, $data, $ItemPacket, $packet;
	public $itemCloudConfig, $itemCloud, $VMachineConfig, $VMachine, $shopConfig, $shop;
	public $event, $shopEvent, $dealEvent, $cloudEvent, $auctionEvent;

	public function onLoad() { if(self::$instance == null) { self::$instance = $this; } }
	public static function getInstance() { return static::$instance; }
	public function onEnable() {
		@mkdir ($this->getDataFolder());
		
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->event = new EventListener($this);
		$this->getServer()->getPluginManager()->registerEvents($this->event, $this);
		$this->event->InitYML();

		if($this->setting['safedeal']) {
			$this->dealEvent = new DealEvent($this);
			$this->getServer()->getPluginManager()->registerEvents($this->dealEvent, $this);
			$this->event->registerCommand("거래", "ideal.commands.deal", "안전하게 유저간에 거래를 진행합니다.");
		}
		if($this->setting['itemcloud']) {
			$this->cloudEvent = new CloudEvent($this);
			$this->getServer()->getPluginManager()->registerEvents($this->cloudEvent, $this);
			$this->event->registerCommand("클라우드", "ideal.commands.cloud", "아이템을 보관할수있는 클라우드에 아이템을 업로드합니다.");
			$this->cloudEvent->saveData();
		}
		if($this->setting['vmachine']) {
			$this->vmachineEvent = new VmachineEvent($this);
			$this->getServer()->getPluginManager()->registerEvents($this->vmachineEvent, $this);
			$this->event->registerCommand("자판기", "ideal.commands.vmachine", "아이템 자동판매기");
		}
		if($this->setting['shop']) {
			$this->shopEvent = new ShopEvent($this);
			$this->getServer()->getPluginManager()->registerEvents($this->shopEvent, $this);
			$this->event->registerCommand("상점", "ideal.commands.shop", "아이템을 구매/판매 할 수 있는 상점을 설치합니다.");
		}
		if($this->setting['auction']) {
			$this->auctionEvent = new AuctionEvent($this);
			$this->getServer()->getPluginManager()->registerEvents($this->auctionEvent, $this);
			$this->event->registerCommand("경매", "ideal.commands.auction", "아이템 경매를 진행합니다.");
		}
		if($this->setting['craft']) {
			$this->craftEvent = new CraftEvent($this);
			$this->getServer()->getPluginManager()->registerEvents($this->craftEvent, $this);
			$this->event->registerCommand("조합", "ideal.commands.craft", "아이템을 조합합니다.");
		}
		$this->event->registerCommand("구매", "ideal.commands.buy", "아이템을 구매합니다.");
		$this->event->registerCommand("판매", "ideal.commands.sell", "아이템을 판매합니다.");
		$this->event->registerCommand("iv", "ideal.commands.iv", "아이템코드를 확인합니다.");
		$this->event->registerCommand("inv", "ideal.commands.inv", "아이템명을 확인합니다.");
		$this->event->registerCommand("ideal", "ideal.commands.ideal", "iDeal 기본 명령어");
		
		if($this->getServer()->getPluginManager()->getPlugin("EconomyAPI") === null) {
			$this->getLogger()->error("이코노미 플러그인을 찾을 수 없어 플러그인을 사용할 수 없습니다.");
			$this->getServer()->getPluginManager()->disablePlugin($this);
		}
		$this->EconomyAPI = \onebone\economyapi\EconomyAPI::getInstance();
		$this->AreaAPI = \inflater\Area\Area::getInstance();
		
		if($this->setting['update-check']) { $this->event->checkUpdate("1.0.7"); }
		$this->getLogger()->info(TextFormat::GREEN . "iDeal - 거래플러그인 [안전거래|아이템클라우드|아이템판매기|상점]");

		$this->setPacket();
		
		if(isset($this->itemCloud['upload'])) {
			foreach($this->itemCloud['upload'] as $playerN => $data) {
				if(isset($this->itemCloud['upload'][$playerN]['items'])) {
					unset($this->itemCloud['upload'][$playerN]['items']);
				}
			}
		}
	}

	public function onPlayerJoin(PlayerJoinEvent $ev) {
		$playerN = strtolower($ev->getPlayer()->getName());
		if(!isset($this->itemCloud['data'][$playerN])) {
			$this->itemCloud['data'][$playerN]['capacity'] = $this->setting['itemcloud_default-size'];
			$this->itemCloud['data'][$playerN]['uploads'] = 0;
			$this->itemCloudConfig->setAll($this->itemCloud);
			$this->itemCloudConfig->save();
		}
		$levelN = $ev->getPlayer()->getLevel()->getName();
		if(isset($this->shop[$levelN])) {
			foreach($this->shop[$levelN] as $pos => $data) {
				$pos = explode('.', $pos);
				$this->event->addItemEntity([$ev->getPlayer()], new Position($pos[0], $pos[1], $pos[2], $ev->getPlayer()->getLevel()), $data['item']);
			}
		}
		if(isset($this->craft[$levelN])) {
			foreach($this->shop[$levelN] as $pos => $data) {
				$pos = explode('.', $pos);
				$this->event->addItemEntity([$ev->getPlayer()], new Position($pos[0], $pos[1], $pos[2], $ev->getPlayer()->getLevel()), $data['item']);
			}
		}
	}

	public function onCommand(CommandSender $sender, Command $command, $label, array $args) {
		$senderN = strtolower($sender->getName());
		if(strtolower($command->getName())==='ideal' && $sender->isOp()) {
			if(!isset($args[0])) { $this->sMsg($sender, '/ideal invclear', -1); return; }
			elseif($args[0]==='invclear') {
				$target = isset($args[1]) ? $this->getServer()->getPlayer($args[1]) : $sender;
				if(!$target instanceof Player) { $this->sMsg($sender, '존재하지 않는 플레이어입니다.', -1, TextFormat::RED); return; }

				foreach($target->getInventory()->getContents() as $item) $target->getInventory()->removeItem($item);
				$this->sMsg($sender, $target->getName().'님의 인벤토리를 비웠습니다.', -1, TextFormat::RED);
			}elseif($args[0]==='invrefresh') {
				$target = isset($args[1]) ? $this->getServer()->getPlayer($args[1]) : $sender;
				if(!$target instanceof Player) { $this->sMsg($sender, '존재하지 않는 플레이어입니다.', -1, TextFormat::RED); return; }

				$temp = [];
				foreach($target->getInventory()->getContents() as $item) {
					$temp[count($temp)] = $item;
					$target->getInventory()->removeItem($item);
				}
				foreach($temp as $num => $item) $target->getInventory()->addItem($item);
				$this->sMsg($sender, $target->getName().'님의 인벤토리를 새로고침하였습니다.', -1, TextFormat::RED);
			}
		}
		if($command->getName()==="iv") {
			if(!$sender Instanceof Player) { $this->sMsg($sender, 'must-use-in-game', 0, TextFormat::RED); return; }
			$hitem = $sender->getInventory ()->getItemInHand ();
			$hitemCode = $hitem->getId().":".$hitem->getDamage();
			$hitemName = $this->getItemName($hitemCode);
			$sender->sendMessage(TextFormat::GREEN."아이템코드 ".TextFormat::GOLD.$hitemCode.TextFormat::GREEN." 아이템명 ".TextFormat::GOLD.$hitemName);
		}
		if($command->getName()==="inv") {
			if(count($args)!=1) { $sender->sendMessage(TextFormat::GREEN."[iDeal] /inv <itemcode>"); return; }
			$sender->sendMessage(TextFormat::GREEN."아이템명 ".TextFormat::GOLD.$this->getItemName($args[0]));
		}
		if($command->getName()==="거래") {
			if(!$sender instanceof Player) { $this->sMsg($sender, 'must-use-in-game', 0, TextFormat::RED); return; }
			if (count($args)==0){ $this->sMsg($sender, 'safedeal-command-usage', 0); return; }
			switch($args[0]) {
				case "요청" :
					if(count($args) != 4) {
						$this->sMsg($sender, 'safedeal-request-usage', 0);
						$this->sMsg($sender, 'safedeal-request-description', 0);
						return;
					}
					$this->dealEvent->request($sender, $args[1], $args[2], (int) $args[3]);
					break;
				case "상태" : $this->dealEvent->state($sender); break;
				case "수락" : $this->dealEvent->accept($sender); break;
				case "거절" : $this->dealEvent->rejected($sender); break;
				case "취소" : $this->dealEvent->cancel($sender); break;
				case "수신" : $this->dealEvent->receive($sender); break;
				default : $this->sMsg($sender, 'safedeal-command-usage'); break;
			}
		}elseif($command->getName()=="클라우드") {
			if(count($args)==0) { $this->sMsg($sender, 'itemcloud-command-usage', 1); return; }
			switch ($args [0]) {
				case "업로드" :
					if(count($args) != 2) {
						$this->sMsg($sender, 'itemcloud-upload-usage', 1);
						$this->sMsg($sender, 'itemcloud-upload-description', 1); return;
					}
					$this->cloudEvent->upload($sender, (int) $args[1]);
					break;
				case "다운로드" :
					if(count($args)!=2) {
						$this->sMsg($sender, 'itemcloud-download-usage', 1);
						$this->sMsg($sender, 'itemcloud-download-description', 1); return;
					}
					$this->cloudEvent->download($sender, (int) $args[1]);
					break;
				case "삭제" : $this->cloudEvent->remove($sender, isset($args[1]) ? $args[1] : false, isset($args[2]) ? $args[2] : null); break;
				case "목록" : $this->cloudEvent->showList($sender, (int) isset($args[1]) ? $args[1] : 1, isset($args[2]) ? $args[2] : null); break;
				case "확장" : $this->cloudEvent->expansion($sender); break;
				case "추가" : $this->cloudEvent->addCommand($sender, isset($args[1]) ? $args[1] : null, isset($args[2]) ? $args[2] : null, isset($args[3]) ? $args[3] : 1); break;
				case "작업취소" : $this->cloudEvent->workCancel($sender); break;
				default: $this->sMsg($sender, 'itemcloud-command-usage', 1); break;
			}
		}elseif($command->getName() === '자판기') {
			if(!$sender instanceof Player) { $this->sMsg($sender, 'must-use-in-game', 2, TextFormat::RED); return; }
			switch(isset($args[0]) ? $args[0] : null) {
				case '설치' : $this->vmachineEvent->install($sender, $args); break;
				case '물품변경' : $this->vmachineEvent->replace($sender, $args); break;
				case '물품꺼내기' : $this->vmachineEvent->takeout($sender, $args); break;
				case '수익인출' : $this->vmachineEvent->withdrawal($sender); break;
				case '제거' : $this->vmachineEvent->remove($sender); break;
				case '작업취소' : $this->vmachineEvent->workCancel($sender); break;
				default: $this->sMsg($sender, 'vmachine-command-usage', 2); break;
			}
		}elseif($command->getName()=="상점") {
			if(!$sender instanceof Player) { $this->sMsg($sender, 'must-use-in-game', 3, TextFormat::RED); return; }
			if(count($args) === 0) { $this->sMsg($sender, 'shop-command-usage', 3); return; }
			elseif($args[0]=='생성') { $this->shopEvent->create($sender); }
			elseif($args[0]=='정보') { $this->shopEvent->info($sender); }
		}elseif($command->getName() === '경매') {
			if(!$sender instanceof Player) { $this->sMsg($sender, 'must-use-in-game', 4, TextFormat::RED); return; }
			if(count($args) === 0) { $this->sMsg($sender, 'auction-command-usage', 4); return; }
			elseif($args[0]==='설치') { $this->auctionEvent->install($sender, $args); }
			elseif($args[0]==='등록') { $this->auctionEvent->registration($sender, $args); }
			elseif($args[0]==='입찰') { $this->auctionEvent->bidding($sender, $args); }
		}elseif($command->getName()==='조합') {
			if(!$sender instanceof Player) { $this->sMsg($sender, 'must-use-in-game', 5, TextFormat::RED); return; }
			if(count($args)===0) { $this->sMsg($sender, 'craft-command-usage', 5); return; }
			elseif($args[0]==='생성' && $sender->isOp()) { $this->craftEvent->create($sender); }
			elseif($args[0]==='정보' && $sender->isOp()) { $this->craftEvent->info($sender); }
			else { $this->craftEvent->craftItem($sender, (int) $args[0]); }
		}
		if($command->getName()=="구매") {
			if(!isset($args[0])) { $this->sMsg($sender, 'buy-command-usage', 3); return; }
			if((int) $args[0] <= 0) { $this->sMsg($sender, 'buy-fail-2', 3, TextFormat::RED); return; }
			if($this->vmachineEvent->buy($sender, (int) $args[0]) === false) {
				$this->shopEvent->buyItem($sender, (int) $args[0]);
			}
		}
		if($command->getName()=="판매") {
			if(!isset($args[0])) { $this->sMsg($sender, 'sell-command-usage', 3); return; }
			$this->shopEvent->sellItem($sender, (int) $args[0]);
		}
	}

	public function onPlayerDropItem(PlayerDropItemEvent $ev) {
		if($this->setting['item-drop']===false&&!$ev->getPlayer()->isOp()) {
			$ev->setCancelled();
		}
	}

	public function onPlayerQuit(PlayerQuitEvent $ev) {
		$sender = $ev->getPlayer();
		$senderN = strtolower($sender->getName());
		if(isset($this->data[$senderN])) { unset($this->data[$senderN]); }
		if(isset($this->data["shop"])) { unset($this->data["shop"][$senderN]); }
		if(isset($this->data["shopDeal"])) { unset($this->data["shopDeal"][$senderN]); }
		if(isset($this->ItemPacket[$senderN])) { unset($this->ItemPacket[$senderN]); }
	}

	public function setPacket() {
		$this->packet["AddItemEntity"] = new AddItemEntityPacket();
		$this->packet["AddItemEntity"]->yaw = 0;
		$this->packet["AddItemEntity"]->pitch = 0;
		$this->packet["AddItemEntity"]->roll = 0;

		$this->packet["AddEntity"] = new AddEntityPacket();
		$this->packet["AddEntity"]->eid = 0;
		$this->packet["AddEntity"]->type = ItemEntity::NETWORK_ID;
		$this->packet["AddEntity"]->metadata = [
				/*Entity::DATA_FLAGS => [Entity::DATA_TYPE_BYTE, 1 << Entity::DATA_FLAG_INVISIBLE],
				Entity::DATA_NAMETAG => [Entity::DATA_TYPE_STRING, ""],
				Entity::DATA_SHOW_NAMETAG => [Entity::DATA_TYPE_BYTE, 1],
				Entity::DATA_NO_AI => [Entity::DATA_TYPE_BYTE, 1],
				Entity::DATA_AIR => [Entity::DATA_TYPE_SHORT, 10]*/
		];

		$this->packet["RemoveEntity"] = new RemoveEntityPacket();
	}

	public function getMonetaryUnit() { return $this->EconomyAPI->getInstance()->getMonetaryUnit(); }

	public function getItemName($itemcode) {
		$it = explode(':', $itemcode);
		if(isset($it[1])) { if($it[1]=='0') { $itemcode = $it[0]; } }
		if (isset ( $this->itemName [$itemcode] )) {
			return $this->itemName [$itemcode];
		} else {
			return false;
		}
	}

	public function sMsg($player, $message, $mark = 0, $color = null, $variable = ['','','','','']) {
		while(count($variable)<5) { $variable[count($variable)] = ''; }
		if($color==null) { $color = TextFormat::DARK_GREEN; }
		if($mark===-1) { $mark = 'ideal-default-mark'; }
		elseif($mark==0) { $mark = 'safedeal-default-mark'; }
		elseif($mark==1) { $mark = 'itemcloud-default-mark'; }
		elseif($mark==2) { $mark = 'vmachine-default-mark'; }
		elseif($mark==3) { $mark = 'shop-default-mark'; }
		elseif($mark==4) { $mark = 'auction-default-mark'; }
		elseif($mark==5) { $mark = 'craft-default-mark'; }
		if($player==='all') { $this->getServer()->broadcastMessage($color . $this->msg($mark) . " " . str_replace(array('{%Monetary-Unit}','{%0}', '{%1}', '{%2}', '{%3}', '{%4}'), array($this->EconomyAPI->getInstance()->getMonetaryUnit(),$variable[0], $variable[1], $variable[2], $variable[3], $variable[4]), $this->msg($message))); }
		else { $player->sendMessage($color . $this->msg($mark) . " " . str_replace(array('{%Monetary-Unit}','{%0}', '{%1}', '{%2}', '{%3}', '{%4}'), array($this->EconomyAPI->getInstance()->getMonetaryUnit(),$variable[0], $variable[1], $variable[2], $variable[3], $variable[4]), $this->msg($message)) ); }
	}

	public function msg($message) {
		if(isset($this->message[$message])) {
			return $this->message[$message];
		}
		return $message;
	}
}

/*
[2015/09/03] 클라운드 다운로드 후, 매물이 없어질시, uploads(용량) 이 줄어들지 않던현상 수정.
			 클라우드용량 fix코드 적용.
			 판매기 터치시 뜨는문구 수정.

[2015/09/04] 구매자가 판매기를 터치시 뜨는문구 수정.
			 거래/클라우드/판매기 아이템 제한기능 추가.

[2015/09/05] 아이템 드랍 허용/금지 기능 추가.

[2015/10/21] 클라우드용량 fix코드 제거.

[2015/10/22] 코드정리 및 오류수정(클라우드 업로드).
			 오피가 일반유저의 판매기를 관리할 수 있도록 변경.

[2015/12/19] (v1.1.0) 상점 추가

[2015/12/20] (v1.1.1) 상점정보 명령어 추가, 아이템 한글명 추가 (염색된 점토들, 양조기, 마법부여대, 꽃들, 황금사과, 나무들)

[2015-12-26] (v1.1.2) 거래 요청시 아이템수가 잘못뜨던 현상 수정, 데미지 구분오류 수정
			 코드정리

[2015-12-30] (v1.1.2.1) 상점에 엔티티가 보이지않던 현상 수정
			 도구는 업로드가 불가능하도록 수정

[2016-01-17] (v1.1.5) 클라우드 추가/제거 기능 추가 및 오류 수정

[2016-01-22] (v1.1.7) 클라우드 데이터 변환코드 적용

[2016-01-24] (v1.2.0) 거래 수락시 오류발생하던 현상 수정, 아이템경매 추가, 플러그인이름변경 - iDeal

정수오류 수정

*/
?>