<?php
/*
   Shop - 상점
*/

namespace inflater\iDeal\event;

use pocketmine\plugin\PluginBase; //used
use pocketmine\event\Listener; //used
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\level\Position;
use pocketmine\item\Item;
use pocketmine\utils\TextFormat;
use inflater\iDeal\iDeal;

class ShopEvent implements Listener {
	private $plugin;

	public function __construct(iDeal $plugin) {
		$this->plugin = $plugin;
	}

	public function create($player) {
		$playerN = strtolower($player->getName());
		if(isset($this->plugin->data['shopmode'][$playerN])) {
			unset($this->plugin->data['shopmode'][$playerN]);
			$this->plugin->sMsg($player, 'shop-mode-2', 3);
		}else{
			$this->plugin->data['shopmode'][$playerN] = true;
			$this->plugin->sMsg($player, 'shop-mode-0', 3);
			$this->plugin->sMsg($player, 'shop-mode-1', 3);
		}
	}

	public function info($player) {
		$playerN = strtolower($player->getName());
		$pos = isset($this->plugin->data['shopDeal'][$playerN]['buy']) ? $this->plugin->data['shopDeal'][$playerN]['buy'] : null;
		if($pos==null) { $pos = isset($this->plugin->data['shopDeal'][$playerN]['sell']) ? $this->plugin->data['shopDeal'][$playerN]['sell'] : null; }
		if($pos==null) { $this->plugin->sMsg($player, 'shop-info-fail-0', 3, TextFormat::RED); return; }
		$bV = $this->plugin->shop[$player->getLevel()->getName()][$pos]['buyVolume'];
		$sV = $this->plugin->shop[$player->getLevel()->getName()][$pos]['sellVolume'];
		$this->plugin->sMsg($player, 'shop-info-0', 3, null, [$pos]);
		$this->plugin->sMsg($player, 'shop-info-1', 3, null, [$bV]);
		$this->plugin->sMsg($player, 'shop-info-2', 3, null, [$sV]);
		return;
	}

	public function buyItem($sender, $num) { //구매
		$senderN = strtolower($sender->getName());
		if(!isset($this->plugin->data['shopDeal'][$senderN]['buy'])) { $this->plugin->sMsg($sender, 'buy-fail-0', 3); return; }
		if($num<1) { $this->plugin->sMsg($sender, 'shop-deal-fail-0', 3); return; }
		$pos = $this->plugin->data['shopDeal'][$senderN]['buy'];
		
		$item = explode(':', $this->plugin->shop[$sender->getLevel()->getName()][$pos]['item']);
		if(!isset($item[1])) { $item[1] = '0'; }
		$price = $this->plugin->shop[$sender->getLevel()->getName()][$pos]['buy'];
		if($this->plugin->EconomyAPI->myMoney($sender) < $price*$num) { $this->plugin->sMsg($sender, 'buy-fail-1', 3); return; }
		$this->plugin->EconomyAPI->reduceMoney($sender, $price*$num);
		$sender->getInventory()->addItem(Item::get($item[0], $item[1], $num));
		$this->plugin->sMsg($sender, 'buy-0', 3, null, [$this->plugin->getItemName($item[0].':'.$item[1]), $num, $price*$num]);

		$this->plugin->shop[$sender->getLevel()->getName()][$pos]['buyVolume'] += $num;
		$this->saveData();
	}

	public function sellItem($sender, $num) { //판매
		$senderN = strtolower($sender->getName());
		if(!isset($this->plugin->data['shopDeal'][$senderN]['sell'])) { $this->plugin->sMsg($sender, 'sell-fail-0', 3); return; }
		if($num<1) { $this->plugin->sMsg($sender, 'shop-deal-fail-0', 3); return; }
		$pos = $this->plugin->data['shopDeal'][$senderN]['sell'];
		
		$item = explode(':', $this->plugin->shop[$sender->getLevel()->getName()][$pos]['item']);
		if(!isset($item[1])) { $item[1] = '0'; }
		$price = $this->plugin->shop[$sender->getLevel()->getName()][$pos]['sell'];

		$count = 0;
		foreach($sender->getInventory()->getContents() as $sitem) {
			if($sitem->getId().':'.$sitem->getDamage() == $this->plugin->shop[$sender->getLevel()->getName()][$pos]['item']) { $count += $sitem->getCount(); }
		}
		if($count < $num) { $this->plugin->sMsg($sender, 'sell-fail-1', 3); return; }
		$this->plugin->EconomyAPI->addMoney($sender, $price*$num);
		$sender->getInventory()->removeItem(Item::get($item[0], $item[1], $num));
		$this->plugin->sMsg($sender, 'sell-0', 3, null, [$this->plugin->getItemName($item[0].':'.$item[1]), $num, $price*$num]);

		$this->plugin->shop[$sender->getLevel()->getName()][$pos]['sellVolume'] += $num;
		$this->saveData();
	}

