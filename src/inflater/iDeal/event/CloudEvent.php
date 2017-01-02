<?php
/*
   Cloud - 아이템 클라우드
*/

namespace inflater\iDeal\event;

use pocketmine\plugin\PluginBase; //used
use pocketmine\Player;
use pocketmine\event\Listener; //used
use pocketmine\item\Item;
use pocketmine\utils\TextFormat;
use inflater\iDeal\iDeal;

class CloudEvent implements Listener {
	private $plugin;

	public function __construct(iDeal $plugin) {
		$this->plugin = $plugin;
	}

	public function upload($sender, $count) {
		if(!$sender instanceof Player) { $this->plugin->sMsg($sender, 'must-use-in-game', 1, TextFormat::RED); return; }
		$senderN = strtolower($sender->getName());
		$hand = $sender->getInventory()->getItemInHand();
		$handId = $hand->getId();
		$handDamage = $hand->getDamage();
		$handItemCode = $handId.":".$handDamage;

		if($hand->isTool()) { $this->plugin->sMsg($sender, 'itemcloud-upload-fail-0', 1, TextFormat::RED); return; }

		$banItem = explode(',', str_replace(" ", "", $this->plugin->setting['itemcloud_ban-item']));
		foreach($banItem as $item) { 
			if(!strpos($item, ':')) { $item .= ":0"; }
			if($item==$handItemCode) { $this->plugin->sMsg($sender, 'itemcloud-upload-fail-0', 1, TextFormat::RED); return; }
		}

		$have = 0;
		foreach ($sender->getInventory()->getContents() as $item) {
			if($handId == 0) { $this->plugin->sMsg($sender, 'itemcloud-upload-0', 1, TextFormat::RED); return; }
			if($item->getId() == $handId && $item->getDamage() == $handDamage) {
				$have += $item->getCount ();
			}
		}
		if($have < $count) { $this->plugin->sMsg($sender, 'itemcloud-upload-1', 1, TextFormat::RED, [$have]); return; }

		// UPLOAD
		$add = $this->add($senderN, $handItemCode, $count);
		if($add !== 0) {
			$sender->getInventory()->removeItem(Item::get($handId, $handDamage, $count));
			$this->plugin->sMsg($sender, 'itemcloud-upload-2', 1, null, [$this->plugin->getItemName($handItemCode), $count, $add]);
		}elseif($add === 0) { $this->plugin->sMsg($sender, 'itemcloud-upload-fail-1', 1, TextFormat::RED, [$this->plugin->itemCloud['data'][$senderN]['capacity']]); }
	}

	public function download($sender, $id) {
		if(!$sender instanceof Player) { $this->plugin->sMsg($sender, 'must-use-in-game', 1, TextFormat::RED); return; }
		$senderN = strtolower($sender->getName());
		if(!isset($this->plugin->itemCloud['upload'][$senderN][$id])) { $this->plugin->sMsg($sender, 'itemcloud-download-fail-0', 1, TextFormat::RED); return; }
		$item = explode(':', $this->plugin->itemCloud['upload'][$senderN][$id]['item']);
		$sender->getInventory()->addItem(Item::get($item[0], $item[1], $this->plugin->itemCloud['upload'][$senderN][$id]['count']));
		$this->plugin->sMsg($sender, 'itemcloud-download-0', 1, null, [$this->plugin->getItemName($this->plugin->itemCloud['upload'][$senderN][$id]['item']), $this->plugin->itemCloud['upload'][$senderN][$id]['count']]);
		$this->plugin->sMsg($sender, 'itemcloud-download-1', 1);
		unset($this->plugin->itemCloud['upload'][$senderN][$id]);
		$this->plugin->itemCloud['data'][$senderN]['uploads']--;
		$this->saveData();
	}

	public function remove($sender, $id = false, $target = null) {
		if(!$sender instanceof Player&&$target==null) { $this->plugin->sMsg($sender, 'must-use-in-game', 1, TextFormat::RED); return; }
		if($target!==null && $sender->isOp()) { $playerN = strtolower($target); } else { $playerN = strtolower($sender->getName()); }
		if(!isset($this->plugin->itemCloud['upload'][$playerN][$id])) { $this->plugin->sMsg($sender, 'itemcloud-remove-fail-0', 1, TextFormat::RED); return; }
		unset($this->plugin->itemCloud['upload'][$playerN][$id]);
		$this->plugin->sMsg($sender, 'itemcloud-remove-0', 1);
		$this->plugin->itemCloud['data'][$playerN]['uploads']--;
		$this->saveData();
	}

