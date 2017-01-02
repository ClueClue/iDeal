<?php
/*
   Deal - 아이템 안전거래
*/

namespace inflater\iDeal\event;

use pocketmine\plugin\PluginBase; //used
use pocketmine\Player;
use pocketmine\event\Listener; //used
use pocketmine\item\Item;
use pocketmine\utils\TextFormat;
use inflater\iDeal\iDeal;

class DealEvent implements Listener {
	private $plugin;

	public function __construct(iDeal $plugin) {
		$this->plugin = $plugin;
	}

	public function request($sender, $targetN, $count, $price) {
		$senderN = strtolower($sender->getName());
		$targetN = strtolower($targetN);
		$target = $this->plugin->getServer()->getPlayer($targetN);
		if(isset($this->plugin->deal['deal'][$senderN])) { $this->plugin->sMsg($sender, 'safedeal-request-fail-0', 0, TextFormat::RED); return; }
		if($targetN == $senderN) { $this->plugin->sMsg($sender, 'safedeal-request-fail-1', 0, TextFormat::RED); return; }
		if(!$target instanceof Player) { $this->plugin->sMsg($sender, 'safedeal-request-fail-2', 0, TextFormat::RED); return; }
		if(isset($this->plugin->deal['settings'][$targetN]['receiveOff'])) { $this->plugin->sMsg($sender, 'safedeal-request-fail-3', 0, TextFormat::RED); return; }
		if(isset($this->plugin->deal['deal'][$targetN])) { $this->plugin->sMsg($sender, 'safedeal-request-fail-4', 0, TextFormat::RED); return; }

		$hand = $sender->getInventory()->getItemInHand();
		$handId = $hand->getId();
		$handDamage = $hand->getDamage();
		if($handDamage == 0) { $handItemCode = $handId; }else{ $handItemCode = $handId . ":" . $handDamage; }
		if($handId == 0) { $this->plugin->sMsg($sender, 'safedeal-request-fail-5', 0, TextFormat::RED); return; }
		$have = 0;
		foreach($sender->getInventory()->getContents() as $item) {
			if($item->getId() == $handId&&$item->getDamage() == $handDamage) { $have += $item->getCount(); }
		}
		$banItem = str_replace(" ", "", $this->plugin->setting['safedeal_ban-item']);
		$banItem = explode(',', $banItem);
		foreach($banItem as $item) {
			if(!strpos($item, ':')) { $item .= ":0"; }
			if($item==$handItemCode) { $this->plugin->sMsg($sender, 'safedeal-request-fail-6', 0, TextFormat::RED); return; }
		}
		if($this->plugin->getItemName($handItemCode) === false) { $this->plugin->sMsg($sender, 'safedeal-request-fail-6', 0, TextFormat::RED); return; }
		if($have < $count) { $this->plugin->sMsg($sender, 'safedeal-request-fail-7', 0, TextFormat::RED, [$have]); return; }
		if ($price < 0) { $this->plugin->sMsg($sender, 'safedeal-request-fail-8', 0, TextFormat::RED); return; }
		$this->plugin->deal['deal'][$senderN]['target'] = $targetN;
		$this->plugin->deal['deal'][$senderN]['item'] = $handItemCode;
		$this->plugin->deal['deal'][$senderN]['count'] = (int) $count;
		$this->plugin->deal['deal'][$senderN]['price'] = (int) $price;
		$this->plugin->deal['deal'][$targetN]['deal'] = $senderN;
		$this->plugin->sMsg($sender, 'safedeal-request-0', 0, null, [$targetN, $this->plugin->getItemName($handItemCode), $count, $price]);
		$this->plugin->sMsg($target, 'safedeal-request-1', 0, null, [$senderN, $this->plugin->getItemName($handItemCode), $count, $price]);
		$this->plugin->sMsg($target, 'safedeal-request-2', 0);
		$this->plugin->getLogger()->info(TextFormat::DARK_GREEN . "{$senderN} 님이 {$targetN}님에게 거래를 요청하였습니다. ({$handItemCode}, {$count}, {$price})");
	}

