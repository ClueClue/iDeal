<?php
/*
   Craft - 조합
*/

namespace inflater\iDeal\event;

use pocketmine\plugin\PluginBase; //used
use pocketmine\event\Listener; //used
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\level\Position;
use pocketmine\item\Item;
use pocketmine\utils\TextFormat;
use inflater\iDeal\iDeal;

class CraftEvent implements Listener {
	private $plugin, $data;

	public function __construct(iDeal $plugin) {
		$this->plugin = $plugin;
	}

	public function create($player) {
		$playerN = strtolower($player->getName());
		if(isset($this->data['createMode'][$playerN])) {
			unset($this->data['createMode'][$playerN]);
			$this->sMsg($player, 'craft-create-2', 5);
		}else{
			$this->data['createMode'][$playerN] = true;
			$this->sMsg($player, 'craft-create-0', 5);
			$this->sMsg($player, 'craft-create-1', 5);
		}
	}

	public function info($player) {
		$playerN = strtolower($player->getName());
		$pos = $this->data['craft'][$playerN];
		if($pos === null) { $this->plugin->sMsg($player, 'craft-info-fail-0', 5, TextFormat::RED); return; }
		$this->plugin->sMsg($player, 'craft-info-0', 5, null, [$pos]);
		$this->plugin->sMsg($player, 'craft-info-1', 5, null, [$this->plugin->craft[$player->getLevel()->getName()][$pos]['craftAmount']]);
		return;
	}

	public function craftItem($sender, $amount) { //조합
		$senderN = strtolower($sender->getName());
		if(!isset($this->data['craft'][$senderN])) { $this->plugin->sMsg($sender, 'craftItem-fail-0', 5); return; }
		if($amount<1) { $this->plugin->sMsg($sender, 'craftItem-fail-1', 5); return; }
		$pos = $this->data['craft'][$senderN];
		$levelN = $sender->getLevel()->getName();

		if($this->plugin->EconomyAPI->myMoney($sender) < $this->plugin->craft[$levelN][$pos]['price'] * $amount) {
			$this->plugin->sMsg($sender, 'craftItem-fail-2', 5); return;
		}

		foreach($this->plugin->craft[$levelN][$pos]['recipe'] as $itemCode => $recipeAmount) {
			$have = 0;
			foreach($sender->getInventory()->getContents() as $item) { if($item->getId() . ':' . $item->getDamage() == $itemCode) { $have += $item->getCount(); } }
			if($have < $recipeAmount * $amount) {
				$this->plugin->sMsg($sender, 'craftItem-fail-3', 5, TextFormat::RED, [$this->plugin->getItemName($itemCode), $have, $recipeAmount * $amount]);
				return;
			}
		}

		$this->plugin->EconomyAPI->reduceMoney($sender, $this->plugin->craft[$levelN][$pos]['price'] * $amount);
		foreach($this->plugin->craft[$levelN][$pos]['recipe'] as $itemCode => $recipeAmount) {
			$sender->getInventory()->removeItem(Item::get(explode(':', $itemCode)[0], explode(':', $itemCode)[1], $recipeAmount));
		}

		$item = explode(':', $this->plugin->craft[$levelN][$pos]['item']);
		$sender->getInventory()->addItem(Item::get($item[0], $item[1], $this->plugin->craft[$levelN][$pos]['itemAmount'] * $amount));

		$this->plugin->sMsg($sender, 'craftItem-1', 5, null, [$this->plugin->getItemName(implode(':', $item)), ($this->plugin->craft[$levelN][$pos]['itemAmount']*$amount)]);
		$this->plugin->craft[$levelN][$pos]['craftAmount'] += $amount;
		$this->saveData();

		$this->plugin->getLogger()->info(TextFormat::RED.'[ 조합 ] '.$senderN.'님이 '.implode(':', $item).' '.($this->plugin->craft[$levelN][$pos]['itemAmount']*$amount).'개를 조합하였습니다.');
	}

	public function onPlayerCommandPreprocess(PlayerCommandPreprocessEvent $ev) {
		if(strpos($message = $ev->getMessage(), '/') === 0 && isset($this->data['create'][strtolower($ev->getPlayer()->getName())])) {
			$player = $ev->getPlayer();
			$playerN = strtolower($player->getName());
			$message = str_replace('/', '', $message);

			$pos = $this->data['create'][$playerN];

			if(substr($message, 0, 3) !== 'fin') {
				$hand = $player->getInventory()->getItemInHand();
				$itemCode = $hand->getId() . ':' . $hand->getDamage();
				if(!isset($this->plugin->craft[$player->getLevel()->getName()][$pos]['recipe'][$itemCode])) {
					$this->plugin->craft[$player->getLevel()->getName()][$pos]['recipe'][$itemCode] = (int) $message;
				}else{ $this->plugin->craft[$player->getLevel()->getName()][$pos]['recipe'][$itemCode] += (int) $message; }
				$this->plugin->sMsg($player, 'craft-setting-1', 5, null, [$this->plugin->getItemName($itemCode), $message]);
			}else{
				$this->plugin->craft[$player->getLevel()->getName()][$pos]['itemAmount'] = (int) explode(' ', str_replace('fin ', '', $message))[0];
				$this->plugin->craft[$player->getLevel()->getName()][$pos]['price'] = (int) explode(' ', str_replace('fin ', '', $message))[1];
				unset($this->data['create'][$playerN]);
				$this->plugin->sMsg($player, 'craft-setting-2', 5);
			}

			$ev->setCancelled(true);
			$this->saveData();
		}
	}

