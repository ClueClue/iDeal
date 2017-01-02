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
use pocketmine\math\Vector3;
use inflater\iDeal\iDeal;

class VmachineEvent implements Listener {
	private $plugin, $temp, $dataConfig, $data;

	public function __construct(iDeal $plugin) {
		$this->plugin = $plugin;
		$this->dataConfig = (new Config($this->plugin->getDataFolder() . 'Vmachine.json', Config::JSON));
		$this->data = $this->dataConfig->getAll();
	}

	public function install($sender, Array $args) { // 자판기 설치
		// <---- Command-Part ---->
		if((isset($args[1]) ? $args[1] : null) !== 'y') {
			$this->plugin->sMsg($sender, 'vmachine-install-0', 2, null, [$this->plugin->setting['vmachine_install-price']]);
			$this->plugin->sMsg($sender, 'vmachine-install-1', 2);
			return false;
		}
		// <---- Work-Part ---->
		if($this->plugin->EconomyAPI->myMoney($sender) < $this->plugin->setting['vmachine_install-price']) {
			$this->plugin->sMsg($sender, 'vmachine-install-fail-0', 2, TextFormat::RED, [$this->plugin->EconomyAPI->myMoney($sender)]); return false;
		}
		$this->plugin->sMsg($sender, 'vmachine-install-2', 2);
		$this->plugin->sMsg($sender, 'vmachine-install-3', 2);
		$this->temp[strtolower($sender->getName())]['install'] = true;
	}

	public function replace($sender, Array $args) { // 자판기 물품 변경
		// <---- Command-Part ---->
		if(count($args)!=3) {
			$this->plugin->sMsg($sender, 'vmachine-replace-usage-0', 2);
			$this->plugin->sMsg($sender, 'vmachine-replace-usage-1', 2);
			return;
		}
		// <---- Work-Part ---->
		$item = $sender->getInventory()->getItemInHand();
		$itemCode = $item->getId().':'.$item->getDamage();

		foreach(explode(',', str_replace(" ", "", $this->plugin->setting['vmachine_ban-item'])) as $banItem) {
			if((!strpos($banItem, ':') ? $banItem.':0' : $banItem) === $itemCode) { $this->plugin->sMsg($sender, 'vmachine-replace-fail-0', 2, TextFormat::RED); return false; }
		}

		$this->temp[strtolower($sender->getName())]['replace']['itemCode'] = $itemCode;
		$this->temp[strtolower($sender->getName())]['replace']['amount'] = (int) $args[1];
		$this->temp[strtolower($sender->getName())]['replace']['price'] = (int) $args[2];

		$this->plugin->sMsg($sender, 'vmachine-replace-0', 2);
		$this->plugin->sMsg($sender, 'vmachine-replace-1', 2);
	}