	public function state($sender) {
		$senderN = strtolower($sender->getName());
		if(isset($this->plugin->deal['deal'][$senderN]['target'])) {
			$this->plugin->sMsg($sender, 'safedeal-state-0', 0);
			$this->plugin->sMsg($sender, 'safedeal-state-2', 0, null, [$this->plugin->deal['deal'][$senderN]['target']]);
			$this->plugin->sMsg($sender, 'safedeal-state-3', 0, null, [$this->plugin->getItemName($this->plugin->deal['deal'][$senderN]['item'])]);
			$this->plugin->sMsg($sender, 'safedeal-state-4', 0, null, [$this->plugin->deal['deal'][$senderN]['count']]);
			$this->plugin->sMsg($sender, 'safedeal-state-5', 0, null, [$this->plugin->deal['deal'][$senderN]['price']]);
			$this->plugin->sMsg($sender, 'safedeal-state-1', 0);
			return true;
		}
		if(isset($this->plugin->deal['deal'][$senderN]['deal'])) { // 받은 거래가 있음
			$dealer = $this->plugin->deal['deal'][$senderN]['deal'];
			$this->plugin->sMsg($sender, 'safedeal-state-0', 0);
			$this->plugin->sMsg($sender, 'safedeal-state-2', 0, null, [$this->plugin->deal['deal'][$senderN]['deal']]);
			$this->plugin->sMsg($sender, 'safedeal-state-3', 0, null, [$this->plugin->getItemName($this->plugin->deal['deal'][$dealer]['item'])]);
			$this->plugin->sMsg($sender, 'safedeal-state-4', 0, null, [$this->plugin->deal['deal'][$dealer]['count']]);
			$this->plugin->sMsg($sender, 'safedeal-state-5', 0, null, [$this->plugin->deal['deal'][$dealer]['price']]);
			$this->plugin->sMsg($sender, 'safedeal-state-1', 0);
			return true;
		}
		$this->plugin->sMsg($sender, 'safedeal-state-6', 0);
	}

	public function accept($sender) {
		$senderN = strtolower($sender->getName());
		if(!isset($this->plugin->deal['deal'][$senderN]['deal'])) { $this->plugin->sMsg($sender, 'safedeal-accept-fail-0', 0); return; }
		$dealerN = $this->plugin->deal['deal'][$senderN]['deal'];
		$dealer = $this->plugin->getServer()->getPlayer($dealerN);
		if(!$dealer instanceof Player) {
			$this->plugin->sMsg($sender, 'safedeal-accept-fail-1', 0, TextFormat::RED);
			$this->plugin->sMsg($sender, 'safedeal-deal-cancelled', 0, TextFormat::RED);
			unset($this->plugin->deal['deal'][$senderN]);
			unset($this->plugin->deal['deal'][$dealerN]);
			return;
		}
		if ($this->plugin->EconomyAPI->myMoney($sender) < $this->plugin->deal['deal'][$dealerN]['price']) { // 본인의 돈이 거래희망액보다 적을경우
			$this->plugin->sMsg($dealer, 'safedeal-accept-fail-3', 0, TextFormat::RED);
			$this->plugin->sMsg($sender, 'safedeal-accept-fail-4', 0, TextFormat::RED);
			$this->plugin->sMsg($sender, 'safedeal-deal-cancelled', 0, TextFormat::RED);
			unset($this->plugin->deal['deal'][$senderN]);
			unset($this->plugin->deal['deal'][$dealerN]);
			return;
		}
		$item = explode(':', $this->plugin->deal['deal'][$dealerN]['item']);
		if(!isset($item[1])) { $item[1] = 0; }
		$have = 0;
		foreach($dealer->getInventory()->getContents() as $content) {
			if ($content->getId() == $item[0] && $content->getDamage() == $item[1]) {
				$have += $content->getCount();
			}
		}
		if ($have < $this->plugin->deal['deal'][$dealerN]['count']) { // 거래요청자의 아이템이 거래희망개수보다 적을경우
			$this->plugin->sMsg($dealer, 'safedeal-accept-fail-2', 0, TextFormat::RED);
			$this->plugin->sMsg($sender, 'safedeal-accept-fail-5', 0, TextFormat::RED);
			$this->plugin->sMsg($sender, 'safedeal-deal-cancelled', 0, TextFormat::RED);
			unset($this->plugin->deal['deal'][$senderN]);
			unset($this->plugin->deal['deal'][$dealerN]);
			return;
		}
		$this->plugin->EconomyAPI->addMoney($dealer, $this->plugin->deal['deal'][$dealerN]['price']);
		$dealer->getInventory()->removeItem(Item::get((int) $item[0], (int) $item[1], (int) $this->plugin->deal['deal'][$dealerN]['count']));
		$this->plugin->EconomyAPI->reduceMoney($sender, $this->plugin->deal['deal'][$dealerN]['price']);
		$sender->getInventory()->addItem(Item::get((int) $item[0], (int) $item[1], (int) $this->plugin->deal['deal'][$dealerN]['count']));
		
		$this->plugin->sMsg($sender, 'safedeal-accept-0');
		$this->plugin->sMsg($sender, 'safedeal-accept-1');
		$this->plugin->sMsg($dealer, 'safedeal-accept-0');
		$this->plugin->sMsg($dealer, 'safedeal-accept-2');
		$this->plugin->getLogger()->info(TextFormat::GREEN . "거래가 무사히 성사되었습니다. ({$dealerN}, {$senderN})" );
		unset($this->plugin->deal['deal'][$senderN]);
		unset($this->plugin->deal['deal'][$dealerN]);
	}