	public function showList($sender, $page = 1, $target = null) {
		if(!$sender instanceof Player&&$target==null) { $this->plugin->sMsg($sender, 'must-use-in-game', 1, TextFormat::RED); return; }
		$playerN = strtolower($sender->getName());
		if($target!=null) { $playerN = strtolower($target); }

		if(!isset($this->plugin->itemCloud['upload'][$playerN]) || count($this->plugin->itemCloud['upload'][$playerN]) < 1) {
			$this->plugin->sMsg($sender, 'itemcloud-list-0', 1, TextFormat::RED); return;
		}
		$try = 0;
		$count = 0;
		$this->plugin->sMsg($sender, 'itemcloud-list', 1, null, [$page, floor((count($this->plugin->itemCloud['upload'][$playerN])+4)/5)]);
		$this->plugin->sMsg($sender, 'itemcloud-list2', 1);
		foreach($this->plugin->itemCloud['upload'][$playerN] as $id => $cloud) {
			if($id=="items") { continue; }
			if($try < ($page-1)*5) { $try++; continue; }
			if($count>4) { break; }
			$this->plugin->sMsg($sender, 'itemcloud-list-item', 1, null, [$id,
				$this->plugin->getItemName($this->plugin->itemCloud['upload'][$playerN][$id]['item']),
				$this->plugin->itemCloud['upload'][$playerN][$id]['count'],
				$this->plugin->itemCloud['upload'][$playerN][$id]['date']
			]);
			$count++;
		}
		if($count==0) { $this->plugin->sMsg($sender, 'itemcloud-list-2', 1, TextFormat::RED); }
	}

	public function expansion($sender) { // 용량 확장
		if(!$sender instanceof Player) { $this->plugin->sMsg($sender, 'must-use-in-game', 1, TextFormat::RED); return; }
		$senderN = strtolower($sender->getName());
		$cloudSize = $this->plugin->itemCloud['data'][$senderN]['capacity'];
		if($this->plugin->EconomyAPI->myMoney($sender) < $this->plugin->setting['itemcloud_expansion-price']) {
			$this->plugin->sMsg($sender, 'itemcloud-expand-0', 1, TextFormat::RED, [$this->plugin->setting['itemcloud_expansion-price']]); return;
		}
		if(!isset($this->plugin->data[$senderN]['cloud']['expansion'])) {
			$this->plugin->sMsg($sender, 'itemcloud-expand-1', 1);
			$this->plugin->sMsg($sender, 'itemcloud-expand-2', 1);
			$this->plugin->sMsg($sender, 'itemcloud-expand-3', 1, null, [$this->plugin->setting['itemcloud_expansion-price'], $this->plugin->setting['itemcloud_expansion-size']]);
			$this->plugin->data[$senderN]['cloud']['expansion'] = true; return;
		}
		$this->plugin->EconomyAPI->reduceMoney($sender, $this->plugin->setting['itemcloud_expansion-price']);
		$this->plugin->itemCloud['data'][$senderN]['capacity'] += $this->plugin->setting['itemcloud_expansion-size'];
		$this->plugin->sMsg($sender, 'itemcloud-expand-4', 1, null, [$cloudSize,$this->plugin->itemCloud['data'][$senderN]['capacity']]);
		unset($this->plugin->data[$senderN]['cloud']['expansion']);
		$this->saveData();
	}

	public function add($playerN, $item, $count = 1) {
		$mycloud = isset($this->plugin->itemCloud['upload'][$playerN]) ? $this->plugin->itemCloud['upload'][$playerN] : null;
		if(count($mycloud !== null ? $mycloud : 5) >= $this->plugin->itemCloud['data'][$playerN]['capacity']) { return 0; }
		$item = explode(':', $item);
		for($i=1; $i<=$this->plugin->itemCloud['data'][$playerN]['capacity']; $i++) {
			if(!isset($mycloud[$i])) {
				$this->plugin->itemCloud['upload'][$playerN][$i]['item'] = $item[0] . ':' . (isset($item[1]) ? $item[1] : 0);
				$this->plugin->itemCloud['upload'][$playerN][$i]['count'] = $count;
				$this->plugin->itemCloud['upload'][$playerN][$i]['date'] = date("Y-m-d H:i:s");
				$this->plugin->itemCloud['data'][$playerN]['uploads']++;
				$this->saveData();
				return $i;
			}
		}
	}

	public function addCommand($sender, $targetN = null, $item = null, $count = 1) {
		if(!$sender->isOp()) { $this->plugin->sMsg($sender, 'cant-use-command', 1, TextFormat::RED); return; }
		if($item===null) { $this->plugin->sMsg($sender, 'itemcloud-add-usage'); return; }
		$add = $this->add($targetN, $item, $count);
		if($add === true) { $this->plugin->sMsg($sender, 'itemcloud-upload-2', 1, null, [$this->plugin->getItemName($item), $count, $i]); }
		elseif($add === 0) { $this->plugin->sMsg($sender, 'itemcloud-upload-fail-1', 1, TextFormat::RED, [$this->plugin->itemCloud['data'][$targetN]['capacity']]); }
		$this->plugin->sMsg($sender, 'itemcloud-upload-2', 1, null, [$this->plugin->getItemName($item), $count, $add]);
	}

	public function workCancel($sender) {
		if(!$sender instanceof Player) { $this->plugin->sMsg($sender, 'must-use-in-game', 1, TextFormat::RED); return; }
		$senderN = strtolower($sender->getName());
		$this->plugin->sMsg($sender, 'itemcloud-work-cancelled', 1, TextFormat::RED);
		unset($this->plugin->data[$senderN]['cloud']);
	}

	public function saveData() {
		$this->plugin->itemCloudConfig->setAll($this->plugin->itemCloud);
		$this->plugin->itemCloudConfig->save();
	}
}
?>