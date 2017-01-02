<?php
/*
   Vmachine - 아이템 자판기
*/

namespace inflater\iDeal\event;

use pocketmine\plugin\PluginBase; //used
use pocketmine\event\Listener; //used
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Item;
use pocketmine\block\Block;
use pocketmine\level\Position;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\scheduler\PluginTask;
use pocketmine\math\Vector3;
use inflater\iDeal\iDeal;

class AuctionEvent implements Listener {
	private $plugin, $temp, $dataConfig, $data, $sysData;

	public function __construct(iDeal $plugin) {
		$this->plugin = $plugin;
		$this->dataConfig = (new Config($this->plugin->getDataFolder() . 'auction.json', Config::JSON, ['_' => ['lastCheck' => date('d')]]));
		$this->data = $this->dataConfig->getAll();
		$this->sysData['lastCheck'] = date('md');
	}

	public function install($sender, Array $args) { // 경매기 설치
		// <---- Command-Part ---->
		if(count($args) < 2) {
			$this->sMsg($sender, 'auction-install-usage');
			return false;
		}
		// <---- Work-Part ---->
		$this->sMsg($sender, 'auction-install-0');
		$this->sMsg($sender, 'auction-install-1');
		$this->temp[strtolower($sender->getName())]['install']['fare'] = (int) $args[1];
	}

	public function registration($sender, Array $args) { // 경매기에 경매 등록
		// <---- Command-Part ---->
		if(count($args) < 2) {
			$this->sMsg($sender, 'auction-registration-usage');
			$this->sMsg($sender, 'auction-registration-usage2');
			return false;
		}
		$args[2] = isset($args[2]) ? $args[2] : 0;

		// <---- Work-Part ---->
		$item = $sender->getInventory()->getItemInHand();
		if($item->getId() === 0) {
			$this->sMsg($sender, 'auction-registration-fail-0');
			return false;
		}else{ $itemCode = $item->getId().':'.$item->getDamage(); }

		foreach(explode(',', str_replace(' ', '', $this->plugin->setting['auction_ban-item'])) as $banItem) {
			if((!strpos($banItem, ':') ? $banItem.':0' : $banItem) === $itemCode) { $this->plugin->sMsg($sender, 'auction-registration-fail-1', TextFormat::RED); return false; }
		}

		if((int) $args[1] <= 0) { $this->sMsg($sender, 'auction-registration-fail-2'); return false; }
		if((int) $args[2] < 0) { $this->sMsg($sender, 'auction-registration-fail-3'); return false; }

		$this->temp[strtolower($sender->getName())]['registration']['itemCode'] = $itemCode;
		$this->temp[strtolower($sender->getName())]['registration']['amount'] = (int) $args[1];
		$this->temp[strtolower($sender->getName())]['registration']['lowestBid'] = (int) $args[2];

		$this->sMsg($sender, 'auction-registration-0');
	}

	public function buy($player, $amount) {
		$playerN = strtolower($player->getName());
		$levelN = $player->getLevel()->getName();

		if(!isset($this->temp[$playerN]['buy'])) { return false; }
		$data = $this->data[$levelN][$this->temp[$playerN]['buy']];

		// <--- Filter ---> //
		if($amount > $data['amount']) { $this->sMsg($player, 'vmachine-buy-fail-0', TextFormat::RED, [$data['amount']]); return; }
		if($this->plugin->EconomyAPI->myMoney($player) < $data['price'] * $amount) {
			$this->sMsg($player, 'vmachine-buy-fail-1', TextFormat::RED, [$this->plugin->EconomyAPI->myMoney($player), $data['price'] * $amount]);
			return;
		}
		foreach(explode(',', str_replace(' ', '', $this->plugin->setting['vmachine_ban-item'])) as $banItem) {
			if((!strpos($banItem, ':') ? $banItem.':0' : $banItem) === $data['item']) { $this->sMsg($player, 'vmachine-buy-fail-2', TextFormat::RED); return; }
		}

		// <--- Buy ---> //
		$player->getInventory()->addItem(Item::get((int) explode(':', $data['item'])[0], (int) explode(':', $data['item'])[1], $amount));
		$this->plugin->EconomyAPI->reduceMoney($player, $data['price'] * $amount);
		$this->data[$levelN][$this->temp[$playerN]['buy']]['profits'] += $data['price'] * $amount;
		$this->data[$levelN][$this->temp[$playerN]['buy']]['CUMprofits'] += $data['price'] * $amount;
		$this->data[$levelN][$this->temp[$playerN]['buy']]['amount'] -= $amount;
		$this->saveData();

		unset($this->temp[$playerN]['buy']);
		$this->sMsg($player, 'vmachine-buy-1', null, [$this->plugin->getItemName($data['item']), $amount, $data['price'] * $amount]);
	}

	public function remove($sender) {
		$senderN = strtolower($sender->getName());
		$this->sMsg($sender, 'vmachine-remove-0');
		$this->sMsg($sender, 'vmachine-remove-1', TextFormat::RED);
		$this->sMsg($sender, 'vmachine-remove-2');
		$this->temp[$senderN]['remove'] = true;
	}