	public function takeout($player, Array $args) {
		$this->temp[strtolower($player->getName())]['takeout'] = true;
		$this->sMsg($player, 'vmachine-takeout-0');
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

	public function withdrawal($sender) {
		$this->temp[strtolower($sender->getName())]['withdrawal'] = true;
		$this->plugin->sMsg($sender, 'vmachine-withdrawal-0', 2);
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
		$levelN = $block->getLevel()->getName();

		if(isset($this->temp[$playerN]['install'])) {
			$ev->setCancelled();
			$replaceBlock = $block->getSide($ev->getFace());

			if(!$player->isOp()) {
				$areaData = $this->plugin->AreaAPI->getAreaData($replaceBlock->x, null, $replaceBlock->z);
				if($areaData === false) { return false; }
				if(!$this->plugin->AreaAPI->getPermission($player, $areaData['id'], '블럭수정')) { return false; }
			}

			if($replaceBlock->getId() !== 0) { return; }
			if($this->plugin->EconomyAPI->myMoney($player) < $this->plugin->setting['vmachine_install-price']) {
				$this->plugin->sMsg($player, 'vmachine-install-fail-0', 2, TextFormat::RED, [$this->plugin->EconomyAPI->myMoney($player)]); return false;
			}else{ $this->plugin->EconomyAPI->reduceMoney($player, (int) $this->plugin->setting['vmachine_install-price']); }

			$block->getLevel()->setBlock($replaceBlock, Block::get(Item::GLASS), true);

			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['owner'] = $playerN;
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['item'] = null;
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['amount'] = null;
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['price'] = null;
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['CUMprofits'] = 0;
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['profits'] = 0;
			$this->data[$levelN]["{$replaceBlock->x}.{$replaceBlock->y}.{$replaceBlock->z}"]['installDate'] = date('Y-m-d H:i:s');
			$this->saveData();
			
			unset($this->temp[$playerN]['install']);
			$this->plugin->sMsg($player, 'vmachine-install-4', 2);
		}elseif(isset($this->temp[$playerN]['replace'])) {
			$ev->setCancelled();
			if(!isset($this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"])) { return; }
			$temp = $this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"];
			if($temp['owner'] !== $playerN) { return; }

			$itemCode = $this->temp[$playerN]['replace']['itemCode'];

			// <--- Inventory ---> //
			$have = 0;
			foreach($player->getInventory()->getContents() as $haveItem) {
				if($itemCode === $haveItem->getId().':'.$haveItem->getDamage()) { $have += $haveItem->getCount(); }
			}
			if($have < $this->temp[$playerN]['replace']['amount']) { $this->temp[$playerN]['replace']['amount'] = $have; }
			$player->getInventory()->removeItem(Item::get(explode(':', $itemCode)[0], explode(':', $itemCode)[1], $this->temp[$playerN]['replace']['amount']));
			if($temp['item'] !== null) $player->getInventory()->addItem(Item::get(explode(':', $temp['item'])[0], explode(':', $temp['item'])[1], $temp['amount']));

			// <--- Data Config ---> //
			$this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"]['item'] = $itemCode;
			$this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"]['amount'] = $this->temp[$playerN]['replace']['amount'];
			$this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"]['price'] = $this->temp[$playerN]['replace']['price'];
			$this->saveData();

			// <--- Entity ---> //
			$this->plugin->event->removeItemEntity($this->plugin->getServer()->getOnlinePlayers(), "{$block->x}.{$block->y}.{$block->z}");
			$this->plugin->event->addItemEntity('all', new Position($block->x, $block->y, $block->z, $block->getLevel()), $this->temp[$playerN]['replace']['itemCode']);

			unset($this->temp[$playerN]['replace']);
			$this->sMsg($player, 'vmachine-replace-2');
			$this->sMsg($player, 'vmachine-replace-3', null, [$this->plugin->getItemName($temp['item']), $temp['amount']]);
		}elseif(isset($this->temp[$playerN]['takeout']) && isset($this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"])) {
			$temp = $this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"];
			if($temp['owner'] !== $playerN) { return; }
			$player->getInventory()->addItem(Item::get(explode(':', $temp['item'])[0], explode(':', $temp['item'])[1], $temp['amount']));
			$this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"]['item'] = null;
			$this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"]['amount'] = null;
			$this->saveData();

			$this->plugin->event->removeItemEntity($this->plugin->getServer()->getOnlinePlayers(), "{$block->x}.{$block->y}.{$block->z}");

			unset($this->temp[$playerN]['takeout']);
			$this->sMsg($player, 'vmachine-takeout-1', null, [$this->plugin->getItemName($temp['item']), $temp['amount']]);
		}elseif(isset($this->temp[$playerN]['withdrawal']) && isset($this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"])) {
			$temp = $this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"];
			if($temp['owner'] !== $playerN) { return; }
			$this->plugin->EconomyAPI->addMoney($player, $temp['profits']);
			$this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"]['profits'] = 0;
			$this->saveData();

			unset($this->temp[$playerN]['withdrawal']);
			$this->sMsg($player, 'vmachine-withdrawal-1', null, [$temp['profits']]);
		}elseif(isset($this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"])) {
			$ev->setCancelled();
			$temp = $this->data[$levelN]["{$block->x}.{$block->y}.{$block->z}"];
			if($temp['owner'] === $playerN) {
				if($temp['item'] === null || $temp['amount'] === null) { $this->sMsg($player, 'vmachine-info-2_1'); }
				else{ $this->sMsg($player, 'vmachine-info-2', null, [$this->plugin->getItemName($temp['item']), $temp['amount'], $temp['price']]); }
				$this->sMsg($player, 'vmachine-info-3', null, [$temp['CUMprofits']]);
				$this->sMsg($player, 'vmachine-info-4', null, [$temp['profits']]);
			}elseif($temp['item'] !== null && $temp['amount'] !== null) {
				$this->sMsg($player, 'vmachine-info-0', null, [$temp['owner']]);
				$this->sMsg($player, 'vmachine-info-1', null, [$temp['amount']]);
				$this->sMsg($player, 'vmachine-buy-0', null, [$this->plugin->getItemName($temp['item']), $temp['price']]);
				$this->temp[$playerN]['buy'] = "{$block->x}.{$block->y}.{$block->z}";
			}
		}else{
			if(isset($this->temp[$playerN]['buy'])) { unset($this->temp[$playerN]['buy']); }
		}
	}

	public function onPlayerJoin(PlayerJoinEvent $ev) {
		if(count($this->data) <= 0) { return; }
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
		$player->sendMessage($colour . $this->plugin->message['vmachine-default-mark'] . ' ' . str_replace(array('{%Monetary-Unit}','{%0}', '{%1}', '{%2}', '{%3}', '{%4}'), array($this->plugin->EconomyAPI->getInstance()->getMonetaryUnit(), $args[0], $args[1], $args[2], $args[3], $args[4]), $message));
	}

	public function saveData() {
		$this->dataConfig->setAll($this->data);
		$this->dataConfig->save();
	}
}
?>