	public function onPlayerInteract(PlayerInteractEvent $ev) {
		$sender = $ev->getPlayer();
		$senderN = strtolower($sender->getName());
		$block = $ev->getBlock();
		$pos = $block->x.'.'.$block->y.'.'.$block->z;
		$levelN = $block->getLevel()->getName();

		if(isset($this->data['craft'][$senderN])) {
			$temp = explode('.', $this->data['craft'][$senderN]);

			for($i = 0; $i <= floor((count($this->plugin->craft[$levelN][$this->data['craft'][$senderN]]['recipe'])-1)/3)+1; $i++) {
				$this->plugin->event->removeEntity([$sender], $temp[0].'.'.($temp[1]-$i).'.'.$temp[2]);
			}
		}

		if(isset($this->data['createMode'][$senderN])) { // 조합대 설치
			if($ev->getFace()===255 && $ev->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) { return; }
			if($block->getId() !== 20) { return; }
			if(isset($this->plugin->craft[$levelN][$pos])) { return; }
			unset($this->data['createMode'][$senderN]);

			$this->plugin->craft[$levelN][$pos]['item'] = $ev->getItem()->getId().':'.$ev->getItem()->getDamage();
			$this->plugin->craft[$levelN][$pos]['itemAmount'] = 1;
			$this->plugin->craft[$levelN][$pos]['craftAmount'] = 0;
			$this->plugin->craft[$levelN][$pos]['price'] = null;
			$this->plugin->craft[$levelN][$pos]['recipe'] = [];
			$this->data['create'][$senderN] = $pos;
			$this->sMsg($sender, 'craft-setting-0', 5);
			$this->saveData(); //상점생성
			$ev->setCancelled();
			$this->plugin->event->addItemEntity([$sender], new Position($block->x, $block->y, $block->z, $block->getLevel()), $this->plugin->craft[$levelN][$pos]['item']);
		}elseif(isset($this->plugin->craft[$levelN][$pos])) { // 조합대 이용
			if($this->plugin->craft[$levelN][$pos]['price'] === null) { return; }
			$ev->setCancelled();
			unset($this->data['craft'][$senderN]);
	
			$recipe = '';
			foreach($this->plugin->craft[$levelN][$pos]['recipe'] as $itemCode => $itemAmount) {
				$recipe .= $this->plugin->getItemName($this->plugin->craft[$levelN][$pos]['item']) . ' ' . $itemAmount . '개\n';
			}

			$ppos = explode('.', $pos);
			$this->plugin->event->createNameTag([$sender], $pos,
				TextFormat::RED . '조합 아이템 : ' . $this->plugin->getItemName($this->plugin->craft[$levelN][$pos]['item']) . "\n"
				. '조합비 : ' . $this->plugin->craft[$levelN][$pos]['price'] . $this->plugin->getMonetaryUnit() . "\n"
				. '== 레시피 ============>>'
			);

			$temp = [];
			foreach($this->plugin->craft[$levelN][$pos]['recipe'] as $itemCode => $itemAmount) {
				$temp[count($temp)][$itemCode] = $itemAmount;
			}
			
			for($i = 1; $i <= floor(count($temp)-1/3)+1; $i++) {
				$ppos[1] -= 1;
				$text = TextFormat::RED;
				foreach($temp as $num => $data) {
					foreach($temp[$num] as $itemCode => $itemAmount) {
						if(($i-1)*3 <= $num && $num < $i*3) { $text .= ' - ' . $this->plugin->getItemName($itemCode) . ' ' . $itemAmount . "개\n"; }
					}
				}
				$this->plugin->event->createNameTag([$sender], implode('.', $ppos), $text);
			}

			$this->sMsg($sender, 'craftItem-0', 5, null, [$this->plugin->craft[$levelN][$pos]['price']]);
			$this->data['craft'][$senderN] = $pos;
		}
	}

	public function onBlockBreak(BlockBreakEvent $ev) {
		$sender = $ev->getPlayer();
		$senderN = strtolower($sender->getName());
		$block = $ev->getBlock();
		$bpos = $block->x.'.'.$block->y.'.'.$block->z;
		$levelN = $block->getLevel()->getName();

		if(isset($this->plugin->craft[$levelN][$bpos])) {
			if($sender->isOp()) {
				unset($this->plugin->craft[$levelN][$bpos]);
				unset($this->data['craft']);
				$this->saveData();
				$this->plugin->sMsg($sender, 'craft-remove-0', 5, TextFormat::RED);
				$this->plugin->event->removeItemEntity('all', $bpos);
				$this->plugin->event->removeEntity($this->plugin->getServer()->getInstance()->getOnlinePlayers(), $bpos);
			}
		}
	}

	public function sMsg($sender, $msg, $mark, $colour = null, $variable = ['', '', '', '', '']) {
		$this->plugin->sMsg($sender, $msg, $mark, $colour, $variable);
	}

	public function saveData() {
		$this->plugin->craftConfig->setAll($this->plugin->craft);
		$this->plugin->craftConfig->save();
	}
}
?>