	public function onPlayerInteract(PlayerInteractEvent $ev) { // 자판기 터치
		if($ev->getFace() === 255 && $ev->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) { return; }
		$player = $ev->getPlayer();
		$playerN = strtolower($player->getName());
		$block = $ev->getBlock();
		$blockPos = $block->x.'.'.$block->y.'.'.$block->z;
		$levelN = $block->getLevel()->getName();

		if(isset($this->temp[$playerN]['install'])) {
			$ev->setCancelled();

			$replaceBlock = $block->getSide($ev->getFace());
			if($replaceBlock->getId() !== 0) { return; }
			$block->getLevel()->setBlock($replaceBlock, Block::get(Item::GLASS), true);

			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['fare'] = $this->temp[$playerN]['install']['fare'];
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['owner'] = null;
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['item'] = null;
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['amount'] = null;
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['lowestBid'] = null;

			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['bid'] = null;
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['bidUser'] = null;
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['useAmount'] = 0;
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['installDate'] = date('Y-m-d H:i:s');
			$this->saveData();
			
			unset($this->temp[$playerN]['install']);
			$this->sMsg($player, 'auction-install-2');
		}elseif(isset($this->temp[$playerN]['registration']) && isset($this->data[$levelN][$blockPos])) {
			$ev->setCancelled();

			$data = $this->data[$levelN][$blockPos];
			if($data['owner'] !== null) { $this->sMsg($player, 'auction-registration-fail-4'); return false; }
			$temp = $this->temp[$playerN]['registration'];

			// <--- Money ---> //
			if($this->plugin->EconomyAPI->myMoney($player) < $data['fare']) {
				$this->sMsg($player, 'auction-registration-fail-5', null, [$this->plugin->EconomyAPI->myMoney($player), $data['fare']]);
				return false;
			}else{ $this->plugin->EconomyAPI->reduceMoney($player, $data['fare']); }

			// <--- Inventory ---> //
			$have = 0;
			foreach($player->getInventory()->getContents() as $haveItem) {
				if($temp['itemCode'] === $haveItem->getId().':'.$haveItem->getDamage()) { $have += $haveItem->getCount(); }
			}
			if($have < $temp['amount']) { $this->sMsg($player, 'auction-registration-fail-6', null, [$have, $temp['amount']]); return false; }
			$player->getInventory()->removeItem(Item::get(explode(':', $temp['itemCode'])[0], explode(':', $temp['itemCode'])[1], $temp['amount']));

			// <--- Data Config ---> //
			$this->data[$levelN][$blockPos]['owner'] = $playerN;
			$this->data[$levelN][$blockPos]['item'] = $temp['itemCode'];
			$this->data[$levelN][$blockPos]['amount'] = $temp['amount'];
			$this->data[$levelN][$blockPos]['lowestBid'] = $temp['lowestBid'];
			$this->data[$levelN][$blockPos]['useAmount']++;
			$this->saveData();

			// <--- Entity ---> //
			$this->plugin->event->addItemEntity('all', new Position($block->x, $block->y, $block->z, $block->getLevel()), $temp['itemCode']);

			unset($this->temp[$playerN]['registration']);
			$this->sMsg($player, 'auction-registration-1', null, [$this->plugin->getItemName($temp['itemCode']), $temp['amount'], $temp['lowestBid']]);
		}elseif(isset($this->data[$levelN][$blockPos])) {
			$ev->setCancelled();
			$data = $this->data[$levelN][$blockPos];
			if($data['owner'] === $playerN) {
				$this->sMsg($player, 'auction-info-0', null, [$this->sysData['task']->timings]);
			}else{
				$this->sMsg($player, 'auction-info-3');
			}
		}
	}

	public function onPlayerJoin(PlayerJoinEvent $ev) {
		if($this->data['_']['lastCheck'] !== date('md')) {
		}

		if(empty($this->data[$ev->getPlayer()->getLevel()->getName()])) { return; }
		foreach($this->data[$ev->getPlayer()->getLevel()->getName()] as $pos => $data) {
			if($data['item'] === null || $data['item'] === '0:0') { continue; }
			$pos = explode('.', $pos);
			$this->plugin->event->addItemEntity([$ev->getPlayer()], new Position($pos[0], $pos[1], $pos[2], $ev->getPlayer()->getLevel()), $data['item']);
		}
	}

	public function onBlockBreak(BlockBreakEvent $ev) {
		$player = $ev->getPlayer();
		$playerN = strtolower($player->getName());
		$block = $ev->getBlock();
		$levelN = $block->getLevel()->getName();

		if(isset($this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"])) {
			if(!isset($this->temp[$playerN]['remove'])) { $ev->setCancelled(); return; }
			$temp = $this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"];
			if($temp['owner'] !== $playerN && !$player->isOp()) { return; }

			unset($this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"]);
			$this->saveData();

			$this->plugin->event->removeItemEntity('all', "{$block->x}.{$block->y}.{$block->z}");

			unset($this->temp[$playerN]['remove']);
			$this->sMsg($player, 'vmachine-remove-3');
		}
	}

	public function workCancel($sender) {
		$senderN = strtolower($sender->getName());
		unset($this->temp[$senderN]);
		$this->plugin->sMsg($sender, 'vmachine-work-cancelled', 2, TextFormat::RED);
	}

	public function sMsg($player, $message, $colour = null, $args = []) {
		while(count($args)<5) { $args[count($args)] = ''; }
		if($colour === null) { $colour = TextFormat::DARK_GREEN; }
		if(isset($this->plugin->message[$message])) { $message = $this->plugin->message[$message]; }
		$player->sendMessage($colour . $this->plugin->message['auction-default-mark'] . ' ' . str_replace(array('{%Monetary-Unit}','{%0}', '{%1}', '{%2}', '{%3}', '{%4}'), array($this->plugin->EconomyAPI->getInstance()->getMonetaryUnit(), $args[0], $args[1], $args[2], $args[3], $args[4]), $message));
	}

	public function saveData() {
		$this->dataConfig->setAll($this->data);
		$this->dataConfig->save();
	}
}
?>