	public function rejected($sender) {
		$senderN = strtolower($sender->getName());
		if(!isset($this->plugin->deal['deal'][$senderN]['deal'] )) { // 요청들어온 거래가 없을경우
			$this->plugin->sMsg($sender, 'safedeal-rejected-fail-0', 0, TextFormat::RED); return true;
		}
		$dealerN = $this->plugin->deal['deal'][$senderN]['deal'];
		$dealer = $this->plugin->getServer()->getPlayer($dealerN);
		if (!$dealer instanceof Player) { // 거래요청자가 오프라인일경우
			$this->plugin->sMsg($sender, 'safedeal-rejected-0', 0, TextFormat::RED);
			unset($this->plugin->deal['deal'][$senderN]);
			unset($this->plugin->deal['deal'][$dealerN]);
			return;
		}
		$this->plugin->sMsg($sender, 'safedeal-rejected-0', 0, TextFormat::RED);
		$this->plugin->sMsg($dealer, 'safedeal-rejected-1', 0, TextFormat::RED, [$senderN]);
		unset($this->plugin->deal['deal'][$senderN]);
		unset($this->plugin->deal['deal'][$dealerN]);
	}

	public function cancel($sender) {
		$senderN = strtolower($sender->getName());
		if(!isset($this->plugin->deal['deal'][$senderN]['target'])) { // 받은 거래요청이 없을경우
			$this->plugin->sMsg($sender, 'safedeal-cancelled-fail-0', 0, TextFormat::RED); return;
		}
		$dealerN = $this->plugin->deal['deal'][$senderN]['target'];
		$dealer = $this->plugin->getServer()->getPlayer($dealerN);
		if(!$dealer instanceof Player) { // 거래요청자가 오프라인일경우
			$this->plugin->sMsg($sender, 'safedeal-cancelled-0', 0, TextFormat::RED);
			unset($this->plugin->deal['deal'][$senderN]);
			unset($this->plugin->deal['deal'][$dealerN]);
			return true;
		}
		$this->plugin->sMsg($sender, 'safedeal-cancelled-0', 0, TextFormat::RED);
		$this->plugin->sMsg($dealer, 'safedeal-cancelled-1', 0, TextFormat::RED);
		unset($this->plugin->deal['deal'][$senderN]);
		unset($this->plugin->deal['deal'][$dealerN]);
	}

	public function receive($sender) {
		$senderN = strtolower($sender->getName());
		if(!isset($this->plugin->deal['settings'][$senderN]['receiveOff'])) {
			$this->plugin->deal['settings'][$senderN]['receiveOff'] = true;
			$this->plugin->sMsg($sender, 'safedeal-receive-0', 0, TextFormat::RED);
			return;
		}
		unset($this->plugin->deal['settings'][$senderN]['receiveOff']);
		$this->plugin->sMsg($sender, 'safedeal-receive-1');
	}
}
?>