	public function onPlayerChat(PlayerChatEvent $ev) {
		$player = $ev->getPlayer();
		$playerN = strtolower($player->getName());
		$message = $ev->getMessage();

		if(isset($this->plugin->data['shop'][$playerN]['buy'])) { $mode = 'buy'; }
		elseif(isset($this->plugin->data['shop'][$playerN]['sell'])) { $mode = 'sell'; }else{ return; }

		$price = false;
		if(is_numeric($message)) { $price = (int) $message; }
		elseif($message != 'false') { return; }
		
		$this->plugin->shop[$player->getLevel()->getName()][$this->plugin->data['shop'][$playerN][$mode]][$mode] = $price;

		if($mode == 'buy') {
			$this->plugin->data['shop'][$playerN]['sell'] = $this->plugin->data['shop'][$playerN]['buy'];
			$this->plugin->sMsg($player, 'shop-setting-1', 3);
		}else{ $this->plugin->sMsg($player, 'shop-setting-2', 3); }

		unset($this->plugin->data['shop'][$playerN][$mode]);
		$ev->setCancelled();
		$this->saveData();
	}

	public function onPlayerInteract(PlayerInteractEvent $ev) {
		$sender = $ev->getPlayer();
		$senderN = strtolower($sender->getName());
		$block = $ev->getBlock();
		$bpos = $block->x.'.'.$block->y.'.'.$block->z;
		$levelN = $block->getLevel()->getName();

		if(isset($this->plugin->data['shopmode'][$senderN])) {
			if($ev->getFace()==255&&$ev->getAction() != PlayerInteractEvent::RIGHT_CLICK_BLOCK) { return; }
			if($block->getId()!='20') { return; }
			if(isset($this->plugin->shop[$levelN][$bpos])) { return; }
			$this->plugin->shop[$levelN][$bpos]['item'] = $ev->getItem()->getId().':'.$ev->getItem()->getDamage();
			$this->plugin->shop[$levelN][$bpos]['buy'] = false;
			$this->plugin->shop[$levelN][$bpos]['buyVolume'] = 0;
			$this->plugin->shop[$levelN][$bpos]['sell'] = false;
			$this->plugin->shop[$levelN][$bpos]['sellVolume'] = 0;
			unset($this->plugin->data['shopmode'][$senderN]);
			$this->plugin->data['shop'][$senderN]['buy'] = $bpos;
			$this->plugin->sMsg($sender, 'shop-setting-0', 3);
			$this->saveData(); //상점생성
			$ev->setCancelled();

			$this->plugin->event->addItemEntity('all', new Position($block->x, $block->y, $block->z, $block->getLevel()), $ev->getItem());
			$this->plugin->data['shopmode'][$senderN] = true;
		}elseif(isset($this->plugin->shop[$levelN][$bpos])) { //상점이용
			$ev->setCancelled();
			unset($this->plugin->data['shopDeal'][$senderN]);
			if($this->plugin->shop[$levelN][$bpos]['buy']!==false&&$this->plugin->shop[$levelN][$bpos]['sell']!==false) {
				$this->plugin->sMsg($sender, 'shop-deal-0', 3, null, [$this->plugin->getItemName($this->plugin->shop[$levelN][$bpos]['item'])]);
				$this->plugin->sMsg($sender, 'shop-deal-1', 3, null, [$this->plugin->shop[$levelN][$bpos]['buy'], $this->plugin->shop[$levelN][$bpos]['sell']]);
				$this->plugin->data['shopDeal'][$senderN]['buy'] = $bpos;
				$this->plugin->data['shopDeal'][$senderN]['sell'] = $bpos;
			}elseif($this->plugin->shop[$levelN][$bpos]['buy']!==false) {
				$this->plugin->sMsg($sender, 'shop-deal-2', 3, null, [$this->plugin->getItemName($this->plugin->shop[$levelN][$bpos]['item']), $this->plugin->shop[$levelN][$bpos]['buy']]);
				$this->plugin->data['shopDeal'][$senderN]['buy'] = $bpos;
			}elseif($this->plugin->shop[$levelN][$bpos]['sell']!==false) {
				$this->plugin->sMsg($sender, 'shop-deal-3', 3, null, [$this->plugin->getItemName($this->plugin->shop[$levelN][$bpos]['item']), $this->plugin->shop[$levelN][$bpos]['sell']]);
				$this->plugin->data['shopDeal'][$senderN]['sell'] = $bpos;
			}
		}
	}

	public function onBlockBreak(BlockBreakEvent $ev) {
		$sender = $ev->getPlayer();
		$senderN = strtolower($sender->getName());
		$block = $ev->getBlock();
		$bpos = $block->x.'.'.$block->y.'.'.$block->z;
		$levelN = $block->getLevel()->getName();

		if(isset($this->plugin->shop[$levelN][$bpos])) {
			if($sender->isOp()) {
				unset($this->plugin->shop[$levelN][$bpos]);
				$this->saveData();
				$this->plugin->sMsg($sender, 'shop-remove-0', 3, TextFormat::RED);
				$this->plugin->event->removeItemEntity('all', $bpos);
			}
		}
	}

	public function saveData() {
		$this->plugin->shopConfig->setAll($this->plugin->shop);
		$this->plugin->shopConfig->save();
	}
}
?>