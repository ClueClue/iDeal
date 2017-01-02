<?php
/*
   Auction - 아이템 경매
*/

namespace inflater\iDeal\event;

use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\PluginTask;
use inflater\iDeal\iDeal;

class AuctionEventd implements Listener {
	private $plugin, $data;

	public function __construct(iDeal $plugin) {
		$this->plugin = $plugin;
	}

	public function onPlayerJoin(PlayerJoinEvent $ev) {
		if(isset($this->data)) { if($this->data['player']===$ev->getPlayer()->getName()) { $this->data['player'] = $ev->getPlayer(); } }
	}
	public function onPlayerQuit(PlayerQuitEvent $ev) {
		if(isset($this->data)) { if($this->data['player']===$ev->getPlayer()) { $this->data['player'] = $ev->getPlayer()->getName(); } }
	}

	public function start($player, $args) {
		$playerN = strtolower($player->getName());
		
		if(isset($this->data)) { $this->plugin->sMsg($player, 'auction-start-fail-0', 4, TextFormat::RED); return; }
		if((isset($args[3]) ? $args[3] : null) !== "y") {
			$this->plugin->sMsg($player, 'auction-start-0', 4);
			$this->plugin->sMsg($player, 'auction-start-1', 4);
			$this->plugin->sMsg($player, 'auction-start-2', 4, TextFormat::RED);
			return;
		}
		if($args[1]<=0) { $this->plugin->sMsg($player, 'auction-start-fail-3', 4, TextFormat::RED); return; }

		$item = $player->getInventory()->getItemInHand();
		$itemCode = $item->getId() . ':' . $item->getDamage();
		$have = 0;
		foreach($player->getInventory()->getContents() as $content) {
			if($content->getId()===$item->getId() && $content->getDamage()===$item->getDamage()) { $have += $content->getCount(); }
		}
		
		if($this->plugin->getItemName($itemCode) === false) { $this->plugin->sMsg($player, 'auction-start-fail-1', 0, TextFormat::RED); return; }
		if($args[1] > $have) { $this->plugin->sMsg($player, 'auction-start-fail-2', 4, TextFormat::RED); return; }
		
		$this->data['player'] = $player;
		$this->data['item'] = $itemCode;
		$this->data['count'] = (int) $args[1];
		$this->data['biddingUser'] = null;
		$this->data['bid'] = (int) $args[2];
		$this->data['_']['time'] = 5;
		$this->plugin->sMsg('all', 'auction-start-3', 4, null, [$player->getName(), $this->plugin->getItemName($itemCode), (int) $args[1], (int) $args[2]]);
		$this->plugin->sMsg('all', 'auction-start-4', 4);
		$this->data['_']['TaskId'] = $this->plugin->getServer()->getScheduler()->scheduleDelayedTask(new task($this->plugin), 250);
		return;
	}

	public function bidding($player, $args) {
		$playerN = strtolower($player->getName());
		if(!isset($this->data)) { $this->plugin->sMsg($player, 'auction-bidding-fail-0', 4, TextFormat::RED); return; }
		if(!isset($args[1])) { $this->plugin->sMsg($player, 'auction-bidding-usage', 4); return; }
		if(floor($this->data['bid'] + ($this->data['bid']/20)) >= (int) $args[1]) {
			$this->plugin->sMsg($player, 'auction-bidding-fail-1', 4, TextFormat::RED, [floor($this->data['bid'] + ($this->data['bid']/20)), $this->data['bid']]); return;
		}
		if($this->plugin->EconomyAPI->myMoney($player) < (int) $args[1]) { $this->plugin->sMsg($player, 'auction-bidding-fail-2', 4, null, [(int) $this->data['bid'], $this->plugin->EconomyAPI->myMoney($player)]); return; }
		
		$this->data['biddingUser'] = $player;
		$temp = $this->data['bid'];
		$this->data['bid'] = (int) $args[1];
		$this->plugin->sMsg('all', 'auction-bidding-0', 4, null, [$player->getName(), (int) $args[1], $temp]);
		$this->plugin->sMsg('all', 'auction-bidding-1', 4);
		$this->data['_']['time'] = 5;
		if(isset($this->data['_']['Task'])) { $this->data['_']['Task']->cancel(); }
		$this->data['_']['Task'] = $this->plugin->getServer()->getScheduler()->scheduleDelayedTask(new task($this->plugin), 150);
		return;
	}

	public function sBid() {
		if($this->data['_']['time'] > 0) {
			if($this->data['_']['time']===5 || $this->plugin->setting['auction-countdown']) {
				if(!isset($this->data['biddingUser'])) { $this->plugin->sMsg('all', 'auction-successfulBid-1', 4, null, [$this->data['_']['time']]); }
				else{ $this->plugin->sMsg('all', 'auction-successfulBid-0', 4, null, [$this->data['_']['time'], $this->data['biddingUser']->getName(), $this->plugin->getItemName($this->data['item']), $this->data['count'], $this->data['bid']]); }
			}
			if(isset($this->data['_']['Task'])) { $this->data['_']['Task']->cancel(); }
			$this->data['_']['time']--;
			$this->data['_']['Task'] = $this->plugin->getServer()->getScheduler()->scheduleDelayedTask(new task($this->plugin), 20);
		}else{
			if(isset($this->data['_']['Task'])) { $this->data['_']['Task']->cancel(); }
			if(!isset($this->data['biddingUser'])) { $this->plugin->sMsg('all', 'auction-successfulBid-fail-3', 4, TextFormat::RED); unset($this->data); return; }
			if(!$this->data['player'] instanceof Player || !$this->data['biddingUser'] instanceof Player) {
				$this->plugin->sMsg('all', 'auction-successfulBid-fail-0', 4, TextFormat::RED); unset($this->data); return;
			}
			$item = explode(':', $this->data['item']);
			$have = 0;
			foreach($this->data['player']->getInventory()->getContents() as $content) {
				if($content->getId()===(int) $item[0] && $content->getDamage()===(int) $item[1]) { $have += $content->getCount(); }
			}
			if($have < $this->data['count']) { $this->plugin->sMsg('all', 'auction-successfulBid-fail-1', 4, TextFormat::RED); unset($this->data); return; }
			if($this->plugin->EconomyAPI->myMoney($this->data['biddingUser']) < $this->data['bid']) { $this->plugin->sMsg('all', 'auction-successfulBid-fail-2', 4, TextFormat::RED); unset($this->data); return; }
			$this->plugin->sMsg('all', 'auction-successfulBid-2', 4, TextFormat::DARK_GREEN, [$this->data['biddingUser']->getName(), $this->plugin->getItemName($this->data['item']), $this->data['count'], $this->data['bid']]);

			$this->data['player']->getInventory()->removeItem(Item::get((int) $item[0], (int) $item[1], (int) $this->data['count']));
			$this->data['biddingUser']->getInventory()->addItem(Item::get((int) $item[0], (int) $item[1], (int) $this->data['count']));
			$this->plugin->sMsg($this->data['biddingUser'], 'auction-successfulBid-3', 4, null, [$this->plugin->getItemName($this->data['item']), $this->data['count']]);

			$this->plugin->EconomyAPI->reduceMoney($this->data['biddingUser'], $this->data['bid']);
			$money = $this->data['bid'];
			if($this->plugin->setting['auction-commission']!==false) {
				$cms = explode('/', $this->plugin->setting['auction-commission']);
				if(!isset($cms[1])) {
					if($this->data['bid'] > $this->plugin->setting['auction-commission']) { $money = $this->data['bid'] - $this->plugin->setting['auction-commission']; }
				}elseif($this->data['bid'] > $cms[0]/$cms[1]) { $money = floor($this->data['bid'] - (($this->data['bid']/$cms[1])*$cms[0])); }
			}
			$this->plugin->EconomyAPI->addMoney($this->data['player'], $money);
			$this->plugin->sMsg($this->data['player'], 'auction-successfulBid-4', 4, null, [$money]);
			
			unset($this->data);
		}
	}
}

class task extends PluginTask{
	public function __construct(iDeal $plugin){
		parent::__construct($plugin);
    }
	public function onRun($tick){
		$this->getOwner()->auctionEvent->sBid();
	}
}
?>